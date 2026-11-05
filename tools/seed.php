<?php
declare(strict_types=1);

/**
 * Set-up data of a new G-SMaRT Shipping System database: modules, pages, roles and their access, the user
 * accounts of every role, a chart of accounts for a Philippine domestic shipping operator, module default
 * accounts (with the shipping posting accounts), tax codes, payment methods, units, item categories, the
 * shipping service items, the fuel item, the Walk-in Passenger customer, fixed asset categories, number
 * sequences, the public list of ports, the statutory passenger categories, vehicle classes, the third-party
 * charges, one ticket office and the compliance checklist.
 *
 * Nothing here is a transaction: tools/demo_data.php adds the sample vessels, voyages and records.
 */
function seed_master_data(): void
{
    $reg = require APP_ROOT . '/app/registry.php';

    // ---------------------------------------------------------------- settings
    $settings = Settings::DEFAULTS;
    $settings['company_name'] = 'Visayas Star Shipping Lines, Inc.';
    $settings['company_address'] = 'Pier 4, North Reclamation Area, Cebu City 6000';
    $settings['company_tin'] = '000-482-917-000';
    $settings['company_phone'] = '(032) 412-5500';
    $settings['company_email'] = 'reservations@visayasstar.test';
    $settings['pos_receipt_footer'] = "Thank you for sailing with us!\nThis serves as your official receipt.";
    $settings['shp_qr_secret'] = bin2hex(random_bytes(24));
    $settings['allow_negative_stock'] = '0';
    foreach ($settings as $k => $v) {
        DB::insert('settings', ['setting_key' => $k, 'setting_value' => $v]);
    }

    // ---------------------------------------------------------------- modules & pages
    $moduleIds = [];
    foreach ($reg['modules'] as $code => [$name, $icon, $sort]) {
        $moduleIds[$code] = DB::insert('modules', ['code' => $code, 'name' => $name, 'icon' => $icon, 'sort_order' => $sort]);
    }
    $pageIds = [];
    $sort = 0;
    foreach ($reg['pages'] as $key => [$module, $name, $type, $group, $file, $params, $menu]) {
        $pageIds[$key] = DB::insert('pages', [
            'page_key' => $key, 'module_id' => $moduleIds[$module], 'name' => $name, 'page_type' => $type,
            'menu_group' => $group, 'sort_order' => ++$sort * 10, 'show_in_menu' => $menu,
        ]);
    }

    // ---------------------------------------------------------------- chart of accounts (domestic shipping)
    $typeInfo = [
        'Bank' => ['Asset', 'Debit'], 'Accounts Receivable' => ['Asset', 'Debit'], 'Other Current Asset' => ['Asset', 'Debit'],
        'Fixed Asset' => ['Asset', 'Debit'], 'Other Asset' => ['Asset', 'Debit'], 'Accounts Payable' => ['Liability', 'Credit'],
        'Credit Card' => ['Liability', 'Credit'], 'Other Current Liability' => ['Liability', 'Credit'], 'Long Term Liability' => ['Liability', 'Credit'],
        'Equity' => ['Equity', 'Credit'], 'Income' => ['Income', 'Credit'], 'Other Income' => ['Income', 'Credit'],
        'Cost of Goods Sold' => ['Expense', 'Debit'], 'Expense' => ['Expense', 'Debit'], 'Other Expense' => ['Expense', 'Debit'],
    ];
    $coa = [
        ['1000', 'Cash', 'Bank', null, null],
        ['1001', 'Cash on Hand - Ticket Offices', 'Bank', null, '1000'],
        ['1002', 'Cash on Hand - Onboard Canteens', 'Bank', null, '1000'],
        ['1005', 'Petty Cash Fund', 'Bank', null, '1000'],
        ['1006', 'Revolving Fund - Vessels', 'Bank', null, '1000'],
        ['1010', 'Cash in Bank - BDO Current', 'Bank', null, '1000'],
        ['1020', 'Cash in Bank - Landbank Savings', 'Bank', null, '1000'],
        ['1050', 'Undeposited Funds', 'Other Current Asset', null, null],
        ['1060', 'Card Clearing', 'Other Current Asset', null, null],
        ['1065', 'E-wallet Clearing (GCash / Maya)', 'Other Current Asset', null, null],
        ['1070', 'P.O. Card Clearing', 'Other Current Asset', null, null],
        ['1075', 'Online Payment Gateway Clearing', 'Other Current Asset', null, null],
        ['1100', 'Accounts Receivable - Trade', 'Accounts Receivable', null, null],
        ['1150', 'Allowance for Doubtful Accounts', 'Other Current Asset', 'Credit', null],
        ['1200', 'Inventory - Spare Parts', 'Other Current Asset', null, null],
        ['1210', 'Inventory - Fuel and Lubricants', 'Other Current Asset', null, null],
        ['1220', 'Inventory - Canteen Merchandise', 'Other Current Asset', null, null],
        ['1230', 'Inventory - Ship Stores and Consumables', 'Other Current Asset', null, null],
        ['1300', 'Input Tax', 'Other Current Asset', null, null],
        ['1310', 'Creditable Withholding Tax', 'Other Current Asset', null, null],
        ['1315', 'Creditable VAT Withheld', 'Other Current Asset', null, null],
        ['1400', 'Prepaid Expenses', 'Other Current Asset', null, null],
        ['1405', 'Prepaid Insurance - Hull and Machinery', 'Other Current Asset', null, null],
        ['1410', 'Advances to Suppliers', 'Other Current Asset', null, null],
        ['1420', 'Advances to Officers and Crew', 'Other Current Asset', null, null],
        ['1500', 'Land', 'Fixed Asset', null, null],
        ['1510', 'Terminal Buildings and Improvements', 'Fixed Asset', null, null],
        ['1515', 'Accumulated Depreciation - Buildings', 'Fixed Asset', 'Credit', null],
        ['1520', 'Vessels and Floating Equipment', 'Fixed Asset', null, null],
        ['1525', 'Accumulated Depreciation - Vessels', 'Fixed Asset', 'Credit', null],
        ['1526', 'Dry-docking Costs (capitalized)', 'Fixed Asset', null, null],
        ['1527', 'Accumulated Amortization - Dry-docking', 'Fixed Asset', 'Credit', null],
        ['1530', 'Transportation Equipment', 'Fixed Asset', null, null],
        ['1535', 'Accumulated Depreciation - Transportation', 'Fixed Asset', 'Credit', null],
        ['1540', 'Furniture and Fixtures', 'Fixed Asset', null, null],
        ['1545', 'Accumulated Depreciation - Furniture', 'Fixed Asset', 'Credit', null],
        ['1550', 'Computer and Ticketing Equipment', 'Fixed Asset', null, null],
        ['1555', 'Accumulated Depreciation - Computer and Ticketing', 'Fixed Asset', 'Credit', null],
        ['1560', 'Capital Work in Progress - Vessel Repairs', 'Fixed Asset', null, null],
        ['1600', 'Security Deposits', 'Other Asset', null, null],
        ['2000', 'Accounts Payable - Trade', 'Accounts Payable', null, null],
        ['2050', 'Company Credit Card', 'Credit Card', null, null],
        ['2100', 'Output Tax', 'Other Current Liability', null, null],
        ['2110', 'Withholding Tax Payable', 'Other Current Liability', null, null],
        ['2115', 'VAT Withheld Payable', 'Other Current Liability', null, null],
        ['2120', 'Gift Vouchers Outstanding', 'Other Current Liability', null, null],
        ['2130', 'Customer Deposits', 'Other Current Liability', null, null],
        ['2140', 'Deferred Passenger Revenue', 'Other Current Liability', null, null],
        ['2145', 'Deferred Freight Revenue', 'Other Current Liability', null, null],
        ['2150', 'Terminal Fee Payable - Port Authority', 'Other Current Liability', null, null],
        ['2155', 'Insurance Premium Payable', 'Other Current Liability', null, null],
        ['2160', 'Refunds Payable', 'Other Current Liability', null, null],
        ['2170', 'Agent Commissions Payable', 'Other Current Liability', null, null],
        ['2200', 'Accrued Expenses', 'Other Current Liability', null, null],
        ['2210', 'Accrued Crew Wages', 'Other Current Liability', null, null],
        ['2500', 'Loans Payable - Vessel Financing', 'Long Term Liability', null, null],
        ['3000', 'Capital Stock', 'Equity', null, null],
        ['3100', 'Dividends Declared', 'Equity', 'Debit', null],
        ['3200', 'Retained Earnings', 'Equity', null, null],
        ['3900', 'Opening Balance Equity', 'Equity', null, null],
        ['4000', 'Passenger Fare Revenue', 'Income', null, null],
        ['4005', 'Fuel Surcharge Revenue', 'Income', null, null],
        ['4010', 'Vehicle / RoRo Freight Revenue', 'Income', null, null],
        ['4020', 'Cargo Freight Revenue', 'Income', null, null],
        ['4025', 'Cargo Handling and Insurance Revenue', 'Income', null, null],
        ['4030', 'Excess Baggage Revenue', 'Income', null, null],
        ['4040', 'Ticket Change and Cancellation Fees', 'Income', null, null],
        ['4045', 'Charter Revenue', 'Income', null, null],
        ['4050', 'Sales Returns and Allowances', 'Income', 'Debit', null],
        ['4060', 'Sales Discounts', 'Income', 'Debit', null],
        ['4065', 'Statutory Fare Discounts', 'Income', 'Debit', null],
        ['4070', 'Item Group Price Variance', 'Income', null, null],
        ['4080', 'Onboard Canteen Sales', 'Income', null, null],
        ['4090', 'Forfeited / No-show Fares', 'Income', null, null],
        ['4110', 'Reimbursable Expense Income', 'Income', null, null],
        ['4100', 'Finance Charge Income', 'Other Income', null, null],
        ['4200', 'Interest Income', 'Other Income', null, null],
        ['4300', 'Gain (Loss) on Disposal of Assets', 'Other Income', null, null],
        ['5000', 'Cost of Canteen Sales', 'Cost of Goods Sold', null, null],
        ['5050', 'Purchase Discounts', 'Cost of Goods Sold', 'Credit', null],
        ['5100', 'Inventory Adjustments and Shrinkage', 'Cost of Goods Sold', null, null],
        ['5200', 'Internal Labor Absorbed', 'Cost of Goods Sold', 'Credit', null],
        ['5300', 'Voyage Fuel Expense', 'Cost of Goods Sold', null, null],
        ['5305', 'Fuel Shrinkage and Variance', 'Cost of Goods Sold', null, null],
        ['5310', 'Lubricants Expense', 'Cost of Goods Sold', null, null],
        ['5320', 'Port Charges and Dockage', 'Cost of Goods Sold', null, null],
        ['5330', 'Pilotage and Tug Assistance', 'Cost of Goods Sold', null, null],
        ['5340', 'Crew Meal and Travel Allowances', 'Cost of Goods Sold', null, null],
        ['5345', 'Crew Salaries and Wages', 'Cost of Goods Sold', null, null],
        ['5350', 'Vessel Repairs and Maintenance', 'Cost of Goods Sold', null, null],
        ['5355', 'Spare Parts Consumed', 'Cost of Goods Sold', null, null],
        ['5360', 'Hull and Machinery Insurance', 'Cost of Goods Sold', null, null],
        ['5380', 'Cargo Handling and Arrastre', 'Cost of Goods Sold', null, null],
        ['5390', 'Agent Commissions', 'Cost of Goods Sold', null, null],
        ['5395', 'Fresh Water and Ship Stores', 'Cost of Goods Sold', null, null],
        ['5400', 'Cargo Claims and Passenger Compensation', 'Cost of Goods Sold', null, null],
        ['6000', 'Salaries and Wages - Shore Staff', 'Expense', null, null],
        ['6010', 'Rent Expense', 'Expense', null, null],
        ['6020', 'Utilities Expense', 'Expense', null, null],
        ['6030', 'Repairs and Maintenance - Office', 'Expense', null, null],
        ['6040', 'Office Supplies Expense', 'Expense', null, null],
        ['6050', 'Fuel and Oil - Service Vehicles', 'Expense', null, null],
        ['6060', 'Meals and Representation', 'Expense', null, null],
        ['6070', 'Professional Fees', 'Expense', null, null],
        ['6080', 'Bank, Gateway and Merchant Charges', 'Expense', null, null],
        ['6090', 'Depreciation Expense', 'Expense', null, null],
        ['6095', 'Amortization of Dry-docking', 'Expense', null, null],
        ['6100', 'Freight and Delivery', 'Expense', null, null],
        ['6110', 'Purchases - Non-inventory', 'Expense', null, null],
        ['6200', 'Cash Over and Short', 'Expense', null, null],
        ['6300', 'Advertising and Promotions', 'Expense', null, null],
        ['6400', 'Licenses, Permits and Certificates', 'Expense', null, null],
        ['6500', 'Communication Expense', 'Expense', null, null],
        ['6900', 'Miscellaneous Expense', 'Expense', null, null],
        ['7000', 'Interest Expense', 'Other Expense', null, null],
    ];
    $acct = [];
    foreach ($coa as [$code, $name, $type, $nb, $parent]) {
        [$cat, $normal] = $typeInfo[$type];
        $acct[$code] = DB::insert('accounts', [
            'code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $cat,
            'normal_balance' => $nb ?? $normal, 'parent_id' => $parent ? $acct[$parent] : null,
            'is_system' => in_array($code, ['1050', '1100', '1200', '2000', '2100', '1300', '3200', '3900', '2140', '2145'], true) ? 1 : 0,
            'bank_account_no' => $code === '1010' ? '0054-1188-2207' : ($code === '1020' ? '3361-0092-41' : null),
        ]);
    }

    $defaults = [
        'ar' => '1100', 'undeposited_funds' => '1050', 'sales_income' => '4080', 'sales_returns' => '4050',
        'sales_discounts' => '4060', 'finance_charge_income' => '4100', 'reimbursable_income' => '4110', 'cwt' => '1310',
        'output_tax' => '2100', 'ap' => '2000', 'input_tax' => '1300', 'purchase_discounts' => '5050',
        'default_expense' => '6110', 'inventory_asset' => '1200', 'cogs' => '5000', 'inventory_adjustment' => '5100',
        'cash_on_hand' => '1002', 'cash_over_short' => '6200', 'card_clearing' => '1060', 'ewallet_clearing' => '1065',
        'merchant_fees' => '6080', 'interest_income' => '4200', 'retained_earnings' => '3200', 'opening_equity' => '3900',
        'gain_loss_disposal' => '4300', 'processing_variance' => '5100', 'processing_absorbed' => '5200',
        'cvat_withheld' => '1315', 'wht_payable' => '2110', 'vat_withheld_payable' => '2115', 'vendor_advances' => '1410',
        'group_price_variance' => '4070',
        'shp_deferred_pax' => '2140', 'shp_deferred_freight' => '2145', 'shp_fare_revenue' => '4000', 'shp_surcharge_revenue' => '4005',
        'shp_statutory_disc' => '4065', 'shp_vehicle_revenue' => '4010', 'shp_cargo_revenue' => '4020', 'shp_handling_revenue' => '4025',
        'shp_change_fees' => '4040', 'shp_noshow_revenue' => '4090', 'shp_terminal_payable' => '2150', 'shp_insurance_payable' => '2155',
        'shp_fuel_expense' => '5300', 'shp_repairs_expense' => '5350', 'shp_spares_expense' => '5355', 'shp_capex_wip' => '1560',
        'shp_labor_absorbed' => '5200', 'shp_port_charges' => '5320', 'shp_crew_allowance' => '5340', 'shp_claims_expense' => '5400',
        'shp_fuel_variance' => '5305',
    ];
    foreach (Accts::KEYS as $key => [$label, $module]) {
        DB::insert('default_accounts', ['setting_key' => $key, 'label' => $label, 'module' => $module, 'account_id' => $acct[$defaults[$key]]]);
    }
    Accts::reset();

    // ---------------------------------------------------------------- lists
    $terms = [];
    foreach ([['Due on receipt', 0, 0, 0], ['Net 15', 15, 0, 0], ['Net 30', 30, 0, 0], ['2% 10 Net 30', 30, 2, 10], ['Net 60', 60, 0, 0]] as [$n, $d, $p, $dd]) {
        $terms[$n] = DB::insert('terms', ['name' => $n, 'net_days' => $d, 'discount_pct' => $p, 'discount_days' => $dd]);
    }
    $tax = [];
    $tax['VAT12'] = DB::insert('tax_codes', ['code' => 'VAT12', 'name' => 'VAT 12%', 'rate' => 12, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['ZR'] = DB::insert('tax_codes', ['code' => 'ZR', 'name' => 'Zero-rated', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['EX'] = DB::insert('tax_codes', ['code' => 'EX', 'name' => 'VAT-exempt (Senior / PWD and other exempt sales)', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['NT'] = DB::insert('tax_codes', ['code' => 'NT', 'name' => 'Non-taxable (collections for third parties)', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);

    $wht = [];
    foreach ([
        ['WT-GOODS', 'WC158', 'WT on Goods', 'income', 1, 'Goods bought from regular suppliers by top withholding agents (RR 11-2018)', 10],
        ['WT-SERVICES', 'WC160', 'WT on Services', 'income', 2, 'Services bought from regular suppliers by top withholding agents (RR 11-2018)', 20],
        ['WT-VAT', 'WV010', 'WT on VAT', 'vat', 5, 'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)', 30],
    ] as [$code, $atc, $name, $kind, $rate, $desc, $sortNo]) {
        $wht[$code] = DB::insert('withholding_taxes', ['code' => $code, 'atc' => $atc, 'name' => $name, 'tax_kind' => $kind, 'rate' => $rate,
            'base_type' => 'net_of_vat', 'applies_to' => 'both', 'description' => $desc, 'sort_order' => $sortNo]);
    }
    Withholding::reset();

    $pm = [];
    foreach ([
        ['Cash', 'cash', null, 1], ['Check', 'check', null, 0], ['Credit/Debit Card', 'card', '1060', 1], ['GCash', 'ewallet', '1065', 1],
        ['Maya', 'ewallet', '1065', 1], ['Bank Transfer', 'bank_transfer', null, 0], ['Gift Voucher', 'voucher', '2120', 1],
        ['P.O. Card', 'card', '1070', 0], ['Bank', 'bank_transfer', '1010', 0], ['Online Payment Gateway', 'card', '1075', 0],
    ] as [$n, $t, $a, $pos]) {
        $pm[$n] = DB::insert('payment_methods', ['name' => $n, 'method_type' => $t, 'clearing_account_id' => $a ? $acct[$a] : null, 'show_in_pos' => $pos]);
    }
    $pl = [];
    foreach ([['Corporate Rate', -5], ['Travel Agent Net', -8], ['Crew / Staff', -20], ['Price Level 4', 0], ['Price Level 5', 0], ['Price Level 6', 0], ['Price Level 7', 0], ['Price Level 8', 0], ['Price Level 9', 0], ['Price Level 10', 0]] as [$n, $p]) {
        $pl[$n] = DB::insert('price_levels', ['name' => $n, 'adjust_pct' => $p]);
    }
    $ctype = [];
    foreach (['Walk-in Passenger', 'Corporate Account', 'Travel Agency', 'Cargo Shipper', 'Government', 'Bus Operator'] as $n) {
        $ctype[$n] = DB::insert('customer_types', ['name' => $n]);
    }
    DB::insert('billing_groups', ['name' => 'Monthly Cargo Accounts']);

    // ---------------------------------------------------------------- units of measure
    $uomSets = [];
    foreach ([
        ['Piece', 'count', 'pc', [['pc', 'Piece', 1, 'S'], ['set', 'Set', 1, ''], ['box', 'Box of 24', 24, 'P'], ['case', 'Case of 48', 48, '']]],
        ['Weight (kg)', 'weight', 'kg', [['kg', 'Kilogram', 1, 'SP'], ['g', 'Gram', 0.001, ''], ['t', 'Metric ton', 1000, '']]],
        ['Volume (L)', 'volume', 'L', [['L', 'Liter', 1, 'SP'], ['drum', 'Drum of 200 L', 200, ''], ['kl', 'Kiloliter (1,000 L)', 1000, '']]],
        ['Length (m)', 'length', 'm', [['m', 'Meter', 1, 'SP'], ['cm', 'Centimeter', 0.01, ''], ['roll', 'Roll of 100 m', 100, '']]],
        ['Time (hour)', 'time', 'hr', [['hr', 'Hour', 1, 'SP'], ['day', 'Day (8 hours)', 8, '']]],
    ] as [$sName, $kind, $baseUnit, $units]) {
        $sid = DB::insert('uom_sets', ['name' => $sName, 'uom_kind' => $kind, 'base_unit' => $baseUnit, 'is_active' => 1]);
        $uomSets[$baseUnit] = $sid;
        $so = 0;
        foreach ($units as [$code, $uName, $factor, $flags]) {
            DB::insert('uom_units', ['set_id' => $sid, 'code' => $code, 'name' => $uName, 'factor' => $factor,
                'is_base' => $factor == 1 && $code === $baseUnit ? 1 : 0, 'is_sales_default' => str_contains($flags, 'S') ? 1 : 0,
                'is_purchase_default' => str_contains($flags, 'P') ? 1 : 0, 'sort_order' => $so++]);
        }
    }

    $cat = [];
    foreach (['Shipping Services', 'Fuel and Lubricants', 'Spare Parts', 'Ship Stores', 'Canteen'] as $n) {
        $cat[$n] = DB::insert('item_categories', ['name' => $n]);
    }

    // ---------------------------------------------------------------- sites (vessel tanks and stores are added with each vessel)
    $site = [];
    $site['SHORE-CEB'] = DB::insert('sites', ['code' => 'SHORE-CEB', 'name' => 'Cebu Shore Warehouse', 'site_type' => 'warehouse', 'address' => 'Pier 4, North Reclamation Area, Cebu City']);

    // ---------------------------------------------------------------- roles
    $pageDefs = [];
    foreach ($reg['pages'] as $k => $d) {
        $pageDefs[$k] = ['module' => $d[0], 'type' => $d[2], 'group' => $d[3]];
    }
    $adminSetup = ['admin.users', 'admin.roles', 'admin.page_access', 'admin.effective_access', 'admin.settings',
        'admin.utilities', 'admin.import', 'admin.backup', 'admin.multi_company',
        'company.profile', 'company.users', 'company.join_requests'];
    $select = function (string $pattern) use ($pageDefs): array {
        if (isset($pageDefs[$pattern])) {
            return [$pattern];
        }
        [$a, $b] = explode(':', $pattern, 2) + [1 => '*'];
        $out = [];
        foreach ($pageDefs as $k => $d) {
            if ($a === 'group') {
                if ($d['group'] === $b) {
                    $out[] = $k;
                }
                continue;
            }
            if ($d['module'] !== $a) {
                continue;
            }
            if ($b === '*' || ($b === 'report' && $d['type'] === 'report') || ($b === '!report' && $d['type'] !== 'report')) {
                $out[] = $k;
            }
        }
        return $out;
    };
    $shipReports = ['ops:report' => 'P', 'tkt:report' => 'P', 'cgo:report' => 'P', 'fleet:report' => 'P', 'care:report' => 'P', 'shp:report' => 'P'];

    $roleDefs = [
        'Administrator' => [
            'desc' => 'Company administrator: everything in every module of this company, including users, roles, page access, the closing date and the audit trail.',
            'admin' => 1, 'pages' => [], 'special' => [],
        ],
        'General Manager' => [
            'desc' => 'Sees everything; approves refunds, compensation, departure overrides, purchases and fare changes; gives the management release of safety holds.',
            'pages' => array_merge(['*' => 'P'], ['ops.approvals' => 'CEPA', 'tkt.refunds' => 'PA', 'care.cases' => 'CEPA', 'ops.voyages' => 'EPA', 'ops.holds' => 'EPA',
                'fleet.requisitions' => 'PA', 'fleet.workorders' => 'PA', 'shp.fares' => 'CEPA', 'ops.fuel' => 'PA', 'tkt.cashier' => 'PA', 'shp.delegations' => 'CEDP']),
            'deny' => $adminSetup,
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'shp_view_pii' => '1', 'shp_approve_limit' => '', 'shp_departure_override' => '1',
                'shp_safety_release' => '1', 'shp_emergency_purchase' => '1', 'shp_fare_override' => '1', 'design_reports' => '1'],
        ],
        'Operations Manager' => [
            'desc' => 'Voyages, schedules, dispatch, holds, fuel, crew, manifests, cargo and RoRo operations, port fees; approves departure overrides and gives the management release of safety holds.',
            'pages' => array_merge($shipReports, ['home:*' => 'P', 'ops:!report' => 'CEPA', 'tkt.manifest' => 'CEP', 'tkt.bookings' => 'P', 'tkt.boarding' => 'P', 'cgo:!report' => 'CEP',
                'fleet.vessels' => 'P', 'fleet.certificates' => 'P', 'fleet.defects' => 'CEP', 'fleet.workorders' => 'P', 'care.cases' => 'P', 'care.notifications' => 'P',
                'shp.ports' => 'CEP', 'shp.routes' => 'CEP', 'shp.offices' => 'P', 'shp.compliance' => 'CEP']),
            'special' => ['view_costs' => '1', 'shp_departure_override' => '1', 'shp_safety_release' => '1', 'shp_view_pii' => '1', 'shp_approve_limit' => '100000'],
        ],
        'Fleet Manager' => [
            'desc' => 'Vessels, certificates, equipment, planned and corrective maintenance, dry-docking, purchasing for the fleet, fuel; approves work, deferrals and requisitions; management release of safety holds.',
            'pages' => array_merge($shipReports, ['home:*' => 'P', 'fleet:!report' => 'CEDPA', 'ops.holds' => 'CEPA', 'ops.fuel' => 'CEPA', 'ops.voyages' => 'P', 'ops.crew' => 'CEP', 'ops.approvals' => 'CEPA',
                'vend.purchase_orders' => 'P', 'vend.receive_items' => 'P', 'inv.center' => 'P', 'inv.items' => 'P', 'rpt.qoh_by_site' => 'P', 'fa.register' => 'P']),
            'special' => ['view_costs' => '1', 'shp_safety_release' => '1', 'shp_approve_limit' => '250000', 'shp_emergency_purchase' => '1'],
        ],
        'Captain / Master' => [
            'desc' => 'Departure readiness and release, voyage status and report, crew list, manifests, boarding, defects and fuel soundings of the vessel.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'ops.voyages' => 'EP', 'ops.crew' => 'P', 'ops.holds' => 'P', 'ops.fuel' => 'CEP', 'tkt.manifest' => 'CEP',
                'tkt.boarding' => 'CP', 'fleet.defects' => 'CEP', 'fleet.certificates' => 'P', 'fleet.vessels' => 'P', 'cgo.vehicles' => 'EP', 'cgo.shipments' => 'P',
                'rpt.shp_punctuality' => 'P', 'rpt.shp_fuel' => 'P', 'rpt.shp_crew_certs' => 'P'],
            'special' => ['shp_view_pii' => '1'],
        ],
        'Chief Engineer' => [
            'desc' => 'Engine room, equipment and running hours, defects and work orders (inspects and signs the technical release of critical work), fuel, requisitions.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'fleet.equipment' => 'CEP', 'fleet.pm' => 'CEP', 'fleet.defects' => 'CEPA', 'fleet.workorders' => 'CEPA',
                'fleet.requisitions' => 'CEP', 'fleet.drydock' => 'P', 'ops.fuel' => 'CEP', 'ops.holds' => 'EP', 'ops.voyages' => 'P', 'ops.approvals' => 'CEPA',
                'fleet.vessels' => 'P', 'fleet.certificates' => 'P', 'fleet:report' => 'P', 'rpt.shp_fuel' => 'P', 'inv.items' => 'P'],
            'special' => ['shp_technical_release' => '1', 'shp_emergency_purchase' => '1', 'shp_approve_limit' => '50000', 'view_costs' => '1'],
        ],
        'Maintenance Planner' => [
            'desc' => 'Equipment register, planned maintenance plans, work order planning, dry-docking projects, requisitions and maintenance reports.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'fleet.equipment' => 'CEP', 'fleet.pm' => 'CEDP', 'fleet.defects' => 'CEP', 'fleet.workorders' => 'CEP',
                'fleet.drydock' => 'CEP', 'fleet.requisitions' => 'CEP', 'fleet.certificates' => 'CEP', 'fleet.vessels' => 'P', 'fleet:report' => 'P', 'inv.center' => 'P', 'inv.items' => 'P'],
            'special' => ['view_costs' => '1'],
        ],
        'Engineer / Technician' => [
            'desc' => 'Reports defects, carries out work orders and logs labor and parts. Cannot inspect or release their own work.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'fleet.defects' => 'CP', 'fleet.workorders' => 'EP', 'fleet.equipment' => 'P', 'fleet.pm' => 'P', 'fleet.requisitions' => 'CP'],
            'special' => [],
        ],
        'Procurement Officer' => [
            'desc' => 'Canvass of approved requisitions, purchase orders, supplier records; follows receiving and supplier invoices.',
            'pages' => ['home:*' => 'P', 'fleet.requisitions' => 'CEP', 'vend.purchase_orders' => 'CEP', 'vend.center' => 'CEP', 'vend.receive_items' => 'P', 'vend.bill_received' => 'P',
                'inv.items' => 'P', 'inv.center' => 'P', 'rpt.open_so_item' => '', 'rpt.vendor_contacts' => 'P', 'rpt.txn_list_vendor' => 'P'],
            'special' => ['view_costs' => '1'],
        ],
        'Warehouse Keeper' => [
            'desc' => 'Shore warehouse and vessel stores: receiving, transfers, spare parts issued to work orders, bunkering receipts, stock counts.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'inv.center' => 'P', 'inv.items' => 'P', 'inv.sites' => 'P', 'inv.transfers' => 'CEP', 'inv.adjust' => 'CP',
                'inv.physical_count' => 'P', 'vend.receive_items' => 'CEP', 'vend.purchase_orders' => 'P', 'fleet.workorders' => 'EP', 'fleet.requisitions' => 'CP', 'ops.fuel' => 'CP',
                'rpt.qoh_by_site' => 'P', 'rpt.stock_status_item' => 'P', 'rpt.stock_status_site' => 'P', 'rpt.physical_worksheet' => 'P', 'rpt.negative_inventory' => 'P', 'rpt.shp_spares' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '20000'],
        ],
        'Ticketing Manager' => [
            'desc' => 'Ticketing, boarding and manifests; approves refunds and compensation, reviews cashier shifts, sets fares and passenger categories.',
            'pages' => array_merge(['home:*' => 'P', 'tkt:*' => 'CEDPA', 'cgo.vehicles' => 'CEP', 'ops.voyages' => 'P', 'ops.schedule' => 'P', 'ops.approvals' => 'CEPA', 'care:*' => 'CEPA',
                'shp.fares' => 'CEP', 'shp.categories' => 'CEP', 'shp.fees' => 'P', 'shp.offices' => 'CEP', 'rpt.shp_exceptions' => 'P', 'rpt.shp_reconcile' => 'P', 'rpt.shp_port_fees' => 'P']),
            'special' => ['shp_view_pii' => '1', 'shp_fare_override' => '1', 'shp_approve_limit' => '50000', 'max_amount' => '50000'],
        ],
        'Ticketing Agent / Cashier' => [
            'desc' => 'Booking desk, ticket issuance and payment, cashier shift, refund requests and rebooking at the assigned ticket office.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'tkt.booking' => 'CP', 'tkt.bookings' => 'EP', 'tkt.cashier' => 'CP', 'tkt.refunds' => 'CP', 'cgo.vehicles' => 'P',
                'care.cases' => 'CP', 'rpt.shp_sales' => 'P'],
            'special' => ['shp_view_pii' => '0', 'max_amount' => '0'],
        ],
        'Boarding Officer' => [
            'desc' => 'QR check-in and boarding at the gate, vehicle loading scans, the passenger count and the manifest of the voyage.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'tkt.boarding' => 'CP', 'tkt.manifest' => 'P', 'cgo.vehicles' => 'EP'],
            'special' => ['shp_view_pii' => '1'],
        ],
        'Cargo Clerk' => [
            'desc' => 'Cargo quotations, waybills, acceptance, loading tally, delivery and exceptions; vehicle bookings and loading lists; collects freight at the counter.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'cgo.shipments' => 'CEP', 'cgo.vehicles' => 'CEP', 'cgo:report' => 'P', 'tkt.cashier' => 'CP', 'care.cases' => 'CP', 'cust.center' => 'P'],
            'special' => ['credit_limit_override' => '0'],
        ],
        'Port Agent' => [
            'desc' => 'Agent at an outport: sells tickets through the shared inventory, handles cargo and vehicles, sees the voyage and its manifest.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'ops.voyages' => 'P', 'tkt.booking' => 'CP', 'tkt.bookings' => 'P', 'tkt.cashier' => 'CP', 'tkt.manifest' => 'P',
                'cgo.shipments' => 'CEP', 'cgo.vehicles' => 'EP', 'cgo.port_fees' => 'P'],
            'special' => [],
        ],
        'Customer Service' => [
            'desc' => 'Support cases and claims, notices to passengers, booking assistance and free rebooking of cancelled voyages; requests refunds and compensation.',
            'pages' => ['home.dashboard' => '', 'home.notices' => '', 'care:*' => 'CEP', 'tkt.bookings' => 'EP', 'tkt.refunds' => 'CP', 'cgo.shipments' => 'P', 'ops.voyages' => 'P'],
            'special' => ['shp_view_pii' => '1'],
        ],
        'Accountant' => [
            'desc' => 'Financial set-up and reports, journal entries, fixed assets, banking, BIR reports, shipping posting accounts, port fee remittance and every shipping report.',
            'pages' => array_merge($shipReports, [
                'home:*' => 'P', 'coa:*' => 'CEDP', 'fa:*' => 'CEDP', 'bank:*' => 'CEDP',
                'cust:report' => 'P', 'vend:report' => 'P', 'inv:report' => 'P', 'pos:report' => 'P',
                'cust.center' => 'P', 'cust.payments' => 'P', 'cust.invoices' => 'P', 'vend.center' => 'P',
                'vend.bill_tracker' => 'P', 'vend.bills' => 'P', 'vend.pay_bills' => 'P', 'vend.advances' => 'P', 'bir:*' => 'CEDP',
                'inv.center' => 'P', 'inv.items' => 'P', 'inv.categories' => 'P', 'inv.adjust' => 'P', 'inv.uom' => 'P',
                'admin.utilities' => 'P', 'cgo.port_fees' => 'CEP', 'tkt.cashier' => 'PA', 'shp.settings' => 'CEP', 'ops.voyages' => 'P', 'tkt.bookings' => 'P', 'cgo.shipments' => 'P',
            ]),
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'coa_changes' => '1', 'max_amount' => '', 'max_discount_pct' => '0', 'max_price_override_pct' => '0', 'credit_limit_override' => '0', 'closed_period_changes' => '0', 'design_reports' => '1'],
        ],
        'Treasurer' => [
            'desc' => 'Banking, deposits of ticket collections, bank reconciliation, supplier payments, cashier shift review, refund pay-outs and port fee remittance.',
            'pages' => ['home:*' => 'P', 'bank:*' => 'CEDP', 'vend.pay_bills' => 'CEPA', 'vend.center' => 'P', 'tkt.cashier' => 'PA', 'tkt.refunds' => 'EP', 'cgo.port_fees' => 'CEP',
                'rpt.shp_cashier' => 'P', 'rpt.shp_port_fees' => 'P', 'rpt.ap_aging_summary' => 'P', 'rpt.ar_aging_summary' => 'P', 'rpt.unpaid_bills' => 'P'],
            'special' => ['view_bank_balances' => '1', 'max_amount' => ''],
        ],
        'A/R Clerk' => [
            'desc' => 'Customers module (corporate and cargo accounts, invoices, collections) and the receivables reports.',
            'pages' => ['home:*' => 'P', 'cust:!report' => 'CEP', 'group:Customer and Receivables Reports' => 'P', 'inv.items' => '', 'tkt.bookings' => 'P', 'cgo.shipments' => 'P'],
            'special' => ['view_costs' => '0', 'view_bank_balances' => '0', 'max_discount_pct' => '10', 'max_price_override_pct' => '5', 'max_amount' => '50000', 'credit_limit_override' => '0'],
        ],
        'A/P Clerk' => [
            'desc' => 'Vendors module (bills, supplier debit memos, pay bills, advances), payables reports and BIR Form 2307 for suppliers.',
            'pages' => ['home:*' => 'P', 'vend:!report' => 'CEP', 'vend.center' => 'P', 'vend:report' => 'P', 'inv.items' => '', 'fleet.requisitions' => 'P',
                'bir.form_2307' => 'P', 'bir.wht_table' => '', 'rpt.bir_wht_summary' => 'P', 'rpt.bir_ewt_register' => 'P', 'rpt.bir_vat_withholding' => 'P', 'rpt.bir_remittance' => 'P'],
            'special' => ['view_costs' => '1', 'view_bank_balances' => '0', 'max_amount' => '100000'],
        ],
        'Inventory Clerk' => [
            'desc' => 'Items, sites, receiving, adjustments, transfers, stock counts and inventory reports.',
            'pages' => [
                'home:*' => 'P', 'inv.center' => 'P', 'inv.items' => 'CEP', 'inv.categories' => 'CEDP', 'inv.sites' => 'CEP', 'inv.transfers' => 'CEP',
                'inv.adjust' => 'CEP', 'inv.build' => 'CEP', 'inv.physical_count' => 'P', 'inv.uom' => 'CEP',
                'vend.receive_items' => 'CEP', 'vend.purchase_orders' => 'P',
                'rpt.qoh_by_site' => 'P', 'rpt.stock_status_item' => 'P', 'rpt.stock_status_vendor' => 'P', 'rpt.stock_status_site' => 'P',
                'rpt.physical_worksheet' => 'P', 'rpt.negative_inventory' => 'P', 'rpt.inv_no_movement' => 'P',
                'rpt.inv_movement_summary' => 'P', 'rpt.inv_movement_detail' => 'P', 'rpt.inv_movement_txns' => 'P',
            ],
            'special' => ['view_costs' => '0', 'max_amount' => '20000'],
        ],
        'Corporate Sales' => [
            'desc' => 'Corporate, travel-agency and cargo accounts: estimates, sales orders, invoices and the sales reports.',
            'pages' => ['home:*' => 'P', 'cust.center' => 'P', 'cust.estimates' => 'CEP', 'cust.sales_orders' => 'CEP', 'cust.invoices' => 'CEP', 'group:Sales Reports' => 'P',
                'tkt.bookings' => 'P', 'cgo.shipments' => 'CP', 'rpt.shp_sales' => 'P', 'rpt.shp_cargo_volume' => 'P'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '5', 'max_price_override_pct' => '0'],
        ],
        'Canteen Cashier' => [
            'desc' => 'Onboard canteen POS sales, returns and cash drops at the assigned terminal only.',
            'pages' => ['home.dashboard' => '', 'pos.register' => 'CP', 'pos.shifts' => 'CP', 'pos.receipts' => 'CDP', 'rpt.pos_ending_inventory' => 'P'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '5', 'max_price_override_pct' => '0', 'max_amount' => '0'],
        ],
        'Purser (POS Supervisor)' => [
            'desc' => 'Onboard canteen supervision: approves voids, refunds and overrides, runs X- and Z-readings.',
            'pages' => ['home.dashboard' => '', 'home.reports' => '', 'pos.register' => 'CEDPA', 'pos.shifts' => 'CEDPA', 'pos.receipts' => 'CEDPA', 'pos.approval_codes' => 'CEDPA', 'pos.terminals' => 'P', 'pos:report' => 'P'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '30', 'max_price_override_pct' => '20', 'max_amount' => '20000'],
        ],
        'Internal Auditor' => [
            'desc' => 'View-only access to every record, report and the audit trail. Cannot create, change, approve or post anything.',
            'pages' => ['*' => 'P'],
            'deny' => array_merge($adminSetup, ['pos.register', 'pos.shifts', 'tkt.booking', 'tkt.boarding', 'tkt.cashier']),
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'shp_view_pii' => '1'],
        ],
        'Regulator (read-only)' => [
            'desc' => 'Authorized regulator access (for example a PCG or MARINA inspector): voyages, manifests, vessel certificates and safety reports, read-only. No finance.',
            'pages' => ['home.dashboard' => '', 'ops.voyages' => 'P', 'tkt.manifest' => 'P', 'fleet.vessels' => 'P', 'fleet.certificates' => 'P', 'ops.holds' => 'P',
                'rpt.shp_punctuality' => 'P', 'rpt.shp_cert_expiry' => 'P', 'rpt.shp_sold_boarded' => 'P', 'rpt.shp_crew_certs' => 'P'],
            'special' => ['shp_view_pii' => '1'],
        ],
    ];

    $roleIds = [];
    foreach ($roleDefs as $name => $def) {
        $rid = DB::insert('roles', [
            'name' => $name, 'description' => $def['desc'], 'is_admin' => $def['admin'] ?? 0, 'is_system' => 1,
            'all_sites' => isset($def['sites']) ? 0 : 1,
        ]);
        $roleIds[$name] = $rid;
        $grant = [];
        foreach ($def['pages'] as $pattern => $letters) {
            $keys = $pattern === '*' ? array_keys($pageDefs) : $select($pattern);
            foreach ($keys as $k) {
                $grant[$k] = ($grant[$k] ?? '') . $letters;
            }
        }
        foreach ($def['deny'] ?? [] as $k) {
            unset($grant[$k]);
        }
        foreach ($grant as $k => $letters) {
            DB::insert('role_page_access', [
                'role_id' => $rid, 'page_id' => $pageIds[$k], 'allowed' => 1,
                'can_create' => (int)str_contains($letters, 'C'), 'can_edit' => (int)str_contains($letters, 'E'),
                'can_delete' => (int)str_contains($letters, 'D'), 'can_print' => (int)str_contains($letters, 'P'),
                'can_approve' => (int)str_contains($letters, 'A'),
            ]);
        }
        foreach ($def['special'] as $k => $v) {
            DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => $k, 'perm_value' => $v]);
        }
    }

    // ---------------------------------------------------------------- user accounts (one or more per role)
    $users = [
        // username, full name, role, password, must change at first log-in
        ['admin', 'System Administrator', 'Administrator', 'Admin@12345', 1],
        ['gm', 'Ramon Villafuerte', 'General Manager', 'Demo@12345', 0],
        ['opsmgr', 'Teresa Abellana', 'Operations Manager', 'Demo@12345', 0],
        ['fleetmgr', 'Engr. Arnel Pacquiao', 'Fleet Manager', 'Demo@12345', 0],
        ['captain', 'Capt. Rodrigo Saavedra', 'Captain / Master', 'Demo@12345', 0],
        ['chiefeng', 'Engr. Victor Labra', 'Chief Engineer', 'Demo@12345', 0],
        ['planner', 'Liza Montenegro', 'Maintenance Planner', 'Demo@12345', 0],
        ['tech1', 'Joel Sumalinog', 'Engineer / Technician', 'Demo@12345', 0],
        ['tech2', 'Ricky Bacalso', 'Engineer / Technician', 'Demo@12345', 0],
        ['procure', 'Marivic Ouano', 'Procurement Officer', 'Demo@12345', 0],
        ['warehouse', 'Dennis Cabahug', 'Warehouse Keeper', 'Demo@12345', 0],
        ['tktmgr', 'Grace Lim-Uy', 'Ticketing Manager', 'Demo@12345', 0],
        ['ticket1', 'Analyn Tumulak', 'Ticketing Agent / Cashier', 'Demo@12345', 0],
        ['ticket2', 'Paolo Ybanez', 'Ticketing Agent / Cashier', 'Demo@12345', 0],
        ['boarding1', 'Jessa Mae Rosales', 'Boarding Officer', 'Demo@12345', 0],
        ['cargoclerk', 'Noel Alcuizar', 'Cargo Clerk', 'Demo@12345', 0],
        ['portagent', 'Mylene Gacasan', 'Port Agent', 'Demo@12345', 0],
        ['csagent', 'Kristine Dumaguit', 'Customer Service', 'Demo@12345', 0],
        ['accountant', 'Ana Cruz, CPA', 'Accountant', 'Demo@12345', 0],
        ['treasurer', 'Belinda Sy', 'Treasurer', 'Demo@12345', 0],
        ['arclerk', 'Ben Garcia', 'A/R Clerk', 'Demo@12345', 0],
        ['apclerk', 'Carla Lim', 'A/P Clerk', 'Demo@12345', 0],
        ['invclerk', 'Dan Villanueva', 'Inventory Clerk', 'Demo@12345', 0],
        ['salesrep', 'Maria Santos', 'Corporate Sales', 'Demo@12345', 0],
        ['canteen1', 'Ella Ramos', 'Canteen Cashier', 'Demo@12345', 0],
        ['purser', 'Fred Tan', 'Purser (POS Supervisor)', 'Demo@12345', 0],
        ['auditor', 'Grace Uy', 'Internal Auditor', 'Demo@12345', 0],
        ['regulator', 'PCG Inspector (read-only)', 'Regulator (read-only)', 'Demo@12345', 0],
    ];
    $n = 0;
    foreach ($users as [$u, $full, $role, $pw, $must]) {
        $uid = DB::insert('users', [
            'username' => $u, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $full,
            'email' => $u . '@visayasstar.test', 'employee_no' => sprintf('VSS-%03d', ++$n), 'status' => 'active',
            'must_change_password' => $must, 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $roleIds[$role]]);
    }

    // ---------------------------------------------------------------- vendors used by the set-up (the port authority, the insurer)
    $vend = [];
    foreach ([
        ['Philippine Ports Authority - PMO Cebu', 'Revenue Section', '(032) 232-8400', 'pmocebu@ppa.test', 'Cebu Baseport, Cebu City', 'Due on receipt', '5320', 'NT'],
        ['Pioneer Marine Insurance', 'Marine Desk', '(02) 8812-7777', 'marine@pioneer.test', 'Makati City', 'Net 30', '5360', 'NT'],
    ] as [$name, $contact, $phone, $email, $addr, $t, $exp, $tc]) {
        $vend[$name] = DB::insert('vendors', [
            'name' => $name, 'company_name' => $name, 'contact_person' => $contact, 'phone' => $phone, 'email' => $email, 'address' => $addr,
            'terms_id' => $terms[$t], 'default_expense_account_id' => $acct[$exp], 'tax_code_id' => $tax[$tc],
        ]);
    }

    // ---------------------------------------------------------------- items: shipping services, fuel
    $item = [];
    $mk = function (array $d) use (&$item, $acct, $tax, $cat) {
        $row = array_merge([
            'income_account_id' => $acct['4080'], 'cogs_account_id' => $acct['5000'], 'asset_account_id' => null,
            'expense_account_id' => null, 'tax_code_id' => $tax['VAT12'], 'unit' => 'pc',
        ], $d);
        if (isset($row['cat'])) {
            $row['category_id'] = $cat[$row['cat']];
            unset($row['cat']);
        }
        if (in_array($row['item_type'], ['inventory', 'assembly'], true)) {
            $row['asset_account_id'] ??= $acct['1200'];
            $row['avg_cost'] = 0;
        }
        $item[$row['code']] = DB::insert('items', $row);
    };
    foreach ([
        ['SHP-FARE', 'Passenger Fare', '2140', 'VAT12', 'Passenger fare (deferred until the voyage is completed)'],
        ['SHP-SURCH', 'Fuel Surcharge', '2140', 'VAT12', 'Fuel surcharge'],
        ['SHP-VEH', 'Vehicle / RoRo Freight', '2145', 'VAT12', 'Rolling cargo / vehicle freight'],
        ['SHP-CARGO', 'Cargo Freight', '2145', 'VAT12', 'Cargo freight'],
        ['SHP-HANDL', 'Cargo Handling Fee', '4025', 'VAT12', 'Cargo handling'],
        ['SHP-CINS', 'Cargo Insurance (for the insurer)', '2155', 'NT', 'Cargo insurance premium collected for the insurer'],
        ['SHP-TFEE', 'Terminal Fee (for the port authority)', '2150', 'NT', 'Passenger terminal fee collected for the port authority'],
        ['SHP-PINS', 'Passenger Insurance (for the insurer)', '2155', 'NT', 'Passenger accident insurance premium collected for the insurer'],
        ['SHP-CHG', 'Ticket Change / Rebooking Fee', '4040', 'VAT12', 'Ticket change, rebooking or revalidation fee'],
        ['SHP-BAG', 'Excess Baggage', '4030', 'VAT12', 'Excess baggage charge'],
    ] as [$code, $name, $inc, $tc, $desc]) {
        $mk(['item_type' => 'service', 'code' => $code, 'name' => $name, 'description' => $desc, 'sales_price' => 0, 'income_account_id' => $acct[$inc],
            'tax_code_id' => $tax[$tc], 'cat' => 'Shipping Services', 'unit' => 'ea']);
    }
    $mk(['item_type' => 'inventory', 'code' => 'FUEL-MDO', 'name' => 'Marine Diesel Oil', 'description' => 'Marine diesel oil (liters)', 'purchase_description' => 'Marine diesel oil',
        'cost' => 62.50, 'sales_price' => 0, 'unit' => 'L', 'asset_account_id' => $acct['1210'], 'cogs_account_id' => $acct['5300'], 'cat' => 'Fuel and Lubricants', 'reorder_point' => 0]);
    $mk(['item_type' => 'inventory', 'code' => 'LUBE-40', 'name' => 'Engine Lube Oil SAE 40', 'description' => 'Marine engine lube oil SAE 40 (liters)', 'purchase_description' => 'Lube oil SAE 40',
        'cost' => 185, 'sales_price' => 0, 'unit' => 'L', 'asset_account_id' => $acct['1210'], 'cogs_account_id' => $acct['5310'], 'cat' => 'Fuel and Lubricants', 'reorder_point' => 200]);
    foreach ($uomSets as $baseUnit => $sid) {
        DB::run('UPDATE items SET uom_set_id = :s WHERE unit = :u', ['s' => $sid, 'u' => $baseUnit]);
    }

    // ---------------------------------------------------------------- customers
    DB::insert('customers', ['name' => 'Walk-in Passenger', 'terms_id' => $terms['Due on receipt'], 'customer_type_id' => $ctype['Walk-in Passenger'],
        'payment_method_id' => $pm['Cash'], 'tax_code_id' => $tax['VAT12'], 'notes' => 'Individual passengers paying at the counter or on the portal are receipted under this name; the passengers themselves are on the tickets.']);

    // ---------------------------------------------------------------- fixed asset categories
    foreach ([
        ['Vessels and Floating Equipment', '1520', '1525', 'straight_line', 20],
        ['Dry-docking (amortized to the next dry-dock)', '1526', '1527', 'straight_line', 2.5],
        ['Terminal Buildings and Improvements', '1510', '1515', 'straight_line', 25],
        ['Transportation Equipment', '1530', '1535', 'declining_balance', 5],
        ['Furniture and Fixtures', '1540', '1545', 'straight_line', 10],
        ['Computer and Ticketing Equipment', '1550', '1555', 'declining_balance', 3],
    ] as [$nm, $a, $ad, $m, $life]) {
        DB::insert('asset_categories', ['name' => $nm, 'asset_account_id' => $acct[$a], 'accum_dep_account_id' => $acct[$ad],
            'dep_expense_account_id' => $acct[$a === '1526' ? '6095' : '6090'], 'default_method' => $m, 'default_life_years' => $life]);
    }

    // ---------------------------------------------------------------- sequences
    foreach ([
        ['estimate', 'EST-', 1, 5], ['sales_order', 'SO-', 1, 5], ['invoice', 'INV-', 1, 5], ['sales_receipt', 'SR-', 1, 6],
        ['statement_charge', 'SC-', 1, 5], ['finance_charge', 'FC-', 1, 5], ['payment', 'PMT-', 1, 5], ['credit_memo', 'CM-', 1, 5],
        ['purchase_order', 'PO-', 1, 5], ['bill', 'BILL-', 1, 5], ['item_receipt', 'IR-', 1, 5], ['check', '', 1001, 4],
        ['deposit', 'DEP-', 1, 5], ['transfer', 'TRF-', 1, 5], ['journal', 'JE-', 1, 5], ['adjustment', 'ADJ-', 1, 5],
        ['inv_transfer', 'IT-', 1, 5], ['build', 'BLD-', 1, 5], ['asset', 'FA-', 1, 5], ['pos', 'POS-', 1, 6],
        ['processing', 'PRC-', 1, 5], ['cash_voucher', 'CAV-', 1, 5],
        ['maintenance', 'MNT-', 1, 5], ['debit_memo', 'DM-', 1, 5], ['vendor_advance', 'ADV-', 1, 5], ['advance_apply', 'AAP-', 1, 5],
        ['shp_ticket', 'ET-', 1, 8], ['shp_vehicle', 'VP-', 1, 7], ['shp_payment', 'PAY-', 1, 7], ['shp_refund', 'RF-', 1, 6], ['shp_waybill', 'WB-', 1, 7],
        ['shp_shift', 'SH-', 1, 6], ['shp_defect', 'DR-', 1, 6], ['shp_wo', 'WO-', 1, 6], ['shp_pr', 'PR-', 1, 6], ['shp_case', 'CS-', 1, 6], ['shp_drydock', 'DD-', 1, 4],
    ] as [$k, $pfx, $next, $pad]) {
        DB::insert('sequences', ['seq_key' => $k, 'prefix' => $pfx, 'next_no' => $next, 'pad' => $pad]);
    }

    // ---------------------------------------------------------------- ports (public list) and the main ticket office
    $port = [];
    foreach ([
        ['CEB', 'Cebu Baseport', 'Cebu City', 'Cebu', 'VII', 'PPA PMO Cebu', 'Pier 1, Pier 3, Pier 4, Pier 5'],
        ['TAG', 'Tagbilaran Port', 'Tagbilaran City', 'Bohol', 'VII', 'PPA PMO Bohol', 'Berth 1, Berth 2'],
        ['ORM', 'Ormoc Port', 'Ormoc City', 'Leyte', 'VIII', 'PPA PMO Ormoc', 'Berth 1, Berth 2'],
        ['DGT', 'Dumaguete Port', 'Dumaguete City', 'Negros Oriental', 'VII', 'PPA PMO Negros Oriental', 'Berth 1'],
        ['CGY', 'Cagayan de Oro Port', 'Cagayan de Oro City', 'Misamis Oriental', 'X', 'PPA PMO Misamis Oriental', 'Berth 3, Berth 4'],
        ['ILO', 'Iloilo Port', 'Iloilo City', 'Iloilo', 'VI', 'PPA PMO Iloilo', 'Fort San Pedro, Berth 2'],
        ['BCD', 'Bacolod (BREDCO) Port', 'Bacolod City', 'Negros Occidental', 'VI', 'Private port operator', 'BREDCO 1'],
        ['BTG', 'Batangas Port', 'Batangas City', 'Batangas', 'IV-A', 'PPA PMO Batangas', 'Terminal 1, Terminal 2'],
        ['CPN', 'Calapan Port', 'Calapan City', 'Oriental Mindoro', 'MIMAROPA', 'PPA PMO Mindoro', 'RoRo Ramp 1, RoRo Ramp 2'],
        ['MNL', 'Manila North Harbor', 'Manila', 'Metro Manila', 'NCR', 'Manila North Harbour Port Inc.', 'Pier 4, Pier 6'],
        ['SUR', 'Surigao Port', 'Surigao City', 'Surigao del Norte', 'XIII', 'PPA PMO Surigao', 'Berth 1'],
        ['LIL', 'Liloan Port', 'Liloan', 'Southern Leyte', 'VIII', 'PPA TMO Liloan', 'RoRo Ramp'],
        ['STB', 'Santander Port', 'Santander', 'Cebu', 'VII', 'Cebu Port Authority', 'RoRo Ramp'],
        ['CAT', 'Caticlan Jetty Port', 'Malay', 'Aklan', 'VI', 'Provincial Government of Aklan', 'Jetty 1'],
    ] as [$code, $name, $city, $prov, $region, $auth, $berths]) {
        $port[$code] = DB::insert('shp_ports', ['code' => $code, 'name' => $name, 'city' => $city, 'province' => $prov, 'region' => $region,
            'authority' => $auth, 'berths' => $berths, 'fee_vendor_id' => $code === 'CEB' ? $vend['Philippine Ports Authority - PMO Cebu'] : null]);
    }
    DB::insert('shp_offices', ['code' => 'CEB-P4', 'name' => 'Cebu Pier 4 Ticket Office', 'port_id' => $port['CEB'], 'office_type' => 'ticket_office', 'address' => 'Pier 4 Passenger Terminal, Cebu City']);
    DB::insert('shp_offices', ['code' => 'WEB', 'name' => 'Online Portal', 'port_id' => null, 'office_type' => 'online', 'address' => null]);

    // ---------------------------------------------------------------- passenger categories (statutory privileges are configurable and dated)
    foreach ([
        ['REG', 'Regular (adult)', 0, 0, 0, 0, null, null, null, null, 0, 10],
        ['SC', 'Senior Citizen', 20, 1, 1, 1, 'OSCA / Senior Citizen ID', 'RA 9994 (Expanded Senior Citizens Act)', 60, null, 0, 20],
        ['PWD', 'Person with Disability', 20, 1, 1, 1, 'PWD ID', 'RA 10754 (benefits and privileges of PWD)', null, null, 0, 30],
        ['STU', 'Student', 20, 0, 1, 1, 'School ID / enrolment form', 'RA 11314 (Student Fare Discount Act)', null, null, 0, 40],
        ['MOV', 'Medal of Valor awardee / dependent', 20, 0, 1, 1, 'AFP Medal of Valor ID', 'RA 9049', null, null, 0, 50],
        ['CHD', 'Child (3 to 11 years)', 50, 0, 0, 0, null, 'Company policy', 3, 11, 0, 60],
        ['INF', 'Infant (below 3, on lap)', 100, 0, 0, 0, null, 'Company policy (no seat; counted on the manifest)', 0, 2, 1, 70],
    ] as [$code, $name, $pct, $vex, $stat, $req, $idl, $basis, $min, $max, $feeEx, $so]) {
        DB::insert('shp_fare_categories', ['code' => $code, 'name' => $name, 'discount_pct' => $pct, 'vat_exempt' => $vex, 'is_statutory' => $stat,
            'requires_id' => $req, 'id_label' => $idl, 'legal_basis' => $basis, 'min_age' => $min, 'max_age' => $max, 'fee_exempt' => $feeEx,
            'effective_from' => '2026-01-01', 'sort_order' => $so]);
    }

    // ---------------------------------------------------------------- vehicle classes (lane meters taken on the deck)
    foreach ([
        ['MC', 'Motorcycle / scooter', 2.5, 0.4, 1.0, 10], ['CAR', 'Car / SUV (up to 5 m)', 5.0, 2.5, 6.0, 20], ['VAN', 'Van / pick-up (up to 6 m)', 6.0, 3.5, 7.0, 30],
        ['TRK6', 'Light truck (up to 8 m)', 8.0, 10.0, 9.5, 40], ['BUS', 'Bus (up to 12 m)', 12.0, 16.0, 13.5, 50], ['TRK10', 'Heavy truck / 10-wheeler (up to 12 m)', 12.0, 30.0, 13.5, 60],
        ['TRL', 'Tractor-trailer (up to 18 m)', 18.0, 40.0, 19.5, 70],
    ] as [$code, $name, $len, $wt, $lane, $so]) {
        DB::insert('shp_vehicle_classes', ['code' => $code, 'name' => $name, 'max_length_m' => $len, 'max_weight_t' => $wt, 'lane_m' => $lane, 'sort_order' => $so]);
    }

    // ---------------------------------------------------------------- third-party charges (owner and account decide the posting)
    DB::insert('shp_fees', ['code' => 'TFEE', 'name' => 'Passenger Terminal Fee', 'fee_type' => 'terminal', 'applies_to' => 'passenger', 'calc' => 'per_pax', 'amount' => 30,
        'ownership' => 'agent', 'liability_account_id' => $acct['2150'], 'vendor_id' => $vend['Philippine Ports Authority - PMO Cebu'], 'port_id' => null, 'exempt_categories' => 'INF', 'sort_order' => 10]);
    DB::insert('shp_fees', ['code' => 'PINS', 'name' => 'Passenger Accident Insurance', 'fee_type' => 'insurance', 'applies_to' => 'passenger', 'calc' => 'per_pax', 'amount' => 20,
        'ownership' => 'agent', 'liability_account_id' => $acct['2155'], 'vendor_id' => $vend['Pioneer Marine Insurance'], 'exempt_categories' => 'INF', 'sort_order' => 20]);
    DB::insert('shp_fees', ['code' => 'VTFEE', 'name' => 'Vehicle Terminal Fee', 'fee_type' => 'terminal', 'applies_to' => 'vehicle', 'calc' => 'per_vehicle', 'amount' => 100,
        'ownership' => 'agent', 'liability_account_id' => $acct['2150'], 'vendor_id' => $vend['Philippine Ports Authority - PMO Cebu'], 'sort_order' => 30]);

    // ---------------------------------------------------------------- compliance checklist (a register of tasks, never a claim of compliance)
    foreach ([
        ['BIR', 'RR 7-2024 / RMC 77-2024', 'Register the invoicing system and invoice series (sales invoices, receipts) with the RDO', 'once'],
        ['BIR', 'RR 11-2025, RR 26-2025', 'Assess coverage and timing of electronic invoicing (EIS) for the company; plan the upgrade', 'once'],
        ['BIR', 'CAS / CBA', 'Computerized accounting system registration: keep the permit and the list of books current', 'annual'],
        ['BIR', 'RR 16-2005', 'File the monthly / quarterly VAT and withholding returns from the BIR Reports', 'monthly'],
        ['MARINA', 'MC DS 2026-01 (SOTS)', 'Review the Shipping Online Ticketing System requirements and their phased dates for each vessel', 'once'],
        ['MARINA', 'Certificates', 'Keep every vessel certificate (CPC, safety, load line, radio, tonnage) valid and uploaded', 'monthly'],
        ['PCG', 'Pre-departure inspection', 'Passenger manifest and pre-departure clearance before every voyage', 'once'],
        ['PPA', 'Terminal fees', 'Remit the passenger and vehicle terminal fees collected, with the reconciliation', 'monthly'],
        ['NPC', 'Data Privacy Act (RA 10173)', 'Privacy notice on the portal, retention schedule of manifests and IDs, breach response plan, DPO registration', 'annual'],
    ] as [$auth, $ref, $title, $freq]) {
        DB::insert('shp_compliance', ['title' => $title, 'authority' => $auth, 'reference' => $ref, 'frequency' => $freq, 'status' => 'needs_review',
            'description' => 'Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.']);
    }
}
