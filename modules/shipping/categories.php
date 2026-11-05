<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

ship_master_page([
    'table' => 'shp_fare_categories', 'page' => 'shp.categories', 'title' => 'Passenger Categories & Discounts', 'singular' => 'Passenger category', 'plural' => 'categories',
    'order' => 'sort_order, code',
    'intro' => 'The discount of each passenger category. A statutory privilege is never combined with a promo; a VAT-exempt one takes the fare net of VAT before the discount. '
        . 'Keep the rates, the ID required and the legal basis current with the law in force: the system applies what is set here and does not decide eligibility under the law.',
    'unique' => 'code', 'upper' => true,
    'fields' => [
        'code' => ['Code', 'text', null, true, 'col-md-2'],
        'name' => ['Name', 'text', null, true, 'col-md-4'],
        'discount_pct' => ['Discount %', 'number', null, true, 'col-md-2'],
        'min_age' => ['From age', 'number', null, false, 'col-md-2', '', '', '1'],
        'max_age' => ['To age', 'number', null, false, 'col-md-2', '', '', '1'],
        'vat_exempt' => ['VAT-exempt (fare taken net of VAT first)', 'check', null, false, 'col-md-4'],
        'is_statutory' => ['Statutory privilege (never stacked with a promo)', 'check', null, false, 'col-md-4'],
        'fee_exempt' => ['No terminal fee / insurance (infant)', 'check', null, false, 'col-md-4'],
        'requires_id' => ['An ID is required and recorded', 'check', null, false, 'col-md-4'],
        'id_label' => ['ID required', 'text', null, false, 'col-md-4'],
        'legal_basis' => ['Legal basis / policy', 'text', null, false, 'col-md-4'],
        'effective_from' => ['Effective from', 'date', null, false, 'col-md-3'],
        'sort_order' => ['Order', 'number', null, false, 'col-md-2', '', 0, '1'],
    ],
    'list' => ['code' => ['Code', 'text'], 'name' => ['Category', 'text'], 'discount_pct' => ['Discount', 'html', fn($r) => h(qty($r['discount_pct'])) . '%'],
        'vat_exempt' => ['VAT-exempt', 'bool'], 'is_statutory' => ['Statutory', 'bool'], 'requires_id' => ['ID', 'text', fn($r) => $r['requires_id'] ? (string)$r['id_label'] : ''],
        'age' => ['Ages', 'text', fn($r) => $r['min_age'] !== null || $r['max_age'] !== null ? ($r['min_age'] ?? 0) . ' to ' . ($r['max_age'] ?? 'up') : ''],
        'legal_basis' => ['Basis', 'text'], 'is_active' => ['Active', 'bool']],
]);
