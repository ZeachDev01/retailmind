# M5 or UCI II for the panel demonstration?

Discussion opened 2026-10-28. The user has now chosen M5 for the main demonstration because its grocery setting is closer to the intended Store. This supersedes the earlier UCI II main-demo choice. Walmart is not the operational Store; similarity does not establish local forecast accuracy. Implementation details remain under discussion.

## Simple recommendation

For a grocery inventory forecasting project, use M5 as the main external forecasting example. It has food products, one identifiable source Store, daily unit sales, and enough history for two training years plus later checks. Use UCI II as an optional example of cleaning transaction records and turning them into daily sales. Keep the measured strengths and weaknesses of both in the discussion.

This recommendation concerns how well the evidence fits the project. It does not say M5 proves Store accuracy or guarantees stronger results. Neither external dataset represents the operational Store.

## What a panelist may ask

| Panel question | M5 experiment | UCI Online Retail II experiment |
|---|---|---|
| Why does the business fit a grocery inventory project? | Food products from one Walmart Store; closer to the intended setting. | UK online gifts, with many wholesale customers; a different type of business. |
| Is this one source Store? | Yes: CA_1, FOODS. | No physical Store identifier; the experiment pools the retailer's transactions. |
| Are there two full years for initial training, plus later checks? | Yes: 730 training days, followed by 84 later days. | No: 653 initial training days after keeping 84 days aside. |
| Can you demonstrate real transaction cleaning? | The source already contains daily totals. | Yes: both yearly sheets, returns/cancellations, repeated copies, daily aggregation, and product descriptions. |
| Does the model beat the simple last-week rule? | In the final test: yes at 14/28 days, no at 7 days. | In the final test: yes at 7/14 days, no at 28 days. |
| Does a high F1 prove good buying quantities? | No. Its sale-day F1 also loses to always predicting a sale. | No. Its strong sale-day F1 answers whether anything sells, not how many units to buy. |

Dataset sources: [M5 daily schema](https://huggingface.co/datasets/skforecast/m5_daily), [UCI II business and date description](https://archive.ics.uci.edu/dataset/502/online+retail+ii). Closer fit to this project is a reasoned judgment, not a source claim about model quality or a prediction of what particular panelists will prefer.

Measured results: [M5 local experiment](m5-results-20261028.md), [UCI II local experiment](uci-ii-results-20261028.md). Both use the same Random Forest recipe, but different products, dates, training lengths, and selection rules. Compare each with its own baseline. Do not treat the different raw errors as a controlled head-to-head test of dataset superiority.

## Correct the earlier positive-result assumption

The earlier UCI table with WAPE around 31–32% belongs to **UCI Online Retail**, not UCI Online Retail II. It pools three evaluation blocks. The new UCI II final-block WAPE is 45.80%, 39.67%, and 40.22% for 7/14/28 days; M5 is 44.33%, 34.73%, and 35.45%. These setups are not identical, and those values cannot be swapped between datasets or described as accuracy percentages.

Neither current run passes every existing Demo gate. UCI II's sale-day F1 of about 99.58% does not remove its quantity errors. Choosing a dataset after seeing favorable results gives a selected example, not independent proof of reliability. The UCI II final period also overlaps the earlier UCI experiment.

Both Colab notebooks are prepared and their core code ran locally. Neither is verified as executed in Google Colab in this session. Demonstrating the model in Colab does not demonstrate that automatic Store training is already integrated.

## A defensible presentation claim

“We used public sales because the Store does not yet have enough history. We tested predictions against later sales and a simple weekly rule. The model helps in some periods but still makes mistakes. RetailMind will collect its own sales and test a Store model before using it for routine buying guidance.”

Do not claim “99% inventory accuracy,” “the model always beats simple rules,” or “public-data results prove accuracy for our Store.” No automatic stock change or buying action is demonstrated by a forecast alone.

## Decisions still to settle

1. Main demonstration: M5 is confirmed. Whether to retain UCI II as a supporting example remains optional.
2. Written history requirement: confirmed two full initial training years, with later validation and test dates kept separately.
3. After the main choice, settle the short presentation sequence and exact claim the panel will see. Do not select new products based on their test scores or discard a weak horizon to make the demonstration look stronger.

## Follow-up scope — 2026-10-28

Confirmed sequence: investigate more M5 products and measure whether training improves; then expose Forecast Quantity beside Suggested quantity in Replenishment Recommendations; discuss how to validate and develop the Store sales-recording-to-training pipeline. No improvement is assumed before measurement.

Confirmed experiment design: retain the original 20 products and add 30 randomly selected eligible products, using two full initial training years and the same model settings. Compare the original and expanded training runs on the original 20 products, then report all 50 separately. The existing report found 1,437 eligible CA_1 food products. The existing final test has already been inspected, so rerunning it provides development evidence, not a fresh independent confirmation. Exact expansion seed and improvement decision rule remain to settle.

Confirmed final display: Product, On hand, Stock-rule suggestion, Expected sales (28 days). Stock-rule suggestion means the quantity to buy under existing reorder-level/safety-stock rules and minimum/package rounding; Expected sales means forecast sales over the stated future period, not a purchase quantity. Do not display a separate forecast-based buying suggestion or choose a winning method for the Inventory Manager. The Inventory Manager makes the final buying decision; these values never create an order or change stock. Use Base Units and include the PDF export. Reuse the existing stock-rule calculation and persisted 28-day forecast total without a new model, table, endpoint, or period selector. Unusable forecasts read Unavailable, not zero; show the forecast date range. Public M5 quantities must remain in an isolated demonstration and must not be attached to operational Store products. Exact rounding and freshness checks still need verification before implementation.

Confirmed approach: validate the existing Store pipeline before extending it. Review completed sales and corrections, pack conversion, unknown days versus genuine zero sales, closures and stockouts, readiness, chronological testing against a baseline, worker scheduling and failure handling, and human model approval. Automatic training remains separate from approval. Detailed policies remain to settle.

### Latest interview answers

- Accepted the improvement comparison: report matched original-20 results across 7/14/28 days, unit errors, WAPE, and over/underprediction, with all-50 results separately. Describe mixed outcomes as mixed rather than claiming overall improvement.
- Accepted usable-history checks before scheduling and again in the worker; retain reasons for ineligibility and separate model approval. The existing complete backtest needs at least 140 consecutive usable days, not merely 140 calendar days.
- Display design reopened: the user wants a clearer distinction between forecast quantities, forecast-based purchase suggestions, and stock-rule suggestions. The previously proposed single Suggested column is not the final design. Present a comparison design before implementation.
- The separate question about correcting the Model Retraining definition has not received an unambiguous answer yet.

### Final column choice and sales-history decision

The user selected the four-column display above, superseding the proposed five-column comparison and any active-method highlighting. The Stock-rule suggestion must remain based on stock rules even when a usable forecast exists; the current service can return a forecast-based recommended_quantity, so merely relabeling that field would be incorrect. Keep Expected sales available independently of the stock-rule suggestion, subject to the agreed forecast usability checks. This display decision does not authorize changing other consumers of the recommendation service.

The user also accepted Base Unit conversion and separately preserved corrections: two sold packs of ten sachets represent twenty units sold. Keep the original sale and linked correction, and ensure historical evaluation sees only corrections known at its cutoff. Verify the existing history reader rather than introducing a second sales aggregation path.

### Implemented and measured

The user confirmed the final design and chose to skip ineligible products while eligible Store products train together. The [20-versus-50 expansion report](m5-expansion-results-20261028.md) records actual local results: improved validation, but mixed results on the original 20 in the previously inspected final window. No model was approved or deployed.

The recommendation table and PDF now separate Product, On hand, Stock-rule suggestion, and Expected sales (28 days). Stock-rule quantities remain independent of forecasts. Expected sales require 28 complete forecast rows from the approved model, yesterday's Philippine forecast origin, and usable readiness. Show the period/readiness and keep missing, stale, incomplete, observation, suspended, or unapproved output unavailable; a real zero remains zero.

Store training now shares eligibility through DemandHistoryService before scheduled enqueue and again through the worker export. Eligible active products need a trailing 140-day usable suffix; training uses the common observed suffix of eligible products. Skip the rest with reasons. Generation excludes products outside the fitted model and products missing the latest 28 observed days. Approval, stock mutation, and external-data isolation remain separate. See [the Store pipeline validation record](store-sales-training-validation.md) for checks and remaining limitations.

Forecast Quantity is recorded in the root glossary. No new architectural decision is needed for reusing existing forecast values.
