import numpy as np
import pandas as pd
from typing import List, Tuple

def rolling_origin_split(df: pd.DataFrame, date_col: str, horizon_days: int, n_splits: int = 3) -> List[Tuple[pd.DataFrame, pd.DataFrame]]:
    """
    Creates rolling-origin chronological splits preserving calendar horizons (#365).
    df must have date_col.
    Returns list of (train_df, test_df) in chronological order.
    """
    df = df.sort_values(by=date_col)
    max_date = pd.to_datetime(df[date_col].max())
    min_date = pd.to_datetime(df[date_col].min())
    
    splits = []
    for i in range(n_splits):
        test_end_date = max_date - pd.Timedelta(days=i * horizon_days)
        test_start_date = test_end_date - pd.Timedelta(days=horizon_days - 1)
        origin_date = test_start_date - pd.Timedelta(days=1)
        
        if origin_date < min_date:
            break
            
        test_mask = (df[date_col] >= test_start_date) & (df[date_col] <= test_end_date)
        train_mask = df[date_col] <= origin_date
        
        if not train_mask.any() or not test_mask.any():
            break
            
        splits.append((df[train_mask], df[test_mask]))
        
    return splits[::-1]  # Return in chronological order
