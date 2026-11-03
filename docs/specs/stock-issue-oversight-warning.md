# Remove the spurious Stock Issue Oversight access warning

## Problem Statement

An Administrator sees the unwanted message "Stock issue oversight is limited to Administrators, Inventory Managers, and Cashiers viewing their own reports." while using Stock Issue Oversight. This makes an intended Administrator responsibility appear forbidden and interrupts access to Store loss history.

The user confirmed that Administrators should retain read-only Stock Issue Oversight and requested removal of this specific warning.

## Solution

Allow an authorized Administrator to open and use Stock Issue Oversight without the quoted warning. Identify and correct the actual source of the warning rather than suppressing all authorization errors. Preserve the existing read-only responsibility and role boundaries.

## User Stories

1. As an Administrator, I want to open Stock Issue Oversight without an incorrect access warning, so that I can monitor Store losses confidently.
2. As an Administrator, I want direct navigation to work, so that bookmarked oversight links remain useful.
3. As an Administrator, I want sidebar navigation to work without the warning, so that routine monitoring is uninterrupted.
4. As an Administrator, I want to filter Stock Issue reports without the warning, so that I can investigate relevant history.
5. As an Administrator, I want to open report details without the warning, so that I can inspect actors, decisions, and linked corrections.
6. As a person using the Administrator Workspace through an additional assigned role, I want my active Workspace recognized, so that my permitted oversight works consistently.
7. As an Administrator, I want oversight to remain read-only, so that inventory decisions stay with the Inventory Manager.
8. As an Inventory Manager, I want existing report review and inventory decisions preserved, so that removing the warning does not disrupt my work.
9. As a Cashier, I want access to remain limited to my own Stock Issue reports, so that other staff reports remain protected.
10. As a Store operator, I want unauthorized access to remain denied, so that warning cleanup does not expand privileges.
11. As an Administrator, I want genuine loading failures and missing-report errors to remain visible, so that I can distinguish failures from an empty report list.

## Implementation Decisions

- Reuse the Stock Issue service, Administrator oversight page, and existing Workspace authorization mechanisms.
- Trace the quoted exception through report listing, detail loading, active Workspace resolution, and navigation feedback before choosing the smallest complete fix.
- Current service inspection shows listing and detail authorization use a viewer role resolved from the account. Investigate its consistency with the active Workspace, especially for additional role assignments; this is a lead, not a confirmed cause.
- The existing browser alert test explicitly injects this message through an error query parameter. That test demonstrates feedback presentation, not that normal Administrator navigation reproduces the bug. Do not treat synthetic URL feedback as proof of a production authorization failure.
- Ensure authorized navigation does not generate or carry stale copies of this warning. Preserve unrelated query feedback and genuine error handling.
- Preserve ADR-0001 Store operational authority and ADR-0009 active Workspace boundaries. Assigned roles do not combine permissions.
- Preserve Administrator mutation denial, Inventory Manager decision authority, Cashier own-report scoping, immutable decisions, and linked correction history.
- No schema changes, new permissions, dependencies, or interface redesign are required.

## Testing Decisions

- Use the existing Stock Issue browser coverage as the primary external-behavior seam. The user confirmed this approach before publication.
- Verify successful report loading and absence of the quoted warning through direct and sidebar navigation, including filter and detail access.
- Cover an ordinary Administrator and an account with additional Administrator access operating in the Administrator Workspace, using isolated synthetic fixtures.
- Prefer visible report content and accessible page feedback assertions over source-text assertions or private-method tests.
- Extend or reuse existing Stock Issue oversight and Workspace authorization contracts to verify unauthorized access remains denied, Cashiers cannot view another Cashier's reports, and Administrators cannot mutate reports.
- Preserve coverage for genuine missing-report and loading errors. If the generic alert test still needs synthetic feedback, use a neutral message independent of this authorization regression.
- Prior art includes the existing Stock Issue alert browser test, oversight authorization contract, and Administrator mobile navigation coverage. Add no new testing seam unless existing coverage cannot reproduce the failure.

## Out of Scope

Removing Stock Issue Oversight, granting Administrator inventory mutation rights, granting Super Administrator Store operational authority, changing approval workflows, hiding all warnings, redesigning the page, altering notification thresholds, or changing historical Stock Issue data.

## Further Notes

The exact unwanted text is an authorization exception, not a standing informational banner. Establish a reproducer before implementation and document whether the trigger was role resolution, stale navigation feedback, or another confirmed cause. This spec requests correct authorized behavior, not deletion of the underlying security check.
