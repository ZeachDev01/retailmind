# Windows forecasting scheduler #501

Verified on Windows, 23 November 2026. All timestamps below are Asia/Manila.

## Isolation and actual execution

- Working tree: `C:/xampp/htdocs/retailmind/.worktrees/ticket-501`.
- Source Demo database was read only for `mysqldump --single-transaction` on loopback port 3307. Restored it into the separate database `retailmind_demo_m5_501_20261123`. No Store database, root configuration, approved model or Store artifact was changed.
- Private rehearsal folder: `C:/Users/Mike/AppData/Local/retailmind-demos/scheduler-501-20261123`. It retains the source dump, isolated environment configuration, task XML, task result, running/completed evidence, screenshots and model artifacts. Configuration and credentials are not committed.
- The worktree's ignored `.env` selected only that clone, with its own session and recovery directories. Its ignored `forecasting_runner.json` retained PHP `C:/xampp/php/php.exe`, Python `C:/xampp/htdocs/retailmind/.venv/Scripts/python.exe`, the worktree root, and model storage in the private rehearsal folder's `models` directory.
- Registered and launched the actual SYSTEM task `RetailMind Demo Scheduler 501 20261123` using `install_forecasting_scheduler.ps1`, not a browser or a directly invoked Python process. The exported action selects this worktree and `-Run`; the runner invokes the PHP scheduler with `--sync`, which launches the existing Python worker. Startup and five-minute triggers, noninteractive execution and overlapping-instance prevention are in the exported task.
- Initial rehearsal deliberately exposed an unsuitable resolved runtime, `C:/Python314/python.exe`, which lacks pandas. The task failed and exposed a startup diagnostic; it did not claim training completion. Reinstallation selected the existing forecasting virtual environment. The installer now checks required Python imports before registration.
- Queued job **8** through `MlJobService::enqueue` with the existing Super Administrator boundary (Administrator training authorization belongs to #502). Queued **12:57:35**, observed running with attempt **1** and a 30-minute lease at **12:58:45**, succeeded **13:01:34**. The initial missing-dependency launch did not claim a job or consume an attempt.
- Training run **4**, model **`demo_20261123130134_4263bb`**, completed for **50 Products / 40,700 source records**. The actual saved artifact is `models/demo/a34490fb64e82d72/random_forest_candidate_demo_20261123130134_4263bb.joblib`, SHA-256 `c5b63f3a8e20eeef5c5a81052306213d2a15e9998d55aabd609f73b086001c30`.
- Task result **0**; final successful check **13:01:34**, pending jobs **0**, no diagnostic. The task was exported then unregistered after completion; database and artifacts remain for reproduction. Test fixture task registration is mocked; the separately named rehearsal task above was real.

This is historical M5 Demo training, not Store validation or operational approval. The candidate failed its existing quality checks. Its imported history retains 2014–2016 dates and Demo provenance; no quality, observation, activation or operational-isolation gate was relaxed.

## Checks

- Existing PHP installation contract: retained BOM-free paths readable by PHP/Python, bounded database startup retry, diagnostic emission, remote-database rejection and schedule defaults.
- PowerShell installer test now uses a temporary project and stub PHP entry point, with task registration mocked after explicitly loading the ScheduledTasks module. It verifies retained paths, task action/working directory, noninteractive principal, startup/repeating triggers, immediate launch, synchronous mode, failure exit propagation and an explicitly labelled configuration-only mode. It never invokes the Store scheduler.
- New scheduler-health contract: missing/corrupt status, healthy idle with no completed job, pending work, fifteen-minute staleness, failure reference and recovery.
- Existing scheduler tests extended for ADR 0023's five-minute lease grace. Worker claim/recovery tests cover the same grace and three-attempt ceiling. Long-phase lease renewal remains.
- Existing worker rollback test exposed an already-present failure: the final lease-renewal commit published evaluation rows before job completion. The same test failed against baseline `d7d449ec` (`3 != 0`). Removed that redundant final commit so final job status and outputs commit together; renewal before long phases is retained. This correction followed the recorded successful task run and is covered by the worker suite; #505 performs the final integrated rehearsal.
- Signed-in Playwright checks on isolated port 8097 verified `demo_admin` sees healthy idle in Model Review and `demo_super_admin` sees it in Model Training. At 1280×900 both status blocks fit their content regions; screenshots are retained outside Git. Health access adds no configuration permission.
- Startup-outage attention resolves on a later healthy idle check; job completion is not necessary. Operator copy exposes a reference and recovery action while raw connection errors remain in local diagnostics.

TestSprite was authenticated; the parent #499/#505 integration run owns remote verification. Reboot/unattended cold boot was not performed; this evidence proves actual Task Scheduler launch without a browser, actual worker completion, persisted state and output artifact.
