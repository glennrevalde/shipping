<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

$users = fn() => DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name");
$types = '* = all; or any of: ' . implode(', ', array_keys(ShipApproval::TYPES));
ship_master_page([
    'table' => 'shp_delegations', 'page' => 'shp.delegations', 'title' => 'Approval Delegations', 'singular' => 'Delegation', 'plural' => 'delegations', 'order' => 'is_active DESC, valid_to DESC',
    'intro' => 'An approver who is away hands their approvals to another person for a set period. The delegate decides with the approver\'s rights and limit, never on their own request, '
        . 'and the Approvals Queue and the audit trail record "for" whom they approved.',
    'fields' => [
        'from_user_id' => ['Approver (delegates)', 'select', $users, true, 'col-md-4'],
        'to_user_id' => ['Delegate (decides for them)', 'select', $users, true, 'col-md-4'],
        'approval_types' => ['Kinds of approval', 'text', null, true, 'col-md-4', $types, '*'],
        'valid_from' => ['From', 'date', null, true, 'col-md-3'],
        'valid_to' => ['Until', 'date', null, true, 'col-md-3'],
        'reason' => ['Reason', 'text', null, false, 'col-md-6'],
    ],
    'before_save' => function (array $row) {
        if ((int)$row['from_user_id'] === (int)$row['to_user_id']) {
            fail('Choose two different people.');
        }
        if ($row['valid_to'] < $row['valid_from']) {
            fail('The end date is before the start date.');
        }
        $row['created_by'] = Auth::id();
        return $row;
    },
    'list' => ['from' => ['Approver', 'text', fn($r) => Ship::userName((int)$r['from_user_id'])], 'to' => ['Delegate', 'text', fn($r) => Ship::userName((int)$r['to_user_id'])],
        'approval_types' => ['Kinds', 'text'], 'valid_from' => ['From', 'date'], 'valid_to' => ['Until', 'date'], 'reason' => ['Reason', 'text'], 'is_active' => ['Active', 'bool']],
]);
