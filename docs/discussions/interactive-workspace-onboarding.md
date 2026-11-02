# Interactive Workspace onboarding

Status: design consolidated; awaiting final shared-understanding confirmation. Implementation is not authorized by this discussion.

## Confirmed direction

On 2026-11-02, the user accepted these recommendations:

- Offer optional, task-focused walkthroughs teaching the first useful tasks. Keep other capabilities available through Help rather than requiring an every-feature tour.
- Let staff choose a task in Help, then show short instructions and subtle highlights on the actual workflow page. Provide Back, Next, Exit, and reduced-motion support.
- Give the Administrator and Inventory Manager separate walkthroughs matching the active Workspace and its permissions.
- Inventory Manager first-release tasks: review replenishment needs, set up a Product, and record Stock Receiving.
- Administrator guidance must also cover creating staff accounts, setting up a Register, managing Fiscal Periods, and changing Store Settings. Newly created Administrator accounts need an onboarding invitation; exact timing and sequence remain open.
- Walkthroughs accompany genuine work. Staff explicitly save or confirm every operational action; guidance never submits automatically or adds practice records.
- Offer walkthroughs through Help and the optional first-visit overview, without repeated automatic launches. Help supports replay.
- Invite a new Administrator after the mandatory temporary-password change, even when shared Store setup is already complete. Offer Start guidance and Not now without blocking work.
- Review existing accounts, Registers, Fiscal Periods, and Store Settings rather than requiring duplicate setup. Changes remain optional and user-initiated.
- Split Administrator guidance into Get oriented (Store Settings, Registers, Fiscal Periods, staff accounts, Store calendar) and Daily oversight (Needs Attention, Store Reports).
- Use gentle transitions, a spotlight around the current control, and one attention pulse when a step appears. Avoid looping distractions. Reduced-motion users receive immediate, static cues.
- Show step counts and a topic checklist. Support Continue where you left off and replay through Help. Progress is personal to the user and active Workspace. Completion reads Guidance finished, distinct from Store setup or operational completion.
- Each step contains one action, one short explanation, and optional Learn more. Highlight relevant controls while leaving every operational action to the user.

## Existing constraints

The [accepted onboarding specification](../specs/role-onboarding.md) separates personal guidance from shared Store setup confirmations. Walkthrough completion must not confirm setup or create operational records. Existing procedures and role boundaries remain authoritative.

The current Help implementation already has role-specific workflow links and expandable explanations. This proposal extends that guidance; it does not establish a need to rebuild the underlying forms.

Current code suppresses first-visit guidance once shared Store setup is complete. A new Administrator's personal onboarding invitation will need to be independent of that shared completion state. This is a proposed change to the existing reminder policy, not a claim that the behavior already exists.

## Consolidated interaction

1. After the mandatory password change, a new Administrator receives a nonblocking welcome invitation independent of shared Store setup completion.
2. Start guidance opens the chosen path. Not now dismisses the invitation without repeated automatic launches; Help remains available.
3. A topic opens the existing permitted workflow and explains its actual controls with short steps and purposeful motion. Existing configuration is reviewed rather than recreated for training.
4. Back, Next, and Exit keep movement under the user's control. Personal progress supports continuation and replay.
5. A brief completion checkmark and Guidance finished acknowledge learning, without claiming that operational work or shared Store setup is complete.

## First-release paths

| Workspace | Path | Topics |
| --- | --- | --- |
| Administrator | Get oriented | Store Settings, Registers, Fiscal Periods, staff accounts, Store calendar |
| Administrator | Daily oversight | Needs Attention, Store Reports |
| Inventory Manager | First useful tasks | Replenishment needs, Product setup, Stock Receiving |

Registers guidance covers the existing named till record and receipt-width settings; it does not promise printer-driver or hardware installation. Store Settings guidance stays within Administrator authority; Platform Settings remain outside this scope. Other capabilities remain accessible through Help.

## Final confirmation

All proposed interaction and motion choices above were accepted during the interview. Present the consolidated scope to the user for final shared-understanding confirmation before implementation. No application changes, operational records, or new setup requirements have been introduced by this discussion.

No ADR is warranted yet: no costly architectural trade-off has been settled. No glossary terms have been added; the accepted concepts use existing Workspace and Store setup language.
