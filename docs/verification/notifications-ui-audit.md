# Notifications UI audit and simplification

Reviewed 2026-10-14. Target: `src/frontend/components/notification/notifications.php` and `src/frontend/assets/css/notifications.css`. User confirmed Notifications after resolving the mismatched Reset Data link text. Audit followed by requested simplification; this is a local Operate-surface refinement.

## Implementation integrity verdict

Pass after refinement: the existing navy workspace, theme tokens, system font, native filter, and server-rendered actions remain coherent. Category names now explain the updates; explicit Unread labels explain state without relying on color. One list replaces repeated bordered cards. No dependencies, imagery, animations, or new backend behavior were introduced.

## Health score

Scores are implementation assessments, not a WCAG certification or performance benchmark.

| Dimension | Before | After | Evidence / limit |
| --- | --- | --- | --- |
| Accessibility | 2 | 3 | Timestamp contrast tested at 4.5:1 minimum in both themes; sequential h1/h2 headings; explicit unread text; visible keyboard focus. Screen reader not tested. |
| Performance | 3 | 3 | Removed emoji rendering and unused CSS; no JS added. Existing cumulative Show more query remains; latency not benchmarked. |
| Theming | 2 | 4 | Page surfaces, borders, text, and state use existing tokens; inspected light/dark screenshots. White action text follows existing button convention. |
| Responsive | 2 | 3 | Chromium tests at 320, 390, 768, and 1440px: no horizontal overflow, page controls at least 44px high. Physical touch devices not tested. |
| Implementation integrity | 2 | 4 | Explicit categories, quiet hierarchy, documented type ramp. Detector's three metadata-size advisories corrected to 1rem. |
| Total | 11/20 | 17/20 | Acceptable → Good; remaining limits concern untested coverage. |

## Verified findings and disposition

Initial findings: P0: 0; P1: 1; P2: 5; P3: 0. All addressed within the requested page.

| Priority | Location before refinement | Category / user impact | Resolution |
| --- | --- | --- | --- |
| P1 | `.notification-time` in notifications.css | Accessibility: #999 on white is about 2.85:1, below WCAG 1.4.3's 4.5:1 normal-text requirement. Dates were difficult to read. | Use theme muted text; regression check measures computed timestamp/surface contrast in light and dark themes. |
| P2 | `.btn-mark-read` | Responsive: small padding/font produced short controls, making repeated mobile actions harder. 44px is the chosen touch quality bar, not a claim of an AA failure. | Every page button, select, and Show more link has a tested 44px minimum height. |
| P2 | `.notification-content h4` in notifications.php | Accessibility: heading levels skipped from h1 to h4, weakening navigation structure. | Notification titles use h2 inside article elements. |
| P2 | `.notification-icon` / `$icons` | Implementation integrity: emoji treatments conveyed categories inconsistently; adjustment and system colors suggested severity unrelated to actual state. | Replace icon badges with readable category labels. |
| P2 | `.unread-badge`, `.mark-all-btn`, item borders and timestamps | Theming: fixed red, grey, and blue values diverged from workspace tokens and risked inconsistent dark-theme states. | Use shared surfaces, borders, primary action and text tokens; read actions stay neutral in dark mode through existing secondary-button convention. |
| P2 | Notification toolbar and `.notification-item` | Implementation integrity / UX: separate filter/status rows and repeated card outlines made equivalent read actions visually compete with content. | Combine filter, unread count and bulk action into one responsive toolbar; group rows into one bordered list. |

Systemic pattern on this surface: old view-specific styling duplicated theme decisions. Fixed locally; shared shell and other routes are outside this audit.

## Practices retained

- Native GET filter and POST read actions, including filtering bulk updates to the selected category.
- Existing CSRF verification, per-user ownership checks, newest-first ordering and Show more expansion.
- Escaped content, empty-state message, readable content wrapping and semantic form labels.
- All existing notification messages and stored data.

## Verification

`php -l src/frontend/components/notification/notifications.php` passed.

`RUN_DB_TESTS=1 node src/backend/tests/notification_list_browser_test.js` passed against its randomly named disposable database. Coverage includes category filtering, 10/20/all expansion, individual/bulk read actions, invalid/malformed filters, account isolation, viewport overflow, touch-target height, timestamp contrast and focus visibility. Fixture cleanup remains in the test's finally block. Existing tests were adapted from h4 to h2 and extended for UI checks.

Visual evidence: incumbent fixture screenshot matched the original source structure; final desktop, mobile and dark screenshots were inspected in two bounded rounds. Final evidence is in `.impeccable/review/notifications-after/`. Live user-browser inspection was unavailable; authenticated Chromium fixture rendering supplied the evidence instead. No live Store data was changed.

`node src/backend/tests/notification_responsive_browser_test.js` also passed: shipped article markup, ordinary and long unbroken messages, contained content and working read controls at 320, 375, 425, 768, 1024 and 1440px. Its extracted fixture was updated for the semantic article/h2 markup and page scope.

Mechanical detector ran once after implementation. It reported three advisory `.875rem` sizes outside DESIGN.md; those values were replaced with documented 1rem. No global design tokens or pre-existing documentation drift were changed.

No outstanding page defects found within this coverage. If further review is needed: `$impeccable harden` for screen-reader/physical-touch coverage, then `$impeccable polish`. These can run separately or together; re-run `$impeccable audit` to expand evidence.
