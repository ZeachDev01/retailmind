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
                            'steps' => [],
                        ],
                        'fiscal_periods' => [
                            'id' => 'fiscal_periods',
                            'title' => 'Fiscal Periods',
                            'path' => 'components/administrator/fiscal_periods.php',
                            'summary' => 'Find and understand the existing period workflow.',
                            'steps' => [],
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
                            'steps' => [],
                        ],
                        'report_generation' => [
                            'id' => 'report_generation',
                            'title' => 'Store Reports',
                            'path' => 'components/report/report_generation.php',
                            'summary' => 'Inspect Store performance across sales and shifts.',
                            'steps' => [],
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
