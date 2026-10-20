# UCI training and testing data

Created 2026-10-21 from the prepared UCI Online Retail experiment. CSV files can
be opened in Excel. Product selection and preparation are unchanged.

| File | Dates, inclusive | Calendar days | Daily rows | Products |
| --- | --- | ---: | ---: | ---: |
| `train.csv` | 2010-12-02 to 2011-09-15 | 288 | 5,760 | 20 |
| `test.csv` | 2011-09-16 to 2011-12-08 | 84 | 1,680 | 20 |

The split is chronological, approximately 77.4% training / 22.6% testing. All
products use the same date boundary. There are no overlapping product/date keys,
no missing rows relative to the prepared history, and no random shuffling.
The final 84 days match the existing three 28-day evaluation blocks.

## Columns

- `date`: original historical calendar date, `YYYY-MM-DD`.
- `product_id`: original external product code; load as text. Names are in
  `products.csv`. These codes are not live Store product IDs.
- `units_sold`: recorded positive sales units, the prediction target. Return and
  cancellation rows were excluded during preparation; these are not net sales
  after matching cancellations to original purchases.

These are daily observations, not precomputed model features. Use the shared
`feature_pipeline.py` to construct lag, rolling, calendar and horizon inputs.
The first 28 training days provide historical context. Never build features
using sales from after their forecast origin. Keep training history available
when building the first test-period inputs; the test CSV alone has no pre-test
lag context. The source's zero-sales assumption and other limitations still apply.

## How to use the split honestly

Fit a new model using only `train.csv`, then score its predictions against
`test.csv`. Freeze model choices before evaluating. The existing Demo model was
finally refitted on the entire history, so it must not be evaluated against this
test file as though those observations were unseen.

Previously reported results are chronological rolling-origin backtests, not a
new fixed-model train/test score. Their later folds may learn from earlier
completed blocks. No model was retrained and no new accuracy score was produced
when exporting these files. No database or live Demo state was changed.

## Source and license

Chen, D. (2015). *Online Retail* [Dataset]. UCI Machine Learning Repository.
[Dataset and schema](https://archive.ics.uci.edu/dataset/352/online+retail),
[DOI: 10.24432/C5BW33](https://doi.org/10.24432/C5BW33).
Licensed [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
Modifications: filtering, training-period product selection, daily aggregation,
explicit zero-recorded-sales assumption, boundary-date exclusion, and date split.
Full details and workbook checksum: [dataset assessment](../../docs/research/public-sales-capstone.md).

This is UK online gift/wholesale data, not sales from the partner Store. Its
results cannot establish Store accuracy or stockout-adjusted demand.

## Reproduce

Future runs of `src/backend/scripts/public_sales_experiment.py` export
`train.csv` and `test.csv` alongside `daily_history.csv`. The split comes from
the same `split_history` function used to create these files. The original
workbook and generated model remain in the separate experiment directory.
