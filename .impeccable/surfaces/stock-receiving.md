---
version: 1
slug: stock-receiving
primary_target: src/frontend/components/report/stock_receiving.php
related_targets: [src/frontend/assets/css/stock-receiving.css]
---

# Stock Receiving

Mode: Operate. Audience: authorized Store staff recording bought goods through direct bulk Stock Receiving.

Direction: adapt the supplied receiving workbench inside RetailMind's existing navigation. Numbered steps guide product selection, quantity entry, and review before saving. The selection catalog occupies the full content width; quantity entry pairs delivery lines with a live summary of accepted, received, damaged, and known accepted cost. Damaged units never increase available stock; blank costs remain unknown in the summary. Native fields and the existing ReceivingService retain the operational contract.

First viewport: Stock Receiving title, receiving history, numbered progress, then searchable/scannable catalog. At quantity entry, the catalog yields to the delivery editor. Desktop uses a sticky 288px summary, narrowing to 264px below 1100px; at 767px the summary and catalog stack, and fields become one column at 390px. Theme variables carry light/dark surfaces, primary actions, borders, muted text, and semantic success. Buttons have a 44px minimum height and controls expose keyboard focus.

Evidence inspected: `output/playwright/stock-receiving/light-1440-step1.png`, `light-390-step2.png`, and `dark-1440-step2.png`, alongside the PHP view, dedicated stylesheet, `docs/PRODUCT.md`, and `docs/DESIGN.md`. Parent verification reports all steps passing at 320/390/768/1440 in light/dark, AA contrast, and 44px controls; this documentation pass did not rerun those checks.

Surface-specific departures remain local: 8px workbench panel corners versus the documented 14px panel rule, a 2rem desktop page title versus the documented 1.8rem headline ceiling, and compact labels below the general body scale. These do not replace system tokens. `docs/DESIGN.md` and `.impeccable/design.json` remain authoritative and unchanged; no system-wide redesign was authorized.
