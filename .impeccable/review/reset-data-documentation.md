# Reset Data: implementation evidence

Reviewed 2026-10-12 against `docs/PRODUCT.md` and `docs/DESIGN.md`.

The page extends RetailMind's established Working Stockroom system. It retains the shared application shell, system font, Action Blue interaction, theme-aware neutral surfaces, 14px panels, existing form controls, and semantic destructive actions. No global visual-system change is warranted.

The Operate layout separates one-product deletion from whole-Store reset, groups all 21 record totals into four domains, and places reset preparation and confirmation beside the record review. At 760px it stacks into one column. Passwords, exact phrases, CSRF fields, nonce, capability gate, and blocker-driven disabling remain visible in the implementation.

Local variations are appropriate surface choices: flat bordered panels, 1.5rem panel padding, compact supporting text, outlined product deletion, and a filled whole-Store reset action. These are not new global tokens.

Existing drift: DESIGN.md describes white surfaces and fixed semantic colors, while shared CSS already supports theme surface overrides. This predates the page; no repair or documentation rewrite made.

Scope: source comparison only. Browser rendering and destructive-operation tests belong to the main implementation checks. PRODUCT.md, DESIGN.md, and any design sidecar were preserved.

