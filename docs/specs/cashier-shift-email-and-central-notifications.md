## Problem Statement

Cashiers can leave a Cashier Shift open after the Store's reminder time or return the next day with yesterday's shift still open. The existing browser reminder cannot reach a signed-out Cashier or an Administrator who is away from RetailMind. Personal notification switches also make delivery inconsistent and difficult for the Administrator to control.

The Store needs timely, limited email awareness and a clear prompt to close yesterday's shift. Stale emails arriving much later would confuse recipients and must be skipped.

## Solution

Extend Shift reminders in Administrator Store Configuration with an explicit email switch. When enabled, use Brevo to notify the responsible Cashier and active ordinary Administrators if the shift remains open 30 minutes after its reminder deadline. Send each recipient at most one successful reminder per shift. Allow only 30 minutes of delivery lateness, then expire the reminder.

Centralize the four existing optional operational notification controls in Administrator Store Configuration and remove personal Preferences as a settings page. On the Cashier's first return on a later Philippine calendar date with an old shift still open, show a SweetAlert with OK that navigates to Close shift. Preserve the normal counting and reconciliation process, real timestamps, and the existing one-open-shift rule.

## User Stories

1. As an Administrator, I want Shift reminders beneath Attention thresholds, so that I can find the existing reminder settings.
2. As an Administrator, I want an explicit email reminder On/Off control, so that I can choose whether overdue shifts generate email.
3. As an Administrator, I want Enable reminder Off to suppress both ordinary shift popups and shift emails, so that the master control behaves consistently.
4. As an Administrator, I want email reminders disabled initially, so that installing this feature does not start unexpected email delivery.
5. As an Administrator, I want the daily time interpreted in Philippine time, so that Store staff share one schedule.
6. As a Cashier, I want a popup at the configured daily time, so that I remember to close my shift.
7. As a Cashier opening a shift after that time, I want the shift to open normally with an immediate reminder, so that late work remains possible.
8. As a Cashier opening late, I want 30 minutes before an email is due, so that opening the shift does not immediately send overdue email.
9. As an Administrator, I want an email when an eligible shift is still open at its email deadline, so that I know who has an unclosed shift and at which counter.
10. As a Cashier, I want an email about my own unclosed shift, so that I can return and close it.
11. As a Cashier, I want my email limited to my own shift, so that I do not receive other Cashiers' operational details.
12. As a recipient, I want separate email delivery, so that other recipients' addresses are not exposed.
13. As a recipient, I want only one successful email per shift, so that an old shift does not produce daily spam.
14. As an Administrator, I want locked or logged-out shifts included, so that leaving the browser does not conceal an unclosed shift.
15. As a Cashier, I want Later to snooze the browser popup without changing the fixed email deadline, so that the two reminders have clear meanings.
16. As a recipient, I want severely delayed email skipped, so that yesterday's obsolete warning does not arrive today.
17. As an Administrator, I want a recently delayed email sent only if the shift is still open, so that a completed close does not create a stale warning.
18. As an Administrator, I want failed delivery to one recipient kept separate from successful delivery to another, so that retries do not resend the successful email.
19. As an Administrator, I want missing email addresses and delivery failures recorded honestly, so that a reminder is not falsely reported as delivered.
20. As a Cashier returning on a later date, I want a clear alert about my old shift, so that I can close it before opening another.
21. As a Cashier, I want OK to take me to Close shift, so that I know the next step without an automatic close.
22. As a Cashier, I want my cash counting, held-sale checks and reconciliation preserved, so that closing the old shift remains accurate.
23. As an Administrator, I want actual opening, sale and closing dates retained, so that reminders do not rewrite the Store's history.
24. As an Administrator, I want Store-wide Low stock, Inventory adjustments, In-app and Email switches, so that optional operational delivery has one authority.
25. As an Administrator, I want old personal notification choices to stop overriding Store policy, so that settings reflect actual delivery behavior.
26. As a staff member, I want required security and review alerts retained, so that optional switches do not hide work I must address.
27. As a staff member, I want Theme and Full Screen controls preserved, so that removing notification Preferences does not remove unrelated settings.
28. As a Super Administrator, I want technical email transport and account-security controls preserved, so that Store notification settings do not change platform governance.
29. As an Administrator, I want the existing live Open cashier shifts section preserved, so that I can still inspect open shifts and their reports.
30. As a signed-in user, I want reminder polling to preserve idle-session expiry, so that background checks do not keep my account signed in indefinitely.

## Implementation Decisions

- Reuse the existing Store Configuration navigation, settings persistence, authorization, CSRF protection, Store write gate and audit recording. Do not introduce per-user or per-role configuration for this feature.
- Keep Shift reminders directly below Attention thresholds. Its email switch is independent of the existing global optional Email notifications switch; both must permit shift email. Visually disable the shift email control while Enable reminder is Off and explain the parent relationship. Effective delivery must enforce the parent switch on the server as well.
- The effective shift email policy requires Enable reminder, the shift email switch, central optional operational email, and the existing verified Brevo transport gate all to be enabled. Preserve the mail-disabled environment override. Do not relocate Brevo credentials or transport verification from Super Administrator governance.
- Central optional notification defaults are Low stock On, Inventory adjustments Off, In-app On and Email Off. The shift email switch also defaults to Off. Existing personal rows no longer govern Store operational delivery; preserve historical records rather than destructively deleting preference data.
- Apply the central operational policy consistently to existing optional notification producers, queued operational email and Daily Stock Brief account-recipient eligibility. Preserve their existing schedules, audience scope and nonempty-brief conditions. Do not accidentally make a Cashier a stock-brief recipient.
- Required-action in-app alerts remain visible independently of optional switches. Password-reset, account-security and explicit test emails retain their existing separate rules. Super Administrator technical notification policy remains under platform governance.
- Remove the personal notification Preferences page and profile Notifications settings link. Preserve Theme and Full Screen controls and the notification list/bell. Old GET links redirect Administrators to central configuration and other staff to their notification list; old personal POST writes must be rejected without changing policy, including compatibility routes.
- Evaluate all shift dates in Asia/Manila. The ordinary popup deadline is the configured daily clock time on the shift's opening date, or the actual opening time if it opened after that clock time. This explicitly replaces the earlier next-day rule for late-open shifts.
- The email deadline is the popup deadline plus 30 minutes. For a daily time of 20:00: an 08:00 opening is email-eligible at 20:30; a 21:00 opening is eligible at 21:30. Opening exactly at 20:00 gives an email deadline of 20:30. Midnight arithmetic must preserve the correct following date; for example, 23:45 plus 30 minutes is 00:15 next day.
- Send only while the shift is still open and the current time is between the email deadline and 30 minutes after that deadline, inclusive. Later attempts expire without sending. An overdue shift does not acquire a fresh email occurrence on each subsequent day.
- Locked shifts, signed-out Cashiers and closed browsers remain email-eligible. Browser Later does not postpone email. The Store PC and internet must be available; never claim that a powered-off installation can send.
- Add frequent background evaluation independent of page visits, using the existing CLI/scheduled-task deployment pattern. Evaluate at least once per minute; delivery is due at the first available evaluation on or after the deadline. Do not increase the frequency of the unrelated daily maintenance/stock-brief job.
- Reuse the Brevo sender, branded email and delivery logging. Maintain durable per-shift/per-recipient delivery state across restarts and concurrent worker runs. Claim a send atomically and never resend an accepted delivery merely because another recipient failed, settings changed, the page reloaded or the task restarted.
- Resolve recipients from active ordinary Administrator account assignments and the owning Cashier account, using their account email addresses, not the Store's official email as a replacement. With one Administrator and one Cashier this means two separate emails. If there are several active ordinary Administrators, notify each; never include Super Administrator solely because of technical authority.
- Cashiers receive only their own shift. Deduplicate the same normalized address for the same shift. Missing/invalid addresses and inactive Cashier accounts do not prevent eligible Administrators receiving their email; record skipped recipients without marking them delivered.
- Immediately before each dispatch, recheck open status, current settings, recipient eligibility and the lateness window. Closing a shift or disabling delivery suppresses pending sends/retries. Re-enabling or changing the time does not resend a successful reminder; unsent shifts use the current schedule only if still inside its allowed window.
- Retry a definite send rejection/failure at a bounded interval, using five minutes as the implementation default, only within the original delivery window. Do not blindly retry an ambiguous provider timeout if it could have been accepted; retain an unknown outcome rather than claiming failure or causing avoidable duplicates. Provider acceptance is not proof of inbox receipt.
- Email identifies the Store, Cashier, counter, shift opening time and the fact that the shift was still open when checked, with Philippine timestamps and an authenticated role-appropriate link. Do not include cash totals, passwords or another Cashier's details in a Cashier email.
- A previous-day shift is an open shift whose Philippine opening date is before the current Philippine date. On the Cashier's first return/login/foreground check for that shift on a new date, prioritize a SweetAlert explaining that the previous shift remains open. Its single OK button navigates to the existing Close shift page. Do not show Later on this alert.
- Acknowledge the previous-day alert once per signed-in session, shift and Philippine date so navigation does not create an endless popup loop. Do not interrupt active payment or an existing dialog. For a locked shift, show it after normal unlock. Do not duplicate it while the Cashier is already on Close shift.
- Enable reminder Off also suppresses this previous-day popup, consistent with the master reminder setting. It does not close a shift or remove the existing one-open-shift invariant.
- The previous-day alert is guidance only: do not add a date-based sales/refund block, force logout, automatically close a shift or automatically open a new one. Existing held-sale, unlock, counting and reconciliation requirements continue unchanged.
- Preserve actual opened-at, sales/receipt timestamps, shift attribution and actual closing time. The feature encourages daily cleanup; it does not backdate a close or guarantee that an acknowledged alert forces daily separation.
- Keep Store Overview's uncached 30-second open-shift refresh, locked status, empty/unavailable distinction and report links. Background polling must not renew login activity.

## Testing Decisions

- Prefer one high-level acceptance seam: run the real Administrator/Cashier routes and scheduled email evaluation against a disposable Store database with a controlled clock and a fake Brevo transport. Assert visible behavior and captured outbound messages, not internal query text or DOM implementation details.
- Extend existing shift-reminder and Store-settings browser test patterns for settings persistence, authorization, validation, central policy, navigation, the previous-day OK redirect, locked/dialog deferral, desktop/mobile layout and light/dark themes.
- Extend existing shift-reminder clock-boundary tests for before/at/after the daily time, late openings, midnight, previous-day detection, deadline minus one second, exact deadline, final allowed second and expired delivery.
- Exercise the scheduled evaluation twice and concurrently, then restart it, to verify durable deduplication. Cover one recipient succeeding while another fails; subsequent work must not resend the successful recipient.
- With fake email delivery, verify all effective gates independently, required-action alert exceptions, default-off email behavior, rejection of old personal POST routes and consistent retirement of historical personal choices.
- Cover browser closed/signed out, locked shifts, Later snooze, missing email, inactive Cashier, several ordinary Administrators and a shared address. Verify recipients and message content without sending real mail.
- Cover closure immediately before dispatch, disabling/re-enabling, time changes, retryable rejection, ambiguous provider response, short outage recovery and recovery after expiration. Never send a stale closed-shift or expired reminder.
- Preserve the existing session-activity HTTP regression and open-shift overview regression. Run the repository's standard checks/contracts and relevant TestSprite acceptance tests against synthetic data; report missing verification honestly.
- Reuse existing notification/email delivery fixtures and transport injection where available; introduce only the minimal clock/transport control necessary at the acceptance seam. Do not create a parallel notification framework for tests.

## Out of Scope

- Implementing the feature as part of publishing this specification.
- Personal reminder opt-outs, custom per-Cashier schedules, per-role switches, repeated daily shift emails or configurable escalation delays.
- SMS, browser push, operating-system notifications, cloud migration, and delivery while the Store PC is powered off or disconnected.
- Date-based transaction restrictions, automatic close/open, automatic held-sale cancellation, backdated closing or rewriting sales/receipts.
- Reworking the completed Store Overview feature, changing stock-brief schedules, or moving Super Administrator security/transport ownership.
- Guaranteed inbox receipt or exactly-once inbox delivery across ambiguous external-provider outcomes.

## Further Notes

The user explicitly approved the 30-minute late-delivery cutoff, 30 minutes after a late shift opening, one email per recipient per shift, centralized Administrator notification controls and removal of personal Preferences. The final clarification selected an informational SweetAlert whose OK action redirects to Close shift, rather than the proposed new transaction block.

Operational defaults used to make this spec implementation-ready without further interview are: active account-based Administrator recipients, independent recipient delivery tracking, at-least-minute evaluation, five-minute retries for definite failures, one previous-day prompt per signed-in session/shift/date and preservation of mandatory/security channels. These are implementation choices, not additional user-requested configuration options.

The existing local daily notification task runs only at 03:00, so a dedicated frequent evaluator and its deployment wiring are required. Existing browser reminder/Store Overview checks passed previously; those results do not verify this unimplemented extension.
