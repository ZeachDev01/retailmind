# Held GCash exchanges during handover

Status: implemented, 2026-11-25 Philippine time. The user confirmed both question rounds and explicitly requested implementation.

## Confirmed decisions

- Normal GCash handover automatically transfers every Held GCash Exchange whose follow-up belongs to the outgoing Cashier, including follow-ups inherited from an earlier handover.
- Show the included exchanges in the handover dialog without per-item selection.
- Transfer follow-up responsibility only. Preserve the original recorder, money history, and responsibility for any earlier shortage.
- Do not transfer unrelated exchanges assigned to other Cashiers.
- The existing Hand over submission acknowledges the displayed follow-ups. No separate incoming-Cashier confirmation or per-item acceptance is required.
- Cash or wallet movements newly performed when resolving an inherited exchange belong to the session currently holding the funds. Preserve earlier movements in their original history and never count them again.
- This change covers normal Cashier handover only. Administrator emergency handover and service-stop rules retain their existing behavior.

## Original behavior and implementation

- Normal handover previously omitted the optional accepted-exchange list and left assignments unchanged. It now transfers all outgoing-owned follow-ups inside the same transaction as custody transfer and records their carryover count.
- The existing accepted-list service branch scanned all Needs checking exchanges and unassigned unlisted items. Normal handover now scopes the query to the outgoing session Cashier's assignments; the emergency path retains its existing rules.
- Completion previously replaced the original transaction's money deltas. For an inherited exchange, it now preserves those original movements and posts only remaining movements as a linked money-movement record in the current session, with actor attribution and audit linkage. Insufficient funds, absent current custody for remaining movements, and unauthorized outgoing-Cashier completion are rejected.
- The receiving Cashier can read the completed inherited receipt; original receipt attribution stays intact.

## Final scope confirmation

No design questions remain open. The user authorized implementation with “e implement” and subsequently requested continuation with “padayona”.

## Implementation verification requirements

- Verify outgoing-owned exchanges transfer across repeated handovers, including inherited items, while unrelated and unassigned exchanges retain their assignments.
- Verify failed handover leaves session custody and follow-up assignments unchanged.
- Verify original recorder, earlier money history, closing differences, and shortage responsibility remain intact.
- Verify later resolution records newly performed movements in current-session expected balances without repeating prior movements or rewriting the outgoing reconciliation.
- Verify the dialog displays the included exchanges and ordinary submission transfers them without additional acceptance steps.
- Retain emergency handover and service-stop behavior.

## Verification

Passed:

- `npm run check`: styles, views, smoke and access/setup checks; one existing duplicate-ID warning in Cashier Shifts.
- `RUN_DB_TESTS=1 node src/backend/tests/gcash_handover_browser_test.js`: real browser/API transfer, inherited follow-up across repeated handovers, unrelated/unassigned ownership, invalid-recipient and audit-failure rollback, preserved closed sessions, current-session Cash-In/Cash-Out completion without repeated movements, repeat-completion refusal, incoming receipt access, preview failure, and dialog bounds at 1440/768/390 widths. Uses a disposable synthetic Store database.
- Existing held-exchange and session-history browser tests.
- `emergency_gcash_handover_contract.php`, `gcash_follow_up_responsibility_contract.php`, `gcash_verify_clear_evidence_contract.php`, `failed_exchange_actual_returns_contract.php`, `gcash_money_history_contract.php`, `gcash_fee_returns_contract.php`, `gcash_recording_corrections_contract.php`, `gcash_funding_receipt_contract.php`, and `gcash_module_seams_contract.php`.

- [TestSprite run f49ec12f](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/05c87539-34aa-4303-ade9-c54bdda65ed4/test/f49ec12f-de57-4a38-ab1e-25324ac1fe25): passed, 9/9 recorded steps, on a disposable synthetic Store. Pinned steps inspected; they cover sign-in, opening GCash and Hand over, then Cancel. The detailed held-list assertions and money transfer checks are established by local Playwright, not inferred from TestSprite's step labels.

Existing SQLite fixtures emit PHP 8.5 deprecation notices; their assertions pass. No schema migration is required. No live Store transactions were used. Existing user work in the working tree was preserved.
