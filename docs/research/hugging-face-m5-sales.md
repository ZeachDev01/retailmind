# Hugging Face product sales history

Research checked: 2026-10-27. Agreed scope: an isolated forecasting experiment using one external Walmart store (`CA_1`) and food products (`FOODS`). This does not provide sales history for the RetailMind Store.

## Source

- [Hugging Face M5 daily dataset](https://huggingface.co/datasets/skforecast/m5_daily): daily `sales` units by `datetime`, `store_id`, and `item_id`; 59,181,090 rows overall. Filter `store_id = CA_1` and `cat_id = FOODS` before training.
- [Original M5 competition](https://www.kaggle.com/competitions/m5-forecasting-accuracy/data): Walmart product/store daily unit sales with calendar and prices. Its data page says use is subject to competition rules. The Hugging Face page labels its copy CC BY 4.0; verify the original terms before redistributing a subset.

Predict expected units sold as the experiment's demand-forecast target. Keep external item IDs, dates, and models separate from operational Store IDs, imported Store history, and approved models. Evaluate on later dates only. Sales are recorded units, not proof of unconstrained demand during stockouts.

Start with 20 food products that each have at least two years of sales history, as agreed and consistent with [ADR-0005](../adr/0005-single-random-forest-forecasting-pipeline.md). Product-selection criteria and the final experiment run remain to be settled.

The M5 product codes may remain anonymous; do not map them to the Store's Products. The evaluation goal is a Demo pipeline using the existing forecasting engine. Compare expected versus actual units with MAE and WAPE on later dates and against a simple baseline. Also report precision, recall, and F1 for whether any unit sells on a day; those classification scores do not measure quantity accuracy. Public-data scores alone cannot establish reliability for the Store. Pass criteria remain open.

The current UCI Demo already trains and forecasts through the application. Its experiment preparation and importer are UCI-specific, so an M5 source adapter is needed to feed the shared pipeline. Use a separate M5 Demo database and retain the UCI Demo.
