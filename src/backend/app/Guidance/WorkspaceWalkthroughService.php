<?php

namespace App\Guidance;

use DomainException;
use InvalidArgumentException;
use PDO;

class WorkspaceWalkthroughService
{
    public static function paths(string $workspace): array
    {
        if ($workspace === 'admin') {
            return [
                'get_oriented' => [
                    'title' => 'Get oriented',
                    'description' => 'Review Store operational configuration within Administrator authority.',
                    'topics' => [
                        'store_settings' => [
                            'id' => 'store_settings',
                            'title' => 'Store Settings',
                            'path' => 'components/administrator/store_settings.php',
                            'summary' => 'Review Store identity, customer receipts, and operational attention thresholds.',
                            'steps' => [
                                [
                                    'target' => '#store_name',
                                    'action' => 'Review Store identity',
                                    'description' => 'Verify the Store name, business identifier, and contact info shown on customer receipts.',
                                    'learn_more' => 'These details appear on printed and digital customer receipts. Updates apply to future receipts without altering previously issued sales.',
                                ],
                                [
                                    'target' => '#currency_symbol',
                                    'action' => 'Check regional defaults',
                                    'description' => 'Confirm your currency symbol and IANA timezone for accurate timestamps and cash records.',
                                    'learn_more' => 'RetailMind uses your Store timezone for daily sales totals, shift closing, and fiscal periods. Currency symbols format totals across POS and reports.',
                                ],
                                [
                                    'target' => '#receipt_footer',
                                    'action' => 'Review receipt footer note',
                                    'description' => 'Customise return policies, customer service notes, or thank-you messages printed at the bottom of receipts.',
                                    'learn_more' => 'Keep return terms or Store announcements concise so printed receipts remain compact and readable.',
                                ],
                                [
                                    'target' => '#cash_variance_amount',
                                    'action' => 'Inspect attention thresholds',
                                    'description' => 'Review review limits for cash variances, overdue stock issues, and fiscal period warnings.',
                                    'learn_more' => 'Thresholds flag operational exceptions on your dashboard. Platform safety limits apply automatically to protect Store integrity.',
                                ],
                            ],
                        ],
                        'registers' => [
                            'id' => 'registers',
                            'title' => 'Registers',
                            'path' => 'components/administrator/registers.php',
                            'summary' => 'Review or create named till records and receipt-width settings.',
                            'steps' => [
                                [
                                    'target' => '#register-name',
                                    'action' => 'Review named till records',
                                    'description' => 'Name each physical checkout counter or till to match its location in the Store, such as Front Counter.',
                                    'learn_more' => 'Named till records track Cashier Shifts and identify which till issued a receipt. Hardware drivers and printer installation remain managed by your operating system, outside RetailMind.',
                                ],
                                [
                                    'target' => '#register-paper-width',
                                    'action' => 'Configure receipt width',
                                    'description' => 'Choose standard 80 mm or compact 58 mm rolls to match the paper width used by this till\'s receipt printer.',
                                    'learn_more' => 'RetailMind formats sales slips to fit the selected width. Platform Settings and operating system print queues are not required or modified here.',
                                ],
                                [
                                    'target' => '#store-registers-heading',
                                    'action' => 'Review existing till availability',
                                    'description' => 'Inspect configured registers, availability for new Cashier Shifts, and paper widths without creating duplicate records.',
                                    'learn_more' => 'You can rename tills, adjust receipt widths, or disable a register from future shifts anytime. Existing Cashier Shifts and sales records preserve their original till history.',
                                ],
                            ],
                        ],
                        'fiscal_periods' => [
                            'id' => 'fiscal_periods',
                            'title' => 'Fiscal Periods',
                            'path' => 'components/administrator/fiscal_periods.php',
                            'summary' => 'Find and understand the existing period workflow.',
                            'steps' => [
                                [
                                    'target' => '#period_name',
                                    'action' => 'Name fiscal accounting periods',
                                    'description' => 'Identify accounting windows such as monthly or quarterly periods to group Store trading records.',
                                    'learn_more' => 'Fiscal periods establish formal accounting boundaries for sales and inventory auditing. They do not alter opening hours or trading days defined in your Store calendar.',
                                ],
                                [
                                    'target' => '#start_date',
                                    'action' => 'Set period date range',
                                    'description' => 'Specify start and end dates to define which trading days and transactions fall within this accounting window.',
                                    'learn_more' => 'Transactions are evaluated against open periods. Start dates must precede end dates, and dates remain subject to Store Operations authorization rules.',
                                ],
                                [
                                    'target' => '#period-list-title',
                                    'action' => 'Review period workflow and status',
                                    'description' => 'Inspect existing periods across open, closed, and locked statuses without creating duplicate periods.',
                                    'learn_more' => 'Open periods allow active trading. Closing prevents new entries while preserving records for review. Locking permanently seals the period for compliance under existing authorization rules.',
                                ],
                            ],
                        ],
                        'user_manager' => [
                            'id' => 'user_manager',
                            'title' => 'Staff accounts',
                            'path' => 'components/user_manager/user_manager.php',
                            'summary' => 'Maintain operational staff accounts and role assignments.',
                            'steps' => [
                                [
                                    'target' => '.users-table',
                                    'action' => 'Review staff accounts',
                                    'description' => 'Inspect existing staff accounts, active roles, and security statuses before creating new credentials.',
                                    'learn_more' => 'Staff accounts use fixed operational role templates (Cashier, Inventory Manager, Administrator). Super Administrator accounts and protected identities cannot be modified here.',
                                ],
                                [
                                    'target' => '#openUserModal',
                                    'action' => 'Add staff accounts',
                                    'description' => 'Use Add Staff User to create credentials with fixed role assignments and initial passwords.',
                                    'learn_more' => 'New staff must complete Mandatory Password Change on their first login before accessing their Workspace. Administrator minimums ensure access is never locked out.',
                                ],
                                [
                                    'target' => '.action-cell, .open-user-drawer',
                                    'action' => 'Inspect staff lifecycle and access',
                                    'description' => 'Review account status, password requirements, or manage permitted staff without making unwanted changes.',
                                    'learn_more' => 'Role changes and account status updates follow strict authorization boundaries. Active Administrator accounts cannot be disabled if it would breach Administrator minimum safeguards.',
                                ],
                            ],
                        ],
                        'store_calendar' => [
                            'id' => 'store_calendar',
                            'title' => 'Store calendar',
                            'path' => 'components/administrator/store_calendar.php',
                            'summary' => 'Review trading days and planned closures.',
                            'steps' => [
                                [
                                    'target' => '.calendar-scroll, .calendar-grid',
                                    'action' => 'Review trading days',
                                    'description' => 'Inspect the monthly calendar to review scheduled open trading days, closures, and sales provenance.',
                                    'learn_more' => 'Calendar statuses reflect your operational schedule. Completed product sales automatically establish open trading days without rewriting past history.',
                                ],
                                [
                                    'target' => '#weekly-schedule-btn',
                                    'action' => 'Configure weekly schedule',
                                    'description' => 'Review regular weekly open and closed days. Weekly schedules establish baseline trading days across the Store.',
                                    'learn_more' => 'Date exceptions and recorded sales override weekly baselines. Calendar configuration reflects operating hours and does not qualify forecasting models or generate customer sales history.',
                                ],
                                [
                                    'target' => '#record-date-btn',
                                    'action' => 'Record planned closures and holidays',
                                    'description' => 'Use Record Status to set planned closures, holidays, or date-specific trading exceptions.',
                                    'learn_more' => 'Recording holidays and exceptional closures helps operational planning. Forecasting models qualify over time as genuine customer demand accumulates through actual transactions, not calendar entries.',
                                ],
                            ],
                        ],
                    ],
                ],
                'daily_oversight' => [
                    'title' => 'Daily oversight',
                    'description' => 'Learn recurring review workflows for your Workspace.',
                    'topics' => [
                        'dashboard' => [
                            'id' => 'dashboard',
                            'title' => 'Needs Attention',
                            'path' => 'components/administrator/dashboard.php',
                            'summary' => 'Find operational exceptions requiring review.',
                            'steps' => [
                                [
                                    'target' => '#store-attention-section',
                                    'action' => 'Review Needs Attention exceptions',
                                    'description' => 'Inspect active operational warnings such as cash variances, unresolved GCash exchanges, and overdue stock issues.',
                                    'learn_more' => 'The queue flags conditions that need administrator review before trading periods close. Reviewing an item opens its permitted operational workflow without automatically acknowledging or clearing alerts.',
                                ],
                                [
                                    'target' => '.dashboard-freshness',
                                    'action' => 'Check Store status freshness',
                                    'description' => 'Check when operational updates and shift balances were last loaded.',
                                    'learn_more' => 'Store operations update periodically. The Updated timestamp shows when the current view was generated. Automatic updates pause while you interact with forms.',
                                ],
                                [
                                    'target' => '#store-status-section',
                                    'action' => 'Inspect operational indicators',
                                    'description' => 'Monitor headline status for cash variances, pending stock issues, and fiscal periods.',
                                    'learn_more' => 'Headline cards show at-a-glance status and provide direct access to shift reports, stock issues, and fiscal periods within your administrator authority.',
                                ],
                            ],
                        ],
                        'report_generation' => [
                            'id' => 'report_generation',
                            'title' => 'Store Reports',
                            'path' => 'components/report/report_generation.php',
                            'summary' => 'Inspect Store performance across sales and shifts.',
                            'steps' => [
                                [
                                    'target' => '#report',
                                    'action' => 'Select Store report',
                                    'description' => 'Choose which operational report to view, such as Gross Profit, Sales Summary, or Cashier Shifts.',
                                    'learn_more' => 'Different reports show distinct operational views of your Store. Selecting a report changes the available date ranges and filter options.',
                                ],
                                [
                                    'target' => '#from',
                                    'action' => 'Set trading date filters',
                                    'description' => 'Filter report activity by date range, such as today, this week, or custom trading dates.',
                                    'learn_more' => 'Filter parameters restrict report results to the selected timeframe. Reports use your configured Store timezone for consistent daily cutoffs.',
                                ],
                                [
                                    'target' => '#product_id',
                                    'action' => 'Narrow by product or category',
                                    'description' => 'Optionally filter report records to specific catalog items or product categories.',
                                    'learn_more' => 'Filtering by product or category helps isolate performance or inventory movement for specific items without changing catalog settings.',
                                ],
                                [
                                    'target' => '.report-run-btn',
                                    'action' => 'Run Store report',
                                    'description' => 'Generate the report for the selected parameters without altering any underlying transaction records.',
                                    'learn_more' => 'Running a report is an inquiry action. It reads current operational data without modifying Store totals or closing periods.',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($workspace === 'inventory_manager') {
            return [
                'first_useful_tasks' => [
                    'title' => 'First useful tasks',
                    'description' => 'Begin core stock and replenishment workflows.',
                    'topics' => [
                        'replenishment_queue' => [
                            'id' => 'replenishment_queue',
                            'title' => 'Replenishment needs',
                            'path' => 'components/inventory_management/replenishment_queue.php',
                            'summary' => 'Review buying recommendations and their reasons.',
                            'steps' => [
                                [
                                    'target' => '#queue-search',
                                    'action' => 'Filter and search products',
                                    'description' => 'Search by product name or SKU to inspect specific items needing replenishment.',
                                    'learn_more' => 'Filters help focus daily buying review on urgent items. Searching does not place orders or alter stock records.',
                                ],
                                [
                                    'target' => '#queue-stock',
                                    'action' => 'Check stock status',
                                    'description' => 'Filter by Out of stock, Low stock, or Needs replenishment to prioritize orders.',
                                    'learn_more' => 'Status reflects current shelf quantities against calculated reorder thresholds. The queue highlights items at risk of stocking out.',
                                ],
                                [
                                    'target' => '#queue-products',
                                    'action' => 'Review buying suggestions',
                                    'description' => 'Inspect recommended buying quantities, calculation basis, and underlying stock rules or forecast details.',
                                    'learn_more' => 'Suggestions are strictly advisory based on stock rules and Store knowledge. Guidance never purchases goods or changes stock automatically; actual deliveries are recorded in Stock Receiving.',
                                ],
                                [
                                    'target' => '#queue-actions-trigger',
                                    'action' => 'Export or recalculate',
                                    'description' => 'Download a PDF order summary for supplier communication or refresh recommendations with updated sales.',
                                    'learn_more' => 'Use the PDF summary as a purchasing reference for suppliers. Reviewing or exporting recommendations never submits purchase orders.',
                                ],
                            ],
                        ],
                        'products' => [
                            'id' => 'products',
                            'title' => 'Product setup',
                            'path' => 'components/inventory_management/products.php',
                            'summary' => 'Set up product details, opening stock, and stock rules.',
                            'steps' => [
                                [
                                    'target' => '#product-search',
                                    'action' => 'Review existing catalog',
                                    'description' => 'Search the existing catalog to check current items and avoid creating duplicate products.',
                                    'learn_more' => 'Before adding new items, confirm whether the product or variant already exists. Existing product configurations can be reviewed without creating duplicates.',
                                ],
                                [
                                    'target' => '#product-add-trigger',
                                    'action' => 'Open Add Product procedure',
                                    'description' => 'Use the Add Product procedure to register items using the standard Store workflow.',
                                    'learn_more' => 'RetailMind has one single product creation procedure. Guidance walks you through real controls without creating practice records.',
                                ],
                                [
                                    'target' => '#product-name',
                                    'action' => 'Enter product details',
                                    'description' => 'Provide the product name, brand, category, and variant label to establish distinct catalog identity.',
                                    'learn_more' => 'Accurate product details ensure clear recognition at checkout and consistent grouping across Store inventory reports.',
                                ],
                                [
                                    'target' => '#product-receiving-unit',
                                    'action' => 'Define package and unit sizes',
                                    'description' => 'Configure base selling units and receiving package sizes to convert bulk shipments into shelf stock.',
                                    'learn_more' => 'Specifying receiving package sizes ensures supplier deliveries convert into individual sellable units during Stock Receiving.',
                                ],
                                [
                                    'target' => '#initial-stock-quantity',
                                    'action' => 'Enter counted opening stock',
                                    'description' => 'Record physically counted on-hand inventory when setting up new products. Leave as zero if waiting for delivery.',
                                    'learn_more' => 'Opening stock should represent verified physical counts. New deliveries arriving later are entered through Stock Receiving, not opening stock edits.',
                                ],
                                [
                                    'target' => '[name="reorder_level"]',
                                    'action' => 'Set replenishment stock rules',
                                    'description' => 'Define reorder levels, safety stock, and minimum order quantities to trigger automatic buying suggestions.',
                                    'learn_more' => 'Stock rules drive replenishment queue suggestions. Setting thresholds ensures items appear in buying recommendations before shelves run empty.',
                                ],
                            ],
                        ],
                        'stock_receiving' => [
                            'id' => 'stock_receiving',
                            'title' => 'Stock Receiving',
                            'path' => 'components/report/stock_receiving.php',
                            'summary' => 'Record actual goods and quantities that physically arrived.',
                            'steps' => [
                                [
                                    'target' => '#product_search_scan',
                                    'action' => 'Find arriving products',
                                    'description' => 'Scan barcodes or search by SKU and product name to select items from the incoming shipment.',
                                    'learn_more' => 'Products must exist in the catalog before receiving. Use the Product setup workflow if an arriving item is new.',
                                ],
                                [
                                    'target' => '#delivery_title',
                                    'action' => 'Review delivery lines',
                                    'description' => 'Inspect selected items and enter verified quantities that physically arrived at the Store.',
                                    'learn_more' => 'Only record goods that physically arrived. Stock Receiving does not establish customer demand history; customer sales accumulate through checkout transactions.',
                                ],
                                [
                                    'target' => '#supplier',
                                    'action' => 'Record supplier and invoice',
                                    'description' => 'Enter the supplier name and invoice or delivery receipt number to document shipment source.',
                                    'learn_more' => 'Documenting supplier invoice numbers provides an audit trail for invoice reconciliation and supplier payment records.',
                                ],
                                [
                                    'target' => '#receiving_next',
                                    'action' => 'Save received shipment',
                                    'description' => 'Review quantities and explicitly save the receiving entry to update Store inventory counts.',
                                    'learn_more' => 'Stock is only added to inventory upon your explicit save. Guidance navigation never submits receipts or alters stock levels automatically.',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [];
    }

    public static function findTopic(string $workspace, string $topicId): ?array
    {
        $paths = self::paths($workspace);
        foreach ($paths as $pathKey => $path) {
            if (isset($path['topics'][$topicId])) {
                $topic = $path['topics'][$topicId];
                $topic['path_key'] = $pathKey;
                $topic['path_title'] = $path['title'];
                return $topic;
            }
        }
        return null;
    }

    public static function getProgress(PDO $pdo, int $userId, string $workspace): array
    {
        try {
            $stmt = $pdo->prepare('SELECT status, active_topic, active_step, completed_topics, invitation_status FROM workspace_guidance WHERE user_id = ? AND workspace = ?');
            $stmt->execute([$userId, $workspace]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [
                    'status' => null,
                    'active_topic' => null,
                    'active_step' => 0,
                    'completed_topics' => [],
                    'invitation_status' => null,
                ];
            }
            $completed = [];
            if (!empty($row['completed_topics'])) {
                $decoded = json_decode((string)$row['completed_topics'], true);
                if (is_array($decoded)) {
                    $completed = array_values(array_filter($decoded, 'is_string'));
                }
            }
            return [
                'status' => $row['status'] ?? null,
                'active_topic' => $row['active_topic'] ?: null,
                'active_step' => (int)($row['active_step'] ?? 0),
                'completed_topics' => $completed,
                'invitation_status' => $row['invitation_status'] ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => null,
                'active_topic' => null,
                'active_step' => 0,
                'completed_topics' => [],
                'invitation_status' => null,
            ];
        }
    }

    public static function validateMutation(int $authUserId, string $authWorkspace, array $input): array
    {
        if (isset($input['user_id']) && (int)$input['user_id'] !== $authUserId) {
            throw new DomainException('Forged user identity rejected.');
        }
        if (isset($input['workspace']) && (string)$input['workspace'] !== $authWorkspace) {
            throw new DomainException('Forged workspace rejected.');
        }

        $action = (string)($input['walkthrough_action'] ?? 'step');
        if (!in_array($action, ['start', 'step', 'complete', 'exit', 'replay'], true)) {
            throw new InvalidArgumentException('Invalid walkthrough action.');
        }

        $topicId = (string)($input['walkthrough_topic'] ?? '');
        $topic = self::findTopic($authWorkspace, $topicId);
        if (!$topic) {
            throw new InvalidArgumentException('Invalid guidance topic.');
        }

        $step = isset($input['walkthrough_step']) ? (int)$input['walkthrough_step'] : 0;
        $stepCount = count($topic['steps']);
        if ($stepCount > 0 && ($step < 0 || $step >= $stepCount)) {
            throw new InvalidArgumentException('Invalid guidance step.');
        }

        return [
            'action' => $action,
            'topic_id' => $topicId,
            'step' => $step,
            'topic' => $topic,
        ];
    }

    public static function updateStep(PDO $pdo, int $userId, string $workspace, string $topicId, int $step): void
    {
        $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, active_topic, active_step) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE active_topic = VALUES(active_topic), active_step = VALUES(active_step)');
        $stmt->execute([$userId, $workspace, $topicId, $step]);
    }

    public static function exitWalkthrough(PDO $pdo, int $userId, string $workspace, string $topicId, int $step): void
    {
        self::updateStep($pdo, $userId, $workspace, $topicId, $step);
    }

    public static function completeTopic(PDO $pdo, int $userId, string $workspace, string $topicId): void
    {
        $current = self::getProgress($pdo, $userId, $workspace);
        $completed = $current['completed_topics'];
        if (!in_array($topicId, $completed, true)) {
            $completed[] = $topicId;
        }
        $json = json_encode($completed, JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, active_topic, active_step, completed_topics) VALUES (?, ?, NULL, 0, ?) ON DUPLICATE KEY UPDATE active_topic = NULL, active_step = 0, completed_topics = VALUES(completed_topics)');
        $stmt->execute([$userId, $workspace, $json]);
    }

    public static function replayTopic(PDO $pdo, int $userId, string $workspace, string $topicId): void
    {
        $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, active_topic, active_step) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE active_topic = VALUES(active_topic), active_step = 0');
        $stmt->execute([$userId, $workspace, $topicId]);
    }

    public static function inviteAdministrator(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('SELECT status, invitation_status FROM workspace_guidance WHERE user_id = ? AND workspace = ?');
        $stmt->execute([$userId, 'admin']);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, invitation_status) VALUES (?, ?, ?)');
            $stmt->execute([$userId, 'admin', 'invited']);
        } elseif (empty($existing['invitation_status']) && empty($existing['status'])) {
            $stmt = $pdo->prepare('UPDATE workspace_guidance SET invitation_status = ? WHERE user_id = ? AND workspace = ?');
            $stmt->execute(['invited', $userId, 'admin']);
        }
    }

    public static function recordInvitationChoice(PDO $pdo, int $userId, string $workspace, string $choice): void
    {
        if (!in_array($choice, ['started', 'dismissed'], true)) {
            throw new InvalidArgumentException('Invalid invitation choice.');
        }
        $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, invitation_status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE invitation_status = VALUES(invitation_status)');
        $stmt->execute([$userId, $workspace, $choice]);
    }
}
