import numpy as np
import pandas as pd
from typing import List, Tuple

def rolling_origin_split(df: pd.DataFrame, date_col: str, horizon_days: int, n_splits: int = 3) -> List[Tuple[pd.DataFrame, pd.DataFrame]]:
    """
    Creates rolling-origin chronological splits.
    df must have date_col.
    Returns list of (train_df, test_df)
    """
    df = df.sort_values(by=date_col)
    dates = df[date_col].drop_duplicates().sort_values().tolist()
    
    splits = []
    
    # Evaluate the last n_splits periods of length horizon_days
    # For example, if horizon_days=7 and n_splits=3, we need 21 days at the end for testing
    
    for i in range(n_splits):
        # The test period is [end - (i+1)*horizon_days, end - i*horizon_days)
        test_end_idx = len(dates) - i * horizon_days
        test_start_idx = test_end_idx - horizon_days
        
        if test_start_idx < 1:
            break # Not enough data
            
        test_start_date = dates[test_start_idx]
        if test_end_idx < len(dates):
            test_end_date = dates[test_end_idx]
            test_mask = (df[date_col] >= test_start_date) & (df[date_col] < test_end_date)
        else:
            test_mask = (df[date_col] >= test_start_date)
            
        train_mask = df[date_col] < test_start_date
        
        splits.append((df[train_mask], df[test_mask]))
        
    return splits[::-1]  # Return in chronological order
