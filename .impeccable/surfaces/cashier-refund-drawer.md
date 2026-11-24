# Cashier refund drawer

Primary target: `src/frontend/components/cashier/refunds.php`
Related targets: `src/backend/app/Views/refund_return_conditions.php`, `src/frontend/assets/css/refunds.css`

Mode: Operate. Cashiers inspect returned items and correct their condition. The shared fragment also serves Inventory Managers and read-only Administrators.

## Direction contract

THESIS: Place correction beside the item it changes, and collect correction evidence below every returned item.

OWN-WORLD: Inherit RetailMind's system type, blue actions, white surfaces, semantic condition tags, 10px controls and quiet dividers. No global visual-system changes.

STORY: Inspect quantity and current condition, open the item's correction form, review the stock effect and save; scan a newest-first history list with product, conditions, actor, time, reason and movement.

FIRST VIEWPORT: Keep the existing refund facts and fixed drawer header/footer. Each item has its name on the left and an outlined Correct condition disclosure on the right. Its form expands inline. All history follows the item list and scrolls with the drawer body.

FORM: Local extension of the established drawer; code-led, no concept roll. seed: none (unavailable).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Verification

Finish verdict (2026-11-24): **Ship** for this local extension. The finish reviewer found no material fixes at this scope.

Compared with the incumbent system in `docs/DESIGN.md`, the implemented drawer retains system-UI typography, Action Blue, white surfaces, semantic condition tags, 10px controls and quiet dividers. The existing refund facts and fixed header/footer remain; correction expands beside its returned item, and one combined newest-first history follows all items in the scrolling body. This supports the Product principles of calm Store work, explicit role boundaries and historical accountability without adding a new visual language.

Evidence checked: the shared PHP fragment, the trailing screen-only rules in `refunds.css`, the Cashier drawer integration, and desktop/open-form and mobile/history review captures. Review captures are in `.impeccable/review/refund-drawer/`: `desktop.png`, `mobile.png`, `history-mobile.png` and `many-history-mobile.png`. These are review evidence, not shipping assets.

Reported validation from the implementation and review pass:

- `npm run check` passed.
- `refund_return_condition_browser_test.js` passed at 1440, 768, 390 and 320px, covering piece/pack previews, combined history and a long list.
- `refund_return_condition_manager_browser_test.js` passed signed-in save and read-only role checks; the DB, HTTP and concurrency suite passed.
- `refund_return_condition_history_test.php` passed combined-history ordering, correction-ID tie-breaks, escaping and read-only rendering.

Limits: TestSprite run `5135786a-00f3-4370-a382-09fdfc356f1b` reported passed but returned no step evidence, and the local fixture server on port 18879 recorded only a root request returning 404. Exclude that run from verification; it does not establish preview/history coverage or a full live Store save. Local browser, DB and HTTP checks above are the verification evidence.

Documentation scope remains this surface brief. Existing `docs/DESIGN.md` and `.impeccable/design.json` are preserved; no global tokens changed and no raster assets ship.
