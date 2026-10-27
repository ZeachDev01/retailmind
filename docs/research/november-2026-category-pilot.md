# November 2026 category replenishment pilot

Recorded October 27, 2026, before the forecast month. Internal experiment only; do not show as a validated Store forecast.

## Target

Which categories appear at least once on the owner's inventory replenishment receipts in November 2026. This is buying behavior, not customer demand or product quantity.

## Private trial list

From dated purchase receipts through October 13, 2026, count each category once per receipt and rank by how often it appeared. Exclude the return receipt, the receipt without a confirmed date, and four `unsure` item categories. Break frequency ties alphabetically. Top five:

1. Household
2. Pantry
3. Beverages
4. Snacks
5. Personal Care

This is a simple frequency baseline fitted to the available receipts. It is not a trained Random Forest or evidence of seasonality.

## Provisional October check

Using seven dated September purchase receipts only, the baseline suggested Beverages, Household, Pantry, Snacks, and Baby Care for October. Four of those five categories appeared on the four supplied October receipts dated through October 13. Six distinct categories appeared in those receipts: precision **4/5 = 0.800**, recall **4/6 = 0.667**, F1 **8/11 = 0.727**.

These values describe one incomplete October observation, using unapproved item categories and unconfirmed receipt coverage. They are **not validated monthly forecast accuracy** and cannot establish how the method will perform in November. The November list must be compared with November receipts only after that month ends and coverage is checked.

Method and runnable Colab cells: [receipt forecast notebook](replenishment_data_readiness_colab.ipynb). Source corrections and limitations: [image review](replenishment-receipt-image-review-2026-10-27.md).
