# Stock Issue clarification

Active workflow update � 2026-11-25: [Simplified Stock Issues](simplified-stock-issues.md) replaces active chat and adds direct Manager recording. Original report and verified quantities remain distinct; the implementation history below describes the earlier workflow.

## Conversation modal follow-up — 2026-11-25

Status: User confirmed the complete modal flow and authorized implementation on 2026-11-25. Implemented; verification below.

Confirmed in this follow-up:

- Move only the conversation history and reply composer into a dedicated modal in Cashier Stock Issues and Inventory Manager Pending Review. Report details and review actions remain in the main view.
- Conversations stay closed until the user selects Messages, with a message count. Do not automatically expand inline conversations.
- Bound the modal height and scroll only the message history; keep the reply composer and Send action visible.
- Preserve an unsent reply when closing and reopening its modal on the same page. Persistence across refresh or navigation is outside this change.
- Notification links select the exact report but do not automatically open the conversation modal. The user selects Messages to open it.

Existing permissions, waiting states, unread handling, and closed-report restrictions remain applicable.

Implementation:

- The shared conversation view now provides a Messages button and a native modal dialog, with product name, report number, status, history, reply composer and close action. The review form and report details remain outside it.
- Only message history scrolls. The bounded dialog keeps its header and composer visible, including compact landscape layouts. Light/dark tokens and 16px text entry are preserved.
- Closing uses the close button, Escape, or backdrop; focus returns to Messages. Outside-click and visibility listeners exist only while open. The existing shared scroll lock handles the background.
- Draft text stays in its textarea across close/reopen. A send response or send failure reopens the relevant modal, preserving failed text. Successful redirects carry an explicit conversation parameter, which is removed immediately after opening so refresh does not reopen it. Notification links carry only the report selection and leave Messages closed.
- Unread updates clear only when the modal is open and the page is visible, using the existing notification cutoff. JavaScript assets now have file-versioned URLs in both role views.

Verification:

- `npm run check` and `npm run check:contracts` passed. The existing duplicate `open-register` warning in Cashier Shifts remains.
- `RUN_DB_TESTS=1 node src/backend/tests/stock_issue_review_layout_browser_test.js` passed with authenticated Manager/Cashier flows: notification links stay closed and unread, explicit open marks read, sending preserves original quantities and stock, Escape restores focus, backdrop closes, drafts survive close/reopen, and a concurrent decision preserves a failed reply as read-only text.
- Long-history layout assertions cover both roles in light/dark themes at 390, 768 and 1440px, plus 390×844 and 640×360 Cashier viewports. Measurements verify only history scrolls while the textarea and Send remain within the modal; desktop and mobile captures were visually inspected.
- PHP syntax and JavaScript syntax checks passed. Test fixtures use disposable Stores only.
- TestSprite passed all 26 steps for explicit modal opening, long history scrolling, draft retention and return to review controls in both roles: [execution 4aba2ea6-86a1-414d-b198-3398d61c73f6](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/65e6ca9d-720b-4d74-abd2-60ab1fde8e83/test/4aba2ea6-86a1-414d-b198-3398d61c73f6). Responsive measurements come from local Playwright; TestSprite used its default viewport.
- Automatic approval review rejected removal of a temporary fixture folder with `blocked by policy`; temporary fixture storage may remain outside the repository. Both disposable databases from this verification were removed.

## Original clarification implementation

Status: User confirmed the complete workflow and authorized implementation on 2026-11-25. Implemented; verification below.

## Confirmed decisions

- The Inventory Manager and reporting Cashier clarify a Stock Issue through a separate conversation, with author and time visible for each message.
- Replies are separate from changes to report details. Sending a reply does not edit the original report.
- A Cashier reply to a manager's clarification request sends the issue back for manager review without requiring report edits or a separate resubmit action.
- The Cashier cannot change the reported quantity after submitting the report.
- All original report details become read-only after submission, including issue type, stock form, quantity, and reason. Replace the Cashier's Edit & Resubmit flow with clarification messages.
- Approval or rejection closes the conversation. Its full history remains readable, but neither participant may send more messages.
- Both participants may send messages before a final decision, even if the Manager has not yet asked a question. The interface identifies who needs to act next.
- Each new message notifies the other participant, links directly to the report, and is identifiable as unread until read.
- Existing returned reports become Awaiting cashier reply. Preserve their existing reviewer note as the manager's opening clarification, with its original attribution and time where available.
- Preserve the original reported quantity. The existing Verified Stock Issue Quantity workflow determines the quantity deducted on approval; see [Stock Issue verified quantity](stock-issue-verified-quantity.md).

## Agreed flow

1. The Cashier submits a Stock Issue. Original details are locked; the issue awaits manager review.
2. Either participant may send clarification messages. A manager message sets Awaiting cashier reply; a cashier message sets Awaiting manager review. Additional messages from the same participant retain that waiting state.
3. Each new message notifies the other participant. Chat and waiting-state changes never alter inventory.
4. The Manager approves using the verified affected quantity or rejects with a reason. Either decision closes the chat.
5. The existing Cashier cancellation action remains available while awaiting manager review. Cancellation also closes the chat.
6. Report details, conversation, and decision history remain readable after closure.

The user confirmed this complete flow before implementation.

## Previous behavior

- There is no separate conversation. The returned report exposes an edit form and the current reviewer note.
- Save Changes saves report edits; Resubmit for Review only changes status and ignores edits posted with that button. This can discard unsaved typed corrections.
- Existing revisions retain historical review notes, but the Cashier has no conversation timeline and the Manager's pending view omits prior review context.

## Implementation

- Reuse the append-only report revisions for messages. No new schema, dependency, or inventory deduction path is introduced. The existing stored `pending` and `returned` values now display as Awaiting manager review and Awaiting cashier reply.
- Both role views use the same inline Conversation disclosure, attributed message history, reply composer, and scoped stylesheet. Closed reports retain readable messages and show no composer. If closure races with a pending reply, the failed draft remains in a read-only textarea for copying. The Cashier edit/resubmit UI is removed; service compatibility seams explicitly refuse edits and bare resubmission. Original issue type is also immutable.
- Messages accept 1–4,000 UTF-8 characters. Ownership, active role membership, CSRF, and terminal states are checked on the server. Report locks serialize messages with decisions and cancellation; existing operation tokens prevent duplicate messages/notifications on retries.
- The message, waiting-state transition, audit revision, and recipient notifications commit atomically. A failed notification rolls back the message. Replies do not need an active Cashier shift; report submission and cancellation retain their existing shift/fiscal rules.
- Legacy return revisions appear as historical manager questions. Notes without a historical revision are displayed in place and preserved with their original author/time before a reply or final decision can replace them. Unknown historical timestamps are not invented in the conversation.
- Unread updates clear only for an opened, visible conversation. Reading uses the rendered notification cutoff so newer arrivals stay unread. A standard POST fallback works without JavaScript. Notification links open the exact report, including reports outside the initial history page.
- Conversation updates follow the application's server-rendered request/refresh pattern; there is no live push transport.

## Verification

- `php src/backend/tests/stock_issue_correction_contract.php` passed: ownership/read denial, locked details, message validation, duplicate tokens, unread snapshot races, notification rollback, legacy attribution, waiting states and closure.
- `php src/backend/tests/stock_issue_batch_deduction_test.php` passed, including unchanged inventory during clarification and the preserved original quantity alongside the manager's verified deduction.
- `RUN_DB_TESTS=1 node src/backend/tests/stock_issue_review_layout_browser_test.js` passed: real authenticated manager/cashier messages, deep links to older reports, replies after shift closure, escaped markup, unread updates, retained review behavior, and a final-decision race that preserves the unsent draft. Both role conversations were measured and visually inspected at 390, 768 and 1440px in light and dark themes.
- `npm run check` and `npm run check:contracts` passed. The view audit retains the existing duplicate `open-register` ID warning in Cashier Shifts.
- The older `stock_issue_contract.php` remains blocked by its missing open Fiscal Period, and `stock_issue_oversight_contract.php` by its missing `product_batches` fixture. These existing fixture limitations are also recorded in the verified-quantity spec; they are not evidence of a passing run.
- TestSprite passed all 20 steps: [execution 82be3a61-2084-4d69-bee6-ba0fba1ad9c9](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/72a0a127-5274-4807-8c41-bb72aaee29a5/test/82be3a61-2084-4d69-bee6-ba0fba1ad9c9). It verified the legacy manager question, Cashier reply, locked report, verified approval of 3 against original 5, and read-only retained conversation. The Store was disposable; no live Store records were modified. The temporary server was stopped and its disposable database and storage were removed.
