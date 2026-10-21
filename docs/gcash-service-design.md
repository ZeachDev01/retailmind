# GCash service design discussion

## Cashier balance visibility (confirmed 2026-10-21)

- On Cashier → GCash → New Transaction, show Physical Service Cash and GCash App Wallet above the form, each with an Expected badge and last-check time. Keep the section visible when closed or unavailable, using dashes and an explanatory message. Any Cashier can see an open session's recorded balances; only its responsible Cashier can transact.
- Show projected wallet and cash box balances after completion in Exchange summary's collapsed **Balance details**, alongside net balance changes. Recalculate for the entered principal, transaction type, and fee payment method. Saving as Pending does not change balances.
- Exchange summary shows only relevant actions: **Collect cash** and **Send GCash** for Cash-In; **Receive GCash** and **Give cash** for Cash-Out, plus **Collect cash fee** when the fee is paid in cash. Hide irrelevant zero-value movement rows. Show the service fee with its collection method so staff know whether it is included in the cash or GCash received.
- Reuse recorded expected balances and existing exchange calculations. These are not live provider balances or physical counts; show the opening actual-check time and ask staff to check actual funds before exchanging money.

Design discussion in progress. This document records intended behavior, not implemented functionality. Current behavior remains documented in [gcash.md](gcash.md).

The consolidated specification is [gcash-service-spec.md](gcash-service-spec.md), published as [issue #236](https://github.com/ZeachDev01/retailmind/issues/236). It includes later agreements on overviews and recording, and incorporates operational policies settled in [issue #237](https://github.com/ZeachDev01/retailmind/issues/237).

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
- At opening, the responsible cashier counts the service cash box and enters the actual wallet balance. At closing, both are checked again against expected balances, showing shortages or excesses separately.
- Above 10,000, calculate the 1% fee rounded to the nearest centavo.

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

The sign says 10,000 and above is 1%; at exactly 10,000 this also gives 100. The Store confirmed that 1–499 costs 5 and 500–1,000 costs 10, including exactly 500. Principal amounts are whole pesos only; percentage fees round to the nearest centavo.

## Settled operational policies

Operational policies settled on 2026-10-19 for implementation (#237):

### 1. Funding, replenishment, and balance adjustments
- **Authority**: Only Store Administrators or Managers may perform or authorize replenishment and withdrawal of GCash Service Cash and Service Wallet funds.
- **Cashier handoff**: The responsible Cashier may request replenishment when service cash or wallet balances run low, and must confirm actual receipt upon physical cash or wallet handover.
- **Cross-fund transfers**: Any transfer between Service Cash and the Product cash drawer requires explicit Administrator authorization, logged with reason, amount, timestamp, movement type, and authorization credentials.
- **Owner injection/withdrawal**: Owner capital additions or withdrawals must be recorded with external documentation (bank deposit reference, transfer slip, or acknowledgment receipt).
- **Audit trail**: Every actual money movement must be logged immediately so expected balances continuously match physical and wallet realities.

### 2. Exceptions, adjustments, and emergency handover
- **Needs checking**: The responsible Cashier can flag an incomplete, interrupted, or uncertain exchange as `Needs checking` with mandatory explanatory notes.
- **Preservation of actual movements**: Completing or resolving a `Needs checking` exchange preserves previously recorded money movements; the system must never repost or duplicate an already recorded cash or wallet movement.
- **Immutable history and corrections**: Finalized (`Completed`) transactions cannot be deleted or overwritten. Any necessary correction requires a linked, audited adjusting record approved by an Administrator.
- **Reconciliation discrepancies**: Shift closing variances (shortages or excesses) cannot be deleted or hidden. Administrators may record an approved reconciliation note or waiver, but the underlying difference remains on record.
- **Emergency handover**: If the responsible Cashier becomes abruptly unavailable or incapacitated, an Administrator performs an emergency handover by counting physical service cash, inspecting current wallet balance in the GCash app, and recording a handover event marking shift transfer or emergency closure.

### 3. Closing, carryover, and unresolved obligations
- **Non-blocking shift close**: Unresolved `Needs checking` exchanges do not block Cashier Shift closing or POS product drawer closing.
- **Approved carryover**: At shift closing, unresolved exchanges must be acknowledged. Approved carryover transfers the unresolved obligation to the incoming shift or leaves it flagged for Administrator resolution.
- **Transparent reconciliation**: Closing counts reconcile cash and wallet against actual recorded movements. Carryover preserves unresolved obligations in GCash history without altering or masking shift cash counts.

### 4. Amount ceilings and balance checks
- **Operational ceiling**: Principal amounts are restricted to whole pesos from PHP 1 to PHP 50,000 per transaction.
- **Store tracking limit**: The PHP 50,000 ceiling is a Store operational and risk tracking limit, distinct from customer-specific GCash wallet tier limits or provider transaction ceilings.
- **Balance sufficiency**:
  - Cash-Out requires sufficient Service Cash on hand equal to the requested cash payout.
  - Cash-In requires sufficient Service Wallet balance equal to the principal to be transferred.
- **Insufficient balance handling**: Transactions cannot proceed if available service funds are insufficient; the cashier must wait until replenishment is recorded, or mark the exchange `Needs checking` if an external transfer already occurred. Silent negative balance overrides are prohibited.

### 5. Provider charges and fee accounting
- **Provider fee absorption**: Standard provider charges (e.g. bank cash-in fees, wallet top-up fees, or external service deductions), if incurred, are borne by the Store as operational expenses outside customer exchanges.
- **Customer rate integrity**: Customers are charged strictly according to the Store's posted fee schedule without hidden provider fee passthrough or unexpected deductions.
- **Gross fee reporting**: Service revenue is tracked and reported strictly as gross fees earned (separated into cash fee vs wallet fee). Net profit figures are not displayed or claimed in service overviews unless formal cost accounting records are integrated.
