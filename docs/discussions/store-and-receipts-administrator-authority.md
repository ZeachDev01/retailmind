# Administrator authority over Store & receipts

Discussion decisions, 2026-11-02 Philippine time. Application changes are not implemented by this document.

## Confirmed decisions

- The Administrator should run routine Store setup without depending on the Super Administrator.
- For simplicity, the Administrator receives the full Store & receipts function: Store name, TIN / Business identifier, Store email, Contact number, Store address, Currency symbol, Timezone, Expiry alert lead time, and Receipt footer. No field-level approval split is required.

## Scope and existing behavior

- Store & receipts is one section of the current Platform Settings page. Dormancy policy and test-email dispatch are separate functions; the user's delegation decision does not grant access to those actions.
- This direction follows ADR-0001's assignment of Store Settings to the Administrator. Backend authorization must preserve the separate platform-governance boundary.
- ADR-0006 preserves transaction-time details for modern receipts. Legacy receipt reconstruction can use current Store information.
- Currency symbol changes labeling without converting monetary amounts.
- The stored Timezone field currently does not configure the runtime timezone; runtime configuration reads APP_TIMEZONE. Delegating this function does not itself change timezone behavior.

## Confirmed scope and specification

- The user confirmed the final scope: all nine Store & receipts fields belong in Administrator Store Settings, with no per-field Super Administrator approval. Separate platform functions retain their existing authorization.
- The user confirmed request-level settings and authorization coverage with existing receipt-history regression tests.
- Specification: [Administrator Store & receipts](../specs/administrator-store-and-receipts.md), published as [GitHub issue #385](https://github.com/ZeachDev01/retailmind/issues/385) with the ready-for-agent label.
