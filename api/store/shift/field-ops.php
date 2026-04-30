<?php
/**
 * シフト現場運用 API
 *
 * GET    ?action=dashboard&store_id=xxx&date=YYYY-MM-DD
 * GET    ?action=open-shifts&store_id=xxx
 * GET    ?action=my-tasks&store_id=xxx&date=YYYY-MM-DD
 * POST   ?action=create-open-shift
 * POST   ?action=apply-open-shift
 * POST   ?action=save-skill
 * POST   ?action=assign-staff-skill
 * POST   ?action=save-position-skill
 * POST   ?action=add-task
 * POST   ?action=attendance-followup
 * PATCH  ?action=handle-open-shift
 * PATCH  ?action=task-status
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/audit-log.php';

function sfo_valid_date($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function sfo_valid_time($time)
{
    return is_string($time) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time);
}

function sfo_valid_code($code, $max = 20)
{
    return is_string($code) && preg_match('/^[a-z0-9_-]{1,' . (int)$max . '}$/', $code);
}

function sfo_time_to_minutes($time)
{
    $time = (string)$time;
    if (strlen($time) < 5) {
        return 0;
    }
    return ((int)substr($time, 0, 2)) * 60 + (int)substr($time, 3, 2);
}

function sfo_duration_minutes($startTime, $endTime)
{
    $s = sfo_time_to_minutes($startTime);
    $e = sfo_time_to_minutes($endTime);
    if ($e < $s) {
        $e += 1440;
    }
    return max(0, $e - $s);
}

function sfo_overlap_minutes($aStart, $aEnd, $bStart, $bEnd)
{
    $as = sfo_time_to_minutes($aStart);
    $ae = sfo_time_to_minutes($aEnd);
    $bs = sfo_time_to_minutes($bStart);
    $be = sfo_time_to_minutes($bEnd);
    if ($ae < $as) $ae += 1440;
    if ($be < $bs) $be += 1440;
    return max(0, min($ae, $be) - max($as, $bs));
}

function sfo_display_name($row)
{
    return ($row['display_name'] ?? '') !== '' ? $row['display_name'] : ($row['username'] ?? '');
}

function sfo_decode_change_detail($value)
{
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function sfo_role_allowed($pdo, $tenantId, $storeId, $role)
{
    if ($role === null || $role === '') {
        return true;
    }
    if (!sfo_valid_code((string)$role, 20)) {
        return false;
    }
    if (in_array($role, ['kitchen', 'hall'], true)) {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM shift_work_positions
         WHERE tenant_id = ? AND store_id = ? AND code = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $role]);
    return (bool)$stmt->fetch();
}

function sfo_user_in_store($pdo, $tenantId, $storeId, $userId)
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.display_name, u.username, u.role
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$storeId, $userId, $tenantId]);
    return $stmt->fetch();
}

function sfo_load_assignment($pdo, $tenantId, $storeId, $assignmentId)
{
    $stmt = $pdo->prepare(
        'SELECT sa.*, u.display_name, u.username
         FROM shift_assignments sa
         JOIN users u ON u.id = sa.user_id
         WHERE sa.id = ? AND sa.tenant_id = ? AND sa.store_id = ?
         LIMIT 1'
    );
    $stmt->execute([$assignmentId, $tenantId, $storeId]);
    return $stmt->fetch();
}

function sfo_load_staff($pdo, $tenantId, $storeId)
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.display_name, u.username, us.hourly_rate
         FROM users u
         JOIN user_stores us ON us.user_id = u.id AND us.store_id = ?
         WHERE u.tenant_id = ? AND u.is_active = 1 AND u.role = ?
         ORDER BY u.display_name, u.username'
    );
    $stmt->execute([$storeId, $tenantId, 'staff']);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['display_name'] = sfo_display_name($row);
        $row['hourly_rate'] = $row['hourly_rate'] !== null ? (int)$row['hourly_rate'] : null;
    }
    unset($row);
    return $rows;
}

function sfo_load_settings($pdo, $tenantId, $storeId)
{
    $stmt = $pdo->prepare(
        'SELECT submission_deadline_day, default_break_minutes, default_hourly_rate, target_labor_cost_ratio
         FROM shift_settings
         WHERE tenant_id = ? AND store_id = ?'
    );
    $stmt->execute([$tenantId, $storeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'submission_deadline_day' => 5,
            'default_break_minutes' => 60,
            'default_hourly_rate' => null,
            'target_labor_cost_ratio' => 30.0,
        ];
    }
    return [
        'submission_deadline_day' => (int)$row['submission_deadline_day'],
        'default_break_minutes' => (int)$row['default_break_minutes'],
        'default_hourly_rate' => $row['default_hourly_rate'] !== null ? (int)$row['default_hourly_rate'] : null,
        'target_labor_cost_ratio' => isset($row['target_labor_cost_ratio']) ? (float)$row['target_labor_cost_ratio'] : 30.0,
    ];
}

function sfo_load_skills($pdo, $tenantId, $storeId)
{
    $stmt = $pdo->prepare(
        'SELECT id, code, label, is_active, sort_order
         FROM shift_skill_tags
         WHERE tenant_id = ? AND store_id = ?
         ORDER BY is_active DESC, sort_order, label'
    );
    $stmt->execute([$tenantId, $storeId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['is_active'] = (int)$row['is_active'];
        $row['sort_order'] = (int)$row['sort_order'];
    }
    unset($row);
    return $rows;
}

function sfo_skill_exists($pdo, $tenantId, $storeId, $skillCode)
{
    if ($skillCode === null || $skillCode === '') {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM shift_skill_tags
         WHERE tenant_id = ? AND store_id = ? AND code = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $skillCode]);
    return (bool)$stmt->fetch();
}

function sfo_user_has_skill($pdo, $tenantId, $storeId, $userId, $skillCode)
{
    if ($skillCode === null || $skillCode === '') {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM shift_staff_skill_tags
         WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND skill_code = ? AND level > 0
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $storeId, $userId, $skillCode]);
    return (bool)$stmt->fetch();
}

function sfo_has_overlap_assignment($pdo, $tenantId, $userId, $date, $startTime, $endTime)
{
    $stmt = $pdo->prepare(
        'SELECT id, start_time, end_time
         FROM shift_assignments
         WHERE tenant_id = ? AND user_id = ? AND shift_date = ?'
    );
    $stmt->execute([$tenantId, $userId, $date]);
    foreach ($stmt->fetchAll() as $row) {
        if (sfo_overlap_minutes($row['start_time'], $row['end_time'], $startTime, $endTime) > 0) {
            return true;
        }
    }
    return false;
}

function sfo_calc_labor_cost($row, $defaultRate)
{
    $rate = $row['hourly_rate'] !== null ? (int)$row['hourly_rate'] : $defaultRate;
    if ($rate === null) {
        return null;
    }
    $minutes = max(0, sfo_duration_minutes($row['start_time'], $row['end_time']) - (int)$row['break_minutes']);
    return (int)round(($minutes / 60) * $rate);
}

function sfo_load_open_shifts($pdo, $tenantId, $storeId, $fromDate, $userId = null)
{
    $stmt = $pdo->prepare(
        'SELECT os.*, u.display_name AS created_by_name, u.username AS created_by_username
         FROM shift_open_shifts os
         LEFT JOIN users u ON u.id = os.created_by
         WHERE os.tenant_id = ? AND os.store_id = ?
           AND os.shift_date >= ?
         ORDER BY os.shift_date, os.start_time'
    );
    $stmt->execute([$tenantId, $storeId, $fromDate]);
    $openShifts = $stmt->fetchAll();
    if (count($openShifts) === 0) {
        return [];
    }

    $ids = array_column($openShifts, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmtApps = $pdo->prepare(
        "SELECT app.*, u.display_name, u.username
         FROM shift_open_shift_applications app
         JOIN users u ON u.id = app.user_id
         WHERE app.tenant_id = ? AND app.store_id = ?
           AND app.open_shift_id IN ({$ph})
         ORDER BY app.created_at"
    );
    $stmtApps->execute(array_merge([$tenantId, $storeId], $ids));
    $appsByOpen = [];
    foreach ($stmtApps->fetchAll() as $app) {
        $app['display_name'] = sfo_display_name($app);
        if (!isset($appsByOpen[$app['open_shift_id']])) {
            $appsByOpen[$app['open_shift_id']] = [];
        }
        $appsByOpen[$app['open_shift_id']][] = $app;
    }

    foreach ($openShifts as &$row) {
        $row['break_minutes'] = (int)$row['break_minutes'];
        $row['created_by_name'] = sfo_display_name([
            'display_name' => $row['created_by_name'] ?? '',
            'username' => $row['created_by_username'] ?? '',
        ]);
        $row['applications'] = isset($appsByOpen[$row['id']]) ? $appsByOpen[$row['id']] : [];
        $row['my_application_status'] = null;
        if ($userId !== null) {
            for ($i = 0; $i < count($row['applications']); $i++) {
                if ($row['applications'][$i]['user_id'] === $userId) {
                    $row['my_application_status'] = $row['applications'][$i]['status'];
                    break;
                }
            }
        }
    }
    unset($row);
    return $openShifts;
}

function sfo_dashboard($pdo, $tenantId, $storeId, $date)
{
    $settings = sfo_load_settings($pdo, $tenantId, $storeId);
    $staff = sfo_load_staff($pdo, $tenantId, $storeId);
    $staffById = [];
    foreach ($staff as $s) {
        $staffById[$s['id']] = $s;
    }

    $stmtAssignments = $pdo->prepare(
        'SELECT sa.*, u.display_name, u.username, us.hourly_rate
         FROM shift_assignments sa
         JOIN users u ON u.id = sa.user_id
         LEFT JOIN user_stores us ON us.user_id = sa.user_id AND us.store_id = sa.store_id
         WHERE sa.tenant_id = ? AND sa.store_id = ? AND sa.shift_date = ?
         ORDER BY sa.start_time, u.display_name'
    );
    $stmtAssignments->execute([$tenantId, $storeId, $date]);
    $assignments = $stmtAssignments->fetchAll();
    foreach ($assignments as &$asg) {
        $asg['display_name'] = sfo_display_name($asg);
        $asg['break_minutes'] = (int)$asg['break_minutes'];
        $asg['hourly_rate'] = $asg['hourly_rate'] !== null ? (int)$asg['hourly_rate'] : null;
        $asg['confirmation_required'] = (
            isset($asg['status']) && $asg['status'] !== 'confirmed' &&
            !empty($asg['confirmation_reset_at'])
        );
        $asg['confirmation_reset_detail'] = sfo_decode_change_detail($asg['confirmation_reset_detail'] ?? null);
    }
    unset($asg);

    $openShifts = sfo_load_open_shifts($pdo, $tenantId, $storeId, $date, null);

    $stmtTpl = $pdo->prepare(
        'SELECT id, code, label, day_part, default_role_type, sort_order
         FROM shift_task_templates
         WHERE tenant_id = ? AND store_id = ? AND is_active = 1
         ORDER BY sort_order, label'
    );
    $stmtTpl->execute([$tenantId, $storeId]);
    $taskTemplates = $stmtTpl->fetchAll();

    $stmtTasks = $pdo->prepare(
        'SELECT ta.*, u.display_name, u.username, tt.day_part
         FROM shift_task_assignments ta
         JOIN users u ON u.id = ta.user_id
         LEFT JOIN shift_task_templates tt ON tt.id = ta.task_template_id
         WHERE ta.tenant_id = ? AND ta.store_id = ? AND ta.task_date = ?
         ORDER BY ta.status, ta.created_at'
    );
    $stmtTasks->execute([$tenantId, $storeId, $date]);
    $taskAssignments = $stmtTasks->fetchAll();
    foreach ($taskAssignments as &$task) {
        $task['display_name'] = sfo_display_name($task);
    }
    unset($task);

    $skills = sfo_load_skills($pdo, $tenantId, $storeId);
    $stmtStaffSkills = $pdo->prepare(
        'SELECT sst.user_id, sst.skill_code, sst.level, st.label, u.display_name, u.username
         FROM shift_staff_skill_tags sst
         JOIN shift_skill_tags st ON st.tenant_id = sst.tenant_id AND st.store_id = sst.store_id AND st.code = sst.skill_code
         JOIN users u ON u.id = sst.user_id
         WHERE sst.tenant_id = ? AND sst.store_id = ? AND sst.level > 0
         ORDER BY u.display_name, st.sort_order, st.label'
    );
    $stmtStaffSkills->execute([$tenantId, $storeId]);
    $staffSkills = $stmtStaffSkills->fetchAll();
    foreach ($staffSkills as &$ss) {
        $ss['display_name'] = sfo_display_name($ss);
        $ss['level'] = (int)$ss['level'];
    }
    unset($ss);

    $stmtRules = $pdo->prepare(
        'SELECT prs.*, st.label AS skill_label
         FROM shift_position_required_skills prs
         LEFT JOIN shift_skill_tags st ON st.tenant_id = prs.tenant_id AND st.store_id = prs.store_id AND st.code = prs.skill_code
         WHERE prs.tenant_id = ? AND prs.store_id = ?
         ORDER BY prs.role_type, st.sort_order'
    );
    $stmtRules->execute([$tenantId, $storeId]);
    $positionSkillRules = $stmtRules->fetchAll();
    foreach ($positionSkillRules as &$rule) {
        $rule['required_count'] = (int)$rule['required_count'];
    }
    unset($rule);

    $skillMap = [];
    foreach ($staffSkills as $ss2) {
        if (!isset($skillMap[$ss2['user_id']])) {
            $skillMap[$ss2['user_id']] = [];
        }
        $skillMap[$ss2['user_id']][$ss2['skill_code']] = true;
    }

    $skillGaps = [];
    foreach ($positionSkillRules as $rule2) {
        $scheduled = 0;
        $qualified = 0;
        foreach ($assignments as $asg2) {
            if ((string)$asg2['role_type'] !== (string)$rule2['role_type']) {
                continue;
            }
            $scheduled++;
            if (isset($skillMap[$asg2['user_id']][$rule2['skill_code']])) {
                $qualified++;
            }
        }
        if ($scheduled > 0 && $qualified < (int)$rule2['required_count']) {
            $skillGaps[] = [
                'role_type' => $rule2['role_type'],
                'skill_code' => $rule2['skill_code'],
                'skill_label' => $rule2['skill_label'] ?: $rule2['skill_code'],
                'required_count' => (int)$rule2['required_count'],
                'qualified_count' => $qualified,
                'message' => ($rule2['skill_label'] ?: $rule2['skill_code']) . ' が不足しています',
            ];
        }
    }

    $breakPlan = [];
    foreach ($assignments as $asg3) {
        $duration = sfo_duration_minutes($asg3['start_time'], $asg3['end_time']);
        $requiredBreak = 0;
        if ($duration > 480) {
            $requiredBreak = 60;
        } elseif ($duration > 360) {
            $requiredBreak = 45;
        }
        if ($requiredBreak > 0 && (int)$asg3['break_minutes'] < $requiredBreak) {
            $startMin = sfo_time_to_minutes($asg3['start_time']);
            $suggestMin = $startMin + (int)floor($duration / 2);
            $breakPlan[] = [
                'assignment_id' => $asg3['id'],
                'user_id' => $asg3['user_id'],
                'display_name' => $asg3['display_name'],
                'start_time' => substr($asg3['start_time'], 0, 5),
                'end_time' => substr($asg3['end_time'], 0, 5),
                'break_minutes' => (int)$asg3['break_minutes'],
                'required_break_minutes' => $requiredBreak,
                'suggested_time' => sprintf('%02d:%02d', floor(($suggestMin % 1440) / 60), $suggestMin % 60),
            ];
        }
    }

    $stmtAvail = $pdo->prepare(
        'SELECT target_date, user_id
         FROM shift_availabilities
         WHERE tenant_id = ? AND store_id = ?
           AND target_date BETWEEN ? AND ?'
    );
    $deadlineStart = date('Y-m-d');
    $deadlineEnd = date('Y-m-d', strtotime($deadlineStart . ' +7 days'));
    $stmtAvail->execute([$tenantId, $storeId, $deadlineStart, $deadlineEnd]);
    $availMap = [];
    foreach ($stmtAvail->fetchAll() as $av) {
        $availMap[$av['target_date'] . '_' . $av['user_id']] = true;
    }
    $deadlineGaps = [];
    $cur = new DateTime($deadlineStart);
    $end = new DateTime($deadlineEnd);
    while ($cur <= $end) {
        $d = $cur->format('Y-m-d');
        $missing = [];
        foreach ($staff as $sf) {
            if (!isset($availMap[$d . '_' . $sf['id']])) {
                $missing[] = ['user_id' => $sf['id'], 'display_name' => $sf['display_name']];
            }
        }
        if (count($missing) > 0) {
            $deadlineGaps[] = [
                'target_date' => $d,
                'missing_count' => count($missing),
                'missing_staff' => array_slice($missing, 0, 8),
            ];
        }
        $cur->modify('+1 day');
    }

    $laborCost = 0;
    $laborCostKnown = true;
    foreach ($assignments as $asg4) {
        $cost = sfo_calc_labor_cost($asg4, $settings['default_hourly_rate']);
        if ($cost === null) {
            $laborCostKnown = false;
        } else {
            $laborCost += $cost;
        }
    }
    $stmtRevenue = $pdo->prepare(
        'SELECT COALESCE(SUM(o.total_amount), 0) AS revenue
         FROM orders o
         JOIN stores s ON s.id = o.store_id AND s.tenant_id = ?
         WHERE o.store_id = ? AND o.status = ?
           AND DATE(o.created_at) = ?'
    );
    $stmtRevenue->execute([$tenantId, $storeId, 'paid', $date]);
    $revenue = (int)$stmtRevenue->fetchColumn();
    $laborRatio = ($revenue > 0 && $laborCostKnown) ? round(($laborCost / $revenue) * 100, 1) : null;
    $laborLanding = [
        'date' => $date,
        'revenue' => $revenue,
        'scheduled_labor_cost' => $laborCostKnown ? $laborCost : null,
        'labor_cost_ratio' => $laborRatio,
        'target_labor_cost_ratio' => $settings['target_labor_cost_ratio'],
        'rate_missing' => !$laborCostKnown,
    ];

    $stmtHistory = $pdo->prepare(
        'SELECT user_id, COUNT(*) AS assignment_count
         FROM shift_assignments
         WHERE tenant_id = ? AND store_id = ?
           AND shift_date BETWEEN ? AND ?
         GROUP BY user_id'
    );
    $stmtHistory->execute([$tenantId, $storeId, date('Y-m-d', strtotime($date . ' -90 days')), date('Y-m-d', strtotime($date . ' -1 day'))]);
    $historyMap = [];
    foreach ($stmtHistory->fetchAll() as $h) {
        $historyMap[$h['user_id']] = (int)$h['assignment_count'];
    }
    $experienceRisks = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $slotStart = $hour * 60;
        $slotEnd = ($hour + 1) * 60;
        $scheduled = [];
        $experienced = 0;
        foreach ($assignments as $asg5) {
            if (sfo_overlap_minutes($asg5['start_time'], $asg5['end_time'], sprintf('%02d:00', $hour), sprintf('%02d:00', ($hour + 1) % 24)) <= 0) {
                continue;
            }
            $scheduled[] = $asg5['display_name'];
            if (isset($historyMap[$asg5['user_id']]) && $historyMap[$asg5['user_id']] >= 5) {
                $experienced++;
            }
        }
        if (count($scheduled) > 0 && $experienced === 0) {
            $experienceRisks[] = [
                'time' => sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24),
                'scheduled_count' => count($scheduled),
                'staff' => array_slice($scheduled, 0, 6),
                'message' => '経験者がいない時間帯です',
            ];
        }
    }

    // UIの「変更未確認」は shift_assignments.confirmation_reset_* から表示する。
    // 監査ログ全般を混ぜると交代申請・スキル更新まで「変更差分」に見えてしまう。
    $changeLogs = [];

    $suggestions = [];
    foreach ($openShifts as $os) {
        if ($os['status'] === 'open' && count($os['applications']) === 0) {
            $suggestions[] = [
                'level' => 'warning',
                'type' => 'open_shift_no_application',
                'message' => $os['shift_date'] . ' ' . substr($os['start_time'], 0, 5) . '-' . substr($os['end_time'], 0, 5) . ' の募集に応募がありません',
            ];
        }
    }
    foreach ($breakPlan as $bp) {
        $suggestions[] = [
            'level' => 'warning',
            'type' => 'break_missing',
            'message' => $bp['display_name'] . ' の休憩が不足しています（必要' . $bp['required_break_minutes'] . '分）',
        ];
    }
    foreach ($skillGaps as $gap) {
        $suggestions[] = [
            'level' => 'warning',
            'type' => 'skill_gap',
            'message' => $gap['message'] . '（必要' . $gap['required_count'] . ' / 予定' . $gap['qualified_count'] . '）',
        ];
    }
    $pendingTasks = 0;
    foreach ($taskAssignments as $ta) {
        if ($ta['status'] === 'pending') {
            $pendingTasks++;
        }
    }
    if ($pendingTasks > 0) {
        $suggestions[] = [
            'level' => 'info',
            'type' => 'task_pending',
            'message' => '未完了の担当作業が ' . $pendingTasks . ' 件あります',
        ];
    }
    if ($laborRatio !== null && $laborRatio > $settings['target_labor_cost_ratio']) {
        $suggestions[] = [
            'level' => 'warning',
            'type' => 'labor_ratio_high',
            'message' => '今日の予定人件費率が目標を超えています（予定' . $laborRatio . '% / 目標' . $settings['target_labor_cost_ratio'] . '%）',
        ];
    }
    if ($laborLanding['rate_missing']) {
        $suggestions[] = [
            'level' => 'info',
            'type' => 'hourly_rate_missing',
            'message' => '時給未設定のスタッフがいるため、人件費着地は参考表示できません',
        ];
    }
    if (count($experienceRisks) > 0) {
        $suggestions[] = [
            'level' => 'warning',
            'type' => 'experience_risk',
            'message' => '経験者がいない時間帯があります',
        ];
    }
    if (count($deadlineGaps) > 0 && $deadlineGaps[0]['missing_count'] > 0) {
        $suggestions[] = [
            'level' => 'info',
            'type' => 'availability_missing',
            'message' => '直近の希望未提出があります。未提出者へ声かけしてください',
        ];
    }

    return [
        'date' => $date,
        'settings' => $settings,
        'staff' => $staff,
        'assignments' => $assignments,
        'open_shifts' => $openShifts,
        'task_templates' => $taskTemplates,
        'task_assignments' => $taskAssignments,
        'skills' => $skills,
        'staff_skills' => $staffSkills,
        'position_skill_rules' => $positionSkillRules,
        'skill_gaps' => $skillGaps,
        'break_plan' => $breakPlan,
        'deadline_gaps' => $deadlineGaps,
        'labor_landing' => $laborLanding,
        'experience_risks' => $experienceRisks,
        'change_logs' => $changeLogs,
        'action_suggestions' => array_slice($suggestions, 0, 12),
    ];
}

start_auth_session();
handle_preflight();
$method = require_method(['GET', 'POST', 'PATCH']);
$user = require_auth();

$pdo = get_db();
$tenantId = $user['tenant_id'];

if (!check_plan_feature($pdo, $tenantId, 'shift_management')) {
    json_error('PLAN_REQUIRED', 'この機能は現在の契約では利用できません', 403);
}

$storeId = require_store_param();
require_store_access($storeId);
$action = $_GET['action'] ?? ($method === 'GET' ? 'dashboard' : '');

if ($method === 'GET' && $action === 'dashboard') {
    require_role('manager');
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!sfo_valid_date($date)) {
        json_error('INVALID_DATE', 'date は YYYY-MM-DD 形式で指定してください', 400);
    }
    json_response(sfo_dashboard($pdo, $tenantId, $storeId, $date));
}

if ($method === 'GET' && $action === 'open-shifts') {
    require_role('staff');
    json_response([
        'open_shifts' => sfo_load_open_shifts($pdo, $tenantId, $storeId, date('Y-m-d'), $user['user_id']),
    ]);
}

if ($method === 'GET' && $action === 'my-tasks') {
    require_role('staff');
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!sfo_valid_date($date)) {
        json_error('INVALID_DATE', 'date は YYYY-MM-DD 形式で指定してください', 400);
    }
    $endDate = date('Y-m-d', strtotime($date . ' +7 days'));
    $stmt = $pdo->prepare(
        'SELECT ta.*, tt.day_part
         FROM shift_task_assignments ta
         LEFT JOIN shift_task_templates tt ON tt.id = ta.task_template_id
         WHERE ta.tenant_id = ? AND ta.store_id = ? AND ta.user_id = ?
           AND ta.task_date BETWEEN ? AND ?
         ORDER BY ta.task_date, ta.status, ta.created_at'
    );
    $stmt->execute([$tenantId, $storeId, $user['user_id'], $date, $endDate]);
    json_response(['tasks' => $stmt->fetchAll()]);
}

if ($method === 'POST' && $action === 'attendance-followup') {
    require_role('manager');
    $body = get_json_body();
    $assignmentId = $body['shift_assignment_id'] ?? '';
    $status = $body['status'] ?? '';
    $note = isset($body['note']) ? trim((string)$body['note']) : null;

    if ($assignmentId === '' || !in_array($status, ['contacted', 'late_notice', 'absent'], true)) {
        json_error('INVALID_REQUEST', 'shift_assignment_id と status を指定してください', 400);
    }

    $assignment = sfo_load_assignment($pdo, $tenantId, $storeId, $assignmentId);
    if (!$assignment) {
        json_error('NOT_FOUND', 'シフトが見つかりません', 404);
    }

    $followupId = generate_uuid();
    $absenceRequestId = null;
    $needsFill = false;

    try {
        $pdo->beginTransaction();

        $stmtFollowup = $pdo->prepare(
            'INSERT INTO shift_attendance_followups
                (id, tenant_id, store_id, shift_assignment_id, user_id, followup_date, status, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                note = VALUES(note),
                created_by = VALUES(created_by)'
        );
        $stmtFollowup->execute([
            $followupId,
            $tenantId,
            $storeId,
            $assignmentId,
            $assignment['user_id'],
            $assignment['shift_date'],
            $status,
            $note,
            $user['user_id'],
        ]);

        if ($status === 'absent') {
            $needsFill = true;
            $stmtExisting = $pdo->prepare(
                'SELECT *
                 FROM shift_swap_requests
                 WHERE tenant_id = ? AND store_id = ? AND shift_assignment_id = ?
                   AND request_type = ? AND status IN (\'pending\', \'approved\')
                 ORDER BY FIELD(status, \'approved\', \'pending\')
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmtExisting->execute([$tenantId, $storeId, $assignmentId, 'absence']);
            $existing = $stmtExisting->fetch();

            if ($existing) {
                $absenceRequestId = $existing['id'];
                if ($existing['status'] === 'pending') {
                    $stmtApprove = $pdo->prepare(
                        'UPDATE shift_swap_requests
                         SET status = \'approved\', replacement_user_id = NULL,
                             response_note = ?, responded_by = ?
                         WHERE id = ? AND tenant_id = ? AND store_id = ?'
                    );
                    $stmtApprove->execute([
                        $note !== null && $note !== '' ? $note : '未打刻から欠勤にしました',
                        $user['user_id'],
                        $absenceRequestId,
                        $tenantId,
                        $storeId,
                    ]);
                }
            } else {
                $absenceRequestId = generate_uuid();
                $stmtAbsence = $pdo->prepare(
                    'INSERT INTO shift_swap_requests
                        (id, tenant_id, store_id, shift_assignment_id, request_type,
                         requester_user_id, candidate_user_id, replacement_user_id,
                         status, reason, response_note, responded_by)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, \'approved\', ?, ?, ?)'
                );
                $stmtAbsence->execute([
                    $absenceRequestId,
                    $tenantId,
                    $storeId,
                    $assignmentId,
                    'absence',
                    $assignment['user_id'],
                    '未打刻から欠勤処理',
                    $note !== null && $note !== '' ? $note : '未打刻から欠勤にしました',
                    $user['user_id'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[field-ops] attendance followup failed: ' . $e->getMessage(), 3, POSLA_PHP_ERROR_LOG);
        json_error('SERVER_ERROR', '未打刻対応の更新に失敗しました', 500);
    }

    write_audit_log(
        $pdo,
        $user,
        $storeId,
        'shift_attendance_followup',
        'shift_assignment',
        $assignmentId,
        $assignment,
        ['status' => $status, 'note' => $note, 'absence_request_id' => $absenceRequestId]
    );

    json_response([
        'updated' => true,
        'status' => $status,
        'assignment_id' => $assignmentId,
        'absence_request_id' => $absenceRequestId,
        'needs_fill' => $needsFill,
    ]);
}

if ($method === 'POST' && $action === 'create-open-shift') {
    require_role('manager');
    $body = get_json_body();
    $date = $body['shift_date'] ?? '';
    $startTime = $body['start_time'] ?? '';
    $endTime = $body['end_time'] ?? '';
    $breakMinutes = isset($body['break_minutes']) ? (int)$body['break_minutes'] : 0;
    $roleType = isset($body['role_type']) && $body['role_type'] !== '' ? (string)$body['role_type'] : null;
    $skillCode = isset($body['required_skill_code']) && $body['required_skill_code'] !== '' ? (string)$body['required_skill_code'] : null;
    $note = isset($body['note']) ? trim((string)$body['note']) : null;

    if (!sfo_valid_date($date)) {
        json_error('INVALID_DATE', 'shift_date は YYYY-MM-DD 形式で指定してください', 400);
    }
    if (!sfo_valid_time($startTime) || !sfo_valid_time($endTime) || $startTime >= $endTime) {
        json_error('INVALID_TIME', '開始/終了時刻を正しく指定してください', 400);
    }
    if ($breakMinutes < 0 || $breakMinutes > 240) {
        json_error('INVALID_BREAK', '休憩時間は 0〜240 分で指定してください', 400);
    }
    if (!sfo_role_allowed($pdo, $tenantId, $storeId, $roleType)) {
        json_error('INVALID_ROLE', '持ち場は登録済みのものを指定してください', 400);
    }
    if ($skillCode !== null && (!sfo_valid_code($skillCode, 20) || !sfo_skill_exists($pdo, $tenantId, $storeId, $skillCode))) {
        json_error('INVALID_SKILL', '必要スキルは登録済みのものを指定してください', 400);
    }

    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_open_shifts
            (id, tenant_id, store_id, shift_date, start_time, end_time, break_minutes, role_type, required_skill_code, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $date, $startTime, $endTime, $breakMinutes, $roleType, $skillCode, $note, $user['user_id']]);
    write_audit_log($pdo, $user, $storeId, 'shift_open_shift_create', 'shift_open_shift', $id, null, $body);
    json_response(['id' => $id], 201);
}

if ($method === 'POST' && $action === 'apply-open-shift') {
    require_role('staff');
    $body = get_json_body();
    $openShiftId = $body['open_shift_id'] ?? '';
    $note = isset($body['note']) ? trim((string)$body['note']) : null;
    if ($openShiftId === '') {
        json_error('MISSING_OPEN_SHIFT', 'open_shift_id が必要です', 400);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM shift_open_shifts
         WHERE id = ? AND tenant_id = ? AND store_id = ? AND status = ?
         LIMIT 1'
    );
    $stmt->execute([$openShiftId, $tenantId, $storeId, 'open']);
    $openShift = $stmt->fetch();
    if (!$openShift) {
        json_error('NOT_FOUND', '募集シフトが見つかりません', 404);
    }
    if ($openShift['shift_date'] < date('Y-m-d')) {
        json_error('PAST_SHIFT', '過去日の募集には応募できません', 400);
    }
    if (!sfo_user_in_store($pdo, $tenantId, $storeId, $user['user_id'])) {
        json_error('USER_NOT_IN_STORE', 'この店舗のスタッフだけが応募できます', 403);
    }
    if ($openShift['required_skill_code'] && !sfo_user_has_skill($pdo, $tenantId, $storeId, $user['user_id'], $openShift['required_skill_code'])) {
        json_error('SKILL_REQUIRED', 'この募集には必要スキルが設定されています', 400);
    }
    if (sfo_has_overlap_assignment($pdo, $tenantId, $user['user_id'], $openShift['shift_date'], $openShift['start_time'], $openShift['end_time'])) {
        json_error('SHIFT_CONFLICT', '同じ時間帯に既存シフトがあります', 400);
    }

    $stmtExisting = $pdo->prepare(
        'SELECT id, status FROM shift_open_shift_applications
         WHERE tenant_id = ? AND open_shift_id = ? AND user_id = ?
         LIMIT 1'
    );
    $stmtExisting->execute([$tenantId, $openShiftId, $user['user_id']]);
    $existing = $stmtExisting->fetch();
    if ($existing) {
        if ($existing['status'] === 'approved') {
            json_error('ALREADY_APPROVED', 'この募集は承認済みです', 400);
        }
        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_open_shift_applications
             SET status = ?, note = ?, response_note = NULL, responded_by = NULL
             WHERE id = ? AND tenant_id = ?'
        );
        $stmtUpdate->execute(['applied', $note, $existing['id'], $tenantId]);
        $appId = $existing['id'];
    } else {
        $appId = generate_uuid();
        $stmtInsert = $pdo->prepare(
            'INSERT INTO shift_open_shift_applications
                (id, tenant_id, store_id, open_shift_id, user_id, note)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmtInsert->execute([$appId, $tenantId, $storeId, $openShiftId, $user['user_id'], $note]);
    }
    write_audit_log($pdo, $user, $storeId, 'shift_open_shift_apply', 'shift_open_shift_application', $appId, null, ['open_shift_id' => $openShiftId]);
    json_response(['applied' => true, 'id' => $appId]);
}

if ($method === 'PATCH' && $action === 'handle-open-shift') {
    require_role('manager');
    $body = get_json_body();
    $applicationId = $body['application_id'] ?? '';
    $decision = $body['decision'] ?? ($body['action'] ?? '');
    $responseNote = isset($body['response_note']) ? trim((string)$body['response_note']) : null;
    if ($applicationId === '' || !in_array($decision, ['approve', 'reject'], true)) {
        json_error('INVALID_REQUEST', 'application_id と decision を指定してください', 400);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT app.*, os.shift_date, os.start_time, os.end_time, os.break_minutes,
                    os.role_type, os.status AS open_status, os.required_skill_code
             FROM shift_open_shift_applications app
             JOIN shift_open_shifts os ON os.id = app.open_shift_id
             WHERE app.id = ? AND app.tenant_id = ? AND app.store_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$applicationId, $tenantId, $storeId]);
        $app = $stmt->fetch();
        if (!$app) {
            $pdo->rollBack();
            json_error('NOT_FOUND', '応募が見つかりません', 404);
        }
        if ($decision === 'reject') {
            $stmtReject = $pdo->prepare(
                'UPDATE shift_open_shift_applications
                 SET status = ?, response_note = ?, responded_by = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmtReject->execute(['rejected', $responseNote, $user['user_id'], $applicationId, $tenantId]);
            $pdo->commit();
            write_audit_log($pdo, $user, $storeId, 'shift_open_shift_reject', 'shift_open_shift_application', $applicationId, $app, ['response_note' => $responseNote]);
            json_response(['rejected' => true]);
        }
        if ($app['open_status'] !== 'open') {
            $pdo->rollBack();
            json_error('OPEN_SHIFT_CLOSED', 'この募集は既に締め切られています', 400);
        }
        if (sfo_has_overlap_assignment($pdo, $tenantId, $app['user_id'], $app['shift_date'], $app['start_time'], $app['end_time'])) {
            $pdo->rollBack();
            json_error('SHIFT_CONFLICT', '応募スタッフに同時間帯の既存シフトがあります', 400);
        }

        $assignmentId = generate_uuid();
        $stmtAssign = $pdo->prepare(
            'INSERT INTO shift_assignments
                (id, tenant_id, store_id, user_id, shift_date, start_time, end_time, break_minutes, role_type, status, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtAssign->execute([
            $assignmentId,
            $tenantId,
            $storeId,
            $app['user_id'],
            $app['shift_date'],
            $app['start_time'],
            $app['end_time'],
            (int)$app['break_minutes'],
            $app['role_type'],
            'published',
            $responseNote,
            $user['user_id'],
        ]);

        $stmtApprove = $pdo->prepare(
            'UPDATE shift_open_shift_applications
             SET status = ?, response_note = ?, responded_by = ?
             WHERE id = ? AND tenant_id = ?'
        );
        $stmtApprove->execute(['approved', $responseNote, $user['user_id'], $applicationId, $tenantId]);

        $stmtRejectOthers = $pdo->prepare(
            'UPDATE shift_open_shift_applications
             SET status = ?, response_note = ?, responded_by = ?
             WHERE tenant_id = ? AND open_shift_id = ? AND id != ? AND status = ?'
        );
        $stmtRejectOthers->execute(['rejected', '別応募を承認済み', $user['user_id'], $tenantId, $app['open_shift_id'], $applicationId, 'applied']);

        $stmtClose = $pdo->prepare(
            'UPDATE shift_open_shifts
             SET status = ?, approved_by = ?, created_assignment_id = ?
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtClose->execute(['filled', $user['user_id'], $assignmentId, $app['open_shift_id'], $tenantId, $storeId]);
        $pdo->commit();
        write_audit_log($pdo, $user, $storeId, 'shift_open_shift_approve', 'shift_open_shift_application', $applicationId, $app, ['assignment_id' => $assignmentId]);
        json_response(['approved' => true, 'assignment_id' => $assignmentId]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('SERVER_ERROR', '募集シフトの処理に失敗しました', 500);
    }
}

if ($method === 'POST' && $action === 'save-skill') {
    require_role('manager');
    $body = get_json_body();
    $code = isset($body['code']) ? trim((string)$body['code']) : '';
    $label = isset($body['label']) ? trim((string)$body['label']) : '';
    $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 100;
    $isActive = isset($body['is_active']) ? ((int)$body['is_active'] ? 1 : 0) : 1;
    if (!sfo_valid_code($code, 20) || $label === '' || mb_strlen($label) > 50) {
        json_error('INVALID_SKILL', 'スキルコードと表示名を正しく指定してください', 400);
    }
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_skill_tags
            (id, tenant_id, store_id, code, label, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = VALUES(is_active), sort_order = VALUES(sort_order)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $code, $label, $isActive, $sortOrder]);
    write_audit_log($pdo, $user, $storeId, 'shift_skill_save', 'shift_skill', $code, null, $body);
    json_response(['saved' => true]);
}

if ($method === 'POST' && $action === 'assign-staff-skill') {
    require_role('manager');
    $body = get_json_body();
    $userId = $body['user_id'] ?? '';
    $skillCode = $body['skill_code'] ?? '';
    $level = isset($body['level']) ? (int)$body['level'] : 1;
    if ($userId === '' || !sfo_valid_code($skillCode, 20) || !sfo_skill_exists($pdo, $tenantId, $storeId, $skillCode)) {
        json_error('INVALID_REQUEST', 'スタッフとスキルを指定してください', 400);
    }
    if (!sfo_user_in_store($pdo, $tenantId, $storeId, $userId)) {
        json_error('USER_NOT_IN_STORE', '指定スタッフはこの店舗に所属していません', 400);
    }
    if ($level <= 0) {
        $stmt = $pdo->prepare(
            'DELETE FROM shift_staff_skill_tags
             WHERE tenant_id = ? AND store_id = ? AND user_id = ? AND skill_code = ?'
        );
        $stmt->execute([$tenantId, $storeId, $userId, $skillCode]);
        write_audit_log($pdo, $user, $storeId, 'shift_staff_skill_delete', 'shift_staff_skill', $userId, null, $body);
        json_response(['deleted' => true]);
    }
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_staff_skill_tags
            (id, tenant_id, store_id, user_id, skill_code, level)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE level = VALUES(level)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $userId, $skillCode, min(9, $level)]);
    write_audit_log($pdo, $user, $storeId, 'shift_staff_skill_save', 'shift_staff_skill', $userId, null, $body);
    json_response(['saved' => true]);
}

if ($method === 'POST' && $action === 'save-position-skill') {
    require_role('manager');
    $body = get_json_body();
    $roleType = $body['role_type'] ?? '';
    $skillCode = $body['skill_code'] ?? '';
    $requiredCount = isset($body['required_count']) ? (int)$body['required_count'] : 1;
    if (!sfo_role_allowed($pdo, $tenantId, $storeId, $roleType) || !sfo_valid_code($skillCode, 20) || !sfo_skill_exists($pdo, $tenantId, $storeId, $skillCode)) {
        json_error('INVALID_REQUEST', '持ち場とスキルを正しく指定してください', 400);
    }
    if ($requiredCount <= 0) {
        $stmt = $pdo->prepare(
            'DELETE FROM shift_position_required_skills
             WHERE tenant_id = ? AND store_id = ? AND role_type = ? AND skill_code = ?'
        );
        $stmt->execute([$tenantId, $storeId, $roleType, $skillCode]);
        write_audit_log($pdo, $user, $storeId, 'shift_position_skill_delete', 'shift_position_skill', $roleType, null, $body);
        json_response(['deleted' => true]);
    }
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_position_required_skills
            (id, tenant_id, store_id, role_type, skill_code, required_count)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE required_count = VALUES(required_count)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $roleType, $skillCode, min(20, $requiredCount)]);
    write_audit_log($pdo, $user, $storeId, 'shift_position_skill_save', 'shift_position_skill', $roleType, null, $body);
    json_response(['saved' => true]);
}

if ($method === 'POST' && $action === 'add-task') {
    require_role('manager');
    $body = get_json_body();
    $taskDate = $body['task_date'] ?? '';
    $templateId = isset($body['task_template_id']) ? (string)$body['task_template_id'] : '';
    $taskLabel = isset($body['task_label']) ? trim((string)$body['task_label']) : '';
    $userId = $body['user_id'] ?? '';
    $assignmentId = isset($body['shift_assignment_id']) && $body['shift_assignment_id'] !== '' ? (string)$body['shift_assignment_id'] : null;
    $note = isset($body['note']) ? trim((string)$body['note']) : null;
    if (!sfo_valid_date($taskDate) || $userId === '') {
        json_error('INVALID_REQUEST', '日付と担当スタッフを指定してください', 400);
    }
    if (!sfo_user_in_store($pdo, $tenantId, $storeId, $userId)) {
        json_error('USER_NOT_IN_STORE', '指定スタッフはこの店舗に所属していません', 400);
    }
    if ($templateId !== '') {
        $stmtTpl = $pdo->prepare(
            'SELECT id, label FROM shift_task_templates
             WHERE id = ? AND tenant_id = ? AND store_id = ? AND is_active = 1'
        );
        $stmtTpl->execute([$templateId, $tenantId, $storeId]);
        $tpl = $stmtTpl->fetch();
        if (!$tpl) {
            json_error('TEMPLATE_NOT_FOUND', '作業テンプレートが見つかりません', 404);
        }
        if ($taskLabel === '') {
            $taskLabel = $tpl['label'];
        }
    }
    if ($taskLabel === '' || mb_strlen($taskLabel) > 80) {
        json_error('INVALID_TASK_LABEL', '作業名を 80 文字以内で指定してください', 400);
    }
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO shift_task_assignments
            (id, tenant_id, store_id, task_date, task_template_id, task_label, user_id, shift_assignment_id, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $tenantId, $storeId, $taskDate, $templateId !== '' ? $templateId : null, $taskLabel, $userId, $assignmentId, $note, $user['user_id']]);
    write_audit_log($pdo, $user, $storeId, 'shift_task_assign', 'shift_task_assignment', $id, null, $body);
    json_response(['id' => $id], 201);
}

if ($method === 'PATCH' && $action === 'task-status') {
    require_role('staff');
    $body = get_json_body();
    $taskId = $body['task_id'] ?? '';
    $status = $body['status'] ?? '';
    if ($taskId === '' || !in_array($status, ['pending', 'done'], true)) {
        json_error('INVALID_REQUEST', 'task_id と status を指定してください', 400);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM shift_task_assignments
         WHERE id = ? AND tenant_id = ? AND store_id = ?
         LIMIT 1'
    );
    $stmt->execute([$taskId, $tenantId, $storeId]);
    $task = $stmt->fetch();
    if (!$task) {
        json_error('NOT_FOUND', '担当作業が見つかりません', 404);
    }
    if ($user['role'] === 'staff' && $task['user_id'] !== $user['user_id']) {
        json_error('FORBIDDEN', '自分の担当作業のみ更新できます', 403);
    }
    if ($status === 'done') {
        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_task_assignments
             SET status = ?, completed_by = ?, completed_at = NOW()
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtUpdate->execute(['done', $user['user_id'], $taskId, $tenantId, $storeId]);
    } else {
        $stmtUpdate = $pdo->prepare(
            'UPDATE shift_task_assignments
             SET status = ?, completed_by = NULL, completed_at = NULL
             WHERE id = ? AND tenant_id = ? AND store_id = ?'
        );
        $stmtUpdate->execute(['pending', $taskId, $tenantId, $storeId]);
    }
    write_audit_log($pdo, $user, $storeId, 'shift_task_status', 'shift_task_assignment', $taskId, $task, ['status' => $status]);
    json_response(['updated' => true]);
}

json_error('UNKNOWN_ACTION', 'action が不正です', 400);
