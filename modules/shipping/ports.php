<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

ship_master_page([
    'table' => 'shp_ports', 'page' => 'shp.ports', 'title' => 'Ports & Terminals', 'singular' => 'Port', 'plural' => 'ports', 'order' => 'is_active DESC, name',
    'intro' => 'Ports, terminals and their berths. The authority set here is the supplier the terminal fees collected at the port are remitted to.',
    'unique' => 'code', 'upper' => true, 'search' => ['code', 'name', 'city', 'province'],
    'fields' => [
        'code' => ['Port code', 'text', null, true, 'col-md-2'],
        'name' => ['Port / terminal name', 'text', null, true, 'col-md-4'],
        'city' => ['City / municipality', 'text', null, false, 'col-md-3'],
        'province' => ['Province', 'text', null, false, 'col-md-3'],
        'region' => ['Region', 'text', null, false, 'col-md-2'],
        'authority' => ['Port authority / operator', 'text', null, false, 'col-md-4'],
        'fee_vendor_id' => ['Terminal fees remitted to (supplier)', 'select', fn() => DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), false, 'col-md-6'],
        'berths' => ['Berths / jetties (comma separated)', 'text', null, false, 'col-md-8'],
        'notes' => ['Notes, planned closures', 'text', null, false, 'col-md-4'],
    ],
    'list' => [
        'code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'city' => ['City', 'text', fn($r) => trim(($r['city'] ?? '') . ', ' . ($r['province'] ?? ''), ', ')],
        'authority' => ['Authority', 'text'], 'berths' => ['Berths', 'text'], 'is_active' => ['Active', 'bool'],
    ],
]);
