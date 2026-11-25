<?php
// Shared POST actions for the Cashier and Inventory Manager conversation views.
$conversationWorkspace = (string)current_role();
$conversationViewerId = (int)$_SESSION['user_id'];
$conversationDetailId = (int)($_GET['detail'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conversation_action'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $conversationDetailId = (int)($_POST['adjustment_id'] ?? 0);
    try {
        if ($_POST['conversation_action'] === 'read') {
            $stockIssueService->markConversationRead($conversationDetailId, $conversationViewerId, $conversationWorkspace, (int)($_POST['read_upto'] ?? 0));
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                header('Content-Type: application/json');
                echo json_encode(['read' => true]);
                exit;
            }
        } elseif ($_POST['conversation_action'] === 'send') {
            if (!is_string($_POST['body'] ?? null)) throw new RuntimeException('Enter a clarification message.');
            $stockIssueService->sendMessage($conversationDetailId, $conversationViewerId, $conversationWorkspace,
                (string)($_POST['body'] ?? ''), (string)($_POST['operation_token'] ?? ''));
            $_SESSION['stock_issue_message'] = 'Reply sent. The other participant has been notified.';
        } else {
            throw new RuntimeException('Invalid conversation action.');
        }
        $conversationPath = $conversationWorkspace === 'cashier' ? 'cashier' : 'inventory_management';
        header('Location: ' . app_url("components/{$conversationPath}/stock_issues.php?detail={$conversationDetailId}&conversation={$conversationDetailId}#conversation-{$conversationDetailId}"), true, 303);
        exit;
    } catch (Throwable $e) {
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['read' => false]);
            exit;
        }
        $error = \App\Support\OperatorAlert::message($e, 'Messages are read-only. The Inventory Manager can decide the report directly.');
    }
}
$message = (string)($_SESSION['stock_issue_message'] ?? '');
unset($_SESSION['stock_issue_message']);
$unreadConversations = $stockIssueService->unreadConversations($conversationViewerId, $conversationWorkspace);
