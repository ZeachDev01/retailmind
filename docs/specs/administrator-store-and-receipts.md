# Delegate Store & receipts to the Administrator

Confirmed scope and testing approach, 2026-11-02 Philippine time.

## Problem Statement

The Administrator is responsible for Store operations but must currently depend on the Super Administrator to maintain Store identity and customer receipt settings. The Store & receipts section is grouped into Platform Settings with unrelated technical controls. This makes routine Store setup depend on platform-governance intervention.

## Solution

Move the complete Store & receipts function into Administrator Store Settings. The Administrator can view and save all nine existing fields without per-field Super Administrator approval. Keep existing operational attention thresholds available alongside this section. Separate platform functions remain under Super Administrator authority.

## User Stories

1. As an Administrator, I want Store & receipts in Store Settings, so that I can maintain the Store without platform-governance intervention.
2. As an Administrator, I want to update the Store name, so that future customer receipts identify the Store correctly.
3. As an Administrator, I want to update the TIN / Business identifier, so that I can maintain the Store's recorded business identification.
4. As an Administrator, I want to update the Store email, so that the recorded contact details remain current.
5. As an Administrator, I want to update the Contact number, so that customers receive current contact information.
6. As an Administrator, I want to update the Store address, so that future receipts show the current address.
7. As an Administrator, I want to update the Currency symbol, so that I can control the existing monetary display setting.
8. As an Administrator, I want access to the existing Timezone field, so that the complete Store & receipts function is available in my Workspace.
9. As an Administrator, I want to update the Expiry alert lead time, so that I can maintain the existing Store-wide expiry-warning setting.
10. As an Administrator, I want to update the Receipt footer, so that future receipts carry the Store's chosen message.
11. As an Administrator, I want all nine fields available without separate technical approval, so that delegated authority stays simple.
12. As an Administrator, I want current saved values displayed when I open Store Settings, so that I can review and amend the existing configuration.
13. As an Administrator, I want clear validation and save feedback, so that I know whether my changes were accepted.
14. As an Administrator, I want to save receipt settings independently of attention thresholds, so that one form does not overwrite another form's settings.
15. As an Administrator, I want existing operational threshold controls retained, so that delegation does not remove my current Store Settings functions.
16. As a Store operator, I want successful settings changes attributable to the acting user, so that Store configuration changes remain traceable.
17. As a customer, I want newly completed receipts to preserve the applicable Store details, so that later configuration changes do not rewrite my receipt.
18. As an Administrator, I want historical reprints to retain their existing preservation behavior and legacy notices, so that delegation does not undermine receipt history.
19. As a Super Administrator, I want dormancy policy, email testing, and other platform controls to retain their authorization, so that Store delegation does not grant technical governance.
20. As a multi-role user, I want Store settings changes to require the Administrator Workspace, so that assigned roles do not combine authority.
21. As a Store operator, I want unauthorized or forged requests rejected without settings changes, so that the permission boundary holds beyond the visible interface.

## Implementation Decisions

- The Administrator owns the entire existing Store & receipts section: Store name, TIN / Business identifier, Store email, Contact number, Store address, Currency symbol, Timezone, Expiry alert lead time, and Receipt footer.
- Move the section and its mutation responsibility from Platform Settings into Administrator Store Settings. Remove the former Super Administrator editing path for these fields rather than retaining duplicate forms. Under ADR-0001 and the Workspace model, a user with assigned Administrator access switches into that Workspace for Store mutations.
- Preserve the separate platform-governance gate. Do not grant the Administrator the general platform-governance capability. Dormancy policy, test-email dispatch, platform attention thresholds, safety limits, and other technical controls retain their existing authority.
- Audit both the current frontend handler and the legacy system-settings handler so neither retains an unintended Store-settings write path. Preserve unrelated platform actions in both entry points where applicable.
- Reuse the existing Store settings persistence and activity logging mechanisms. Retain existing keys and values; no schema migration, new role, approval workflow, or per-field permission system is required.
- Keep Store & receipts and attention thresholds independently dispatched and explicitly allowlisted. A submission for one section must not clear missing fields from the other section or write arbitrary Store or platform settings.
- Preserve authentication, active-Workspace checks, applicable remote-access and Store write restrictions, CSRF verification, server-side validation, output escaping, and safe failure handling. Retain required Store name, valid optional Store email, and valid optional IANA timezone checks; reuse existing field-specific validation where present.
- Preserve expiry-warning behavior and the existing serialized lead-time format. This work delegates the existing control rather than introducing a new warning policy or redesigning Product-level overrides.
- Record successful Store & receipts saves with the acting user's identity through existing activity logging. Do not report successful saves when persistence fails.
- Preserve modern Sale Receipt, Refund Receipt, and GCash Service Receipt snapshots. Changes apply to subsequently captured details; existing legacy reconstruction behavior and notices remain intact under ADR-0006.
- Preserve the Currency symbol field's current display-only behavior: changing it does not convert amounts or create multi-currency accounting.
- Preserve the Timezone field's current behavior: saving it does not change the runtime timezone, which currently comes from APP_TIMEZONE. Activating runtime timezone switching is outside this delegation.
- Update page descriptions and navigation references affected by the moved section, using existing accessible form and feedback patterns. Do not redesign unrelated settings screens.

## Testing Decisions

- The user confirmed the test approach: exercise authenticated settings-page save requests, verify Workspace and platform authorization boundaries, and reuse existing receipt-history tests.
- Prefer one request-level integration flow through Administrator Store Settings using existing authenticated browser/request fixtures. Assert observable access, persisted values, validation feedback, and audit attribution rather than internal function structure or exact markup.
- Verify that an Administrator can load and save all nine fields, reload them, and save attention thresholds separately without cross-form data loss.
- Verify rejection without mutation for invalid required/name, email, and timezone inputs, missing/invalid CSRF tokens, unauthorized Workspaces, and forged submissions attempting platform actions or extra setting keys. Include the existing remote read-only boundary where the fixture supports it.
- Verify that Super Administrator platform actions remain available under their existing policy while Store & receipts writes require the Administrator Workspace, including any reachable legacy settings entry point.
- Reuse existing authenticated browser coverage for role-specific settings access and responsive settings pages; extend only the assertions affected by relocation.
- Reuse existing receipt attribution and sale, refund, and GCash integration coverage to verify transaction-time snapshots survive later settings edits. Preserve legacy receipt notices without inventing historical data.
- Reuse existing dormancy-policy authorization and Administrator alert contracts as regression coverage for the separated platform actions and retained attention thresholds. Do not add a parallel testing framework.

## Out of Scope

- Application implementation as part of writing or publishing this specification.
- Giving the Administrator general Platform Settings access, technical governance, dormancy-policy authority, or email-test authority.
- Per-field approval, a new permission editor, temporary delegation, new roles, or arbitrary privilege assignment.
- Runtime timezone switching, currency conversion, multi-currency accounting, new receipt layouts, or rewriting historical receipts.
- Changing expiry-warning policy, platform safety limits, operational attention-threshold policy, or remote-access policy.
- New schema, speculative abstractions, and unrelated settings redesign.

## Further Notes

The user explicitly chose the complete function for simplicity, superseding the initially proposed split that reserved TIN, Currency symbol, and Timezone for the Super Administrator. The user then confirmed the final scope before requesting this specification.

This follows ADR-0001's separation of Administrator Store operations and Super Administrator technical governance, and ADR-0006's receipt-preservation policy. Timezone's existing lack of runtime effect and Currency symbol's display-only behavior are known limitations retained by this scope.
