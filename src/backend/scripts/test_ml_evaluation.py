import numpy as np
from ml_evaluation import calculate_metrics

def test_metrics_mae_rmse_wape():
    actual = np.array([10.0, 20.0, 30.0])
    predicted = np.array([12.0, 18.0, 30.0])
    
    metrics = calculate_metrics(actual, predicted)
    
    assert abs(metrics['mae'] - 1.3333333333333333) < 1e-6
    assert abs(metrics['rmse'] - 1.632993161855452) < 1e-6
    assert abs(metrics['wape'] - (4.0 / 60.0)) < 1e-6
    assert metrics['missing_metric_reason'] is None

def test_wape_zero_demand():
    actual = np.array([0.0, 0.0])
    predicted = np.array([2.0, 3.0])
    
    metrics = calculate_metrics(actual, predicted)
    assert metrics['wape'] is None
    assert metrics['missing_metric_reason'] == "Zero actual demand"

if __name__ == '__main__':
    test_metrics_mae_rmse_wape()
    test_wape_zero_demand()
    print("All metrics tests passed.")
