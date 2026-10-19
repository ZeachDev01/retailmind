# GCash service design discussion

Design discussion in progress. This document records intended behavior, not implemented functionality. Current behavior remains documented in [gcash.md](gcash.md).

## Agreed boundaries

Confirmed on 2026-10-19:

- GCash Services use a dedicated physical cash box or pouch, separate from the product-sale drawer.
- One Store-owned GCash Service Wallet is dedicated to these services.
- The GCash workspace handles cash-in and cash-out. Product purchases paid through GCash remain in POS and use a separate product-payment account.
- Service fees are recorded separately. Cash-in fees are added to the physical cash collected. Cash-out supports the two fee-payment options below, superseding the earlier deducted-payout proposal.
- One assigned cashier at a time controls the service cash box and wallet, checking balances at opening and handover.
- Staff perform transfers in the GCash app. RetailMind records completion only after both the wallet transfer and physical cash exchange occur.
- Fees should calculate automatically from the Store's rate schedule, replacing the earlier manual-entry proposal.
- Transaction principal amounts accept whole pesos only. Every amount from 1 through 499 has the same 5 fee; fees follow the posted brackets rather than a percentage within each bracket.

## Agreed cash-out fee options

The cashier enters cash requested, which determines the fee, and selects how the fee is paid. The fee itself does not increase the amount used to select the fee bracket.

For cash requested of 1,000 and fee of 10:

| Fee payment | Wallet received | Cash handed over | Counter fee collected | Net service cash change | Net wallet change |
| --- | --- | --- | --- | --- | --- |
| Fee via GCash | 1,010 | 1,000 | 0 | -1,000 | +1,010 |
| Fee in cash | 1,000 | 1,000 | 10 | -990 | +1,000 |

Record the fee payment method with the completed exchange so cash and wallet reconciliation attribute the fee to the correct place. These are the two confirmed options; deduction from a principal-sized transfer is not currently an agreed third option.

## Supplied fee schedule

The Store supplied photographs of its Cash In & Cash Out rate signs. Both services use the same posted schedule:

| Amount (PHP) | Fee (PHP) |
| --- | --- |
| 1–499 | 5 |
| 500–1,000 | 10 |
| 1,001–1,500 | 15 |
| 1,501–2,000 | 20 |
| 2,001–2,500 | 25 |
| 2,501–3,000 | 30 |
| 3,001–3,500 | 35 |
| 3,501–4,000 | 40 |
| 4,001–4,500 | 45 |
| 4,501–5,000 | 50 |
| 5,001–5,500 | 55 |
| 5,501–6,000 | 60 |
| 6,001–6,500 | 65 |
| 6,501–7,000 | 70 |
| 7,001–7,500 | 75 |
| 7,501–8,000 | 80 |
| 8,001–8,500 | 85 |
| 8,501–9,000 | 90 |
| 9,001–9,500 | 95 |
| 9,501–10,000 | 100 |
| Above 10,000 | 1% of amount |

The sign says 10,000 and above is 1%; at exactly 10,000 this also gives 100. The Store confirmed that 1–499 costs 5 and 500–1,000 costs 10, including exactly 500. Principal amounts are whole pesos only; rounding of percentage fees remains undecided.

## Decisions still open

- Percentage fee rounding and any provider costs.
- Opening balances and shift handover details.
- Confirmation, unresolved exchanges, corrections, and shift-closing rules.
- Wallet replenishment, cash transfers, oversight, and reconciliation.
- Transition from reference-only history to balance tracking.
