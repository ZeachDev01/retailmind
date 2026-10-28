# What happened in this experiment?

Runtime: local Python

| Days ahead | Model average mistake (units) | Simple rule mistake (units) | Model WAPE | Simple rule WAPE |
|---|---:|---:|---:|---:|
| 7 | 302.00 | 310.00 | 45.80% | 47.01% |
| 14 | 493.74 | 541.55 | 39.67% | 43.51% |
| 28 | 954.41 | 946.00 | 40.22% | 39.87% |

These mistakes compare the total units for each product over the chosen number of days.

- At 7 days, the model beats the simple rule on average unit error.
- At 14 days, the model beats the simple rule on average unit error.
- At 28 days, the model does not beat the simple rule on average unit error.

Total predicted minus actual sales: -9844.07 units. Negative means predicting too little; positive means predicting too much.

Sale-day scores (0 to 1, higher is better):
- random_forest: precision=0.9916666666666667, recall=1.0, F1=0.99581589958159.
- seasonal_naive: precision=0.9915254237288136, recall=0.9831932773109243, F1=0.9873417721518988.
- always_sale: precision=0.85, recall=1.0, F1=0.918918918918919.

## What can we improve?

Check products where the model loses to the simple rule. Look for repeated underprediction and missed sales peaks. Test any changes using earlier validation periods. Because we have now seen this final result, a later independent confirmation needs different held-out evidence.

This is a UK gift and wholesale business. The results do not prove accuracy for a Philippine grocery Store. Sales records do not show how much demand was lost when products were unavailable.

## What happens in RetailMind later?

Record real Store sales first. Check that enough usable daily history is available. Train a new Store model in the background. Test it and ask the Super Administrator to approve it before use. Then generate forecasts after closing and show buying suggestions for staff to review.
If the model fails the checks, keep the existing approved model or stock rules. Do not use a failed candidate or change stock automatically.
Automatic training and automatic approval are different. This notebook does not change the Store system or its approval settings.

## Run details

This run completed locally on 2026-10-28. It is not a Google Colab execution. Both yearly worksheets were read: 1,067,371 input rows, with 22,523 repeated copies removed across worksheets. History covers 2009-12-02 to 2011-12-08; 20 products, 653 initial training days and 84 later validation/test days. The final test covers 2011-11-11 to 2011-12-08. The first two blocks choose only the sale-day threshold; model parameters remain fixed.

The sale-day F1 of 0.9958 is about the yes/no sale-day question. It is not 99.58% quantity accuracy. Quantity errors remain substantial and do not meet the existing Demo 35% WAPE cap. Do not approve this candidate for Store use on the strength of F1. These late-2011 dates overlap the earlier UCI dataset experiment, so this is not independent evidence from another retailer.

[Open the Colab notebook](uci_online_retail_ii_colab.ipynb). [Read the simple process and integration plan](uci-ii-colab-and-store-training.md).

Three focused notebook tests passed, and the complete data-loading, training, result-export and chart cells ran locally. Package installation and ZIP downloading inside Google Colab remain unverified. The Store database, scheduler and approval settings were not changed.

Local evidence (ignored by Git):

- [metrics.json](../../.scratch/uci-ii-colab/results-20261028T002924840478Z/metrics.json)
- [scores.csv](../../.scratch/uci-ii-colab/results-20261028T002924840478Z/scores.csv)
- [daily_predictions.csv](../../.scratch/uci-ii-colab/results-20261028T002924840478Z/daily_predictions.csv)
- [products.csv](../../.scratch/uci-ii-colab/results-20261028T002924840478Z/products.csv)
- [test_predictions.png](../../.scratch/uci-ii-colab/results-20261028T002924840478Z/test_predictions.png)

Source workbook SHA-256: `bcbe73b35f5b7babf197fb0cb983a11f5d9ff929078d4aa53d171b1f2df2e980`.
