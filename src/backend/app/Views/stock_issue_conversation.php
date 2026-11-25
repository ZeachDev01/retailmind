<?php
$conversationId = (int)$conversationReport['adjustment_id'];
$conversationMessages = $stockIssueService->getConversation($conversationId, $conversationViewerId, $conversationWorkspace);
if (!$conversationMessages) return;
$conversationUnread = $unreadConversations[$conversationId] ?? ['unread_count' => 0, 'read_upto' => 0];
$conversationReopen = (int)($_GET['conversation'] ?? 0) === $conversationId;
?>
<div class="stock-conversation" id="conversation-<?= $conversationId ?>">
    <button type="button" class="btn btn-secondary conversation-trigger" aria-haspopup="dialog" aria-controls="conversation-dialog-<?= $conversationId ?>">
        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
        <span class="conversation-title">Message history <span class="conversation-count"><?= count($conversationMessages) ?></span></span>
        <?php if ((int)$conversationUnread['unread_count'] > 0): ?>
            <span class="conversation-unread" data-report-unread="<?= $conversationId ?>">Unread updates</span>
        <?php endif; ?>
    </button>
    <dialog class="conversation-dialog" id="conversation-dialog-<?= $conversationId ?>" aria-labelledby="conversation-title-<?= $conversationId ?>" <?= $conversationReopen ? 'data-reopen="true"' : '' ?>>
    <header class="conversation-header">
        <div>
            <h2 id="conversation-title-<?= $conversationId ?>" tabindex="-1">Messages · Report #<?= $conversationId ?></h2>
            <p><?= htmlspecialchars($conversationReport['product_name']) ?></p>
        </div>
        <button type="button" class="conversation-close" aria-label="Close messages"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="conversation-body">
        <p class="conversation-state"><?= htmlspecialchars(StockIssueService::statusLabel($conversationReport['status'])) ?><?= $conversationClosed ? ' · Conversation closed' : '' ?></p>
        <div class="conversation-history" tabindex="0" role="region" aria-label="Message history">
            <ol class="conversation-messages" aria-label="Clarification messages for report #<?= $conversationId ?>">
                <?php foreach ($conversationMessages as $conversationMessage): ?>
                    <li class="conversation-message <?= $conversationMessage['workspace'] === $conversationWorkspace ? 'is-self' : '' ?>">
                        <div class="conversation-message-meta">
                            <strong><?= htmlspecialchars($conversationMessage['actor_name']) ?></strong>
                            <span><?= $conversationMessage['workspace'] === 'cashier' ? 'Cashier' : 'Inventory Manager' ?></span>
                            <?php if (!empty($conversationMessage['created_at'])): ?>
                                <time datetime="<?= htmlspecialchars($conversationMessage['created_at']) ?>"><?= htmlspecialchars(format_display_datetime($conversationMessage['created_at'])) ?></time>
                            <?php else: ?>
                                <span>Time not recorded</span>
                            <?php endif; ?>
                        </div>
                        <p><?= htmlspecialchars($conversationMessage['body']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php if ((int)$conversationUnread['unread_count'] > 0): ?>
            <form method="POST" class="conversation-read-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="adjustment_id" value="<?= $conversationId ?>">
                <input type="hidden" name="conversation_action" value="read">
                <input type="hidden" name="read_upto" value="<?= (int)$conversationUnread['read_upto'] ?>">
                <button type="submit" class="btn btn-secondary">Mark updates as read</button>
            </form>
        <?php endif; ?>
        </div>
    </div>
    </dialog>
</div>
