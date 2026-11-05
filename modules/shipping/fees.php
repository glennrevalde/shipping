<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

$accts = fn() => DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE is_active = 1 ORDER BY code");
ship_master_page([
    'table' => 'shp_fees', 'page' => 'shp.fees', 'title' => 'Fees & Third-party Charges', 'singular' => 'Charge', 'plural' => 'charges', 'order' => 'sort_order, code',
    'intro' => 'Terminal fees, insurance and other charges added to tickets, each as its own line. Collected as an <strong>agent</strong>, a charge goes to its payable account and is remitted later '
        . '(Cargo & RoRo > Port Fee Reconciliation & Remittance); it is never booked as company revenue. As <strong>principal</strong>, it is company revenue.',
    'unique' => 'code', 'upper' => true,
    'fields' => [
        'code' => ['Code', 'text', null, true, 'col-md-2'],
        'name' => ['Name', 'text', null, true, 'col-md-4'],
        'fee_type' => ['Kind', 'select', ['terminal' => 'Terminal fee', 'insurance' => 'Insurance', 'security' => 'Security fee', 'environmental' => 'Environmental fee', 'baggage' => 'Baggage', 'service' => 'Service fee'], true, 'col-md-3'],
        'applies_to' => ['Charged on', 'select', ['passenger' => 'Each passenger', 'vehicle' => 'Each vehicle'], true, 'col-md-3'],
        'amount' => ['Amount', 'number', null, true, 'col-md-2'],
        'ownership' => ['Collected as', 'select', ['agent' => 'Agent (for a third party: payable)', 'principal' => 'Principal (company revenue)'], true, 'col-md-4'],
        'liability_account_id' => ['Payable account (agent)', 'select', $accts, false, 'col-md-3'],
        'revenue_account_id' => ['Revenue account (principal)', 'select', $accts, false, 'col-md-3'],
        'vendor_id' => ['Remitted to (supplier)', 'select', fn() => DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), false, 'col-md-4'],
        'port_id' => ['Only at origin port', 'select', fn() => Ship::portOptions(), false, 'col-md-4'],
        'exempt_categories' => ['Not charged to categories (codes)', 'text', null, false, 'col-md-4', 'Comma separated, e.g. INF'],
        'sort_order' => ['Order', 'number', null, false, 'col-md-2', '', 0, '1'],
    ],
    'before_save' => function (array $row) {
        if ($row['ownership'] === 'agent' && !$row['liability_account_id']) {
            fail('Choose the payable account of a charge collected as an agent.');
        }
        if ($row['ownership'] === 'principal' && !$row['revenue_account_id']) {
            fail('Choose the revenue account of a charge collected as principal.');
        }
        $row['calc'] = $row['applies_to'] === 'vehicle' ? 'per_vehicle' : 'per_pax';
        return $row;
    },
    'list' => ['code' => ['Code', 'text'], 'name' => ['Charge', 'text'], 'applies_to' => ['On', 'text'], 'amount' => ['Amount', 'money'],
        'ownership' => ['Collected as', 'html', fn($r) => $r['ownership'] === 'agent' ? 'Agent (payable)' : 'Principal (revenue)'],
        'acct' => ['Account', 'text', fn($r) => (string)DB::value('SELECT CONCAT(code, " ", name) FROM accounts WHERE id = :id', ['id' => $r['ownership'] === 'agent' ? $r['liability_account_id'] : $r['revenue_account_id']])],
        'vendor' => ['Remitted to', 'text', fn($r) => (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $r['vendor_id']])], 'is_active' => ['Active', 'bool']],
]);
