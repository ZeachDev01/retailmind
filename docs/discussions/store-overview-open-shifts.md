# Store Overview open cashier shifts

Design decisions and implementation authorized on 2026-11-26. The user confirmed the combined scope with “start”.

## Confirmed

- The Administrator's Store Overview should show the total number of open Cashier Shifts.
- Each shift should show its Register (counter), responsible Cashier's name, and opening time.
- “Shifter” in this request means the Cashier responsible for the shift.
- Count every shift that has not been closed, including locked shifts, shifts whose Cashier has logged out, and shifts opened on an earlier date. Show Locked when applicable.
- Use a separate Open cashier shifts section in Store Overview, with the total and shift list together.
- Refresh the shift information every 30 seconds while the overview is open. The existing five-minute cache must not delay these updates.
- Selecting a shift opens its existing Cashier Shift Report.
- When no shifts are open, show an explicit empty state. A failed load must show unavailable information rather than an incorrect zero.

## Existing behavior

- A locked shift remains open; logging out does not close it.
- The overview already obtains an open-shift count, but does not display a shift section.
- The overview currently uses a five-minute cache and refresh interval.

## Final confirmation

All overview and reminder questions have been answered and confirmed, including Q8–Q12. The user authorized implementation of the combined scope.

## Added request: close-shift reminder

- Show a SweetAlert reminding the Cashier to close their shift.
- Let the Administrator configure when the reminder appears.
- Add its System Configuration navigation item directly below Attention thresholds.
- Use a fixed daily clock time, set by the Administrator, in the Store's operational time zone.
- Offer Close shift to open the existing closing process and Later to dismiss the reminder. The reminder never closes a shift automatically.
- Provide a Shift reminders item directly below Attention thresholds in the existing Store Configuration navigation.
- Use one daily reminder time for all Cashiers, with an Enable reminder setting. Reminders are disabled by default until the Administrator enables them.
- Use Philippine operational time (Asia/Manila) consistently for the configured daily time.
- After Later, remind again after ten minutes. Continue until the shift closes, subject to the deferral rules below.
- An existing overdue shift is eligible for a reminder when its Cashier returns. A shift opened after today's configured reminder time is first due at the next day's configured time. A shift opened exactly at the configured time is due then.
- Defer the popup during payment, another open dialog, or a locked shift. Check again when the Cashier can safely act; do not cover the lock screen or interrupt a transaction.
- Deliver reminders only within RetailMind while the Cashier is signed in and the browser is open. Check overdue status when returning to RetailMind; no SMS, operating-system notification, or signed-out delivery is included.
- Keep the normal close-shift prerequisites, cash counting, confirmation, and unfinished-sale protection.

No interview decisions remain open. The overview uses an uncached shift-status read every 30 seconds. Reminders use the existing Store settings and SweetAlert, retain the normal close-shift flow, and never extend idle-session expiry through polling. Later persists a ten-minute snooze per Cashier Shift in browser storage.

No ADR is needed for the display change at this stage; no hard-to-reverse domain decision has been made.

## Verification

The following checks cover the implemented browser reminder and overview, not the email extension requested below.

- `php src/backend/tests/shift_reminder_test.php`: daily due-time boundaries, Philippine timezone, disabled/default behavior and malformed setting validation passed.
- `RUN_DB_TESTS=1 node src/backend/tests/shift_reminder_browser_test.js`: disposable-database settings persistence and validation, role access, overdue reminder, ten-minute snooze across reload, navigation, locked/dialog deferral, existing closing link, live shift list, empty/unavailable states and responsive geometry passed.
- `node src/backend/tests/session_activity_http_test.js`: background shift polling does not renew idle login; session-expiry checks passed. Its legacy fixture was aligned with the current display_name column.
- Existing Store receipt-settings browser regression, `npm run check`, and `npm run check:contracts` passed. The view audit retains the existing duplicate open-register ID warning in cashier/shifts.php.
- [TestSprite run 66b9e7bc](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/8b2a808e-c55d-43b9-8a96-45a53f50eb10/test/66b9e7bc-a378-4329-a0c5-cf056d2f0283): 17/17 steps passed against disposable Store data.
- Desktop/mobile light/dark captures were inspected under ignored output/playwright/shift-reminders. Physical devices remain unverified.

## Proposed extension: overdue shift email

The user requested a new grill-with-docs discussion after the browser reminder was implemented. Email implementation is not yet authorized by this discussion.

Explicit requirements:

- Use Brevo to notify the Administrator and the responsible Cashier when a shift remains unclosed 30 minutes after Daily reminder time (Philippine time).
- Provide an option to turn email reminders off.
- Enable reminder is the parent switch: turning it off must also prevent email reminders.

Confirmed in the first email interview round:

- The Administrator alone controls the new Store-wide email-reminder option. Do not introduce a new personal opt-out for this feature.
- The user subsequently requested removal of the personal Preferences page and centralization of notification on/off controls under Administrator Store Configuration. Inspect the page's other responsibilities before settling the removal scope.
- Send once per shift rather than repeat daily.
- Locked/logged-out shifts remain eligible, and choosing Later on the popup does not postpone the email deadline.
- Skip email catch-up when delivery is already too late; the permitted lateness window remains to be defined.
- The next round confirmed a maximum 30-minute delivery delay after the email deadline; after that window, skip the stale email rather than send it the next day.
- A shift opened after Daily reminder time is eligible for email 30 minutes after opening, rather than waiting until the next day. The user approved the 8 PM setting / 9 PM opening / 9:30 PM email example. Whether the browser due time also changes needs to be explicit.
- “Two emails” means one to the Administrator and one to the Cashier responsible for the shift, once per shift.

Additional request: when the Cashier returns the next morning with a prior-day unclosed shift, show a SweetAlert to close that old shift before creating a new one. Clarify warning versus transaction blocking, midnight boundaries and locked shifts; retain actual historical records rather than backdating closure.

Existing infrastructure findings:

- Brevo sending and delivery logging already exist. The current daily notification task runs at 03:00; delivery at the shift deadline needs more frequent background evaluation.
- Existing account Email notifications preferences default to off. Whether this reminder follows that preference or is governed solely by the Administrator's new switch needs an explicit decision.
- Account email and official Store email are separate; a Cashier account can have no email address.

Final synthesis:

- The user approved Store-wide Administrator control of all four existing optional operational notification switches: Low stock On, Inventory adjustments Off, In-app On and Email Off initially. Remove personal notification Preferences; preserve Theme, Full Screen, required-action alerts and separate technical/security policy.
- Late-open shifts receive an immediate popup and become email-eligible 30 minutes after opening, replacing the earlier next-day timing for this case.
- For a previous-day open shift, show a SweetAlert on the Cashier's return. The user's final correction is a single OK action redirecting to Close shift, with no new sales/refund block. Existing close prerequisites and one-open-shift enforcement remain.
- The user requested synthesis and publication through to-spec without further interview. The complete specification records time boundaries, effective switches, recipients, missing-address handling, bounded delivery/retries and implementation defaults.

Specification: [Cashier shift email and central notifications](../specs/cashier-shift-email-and-central-notifications.md), published as [GitHub issue #510](https://github.com/ZeachDev01/retailmind/issues/510) with ready-for-agent. This extension remains unimplemented; publishing its issue does not send email or change live settings.
