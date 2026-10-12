"""Check reset invalidation and training exclusion without database writes."""
import sys
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'legacy' / 'demandForcasting'))
import train_model

connection = MagicMock()
cursor = connection.cursor.return_value
cursor.fetchone.return_value = (0,)
with patch.object(train_model, 'get_connection', return_value=connection), patch.object(train_model, '_train_and_predict') as train:
    try:
        train_model.train_and_predict('test')
        raise AssertionError('Reset lock must prevent training')
    except RuntimeError as error:
        assert 'already in progress' in str(error)
    train.assert_not_called()
    connection.close.assert_called_once()

connection.reset_mock()
cursor.fetchone.return_value = (1,)
with patch.object(train_model, 'get_connection', return_value=connection), patch.object(train_model, '_train_and_predict', return_value={'status': 'completed'}) as train:
    assert train_model.train_and_predict('test')['status'] == 'completed'
    train.assert_called_once_with(connection, 'test')
    assert any('RELEASE_LOCK' in str(call) for call in cursor.execute.call_args_list)
    connection.close.assert_called_once()

cursor.fetchone.return_value = (0,)
with patch.object(train_model, 'get_connection', return_value=connection):
    assert not train_model.training_run_exists({'training_run_id': 123}), 'Deleting training history must invalidate artifacts'
with patch.object(train_model, 'METRICS_PATH') as metrics_path, patch.object(train_model, 'training_run_exists', return_value=False):
    metrics_path.read_text.return_value = '{"training_run_id":123}'
    assert train_model.load_metrics() == {}, 'Reset metrics must not be displayed'
print('Forecast reset guard checks passed (training exclusion, lock release, stale model invalidation)')
