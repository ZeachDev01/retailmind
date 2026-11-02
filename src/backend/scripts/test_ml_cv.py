import pandas as pd
from ml_cv import rolling_origin_split

def test_rolling_origin_split():
    dates = pd.date_range(start="2026-01-01", periods=30, freq='D')
    df = pd.DataFrame({'date': dates, 'val': range(30)})
    
    # 7 day horizon, 3 splits -> requires 21 days for test, 9 days for initial train
    splits = rolling_origin_split(df, 'date', horizon_days=7, n_splits=3)
    
    assert len(splits) == 3
    
    # First split
    train0, test0 = splits[0]
    assert len(train0) == 9
    assert len(test0) == 7
    assert train0['date'].max() < test0['date'].min()
    assert test0['date'].min() == pd.Timestamp("2026-01-10")
    assert test0['date'].max() == pd.Timestamp("2026-01-16")

    # Second split
    train1, test1 = splits[1]
    assert len(train1) == 16
    assert len(test1) == 7
    assert train1['date'].max() < test1['date'].min()
    assert test1['date'].min() == pd.Timestamp("2026-01-17")
    assert test1['date'].max() == pd.Timestamp("2026-01-23")

    # Third split
    train2, test2 = splits[2]
    assert len(train2) == 23
    assert len(test2) == 7
    assert train2['date'].max() < test2['date'].min()
    assert test2['date'].min() == pd.Timestamp("2026-01-24")
    assert test2['date'].max() == pd.Timestamp("2026-01-30")

def test_rolling_origin_temporal_isolation_28day_holdouts():
    # 3 holdouts of 28 days = 84 days test + 56 days training = 140 days
    dates = pd.date_range(start="2026-01-01", periods=140, freq='D')
    df = pd.DataFrame({'date': dates, 'units_sold': 10.0})
    
    splits = rolling_origin_split(df, 'date', horizon_days=28, n_splits=3)
    assert len(splits) == 3
    
    for i, (train_df, test_df) in enumerate(splits):
        # Permitted past strictly before test period
        assert train_df['date'].max() < test_df['date'].min()
        # Test period is exactly 28 days
        assert (test_df['date'].max() - test_df['date'].min()).days == 27
        # No overlap between train and test
        assert set(train_df['date']).isdisjoint(set(test_df['date']))

def test_rolling_origin_mon_fri_store_with_weekend_closures():
    # Mon-Fri store across 28 calendar weeks (196 calendar days, 140 trading days)
    dates = [d for d in pd.date_range(start="2026-01-01", periods=196, freq='D') if d.dayofweek < 5]
    df = pd.DataFrame({'date': dates, 'units_sold': 5.0})
    
    splits = rolling_origin_split(df, 'date', horizon_days=28, n_splits=3)
    assert len(splits) == 3
    
    for train_df, test_df in splits:
        assert train_df['date'].max() < test_df['date'].min()
        # In a 28-calendar-day window, Mon-Fri store has 20 trading days
        assert len(test_df) == 20
        # No weekend dates in either train or test
        assert (train_df['date'].dt.dayofweek < 5).all()
        assert (test_df['date'].dt.dayofweek < 5).all()

if __name__ == '__main__':
    test_rolling_origin_split()
    test_rolling_origin_temporal_isolation_28day_holdouts()
    test_rolling_origin_mon_fri_store_with_weekend_closures()
    print("All CV tests passed.")
