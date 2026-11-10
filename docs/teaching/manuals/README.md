# RetailMind manuals

Authored manual sources and generated manual deliverables. The manuals describe the live application at the revision they were built from; confirm current labels and behavior on the running page.

## Preserved deliverables

The dependable copies are the generated documents in this directory. They need no build step and are the reference for content:

- `RetailMind_User_Manual.pdf` — full user manual.
- `RetailMind_User_Manual.docx` — full user manual as Word.
- `RetailMind_User_Manual_Admin_Inventory_Cashier.docx` — shorter Administrator, Inventory Manager, and Cashier overview.
- `all_schema_tables.docx` — database schema tables reference.
- `sale_items_normalization_1nf_3nf.docx` — sale-items normalization reference.
- `concise-final.pdf`, `concise-render.pdf`, `word-render.pdf` — earlier generated renders kept as evidence.

## Source inputs

- `source/build_manual.py` — builds the PDF manual from the scripted page content.
- `source/build_word.py` — builds the Word manual from the content in `build_manual.py`.
- `source/build_concise_word.py` — builds the concise role overview from `source/live/operational-captures.json`.
- `source/live/` — live role and page captures, plus the capture and section metadata JSON the concise script reads.
- `source/assets/` — the four page screenshots used by the full manual: `overview-1440.png`, `cashier-populated-1440.png`, `gcash-1440.png`, `populated-light-1440.png`.

The scripts resolve paths from their own location, so run them from anywhere in the checkout:

```bash
python docs/teaching/manuals/source/build_manual.py
python docs/teaching/manuals/source/build_word.py
python docs/teaching/manuals/source/build_concise_word.py
```

Rebuilding requires optional Python packages that are not part of the application runtime or the Node test tooling: `reportlab` and `pypdf` for the PDF, `python-docx` for the Word files, and `Pillow` for image sizing. Without them the generated documents above remain the authoritative copies.

## Known gap

`build_manual.py` also refers to two `.scratch/` inputs that no longer exist and are not recoverable:

- `.scratch/calendar-light-1440.png`
- `.scratch/replenishment-comparison/desktop.png`

The scripts skip missing images, so a rebuild completes with four embedded screenshots instead of six. Regenerate the two missing captures before rebuilding if the calendar and replenishment pages must appear with screenshots.
