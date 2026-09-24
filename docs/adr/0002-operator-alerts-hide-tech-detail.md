# Hide technical detail from Operator Alerts on the shop floor

Operator Alerts show only easy words when a Store operation fails, even when the underlying failure is a raw technical exception. On a dev machine with `APP_DEBUG` on, the same easy line is followed by a small grey tech line so the developer keeps a fast dev view; in the live Store debug stays off so staff keep a calm shop view with no SQLSTATE text, exception class names, file paths, or object dumps. The full exception is always written to the app logs and, where a failure is security- or operation-sensitive, to Protected Audit Records, so the Super Administrator can still fix root causes from the full exception while staff never see it. This is deliberate for safety and calm work: hiding tech detail trades a little developer speed in production for a shop floor that is never frightened by computer words, and the trade is reversible only by enabling `APP_DEBUG`, which must never be on in the live Store. See ADR-0001 for the split between Super Administrator technical governance and Administrator Store operations that this decision rests on.

## Consequences

- Shop mode renders the aligned easy line only; the shared wrapper safety net replaces any unknown technical text with a generic easy line.
- `APP_DEBUG` appends a grey tech line (and console detail) on a dev machine; it is the single switch between fast dev view and calm shop view, with no new env key.
- The user-facing text and the logged text are separate on purpose: `OperatorAlert::message()` and the global/database fallbacks log the full exception before showing the easy line, and Protected Audit Records keep the full exception text at the same boundary.
- If a future reader wants tech detail on screen in the live Store, point them here: the hide is deliberate for safety and calm work.
