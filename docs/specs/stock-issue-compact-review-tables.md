# Compact Stock Issue review tables

Date: 2026-11-25
Status: Scope confirmed; final detail-flow confirmation pending. No application changes made for this specification.

## Confirmed decisions

- Apply to the Inventory Manager's Pending review and Recent decisions views.
- Replace tall report cards in each list with one compact table. Each row represents one Stock Issue, even when several issues concern the same Product.
- Clicking a row or its explicit View details link navigates to a dedicated detail view containing only that report, with its information arranged into clear sections.
- List columns: Report number, Product, Issue type, Quantity, Status, and date. Pending uses reported quantity and reported date; decisions uses deducted quantity and decision date, with clear labels. Rejected or cancelled issues have no stock deduction.
- Default to 15 rows per page. Retain the existing 10/15/25/50/100 page-size options.
- Reuse the existing shared table footer: entry count on the left, page-size selector in the center, and Previous, numbered pages, and Next on the right. Follow its existing responsive positioning and button styles.
- Paginate complete decision history rather than retaining the current 50-record recent-review cutoff. Preserve newest-decision-first ordering and the pending queue's oldest-first ordering.

## Detail flow proposed for final confirmation

- Pending detail contains original report information, live stock availability, verified quantity, batch allocations, and Approve/Reject actions. Preserve existing validation and permissions.
- Decision detail contains the report, reported and deducted quantities, recorder and reviewer, dates, reason and review notes, deducted batches, historical messages, activity, and the existing inventory-count correction link when applicable.
- After approval or rejection, stay on the same report's detail view, show its new status and result, and replace review controls with the completed decision information.
- Back to list returns to the originating Pending review or Recent decisions page with its page number and page size retained. Clamp the page if the queue shrinks.
- Existing direct report links and recording redirects open the dedicated detail view. Historical messages keep their existing read-only modal behavior.

## Implementation and verification boundaries

- Preserve existing role, Store, capability, CSRF, stock availability, batch allocation, quantity validation, and duplicate-submission protections.
- Reuse existing table tokens and the shared `src/backend/app/Views/table_pagination.php`; do not create a second footer design.
- Provide keyboard-accessible detail links and visible focus; row clicking must preserve native link and text-selection behavior.
- Keep compact tables usable at narrow widths through existing responsive table patterns. Detail sections stack and support existing light/dark themes.
- Cover both paginated lists, history beyond 50 decisions, direct detail links, return navigation, empty/invalid states, and real approval/rejection behavior using the existing relevant service and browser tests. Run required repository checks and relevant TestSprite verification before claiming implementation verified.
- Cashier and Administrator page redesigns are outside this scope. Existing user working-tree changes must be preserved.

## Domain documentation

The existing glossary definitions of Stock Issue, Reported Stock Issue Quantity, and Verified Stock Issue Quantity remain accurate. This changes presentation and navigation, so no new glossary term or ADR is needed.
