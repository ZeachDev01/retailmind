# GCash Services: cashier workflow, automatic fees, and separate money overview

Published as [GitHub issue #236](https://github.com/ZeachDev01/retailmind/issues/236), labelled `ready-for-agent`.

## Problem Statement

The Store offers GCash cash-in and cash-out services and needs to know how much physical service cash and wallet money it should have, what it earned in fees, and who handled each exchange. These services must remain separate from Product sales and their cash drawer.

The current GCash workspace captures references and allows a Cashier to mark records Pending, Verified, or Rejected. It does not calculate fees, attribute exchanges to an active Cashier Shift, or change cash or wallet balances. A verified reference alone does not establish that both sides of an exchange occurred.

## Solution

Extend the existing GCash workspace into a service register with Overview, New Transaction, and History tabs. Use one dedicated GCash Service Cash box and one Store-owned GCash Service Wallet, controlled by one assigned Cashier at a time. Other Cashiers may continue using POS.

The Cashier enters the principal amount, sees the automatic fee and exact collection/payout instructions, performs the transfer in the GCash app, captures its reference, checks the actual Store account, and confirms the physical cash exchange. Completed exchanges update service balances and fee totals once. Unfinished exchanges remain visible as Needs checking.

Cashiers reconcile service cash and wallet balances at opening, closing, and handover. The Administrator receives a separate GCash Overview with Store totals, date filtering, Cashier history, and balance differences. Product payments remain in POS and use a separate payment account.

## User Stories

1. As a Cashier, I want a separate GCash navigation entry, so that I can handle services without mixing them with Product checkout.
2. As a Cashier, I want Overview, New Transaction, and History tabs, so that I can find balances, record an exchange, and review past activity.
3. As the responsible Cashier, I want to enter counted opening service cash and the checked actual wallet balance, so that tracking starts from real money.
4. As a Cashier, I want the system to identify who currently controls GCash, so that responsibility is clear.
5. As a POS Cashier, I want to continue Product sales while another Cashier handles GCash, so that the service assignment does not block checkout.
6. As a Cashier, I want to choose Cash-In or Cash-Out, so that the form gives the correct instructions.
7. As a Cashier, I want to enter a whole-peso principal amount, so that the system uses the Store's agreed amount rules.
8. As a Cashier, I want fees calculated automatically using the posted brackets, so that I do not calculate them manually.
9. As a Cashier, I want every amount from PHP 1 through PHP 499 to cost PHP 5, so that amounts such as PHP 350 receive the correct fee.
10. As a Cashier, I want PHP 500 through PHP 1,000 to cost PHP 10, so that the special PHP 500 boundary is respected.
11. As a Cashier, I want amounts above PHP 10,000 charged at 1%, so that larger transactions follow the Store's sign.
12. As a Cashier, I want cash-in to show the principal plus fee to collect, so that the customer receives the requested wallet amount.
13. As a Cashier, I want to choose Fee via GCash for cash-out, so that the customer can transfer the requested cash amount plus the fee.
14. As a Cashier, I want to choose Fee in cash for cash-out, so that I can collect the fee at the counter separately.
15. As a Cashier, I want the fee based on cash requested rather than the transfer including its fee, so that the fee is not charged on itself.
16. As a Cashier, I want clear transfer, collection, payout, and fee amounts before confirmation, so that I exchange the correct money.
17. As a Cashier, I want to perform transfers through the GCash app, so that RetailMind does not need wallet credentials or transfer access.
18. As a Cashier, I want a compatible barcode scanner to fill the reference field, so that supported coded references need less typing.
19. As a Cashier, I want camera text reading to suggest the reference, so that ordinary printed or displayed reference numbers can be captured.
20. As a Cashier, I want to correct a camera suggestion or type manually, so that I can continue when recognition fails.
21. As a Cashier, I want duplicate-reference detection, so that the same transfer cannot be recorded twice.
22. As a Cashier, I want to confirm the actual Store GCash record and physical cash exchange separately, so that Completed means both occurred.
23. As a Cashier, I want unfinished exchanges marked Needs checking, so that real money already moved is not forgotten.
24. As a Cashier, I want retries and repeated clicks to avoid duplicate money updates, so that a connection problem cannot inflate balances.
25. As a Cashier, I want expected service cash and wallet balances with their last actual-check time, so that I understand how current the figures are.
26. As a Cashier, I want current-shift cash-in and cash-out principal totals, so that I can see service activity.
27. As a Cashier, I want service fees split between cash and wallet, so that I can explain where earnings are held.
28. As a Cashier, I want history to show amount, fee, fee method, reference, status, Cashier, shift, and time, so that I can trace an exchange.
29. As a Cashier, I want closing counts compared separately against expected service cash and wallet balances, so that shortages and excesses are visible.
30. As an incoming Cashier, I want a recorded handover of checked service balances, so that I accept a clear starting position.
31. As an Administrator, I want a separate GCash Overview with date filters and Cashier history, so that I can monitor the Store's service money.
32. As an Administrator, I want unresolved exchanges and reconciliation differences visible, so that I can investigate problems.
33. As an Administrator, I want correction history preserved rather than completed records deleted, so that money changes remain explainable.
34. As an Administrator, I want service principal and fees kept out of Product-sales totals, so that sales and inventory reports remain accurate.
35. As an Administrator, I want old reference-only records preserved without inventing balances or fees, so that the upgrade does not fabricate financial history.

## Implementation Decisions

### Scope and existing modules

- Reuse the existing GCash transaction service, reference parser, scanner input, camera reading, history, authorization, and protected audit patterns.
- Integrate service assignment and reconciliation with the existing Cashier Shift workflow. Service cash has its own count and expected balance; it is not included in the Product drawer count.
- Reuse active, unlocked shift enforcement for financial writes. The existing locked-register check alone is insufficient because it permits operation without an open shift.
- Add Administrator GCash overview access through the operational workspace. Do not broaden Cashier access to other Cashiers' history merely to provide Administrator reporting.
- Remove the generic Payment option from new service entries; retain historical Payment records. Product payments remain POS transactions.

### Automatic service fees

The same schedule applies to cash-in and cash-out. Principal amounts are positive whole pesos within Store operational ceilings (PHP 1 to PHP 50,000 per transaction, settled in #237; distinct from provider authorization limits). Service fees and resulting collections may contain centavos. Existing reference-log amount limits are not automatically a guarantee that a transfer is permitted by GCash; provider limits are not defined by this specification.

| Principal amount (PHP) | Fee (PHP) |
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
| Above 10,000 | 1% of principal, rounded to nearest centavo |

- Calculate and validate the authoritative fee on the server; the browser previews the same totals. Reject stale or inconsistent confirmations rather than silently recording different totals from those the Cashier saw.
- Preserve the actual fee and fee payment method on each exchange. Future rate changes must not rewrite historical fees.
- Use exact monetary arithmetic at centavo precision. A whole-peso principal above PHP 10,000 produces an exact centavo fee at 1%; do not round that fee to a whole peso.
- No additional fee-setting interface, Cashier fee override, or discount capability was agreed.

### Cash and wallet effects

Principal means the amount loaded for cash-in or cash requested for cash-out. The fee does not increase the principal used to select the fee bracket.

| Example: PHP 1,000 principal, PHP 10 fee | Customer transfers to Store wallet | Cash collected at counter | Cash handed over | Store wallet sent | Net service cash | Net wallet |
| --- | --- | --- | --- | --- | --- | --- |
| Cash-in | 0 | 1,010 | 0 | 1,000 | +1,010 | -1,000 |
| Cash-out: Fee via GCash | 1,010 | 0 | 1,000 | 0 | -1,000 | +1,010 |
| Cash-out: Fee in cash | 1,000 | 10 | 1,000 | 0 | -990 | +1,000 |

- Each completed example earns PHP 10 in service fees. Fees are already included in the net balance effects; do not add them again.
- Cash-in/out totals show principal, not principal plus fee. Principal is money exchanged, not earnings.
- Expected balances start from checked opening balances and include recorded actual movements once. Partial exchanges must expose known movements rather than presenting a misleading balance based solely on completed rows.
- Do not post service principal or fees as Product sales, inventory deductions, or Product drawer movements.

### Recording and reference capture

1. Select Cash-In or Cash-Out and enter principal.
2. For cash-out, choose Fee via GCash or Fee in cash.
3. Display the automatic fee and exact amounts to transfer, collect, and hand over.
4. Staff perform the transfer in the GCash app.
5. Capture the reference through keyboard-mode scanner, camera text reading, or manual entry.
6. Review the captured digits and compare the reference, amount, and transfer direction against the actual authorized Store account record.
7. Confirm the physical cash exchange and save Completed only when both sides occurred.

- Reference capture fits after transfer and before completion. It fills a field; it does not verify payment or complete an exchange.
- Keep existing reference normalization, configured numeric length validation, leading-zero preservation, ambiguity refusal, and database uniqueness protections.
- Scanner input is scoped to the reference field. Enter checks the reference and never submits a financial transaction.
- Barcode/QR content must actually contain a supported reference. A merchant payment QR is not assumed to contain a completed-transfer reference. C986 hardware compatibility has not been demonstrated.
- Native camera/file capture suggests a reference for explicit human review beside a local photo preview. Keep manual correction and fallback entry, actionable capture/reading errors, and cleanup of the photo preview and OCR worker. Do not upload or retain receipt images as part of this feature.
- Native file capture may open a file picker when the browser cannot open its camera. Disclose the camera reading tool's download requirement. No new scanner SDK or paid reading service is required.

### Records, state, and integrity

- Extend persisted records to identify principal, fee, fee payment method, Cashier, active shift/service responsibility, timestamps, reference, status, confirmation evidence, and cash/wallet effects. Opening and closing records retain actual and expected balances and separate differences.
- Completed means the verified wallet transfer and required physical exchange both occurred. The old Verified flag must not silently imply this stronger meaning.
- Needs checking means the exchange is unfinished or uncertain. Preserve which actual movements occurred and any known reference, with an explanatory note. Do not force a fabricated reference when a transfer has not happened.
- Responsible Cashiers may flag exchanges Needs checking with required explanatory notes. Completion and resolution preserve already recorded money movements and never duplicate balance impacts (#237).
- Completed records cannot be deleted or silently overwritten. Corrections require a linked, audited adjusting record authorized by an Administrator (#237).
- Enforce one responsible Cashier at a time for the shared service cash box and wallet, including concurrent requests.
- Completion, movement posting, fee attribution, and audit recording must succeed or fail together. Repeated requests, duplicate references, and simultaneous confirmations cannot post the same exchange twice. Reload history after uncertain responses.
- Retain existing authentication, workspace restrictions, CSRF protection, validation, and audit protections for new operations. Validate money and state transitions on the server.

### Overview and reconciliation

- Cashier navigation uses the existing GCash entry with Overview, New Transaction, and History tabs. Show current-shift totals and clear Cash-In and Cash-Out actions.
- Show expected service cash, expected wallet funds, their last actual-check time, cash-in principal total, cash-out principal total, fees split by cash/wallet, and Needs checking items. Clearly label computed balances Expected; there is no live balance API.
- Administrator navigation adds GCash Overview with Store-wide summaries, date filters, Cashier history, differences, and unresolved activity. Historical activity filters must not be mistaken for the current wallet balance.
- Opening records the counted service cash and actual checked wallet balance. Closing compares both against expected balances and retains separate shortage/excess figures.
- Connect handover to the existing shift workflow without treating the shared wallet as a newly created wallet each shift. Record acceptance of actual balances and preserve the outgoing reconciliation. Unresolved Needs checking items do not block shift closing; approved carryover transfers accountability to the incoming shift or flags items for Administrator resolution (#237). Closing count discrepancies cannot be erased; Administrators may record approved reconciliation notes/waivers without altering shortage/excess amounts (#237). In emergencies, an Administrator conducts emergency handover by counting physical cash and checking wallet balance in the GCash app (#237).
- Use Philippine time for timestamps and reporting-day boundaries. Keep the existing UI's accessible labels, keyboard operation, and readable status feedback.

### Existing-data transition

- Preserve old reference-only history, review evidence, and unique reference reservations.
- Clearly distinguish legacy reference records from new money-tracked exchanges. Do not backfill inferred fees, shift ownership, or cash/wallet movements from them.
- Start money tracking from an explicitly checked opening cash and wallet balance. Upgrade schemas without destroying existing records, maintaining migration/fresh-install parity and backup coverage.

## Testing Decisions

The user confirmed reuse of the existing GCash browser/API workflow tests, focused fee-boundary/reference-reading tests, and reconciliation coverage. Prefer the existing browser-to-API workflow as the highest seam, backed by the existing database integration seam for atomicity and concurrency; no new testing framework is needed.

- Good tests assert externally visible totals, allowed actions, saved history, permissions, and balance changes. Do not assert incidental markup or private helper structure.
- Extend existing GCash browser coverage for Cash-In and both Cash-Out fee methods, clear totals, reference capture, confirmation, Needs checking visibility, overview filtering, and closing counts.
- Use existing GCash transaction integration patterns with disposable databases for server validation, required active shift, sole service responsibility, duplicate/retry/concurrent posting, authorization, and audit failure rollback.
- Cover all bracket boundaries, especially 1, 350, 499, 500, 1,000, 1,001, 10,000, 10,001, and 12,345. At 12,345 the fee is 123.45. Reject zero, negative, fractional-peso principal, malformed input, and values outside the supported validated range.
- Verify the three PHP 1,000 examples above: service cash/wallet deltas, fee location, principal totals, and unchanged Product-sales and Product drawer totals.
- Extend existing reference parser tests for normalization, leading zeroes, supported payloads, ambiguous captures, and rejection of unsupported references. Browser tests simulate scanner keyboard input and controlled camera-reading results; they do not prove physical hardware compatibility.
- Reuse Cashier Shift opening/reconciliation contract patterns to verify separate Product and service counts, preserved wallet continuity, shortages/excesses, and handover ownership.
- Verify legacy rows remain readable and do not affect newly opened tracked balances. Verify migrations, fresh-install schema, and backup retention for added records.
- Operational policies are settled (#237): acceptance tests in tickets #238–#244 will cover funding authority and cross-fund logging, immutable adjustments and discrepancy waivers, non-blocking shift closing with carryover, PHP 1–PHP 50,000 transaction ceilings with insufficient-balance blocks, and gross fee reporting without customer-facing provider deductions. No tests were executed for this specification-only task.

## Out of Scope

- Initiating GCash transfers, storing wallet credentials, or integrating a live GCash balance/payment-confirmation API.
- Multiple service wallets, multiple Stores, or simultaneous service Cashiers.
- Product checkout redesign, service principal as Product revenue, or using the Product payment account as the service wallet.
- Customer name/phone collection, receipt-photo storage, scanner procurement, or guaranteed C986 OCR capability.
- A third cash-out method that deducts the fee from a principal-sized transfer; only Fee via GCash and Fee in cash are confirmed.
- Configurable rate administration, fee discounts/overrides, and inferred reconstruction of historical service balances.

## Settled Operational Policies

All 5 operational policies were settled and approved on 2026-10-19 under ticket [#237](https://github.com/ZeachDev01/retailmind/issues/237) for implementation across tickets #238–#244:

### Policy 1: Funding, Replenishment, and Fund Transfers
- **Authority**: Only a Store Administrator or Manager may initiate or execute replenishment and withdrawal of GCash Service Cash and Service Wallet funds.
- **Cashier handoff & receipt**: The responsible Cashier may request replenishment when service cash or wallet funds run low, and must confirm and record physical or wallet receipt upon actual handover.
- **Cross-fund transfers**: Any transfer between Service Cash and the Product cash drawer requires explicit Administrator authorization, logged with reason, amount, timestamp, movement type, and Administrator credentials.
- **Owner money movements**: Injections or withdrawals of owner capital must be recorded with external documentation (bank deposit reference, transfer confirmation, or signed receipt).
- **Immediate recording**: Every actual movement must be recorded at occurrence. Undocumented transfers are prohibited; reopening shifts with new balances cannot be used to mask unrecorded fund movements.

### Policy 2: Exceptions, Adjustments, and Emergency Handover
- **Needs checking**: The responsible Cashier may mark an incomplete, interrupted, or uncertain exchange as `Needs checking` with mandatory explanatory notes.
- **Preservation of money effects**: Completing or resolving a `Needs checking` record preserves already recorded money movements; the system must never repost or duplicate an existing balance impact.
- **Immutable finalized records**: Completed exchanges cannot be deleted or silently overwritten. Corrections require linked, audited adjusting records authorized by an Administrator.
- **Discrepancy waivers**: Discrepancies (shortages or excesses) at shift closing cannot be deleted or zeroed out. An Administrator may record an approved reconciliation note or waiver, but the underlying counted variance remains on permanent record.
- **Emergency handover**: If the responsible Cashier becomes abruptly unavailable or incapacitated, an Administrator performs an emergency handover by counting physical service cash, checking the current wallet balance in the GCash app, and recording an administrative handover to reassign control or seal the shift.

### Policy 3: Closing, Carryover, and Unresolved Obligations
- **Non-blocking closing**: Unresolved `Needs checking` exchanges do not block Cashier Shift closing or POS drawer closing.
- **Approved carryover**: During shift closing, all unresolved `Needs checking` items must be acknowledged. Approved carryover transfers the unresolved obligation to the incoming shift or leaves it flagged for Administrator resolution.
- **Reconciliation transparency**: Shift closing cash and wallet counts reconcile against actual movements that occurred. Unresolved exchange obligations remain visible in GCash history without altering or distorting shift cash reconciliation figures.

### Policy 4: Amount Ceilings and Balance Checks
- **Operational ceiling**: Transactions are restricted to whole pesos from PHP 1 to PHP 50,000 per transaction.
- **Store tracking limit**: The PHP 50,000 ceiling is a Store operational and risk tracking limit, distinct from customer-specific GCash wallet tier limits or provider transaction ceilings.
- **Balance sufficiency**:
  - Cash-Out requires sufficient Service Cash on hand to pay out the requested cash principal.
  - Cash-In requires sufficient Service Wallet balance to transfer the requested principal.
- **Insufficient balance handling**: Transactions cannot proceed if available service funds are insufficient; the cashier must wait until replenishment is recorded, or mark the exchange `Needs checking` if an external provider transfer already completed outside RetailMind. Silent negative balance overrides are prohibited.

### Policy 5: Provider Charges and Fee Accounting
- **Provider charge absorption**: Standard provider charges (e.g. bank cash-in fees or wallet top-up processing fees), if incurred, are borne by the Store as store operational expenses outside the customer exchange.
- **Customer rate integrity**: Customers are charged strictly according to the Store's posted fee schedule without hidden provider fee passthrough or deductions.
- **Gross fee reporting**: Service earnings are tracked and reported strictly as gross fees earned, delineated into cash fee vs wallet fee. Net profit calculations are not displayed in service overviews unless formal cost records are integrated.
