<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

$accts = fn() => DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE is_active = 1 AND account_type IN ('Bank', 'Other Current Asset') ORDER BY code");
ship_master_page([
    'table' => 'shp_offices', 'page' => 'shp.offices', 'title' => 'Ticket Offices & Outlets', 'singular' => 'Ticket office', 'plural' => 'offices', 'order' => 'is_active DESC, name',
    'intro' => 'Ticket offices, terminal counters, agencies and the online portal. Cashier shifts are opened at an office; a user given offices below sells only there. '
        . 'Collections go to Undeposited Funds for Banking > Make Deposits unless an office has its own cash account.',
    'unique' => 'code', 'upper' => true,
    'fields' => [
        'code' => ['Code', 'text', null, true, 'col-md-2'],
        'name' => ['Name', 'text', null, true, 'col-md-4'],
        'office_type' => ['Kind', 'select', ['ticket_office' => 'Ticket office', 'terminal_counter' => 'Terminal counter', 'agency' => 'Travel agency outlet', 'online' => 'Online portal'], true, 'col-md-3'],
        'port_id' => ['Port', 'select', fn() => Ship::portOptions(), false, 'col-md-3'],
        'address' => ['Address', 'text', null, false, 'col-md-6'],
        'cash_account_id' => ['Cash account (blank = Undeposited Funds)', 'select', $accts, false, 'col-md-3'],
        'class_id' => ['Accounting class (blank = the vessel\'s)', 'select', fn() => Classes::options(), false, 'col-md-3'],
        'users' => ['Users who sell here', 'users', null, false, 'col-md-6'],
    ],
    'after_save' => function (int $id) {
        DB::run('DELETE FROM shp_user_offices WHERE office_id = :o', ['o' => $id]);
        foreach ((array)($_POST['users'] ?? []) as $u) {
            if ((int)$u > 0) {
                DB::insert('shp_user_offices', ['user_id' => (int)$u, 'office_id' => $id]);
            }
        }
    },
    'list' => ['code' => ['Code', 'text'], 'name' => ['Office', 'text'], 'office_type' => ['Kind', 'text', fn($r) => ucwords(str_replace('_', ' ', $r['office_type']))],
        'port' => ['Port', 'text', fn($r) => (string)DB::value('SELECT name FROM shp_ports WHERE id = :id', ['id' => $r['port_id']])],
        'users' => ['Users assigned', 'text', fn($r) => implode(', ', DB::column('SELECT u.username FROM shp_user_offices uo JOIN users u ON u.id = uo.user_id WHERE uo.office_id = :o ORDER BY u.username', ['o' => $r['id']]))],
        'is_active' => ['Active', 'bool']],
]);
