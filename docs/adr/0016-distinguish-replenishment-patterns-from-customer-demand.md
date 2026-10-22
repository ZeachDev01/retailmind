# Distinguish replenishment patterns from customer demand

The available source for this proposed forecasting feature is the Store's replenishment receipts from larger retailers, rather than customer sales receipts. Category Replenishment Forecasts will provide the Inventory Manager with advance notice of categories likely to be purchased in future weeks or months, alongside insights into previous spending; purchase frequency will not be presented as measured customer demand.

Products will retain consistent reviewed categories across receipts. Receipt extraction and categorization results require human review before entering the approved dataset. This preserves the available purchasing evidence without assuming that buying decisions, discounts, or budgets directly represent customer purchases.

This records the agreed meaning and data boundary. The exact forecast target, calendar windows, dataset coverage, evaluation thresholds, and production model remain open. It does not supersede ADR 0005 or authorize feeding replenishment purchases into the existing customer-sales forecasting pipeline.
