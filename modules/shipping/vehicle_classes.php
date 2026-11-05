<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

ship_master_page([
    'table' => 'shp_vehicle_classes', 'page' => 'shp.vehicle_classes', 'title' => 'Vehicle Classes', 'singular' => 'Vehicle class', 'plural' => 'vehicle classes', 'order' => 'sort_order, code',
    'intro' => 'Rolling cargo classes. The lane meters a class takes are counted against the vehicle deck of each voyage; the length and weight limits are checked when a vehicle is booked.',
    'unique' => 'code', 'upper' => true,
    'fields' => [
        'code' => ['Code', 'text', null, true, 'col-md-2'],
        'name' => ['Name', 'text', null, true, 'col-md-4'],
        'max_length_m' => ['Max length (m)', 'number', null, true, 'col-md-2'],
        'max_weight_t' => ['Max weight (t)', 'number', null, true, 'col-md-2'],
        'lane_m' => ['Lane meters taken', 'number', null, true, 'col-md-2'],
        'sort_order' => ['Order', 'number', null, false, 'col-md-2', '', 0, '1'],
    ],
    'list' => ['code' => ['Code', 'text'], 'name' => ['Name', 'text'], 'max_length_m' => ['Max length (m)', 'num'], 'max_weight_t' => ['Max weight (t)', 'num'],
        'lane_m' => ['Lane meters', 'num'], 'is_active' => ['Active', 'bool']],
]);
