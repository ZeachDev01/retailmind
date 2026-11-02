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
                            'steps' => [],
                        ],
                        'store_calendar' => [
                            'id' => 'store_calendar',
                            'title' => 'Store calendar',
                            'path' => 'components/administrator/store_calendar.php',
                            'summary' => 'Review trading days and planned closures.',
                            'steps' => [],
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
                                    'target' => '#dashboardRefreshBtn',
                                    'action' => 'Refresh Store status',
                                    'description' => 'Check for the latest operational updates and shift balances across your Store registers.',
                                    'learn_more' => 'Store operations update periodically. Use Refresh to pull the most recent cashier shifts and reconciliations while keeping refresh paused when reviewing exceptions.',
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
                            'steps' => [],
                        ],
                        'products' => [
                            'id' => 'products',
                            'title' => 'Product setup',
                            'path' => 'components/inventory_management/products.php',
                            'summary' => 'Set up product details, opening stock, and stock rules.',
                            'steps' => [],
                        ],
                        'stock_receiving' => [
                            'id' => 'stock_receiving',
                            'title' => 'Stock Receiving',
                            'path' => 'components/report/stock_receiving.php',
                            'summary' => 'Record actual goods and quantities that physically arrived.',
                            'steps' => [],
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
        $stmt = $pdo->prepare('INSERT INTO workspace_guidance (user_id, workspace, active_topic, active_step) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE active_topic = VALUES(active_topic), active_step = VALUES(active_step)');
        $stmt->execute([$userId, $workspace, $topicId, $step]);
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
}
