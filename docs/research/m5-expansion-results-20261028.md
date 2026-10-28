# M5 expansion: 20 versus 50 products

Measured locally on 2026-10-28 (Asia/Manila). **Mixed result: more products do not improve every horizon.** No Store model was deployed. Google Colab execution remains unverified.

## Matched comparison

Both models are evaluated on the same original 20 products. Lower MAE and WAPE are better. MAE is the average absolute mistake in each product's horizon total, not a daily error. WAPE is error, not an accuracy percentage.

| Period | Horizon | 20-product MAE | 50-product MAE | 20-product WAPE | 50-product WAPE | Weekly baseline WAPE |
|---|---|---:|---:|---:|---:|---:|
| validation | 7 days | 8.259 | 4.869 | 64.90% | 38.26% | 37.33% |
| validation | 14 days | 16.740 | 10.148 | 67.36% | 40.84% | 41.25% |
| validation | 28 days | 31.845 | 20.486 | 64.79% | 41.68% | 48.93% |
| test | 7 days | 8.134 | 10.358 | 44.33% | 56.45% | 39.51% |
| test | 14 days | 15.037 | 14.941 | 34.73% | 34.51% | 37.18% |
| test | 28 days | 32.707 | 29.744 | 35.45% | 32.24% | 37.67% |

Validation pools the two validation blocks (40 product-period totals per horizon); test contains 20 totals per horizon. The original 20-product rerun reproduces the earlier reported test results.

The expansion improves all three validation horizons, but its 7-day validation error still slightly exceeds the weekly baseline. In the previously inspected final window, 7-day MAE worsens by about 27.35%, 14-day improves by about 0.64%, and 28-day improves by about 9.06%. Therefore it is a promising 28-day demonstration, not an across-the-board improvement. At 28 days, the expanded model still underpredicts the original products by 268.35 units in total (versus 323.24 previously).

## All 50 products (different population)

| Test horizon | MAE | RMSE | WAPE | Weekly baseline WAPE |
|---|---:|---:|---:|---:|
| 7 days | 5.926 | 11.921 | 43.00% | 37.88% |
| 14 days | 9.977 | 16.414 | 32.44% | 37.71% |
| 28 days | 18.936 | 32.244 | 29.63% | 36.53% |

These averages include newly added products and cannot alone prove that additional training helped the original products. The expanded model still loses to the weekly baseline at 7 days. Quantity scores must not be replaced with sale-day F1.

## Fixed selection and protocol

Retained the original 20 CA_1 / FOODS codes and added 30 from the remaining training-eligible codes with seed 42. Eligibility and sampling use earlier sales only. There were 1,437 eligible products; product codes are anonymous and cannot identify equivalent Store brands. Initial training is 2014-03-01 through 2016-02-28 (730 days). Validation covers the following 56 days; the last window is 2016-04-25 through 2016-05-22. Model settings remain 15 trees, depth 8, seed 42, with the same shared features.

Added products:

`FOODS_1_122`, `FOODS_1_127`, `FOODS_1_136`, `FOODS_1_186`, `FOODS_2_046`, `FOODS_2_070`, `FOODS_2_313`, `FOODS_2_360`, `FOODS_2_393`, `FOODS_3_003`, `FOODS_3_022`, `FOODS_3_027`, `FOODS_3_100`, `FOODS_3_116`, `FOODS_3_133`, `FOODS_3_170`, `FOODS_3_307`, `FOODS_3_371`, `FOODS_3_405`, `FOODS_3_431`, `FOODS_3_468`, `FOODS_3_478`, `FOODS_3_506`, `FOODS_3_507`, `FOODS_3_569`, `FOODS_3_585`, `FOODS_3_602`, `FOODS_3_715`, `FOODS_3_774`, `FOODS_3_823`.

The final window was already inspected before this expansion. It is development evidence, not a new independent test. Do not tune repeatedly against it, select only successful products, or infer operational Store approval. Walmart sales are recorded units; unknown stockouts and local Store differences remain limitations.

## Reproduce and inspect

Run from the repository root with the notebook's pinned packages:

```powershell
python src/backend/scripts/m5_sales_experiment.py .scratch/m5-colab/m5_daily.parquet --products 20 --output-dir .scratch/m5-colab/comparison-20-new
python src/backend/scripts/m5_sales_experiment.py .scratch/m5-colab/m5_daily.parquet --products 50 --output-dir .scratch/m5-colab/comparison-50-new
```

For the matched comparison, filter each daily_predictions.csv to the original 20 codes, sum actual/predicted/baseline by fold and product within each horizon, then calculate the same MAE/RMSE/WAPE functions used by the experiment. Keep validation and test separate.

Local evidence (ignored by Git):

- [20-product metrics](../../.scratch/m5-colab/matched-20-20261028/metrics.json) and [daily predictions](../../.scratch/m5-colab/matched-20-20261028/daily_predictions.csv).
- [50-product metrics](../../.scratch/m5-colab/expanded-50-20261028/metrics.json), [per-product scores](../../.scratch/m5-colab/expanded-50-20261028/scores.csv), and [daily predictions](../../.scratch/m5-colab/expanded-50-20261028/daily_predictions.csv).
- [Matched and all-product comparison CSV](../../.scratch/m5-colab/expansion-comparison-20261028.csv).

The [Colab notebook](m5_random_forest_colab.ipynb) now defaults to 50 products, supports the original 20, embeds the current script, and charts every selected product. Its source/parity and local chart execution are checked separately from execution inside Colab.
