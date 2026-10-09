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

if __name__ == '__main__':
    test_rolling_origin_split()
    print("All CV tests passed.")
