# ADR 0023: Separate training readiness from worker lease recovery

- Status: Accepted
- Date: 2026-11-09

## Context

Training eligibility is a normal readiness condition. A Store may have no eligible Product because the required history is not available, and that must not create a queued job or an empty training candidate. A worker that stops renewing its lease is a different operational failure.

The worker lease is 30 minutes. Declaring a job stalled immediately at expiry can race with a worker completing its current operation, while waiting two hours leaves operators with a misleading in-progress state.

## Decision

- Preflight scheduled and manual Store training with the shared eligibility policy.
- Do not enqueue training when no Product is eligible. Show and notify a deduplicated readiness notice with the 140-day requirement and Product blockers.
- Continue training the eligible subset when only some Products are ineligible.
- Treat a running job as stale only when its lease has been expired for five minutes.
- Preserve the existing retry contract: recoverable expired jobs are re-queued below three attempts; only exhausted jobs become terminal failures.
- Renew the 30-minute lease around long-running worker phases.
- Keep readiness notices separate from worker-failure notices and resolve both automatically when their conditions clear.

## Consequences

Operators see why training did not start instead of a stuck training state. A healthy long-running worker must keep its lease renewed. A five-minute grace period can delay recovery slightly, but prevents duplicate work at the lease boundary.
