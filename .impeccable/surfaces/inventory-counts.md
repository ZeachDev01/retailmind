---
version: 1
slug: "inventory-counts"
primary_target: "src/frontend/components/inventory_management/inventory_counts.php"
related_targets: ["src/frontend/assets/css/inventory-counts.css"]
---

# Inventory Counts

Mode: Operate. Preserve the RetailMind navy navigation, Action Blue tabs/actions, theme surfaces, system typography, and existing stock transaction safeguards. The user requested redesign of both New Count and Recent Counts on 2026-11-26, retaining the confirmed direct-save workflow.

## Direction contract

- New Count begins with Product search. Reveal physical quantities after current stock loads; use batch or sealed/loose inputs as the single source of the total. On desktop, a divided summary beside the editor holds the comparison, reason, and Save Count; narrow screens place it afterward. Keep every actual batch input visible on phones without sideways scrolling.
- Recent Counts is a compact searchable ledger: product name leads, SKU and batch-detail link follow, numbers align for comparison, and differences include short/extra/matched labels. Keep complete reasons, recording staff, dates, status, sorting, and pagination. Show an Action column only where a pending recount can be acted on.
- Use the existing shared pagination and explicit no-ghost-row opt-out. A single real count occupies a single row. The search label uses the app's existing accessible hidden-label class; controls use 44px touch targets.
- Extend the established visual system locally; no global design-token or navigation replacement. Keep Administrator history read-only, encrypted draft recovery, all count inputs, and batch-detail inspection available.

## Verification

Passed: `npm run check`, the Inventory Count reconciliation and scan contracts, the disposable barcode browser suite, and the disposable Inventory Count workflow browser suite. Browser coverage includes light/dark captures at 390, 768, and 1366 pixels, desktop summary placement, narrow-screen entry, single-row density, batch details, history navigation, actual stock effects, and Administrator read-only history. Evidence lives under ignored `output/playwright/inventory-counts/`; `redesign-workflow.log`, `scan-redesign.log`, and `redesign-check.log` record the focused results. The shared view audit retains one unrelated duplicate-ID warning in Cashier Shifts.

The redesign's TestSprite run `10101a27-8d84-4182-a06d-63ca46855b21` was blocked after tunnel control disconnected: its steps report the staff login page unavailable, before reaching Inventory Counts. It was already terminal when cancellation was attempted. Artifact download was withheld by TestSprite because the temporary environment no longer existed; the step summary and CLI output are retained locally. Do not count the original workflow's earlier TestSprite pass as redesign verification.
