# Interactive Workspace onboarding

Published as [GitHub issue #402](https://github.com/ZeachDev01/retailmind/issues/402), labelled `ready-for-agent`. Scope and testing boundary confirmed. Specification only; application implementation is not authorized by this task.

## Problem Statement

Administrator and Inventory Manager staff can find role-specific explanations in Help, but still need to connect those explanations to the controls used for real work. A tour of every feature would interrupt Store operations and overwhelm new staff.

A newly created Administrator needs personal orientation even when other staff have already completed shared Store setup. Current guidance suppression follows shared setup completion, so it can hide the invitation that this person needs. Staff also need to distinguish learning progress from operational records and shared setup confirmations.

## Solution

Extend existing Help and the optional first-visit overview with optional, task-focused walkthroughs on the existing workflow pages. Staff choose a topic, see one short explanation and highlighted control at a time, and use Back, Next, or Exit. They can continue later or replay through Help.

Provide Administrator paths for Get oriented and Daily oversight, and an Inventory Manager path for First useful tasks. Invite a new Administrator after Mandatory Password Change, independently of shared Store setup completion. Start guidance and Not now leave work unblocked, and automatic invitations do not repeat after a choice.

Walkthroughs accompany genuine work. Staff explicitly save or confirm every operational action. Existing configuration can be reviewed without creating duplicates. Personal completion reads Guidance finished and never confirms Store setup or claims an operational task was completed.

## User Stories

1. As a new Administrator, I want an invitation after Mandatory Password Change, so that I can learn my responsibilities once my account is ready.
2. As a new Administrator, I want an invitation even when shared Store setup is complete, so that another person's setup work does not hide my orientation.
3. As an Administrator, I want Start guidance on the invitation, so that I can begin orientation when ready.
4. As an Administrator, I want Not now on the invitation, so that I can start Store work immediately.
5. As a returning staff member, I want my invitation choice remembered, so that guidance does not repeatedly launch automatically.
6. As a staff member, I want to choose a task through Help, so that I can learn what I need at the time.
7. As a staff member, I want walkthrough access from the optional first-visit overview, so that I can move from orientation to a useful task.
8. As an Administrator, I want a Get oriented path, so that I can review the Store's operational configuration.
9. As an Administrator, I want Store Settings guidance, so that I can understand the settings within my authority.
10. As an Administrator, I want Register guidance, so that I can review or create named till records and receipt-width settings.
11. As an Administrator, I want Fiscal Period guidance, so that I can find and understand the existing period workflow.
12. As an Administrator, I want staff account guidance, so that I can create accounts using existing permitted procedures.
13. As an Administrator, I want Store calendar guidance, so that I can review trading days and closures.
14. As an Administrator, I want a Daily oversight path, so that I can learn the recurring review work for my Workspace.
15. As an Administrator, I want Needs Attention guidance, so that I can find operational exceptions requiring review.
16. As an Administrator, I want Store Reports guidance, so that I can inspect Store performance through existing reports.
17. As an Inventory Manager, I want a First useful tasks path, so that I can begin the core stock workflows.
18. As an Inventory Manager, I want replenishment guidance, so that I can review buying suggestions and their reasons.
19. As an Inventory Manager, I want Product setup guidance, so that I can use the existing Product procedure.
20. As an Inventory Manager, I want Stock Receiving guidance, so that I can record goods that actually arrived.
21. As a staff member with multiple roles, I want guidance to follow my active Workspace, so that suggested actions match my current authority.
22. As a staff member, I want guidance on the actual workflow page, so that I can connect explanations to real controls.
23. As a staff member, I want one action and one short explanation per step, so that I can focus on the next useful interaction.
24. As a staff member, I want optional Learn more, so that extra detail is available without overwhelming each step.
25. As a staff member, I want a spotlight around the current control, so that I can locate the relevant interaction.
26. As a staff member, I want gentle transitions and one attention pulse per appearing step, so that guidance draws attention without ongoing distraction.
27. As a staff member who prefers reduced motion, I want immediate static cues, so that I can follow the same instructions comfortably.
28. As a staff member, I want Back and Next, so that I can control the pace and revisit an explanation.
29. As a staff member, I want Exit at any step, so that guidance never traps me or blocks Store work.
30. As a staff member, I want step counts and a topic checklist, so that I can understand my learning progress.
31. As a returning staff member, I want Continue where you left off, so that I can resume after leaving a walkthrough.
32. As a staff member, I want replay through Help, so that I can refresh a previously learned task.
33. As a staff member, I want progress personal to my account and active Workspace, so that another person or role does not complete my learning.
34. As an Administrator, I want to review existing accounts, Registers, Fiscal Periods, and Store Settings, so that learning does not require duplicate setup.
35. As a staff member, I want every operational save and confirmation to remain my explicit action, so that guidance cannot create unintended records.
36. As a staff member, I want guidance to avoid practice records, so that Store data continues to represent real operations.
37. As a staff member, I want Guidance finished to acknowledge learning, so that completion does not imply that Store setup or operational work is complete.
38. As a staff member, I want other capabilities to remain available through Help, so that the first-release paths do not limit the rest of my Workspace.
39. As a keyboard user, I want reachable controls and predictable focus, so that I can operate and exit walkthroughs without a pointer.
40. As a staff member using a narrow screen or supported theme, I want readable guidance and visible highlighted controls, so that I can follow a task in my normal working conditions.

## Implementation Decisions

- Extend the authenticated Workspace shell, existing Help workflow catalogue, overview entry points, and existing permitted workflow pages. Reuse current design tokens, components, navigation, error feedback, and guidance conventions. Do not rebuild operational forms.
- Deliver exactly three first-release paths: Administrator Get oriented (Store Settings, Registers, Fiscal Periods, staff accounts, Store calendar); Administrator Daily oversight (Needs Attention, Store Reports); Inventory Manager First useful tasks (replenishment needs, Product setup, Stock Receiving). Other capabilities retain their Help access.
- Offer a new Administrator a nonblocking invitation after Mandatory Password Change and entry into the Administrator Workspace. Shared Store setup completion must not suppress this personal invitation. Start guidance begins the selected path; Not now dismisses it without repeated automatic launches. This explicitly revises the earlier shared-setup-based reminder policy for new Administrators.
- Keep personal invitation, walkthrough progress, and shared Store setup confirmations distinct. Reuse the existing account-and-Workspace guidance persistence boundary and extend it only as needed for continuation and topic progress. Preserve existing saved choices without silently re-enrolling existing users in an automatic tour.
- Persist personal progress across workflow navigation and later visits. Help offers Continue where you left off and replay. Switching Workspace changes the available topics and personal progress context; switching accounts must not expose another person's progress.
- Keep current overview completion distinct from completion of the new walkthrough topics. Finishing an overview alone must not imply completion of interactive guidance. Exact storage representation and migration mechanics remain implementation choices.
- Each step describes one action in concise language, highlights the relevant control, and may offer Learn more. Back, Next, and Exit remain under staff control. Navigation through explanations is not evidence that an operational save or setup confirmation occurred.
- Use gentle transitions, a spotlight, and one attention pulse when a step appears. Avoid looping animation. Honor reduced-motion preferences with immediate static cues and provide a brief completion checkmark with Guidance finished.
- Keep instructions and target controls usable on supported narrow screens and in supported light/dark themes. Provide keyboard access, visible focus, accessible step/status text, and predictable focus on entry and exit. Highlighting must not prevent the intended control interaction.
- Review existing configuration instead of requiring duplicate accounts, Registers, Fiscal Periods, or settings. Any changes remain optional, explicit, and subject to the existing workflow's validation and authorization.
- Guidance never submits operational forms, confirms shared setup, generates practice records, changes stock, or performs staff lifecycle actions automatically. Replenishment remains advisory and Stock Receiving records actual arrivals through the existing workflow.
- Enforce active Workspace permissions for every linked page and progress mutation using existing authentication and CSRF protections. Derive account and Workspace identity from the authenticated context. Walkthroughs grant no additional capability, consistent with ADR-0001 and ADR-0009.
- Register guidance covers named till records and receipt-width settings. Store Settings guidance stays within Administrator authority. Do not imply printer-driver installation, hardware setup, or access to Platform Settings.
- Account for the existing guidance data's participation in account lifecycle cleanup and backup identity reconciliation if persistence changes. Preserve those boundaries using current conventions rather than creating a second preferences system.
- No new public API, tutorial framework dependency, analytics system, or architecture decision is required by this specification. Select the smallest implementation that supports the agreed interactions.

## Testing Decisions

- The user confirmed the primary seam: authenticated browser-to-HTTP acceptance workflows with disposable databases, extending the existing Workspace onboarding and Administrator workflow guidance browser harnesses. Use existing Help, Workspace switching, theme fixtures, and workflow coverage as prior art rather than adding a walkthrough test framework.
- Test externally observable behavior and persisted outcomes, not private helpers, exact DOM structure, animation implementation, or table layout. Cover the authenticated shell, Help, progress handling, invitation eligibility, and the existing operational destinations through this shared boundary.
- Create a new Administrator, exercise Mandatory Password Change, and verify the invitation is offered afterward with shared Store setup both incomplete and complete. It must not bypass the password requirement or block ordinary work.
- Exercise Start guidance and Not now, then navigate, refresh, and log in again. Choices must persist without repeated automatic launches; Help must remain available. Existing skipped, deferred, and completed overview choices must not be silently reset.
- Cover all three paths and their listed topics. Verify each destination is permitted in the active Workspace, including a multi-role account switching between Administrator and Inventory Manager, and ensure other accounts' progress remains separate.
- Exercise Back, Next, Exit, topic checklists, step counts, continuation after navigation and later login, replay, and Guidance finished. Overview completion and topic progress must remain distinguishable. Replay must not restart automatic invitations or change shared Store setup.
- With existing configuration, finish review guidance without creating duplicate records. Compare operational records and setup confirmations before and after explanation-only navigation, exit, replay, and completion; they must remain unchanged.
- Where staff choose genuine operational work, use existing workflow tests to verify explicit save/confirmation, validation, and errors remain authoritative. Next must never submit a form, create a Product, receive stock, create staff, or confirm setup.
- Reject invalid-CSRF and forged account/Workspace progress submissions without changing anyone's progress or operational data. Verify inaccessible workflow destinations remain inaccessible independently of guidance visibility.
- Verify keyboard navigation, focus entry/exit, reachable controls, spotlight target usability, narrow-screen layout, supported themes, reduced-motion static cues, and the absence of looping distraction. Use existing rendered browser inspection patterns; supplement automated behavior checks with visual inspection where needed.
- Run implementation checks only against isolated test data. This planning task does not run application tests or claim walkthrough behavior is implemented.

## Out of Scope

- Application implementation, deployment, live Store changes, or operational fixture creation during specification work.
- Mandatory training, quizzes, an every-feature tour, repeated automatic launches, or new setup requirements.
- Cashier or Super Administrator onboarding and changes to role authority.
- New or rebuilt Product, receiving, account, Register, Fiscal Period, Store Settings, Store calendar, reporting, or attention workflows.
- Automatic form submission, practice records, duplicate setup, automatic purchasing, and changes to shared setup confirmation rules.
- Printer-driver or hardware installation, Platform Settings guidance, forecasting model or replenishment calculation redesign, and operational alert suppression.
- A new analytics platform, general tutorial authoring product, or speculative configuration options.

## Further Notes

- Synthesized from the consolidated interactive Workspace onboarding discussion dated 2026-11-02, Asia/Manila, and the accepted role-onboarding specification. The discussion records accepted interaction and motion choices; this spec preserves them without another product interview.
- This feature extends earlier onboarding guidance. It changes the new Administrator invitation policy independently of shared Store setup completion while retaining personal guidance/shared setup separation and existing operational procedures.
- Inspection found existing role-specific Help explanations, persisted account-and-Workspace overview choices, and browser coverage. Those are reuse opportunities, not evidence that interactive walkthroughs or the revised invitation policy already exist.
- Publication with ready-for-agent organizes future implementation; it does not authorize application changes during this task. Preserve unrelated working-tree changes.
