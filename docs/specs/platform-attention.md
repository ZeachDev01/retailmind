# Super Administrator attention

The attention queue contains conditions with an available corrective action. Normal waiting states and records of routine activity do not increase warning or critical counts.

- Forecast Cold Start is informational: “Gathering sales data.” Missing artifacts and blocked work are expected while no model is approved and no Product is eligible. Demo artifacts do not establish Store model health.
- Once history is ready, missing model setup can require review. Actual worker failures remain critical even during cold start. Queued work overdue for completion remains a warning; elapsed time after successfully completed work alone does not prove failure.
- Model age refers to the approved Store model's completed training run. Another model's recent training does not refresh it. Missing models and worker failures are not mislabeled as stale models.
- Application logs remain available as diagnostics with optional acknowledgement and protected review history. Entries, rotation, and unreviewed fingerprints do not change platform health. Failure to read diagnostics is informational; actual database or storage failures remain independently monitored.
- Platform Settings changes remain in audit history and do not create attention items. Existing attention states resolve and queued email is cancelled on synchronization. Platform and Store settings retain their existing role permissions.
- Before the first backup, a Store without operational activity displays “No backup yet.” Sales, receiving, GCash transactions/funding, inventory adjustments, Cashier Shifts, or positive opening stock end this waiting state. If activity cannot be determined, backup protection is still required. Existing backups retain their age and recovery-key checks, and failed backup operations retain their alerts.
- The same unused Store displays “Store not started” for continuity. No first sale, shift, or opening stock is informational and does not count as a continuity gap.
- Missing migration setup or pending migrations show “Setup incomplete,” with the schema/import and migration action. An empty but initialized database is not a connection failure. An actual failed connection remains critical.
- A warning check creates warning attention, not generic critical service attention. Forecasting failures have their own conditions rather than modifying model age.

Behavior is covered by `system_health_artifact_contract.php`, `system_health_backup_contract.php`, `application_log_review_contract.php`, `attention_rules_contract.php`, and `super_administrator_dashboard_workspace_test.php` under `src/backend/tests/`.
