# Permanent account deletion reference inventory (#301)

Recorded before implementation, against `902cd4ef` plus the existing staged work.
Sources: `database/sql/schema.sql`, all PHP migrations through
`202610250001_retire_unused_storage`, application SQL writes, `log_activity`,
`UserLifecycleService`, `DormancyRunner`, and `Backup/RecoveryStore`.

## Retained identity references — any matching row prevents deletion

| Area | Tables and identity columns |
| --- | --- |
| Sales and checkout | `sales.cashier_id`; `checkout_attempts.cashier_id`; `held_sales.cashier_id, discarded_by`; `sale_reversals.requested_by, approved_by` |
| Cashier Shifts and drawer | `cashier_shifts.cashier_id, reviewed_by, closed_by`; `cash_drawer_movements.cashier_id, recorded_by` |
| Cash Refunds | `cash_refunds.cashier_id, original_cashier_id, approved_by`; `cash_refund_exception_access.cashier_id, approved_by` |
| GCash | `gcash_transactions.cashier_id, verified_by`; `gcash_service_sessions.cashier_id, closed_by, waived_by`; `gcash_funding_movements.authorized_by, recorded_by`; `gcash_adjustments.authorized_by` |
| Stock and catalog | `products.created_by`; `stock_receiving.received_by`; `stock_receiving_groups.received_by`; `stock_movements.moved_by`; `supplier_pullouts.pulled_by`; `inventory_adjustments.reported_by, approved_by`; `inventory_counts.counted_by, approved_by`; `inventory_adjustment_revisions.actor_id` (logical, no FK) |
| Other Store operations | `promotions.created_by`; `cycle_count_schedules.assigned_to, created_by`; `purchase_history.recorded_by`; `fiscal_periods.created_by, closed_by`; `registers.created_by`; `store_calendar.recorded_by`; `branches.created_by` (logical) |
| Forecasting/imports | `forecast_decisions.decided_by`; `csv_import_logs.imported_by`; `forecast_sales_imports.imported_by`; `ml_jobs.requester_id` |
| Settings attribution | `attention_settings.updated_by`; `ml_settings.updated_by`; `store_settings.updated_by`; `platform_settings.updated_by` |
| Backups | `backup_history.performed_by`; `backup_operations.requested_by` and `store_write_gate.paused_by` (logical); private `restore-log.jsonl.actor_id`, `restore-state.json.actor_id` |
| Protected Audit Records | `activity_log.user_id` (actor); `record_id` for `User Access`, legacy `Users`, `Authentication`, `Recovery Account`; identity IDs and username/email in `previous_value`, `new_value`, `metadata`, including nested cashier/actor IDs. Creation, edits, password resets, dormancy, login and profile audits all count. |
| Authentication/delivery evidence | `login_attempts.username` (legacy normalized username/email; current `user:<id>` or `unknown:<normalized identifier>` throttle keys); `email_delivery_log.recipient` (email) |
| Barcode devices | `barcode_pairings.created_by`; linked `barcode_scans` remain retained through the pairing, despite its existing cascading user FK |
| Recovery identity | `recovery_accounts.user_id`, as well as `users.is_recovery_account`; never eligible |

Line items, receipts, batch allocations and receipt snapshots refer to their
parent business records, not additional live user identities. All parent rows
above block deletion. Retired procurement, privilege and emergency-access tables
are removed by existing migrations; if an older schema still has a user FK it
must also block deletion unless explicitly classified disposable below.

## Disposable account-owned state

`users` contains credentials, lockout state, theme and session version.
`user_roles`, `password_reset_tokens`, `notification_preferences`, `notifications`,
and `attention_states` contain assignments, credentials, preferences and notices.
Only these rows may be removed. Profile image access also requires a live
account; this operation does not perform filesystem garbage collection.

PHP sessions live outside the database. Existing per-request account lookup
rejects a missing user; password/reset-token lookup also requires the live user.
Deletion does not need to enumerate or mutate session files.

## Eligibility and atomicity decision

Ordinary accounts created through Users already have retained target creation
audits and are ineligible immediately. Successfully authenticated accounts have
retained login evidence. Eligible fixtures therefore represent imported/legacy
accounts with no history from the outset; never erase audits to manufacture
eligibility.

Keep every FK unchanged. Discover incoming user FKs, treating even `SET NULL`
and `CASCADE` as retained except the explicit disposable set. Check logical
references separately. Use current locking reads during deletion, under the
same Administrator mutex as disable, demotion and dormancy; stale page hints
never authorize deletion. Delete disposable rows, the user, and write an audit
under the actor with a complete target snapshot in one transaction. The deletion
audit's `record_id` and snapshot are retained values, not a live target FK.

Database Restore's private evidence is read only; its format and semantics stay
unchanged. Restore already excludes concurrent requests through RecoveryStore.
