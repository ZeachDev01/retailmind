import numpy as np
from ml_evaluation import calculate_metrics, seasonal_naive_forecast

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

def test_seasonal_naive_forecast():
    # History: 14 days of data.
    history = np.array([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14])
    horizon = 7
    predicted = seasonal_naive_forecast(history, horizon)
    assert len(predicted) == 7
    assert np.array_equal(predicted, [8, 9, 10, 11, 12, 13, 14])

def test_seasonal_naive_forecast_short_history():
    history = np.array([10, 20, 30])
    horizon = 7
    predicted = seasonal_naive_forecast(history, horizon)
    assert len(predicted) == 7
    # For day 1-4 (indices 0-3), step_back > 3, so falls back to history[-1] = 30.
    # For day 5-7 (indices 4-6), step_back is 3, 2, 1, so it uses 10, 20, 30.
    assert np.array_equal(predicted, [30, 30, 30, 30, 10, 20, 30])

if __name__ == '__main__':
    test_metrics_mae_rmse_wape()
    test_wape_zero_demand()
    test_seasonal_naive_forecast()
    test_seasonal_naive_forecast_short_history()
    print("All metrics tests passed.")
