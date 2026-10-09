# Kaggle Capstone Dataset

## Source/Provenance
Dataset: "Store Item Demand Forecasting Challenge"
URL: https://www.kaggle.com/c/demand-forecasting-kernels-only/data
Description: This dataset contains 5 years of daily store-item sales data.
We use Store 1 data to meet the requirement of one Store, at least two full years of daily history, and at least 20 products (up to 50).

## Import Procedure
The data is provided as a CSV with columns: `date, store, item, sales`.
The import script filters for `store = 1`.
We map `item` to internal product ID.

## Product/Category Mapping
Items 1-50 mapped sequentially to product IDs in the demo database. Categories are generically assigned.

## Date Coverage
5 years: 2013-01-01 to 2017-12-31.

## Missing Evidence & Transformation Assumptions
- No stock, calendar, or schedule evidence is available in this dataset. We do NOT fabricate these as observed truth.
- Data is imported into an explicitly isolated demo database.
