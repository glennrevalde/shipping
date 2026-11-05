<?php
declare(strict_types=1);

/**
 * Sample data of the G-SMaRT Shipping System: three vessels on four routes, about four weeks of voyages
 * around today (completed, departing today, and on sale), with bookings, payments, boarding, manifests,
 * vehicles, cargo, fuel, voyage costs, refunds, maintenance, purchasing, support cases, cashier shifts and
 * deposits. Every record goes through the same services the screens use, so the ledger, the stock, the
 * manifests and the audit trail agree with each other. Past days are replayed with Ship::$clock.
 */
require_once APP_ROOT . '/views/layout.php';

function demo_id(string $table, string $col, string $val): int
{
    $id = DB::value("SELECT id FROM `$table` WHERE `$col` = :v", ['v' => $val]);
    if (!$id) {
        throw new RuntimeException("Demo data: $table.$col = $val not found");
    }
    return (int)$id;
}

function demo_as(string $username): void
{
    Auth::actAs(demo_id('users', 'username', $username));
    Access::reset();
}

function load_demo_data(): void
{
    mt_srand(20261031);
    $today = date('Y-m-d');
    $at = fn(string $date, string $time = '08:00') => $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    $day = fn(int $offset) => date('Y-m-d', strtotime($today . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days'));
    $acct = fn(string $c) => demo_id('accounts', 'code', $c);
    $start = $day(-24);
    Ship::$clock = $at($day(-40), '08:00');
    demo_as('admin');

    // ---------------------------------------------------------------- accounting set-up: customers, suppliers, items
    $terms = DB::pairs('SELECT name, id FROM terms');
    $ctype = DB::pairs('SELECT name, id FROM customer_types');
    $tax = DB::pairs('SELECT code, id FROM tax_codes');
    $wht = DB::pairs('SELECT code, id FROM withholding_taxes');
    $cust = [];
    foreach ([
        ['Island Hopper Travel & Tours', 'Travel Agency', 'Net 15', 300000, 'Ms. Joy Sarmiento', 'bookings@islandhopper.test', '(032) 255-1188', 'Mango Ave., Cebu City', null],
        ['Bohol Countryside Bus Lines', 'Bus Operator', 'Net 15', 200000, 'Mr. Ramil Uy', 'dispatch@bcbl.test', '(038) 411-2200', 'Dao Terminal, Tagbilaran City', null],
        ['Visayan Agri Traders Corp.', 'Cargo Shipper', 'Net 30', 500000, 'Mr. Henry Chua', 'logistics@visagri.test', '(032) 345-6677', 'Mandaue City, Cebu', null],
        ['Mindanao Fresh Produce Coop', 'Cargo Shipper', 'Net 15', 150000, 'Ms. Lorna Dagohoy', 'coop@mfpc.test', '(088) 856-2211', 'Puerto, Cagayan de Oro City', null],
        ['Cebu Hardware Depot', 'Cargo Shipper', 'Net 30', 250000, 'Mr. Sonny Go', 'ap@cebuhardware.test', '(032) 416-9090', 'Colon St., Cebu City', null],
        ['DepEd Region VII', 'Government', 'Net 30', 0, 'Procurement Unit', 'procurement.r7@deped.test', '(032) 231-1433', 'Sudlon, Lahug, Cebu City', 'WT-VAT,WT-SERVICES'],
    ] as [$name, $type, $t, $limit, $contact, $email, $phone, $addr, $w]) {
        $cust[$name] = DB::insert('customers', ['name' => $name, 'company_name' => $name, 'contact_person' => $contact, 'email' => $email, 'phone' => $phone,
            'billing_address' => $addr, 'terms_id' => $terms[$t], 'credit_limit' => $limit, 'customer_type_id' => $ctype[$type], 'tax_code_id' => $tax['VAT12'],
            'tin' => sprintf('%03d-%03d-%03d-000', mt_rand(100, 999), mt_rand(100, 999), mt_rand(100, 999)),
            'wht_ids' => $w ? implode(',', array_map(fn($c) => $wht[$c], explode(',', $w))) : null]);
    }
    $vend = DB::pairs('SELECT name, id FROM vendors');
    foreach ([
        ['Petron Marine Fuels', 'Marine Sales', '(032) 344-7000', 'marine@petron.test', 'Mandaue City', 'Net 15', '1210', 'WT-GOODS'],
        ['Cebu Shipyard and Engineering Works', 'Yard Manager', '(032) 340-1234', 'yard@cebushipyard.test', 'Lapu-Lapu City', 'Net 30', '5350', 'WT-SERVICES'],
        ['Marine Engine Parts Trading', 'Sales', '(032) 238-5511', 'sales@mept.test', 'Pier 3 Area, Cebu City', 'Net 30', '1200', 'WT-GOODS'],
        ['Seaborne Ship Chandlers', 'Orders', '(032) 233-7788', 'orders@seaborne.test', 'Cebu City', 'Net 15', '5395', null],
        ['Harbor Pilots Association of Cebu', 'Dispatch', '(032) 232-0011', 'dispatch@hpac.test', 'Cebu City', 'Due on receipt', '5330', null],
        ['Tug Services Visayas', 'Operations', '(032) 236-4455', 'ops@tugvis.test', 'Cebu City', 'Net 15', '5330', 'WT-SERVICES'],
        ['Accounting Partners & Co.', 'Atty. Reyes', '(02) 8555-0606', 'hello@apco.test', 'Taguig City', 'Net 30', '6070', 'WT-SERVICES'],
    ] as [$name, $contact, $phone, $email, $addr, $t, $exp, $w]) {
        $vend[$name] = DB::insert('vendors', ['name' => $name, 'company_name' => $name, 'contact_person' => $contact, 'phone' => $phone, 'email' => $email, 'address' => $addr,
            'terms_id' => $terms[$t], 'default_expense_account_id' => $acct($exp), 'tax_code_id' => $tax['VAT12'], 'wht_ids' => $w ? $wht[$w] : null,
            'tin' => sprintf('%03d-%03d-%03d-000', mt_rand(100, 999), mt_rand(100, 999), mt_rand(100, 999))]);
    }
    $cat = DB::pairs('SELECT name, id FROM item_categories');
    $uomPc = (int)DB::value("SELECT id FROM uom_sets WHERE base_unit = 'pc'");
    $item = [];
    foreach ([
        ['SP-FFILT', 'Fuel Filter Element (main engine)', 1850, 'Spare Parts', 'Marine Engine Parts Trading', 12],
        ['SP-OFILT', 'Lube Oil Filter (main engine)', 1450, 'Spare Parts', 'Marine Engine Parts Trading', 12],
        ['SP-IMP', 'Sea Water Pump Impeller', 6800, 'Spare Parts', 'Marine Engine Parts Trading', 4],
        ['SP-BELT', 'Alternator V-Belt Set', 2200, 'Spare Parts', 'Marine Engine Parts Trading', 6],
        ['SP-GASK', 'Cylinder Head Gasket', 3900, 'Spare Parts', 'Marine Engine Parts Trading', 4],
        ['SP-INJ', 'Fuel Injector Nozzle', 8500, 'Spare Parts', 'Marine Engine Parts Trading', 6],
        ['SP-HOSE', 'Hydraulic Hose Assembly (steering)', 5200, 'Spare Parts', 'Marine Engine Parts Trading', 4],
        ['SP-BATT', '12V Marine Battery (150 Ah)', 9800, 'Spare Parts', 'Marine Engine Parts Trading', 4],
        ['SP-LJ', 'Life Jacket, adult (SOLAS)', 1350, 'Ship Stores', 'Seaborne Ship Chandlers', 40],
        ['SP-ZINC', 'Zinc Anode (hull)', 950, 'Spare Parts', 'Seaborne Ship Chandlers', 20],
        ['CAN-WTR', 'Bottled Water 500 ml', 12, 'Canteen', 'Seaborne Ship Chandlers', 200],
        ['CAN-NDL', 'Cup Noodles', 28, 'Canteen', 'Seaborne Ship Chandlers', 120],
    ] as [$code, $name, $cost, $c, $v, $reorder]) {
        $isCanteen = $c === 'Canteen';
        $item[$code] = DB::insert('items', ['item_type' => 'inventory', 'code' => $code, 'name' => $name, 'description' => $name, 'purchase_description' => $name, 'cost' => $cost,
            'sales_price' => $isCanteen ? round($cost * 2.5) : 0, 'avg_cost' => 0, 'unit' => 'pc', 'uom_set_id' => $uomPc, 'category_id' => $cat[$c], 'preferred_vendor_id' => $vend[$v],
            'income_account_id' => $acct('4080'), 'cogs_account_id' => $acct($isCanteen ? '5000' : '5355'), 'asset_account_id' => $acct($isCanteen ? '1220' : ($c === 'Ship Stores' ? '1230' : '1200')),
            'tax_code_id' => $tax['VAT12'], 'reorder_point' => $reorder, 'max_stock' => $reorder * 3]);
    }
    $item['FUEL-MDO'] = demo_id('items', 'code', 'FUEL-MDO');
    $item['LUBE-40'] = demo_id('items', 'code', 'LUBE-40');
    $shore = demo_id('sites', 'code', 'SHORE-CEB');

    // ---------------------------------------------------------------- opening balances (the company was operating before the system)
    Txn::save('opening_balance', ['txn_date' => $day(-40), 'memo' => 'Opening balances', 'txn_no' => 'OB-0001'], [
        ['line_kind' => 'journal', 'account_id' => $acct('1010'), 'amount' => 18500000, 'description' => 'BDO current account'],
        ['line_kind' => 'journal', 'account_id' => $acct('1020'), 'amount' => 6200000, 'description' => 'Landbank savings'],
        ['line_kind' => 'journal', 'account_id' => $acct('1005'), 'amount' => 20000, 'description' => 'Petty cash fund'],
        ['line_kind' => 'journal', 'account_id' => $acct('1001'), 'amount' => 60000, 'description' => 'Ticket office cash funds (refunds and change)'],
        ['line_kind' => 'journal', 'account_id' => $acct('1520'), 'amount' => 418000000, 'description' => 'Vessels at cost'],
        ['line_kind' => 'journal', 'account_id' => $acct('1525'), 'amount' => -96400000, 'description' => 'Accumulated depreciation of the vessels'],
        ['line_kind' => 'journal', 'account_id' => $acct('1510'), 'amount' => 24000000, 'description' => 'Pier 4 terminal building'],
        ['line_kind' => 'journal', 'account_id' => $acct('1515'), 'amount' => -4800000, 'description' => 'Accumulated depreciation of the terminal'],
        ['line_kind' => 'journal', 'account_id' => $acct('1405'), 'amount' => 3600000, 'description' => 'Hull and machinery insurance paid ahead'],
        ['line_kind' => 'journal', 'account_id' => $acct('2500'), 'amount' => -150000000, 'description' => 'Vessel financing loan'],
        ['line_kind' => 'journal', 'account_id' => $acct('3000'), 'amount' => -150000000, 'description' => 'Capital stock'],
        ['line_kind' => 'journal', 'account_id' => $acct('3200'), 'amount' => -69180000, 'description' => 'Retained earnings'],
    ]);

    // ---------------------------------------------------------------- ports, offices, routes
    $port = DB::pairs('SELECT code, id FROM shp_ports');
    DB::update('shp_ports', ['fee_vendor_id' => $vend['Philippine Ports Authority - PMO Cebu']], 'code IN (\'TAG\', \'ORM\', \'CGY\')');
    $office = DB::pairs('SELECT code, id FROM shp_offices');
    foreach ([['TAG-T', 'Tagbilaran Terminal Counter', 'TAG', 'terminal_counter'], ['ORM-T', 'Ormoc Terminal Counter', 'ORM', 'terminal_counter'], ['CGY-T', 'Cagayan de Oro Terminal Counter', 'CGY', 'terminal_counter']] as [$c, $n, $p, $t]) {
        $office[$c] = DB::insert('shp_offices', ['code' => $c, 'name' => $n, 'port_id' => $port[$p], 'office_type' => $t]);
    }
    foreach ([['ticket1', 'CEB-P4'], ['ticket2', 'CEB-P4'], ['ticket2', 'ORM-T'], ['portagent', 'TAG-T'], ['portagent', 'CGY-T'], ['cargoclerk', 'CEB-P4']] as [$u, $o]) {
        DB::insert('shp_user_offices', ['user_id' => demo_id('users', 'username', $u), 'office_id' => $office[$o]]);
    }
    $route = [];
    foreach ([
        ['CEB-TAG', 'CEB', 'TAG', 42, 120, 'fastcraft', 'Daily, 08:00 from Cebu and 13:00 from Tagbilaran'],
        ['TAG-CEB', 'TAG', 'CEB', 42, 120, 'fastcraft', 'Daily'],
        ['CEB-ORM', 'CEB', 'ORM', 86, 330, 'ropax', 'Overnight, Mon / Wed / Fri'],
        ['ORM-CEB', 'ORM', 'CEB', 86, 330, 'ropax', 'Overnight, Tue / Thu / Sat'],
        ['CEB-CGY', 'CEB', 'CGY', 167, 600, 'ropax', 'Overnight, Tue / Thu / Sun'],
        ['CGY-CEB', 'CGY', 'CEB', 167, 600, 'ropax', 'Overnight, Mon / Wed / Sat'],
    ] as [$code, $o, $d, $nm, $min, $type, $note]) {
        $route[$code] = DB::insert('shp_routes', ['code' => $code, 'name' => DB::value('SELECT name FROM shp_ports WHERE code = :c', ['c' => $o]) . ' to ' . DB::value('SELECT name FROM shp_ports WHERE code = :c', ['c' => $d]),
            'origin_port_id' => $port[$o], 'dest_port_id' => $port[$d], 'distance_nm' => $nm, 'transit_minutes' => $min, 'service_type' => $type, 'sailing_note' => $note]);
    }

    // ---------------------------------------------------------------- vessels, accommodation plans, certificates
    $vessels = [
        'SOB' => ['name' => 'MV Star of Bohol', 'vessel_type' => 'fastcraft', 'official_no' => 'CEB2017-0412', 'call_sign' => 'DUH2417', 'classification' => 'MARINA / BV class', 'owner_operator' => 'Visayas Star Shipping Lines, Inc.',
            'year_built' => 2017, 'gross_tonnage' => 498, 'net_tonnage' => 210, 'deadweight_t' => 60, 'length_m' => 42, 'breadth_m' => 10.6, 'crew_complement' => 14, 'lane_meters' => 0, 'vehicle_lanes' => 0,
            'max_vehicle_weight_t' => 0, 'max_vehicle_length_m' => 0, 'cargo_capacity_t' => 5, 'cargo_capacity_cbm' => 20, 'service_speed_kn' => 26, 'fuel_type' => 'Marine diesel oil',
            'fuel_item_id' => $item['FUEL-MDO'], 'fuel_tank_l' => 18000, 'fuel_rate_lph' => 260, 'home_port_id' => $port['CEB'], 'pax_capacity' => 0, 'cost' => 98000000, 'acc' => 23000000],
        'VSP' => ['name' => 'MV Visayan Pearl', 'vessel_type' => 'ropax', 'official_no' => 'CEB2009-0188', 'call_sign' => 'DUA4120', 'classification' => 'MARINA / ClassNK', 'owner_operator' => 'Visayas Star Shipping Lines, Inc.',
            'year_built' => 2009, 'gross_tonnage' => 4210, 'net_tonnage' => 1980, 'deadweight_t' => 1200, 'length_m' => 98, 'breadth_m' => 18.4, 'crew_complement' => 42, 'lane_meters' => 320, 'vehicle_lanes' => 4,
            'max_vehicle_weight_t' => 40, 'max_vehicle_length_m' => 18, 'cargo_capacity_t' => 300, 'cargo_capacity_cbm' => 900, 'service_speed_kn' => 15.5, 'fuel_type' => 'Marine diesel oil',
            'fuel_item_id' => $item['FUEL-MDO'], 'fuel_tank_l' => 120000, 'fuel_rate_lph' => 300, 'home_port_id' => $port['CEB'], 'pax_capacity' => 0, 'cost' => 140000000, 'acc' => 46000000],
        'MEX' => ['name' => 'MV Mindanao Express', 'vessel_type' => 'ropax', 'official_no' => 'MNL2012-0731', 'call_sign' => 'DUB7731', 'classification' => 'MARINA / BV class', 'owner_operator' => 'Visayas Star Shipping Lines, Inc.',
            'year_built' => 2012, 'gross_tonnage' => 6650, 'net_tonnage' => 3100, 'deadweight_t' => 2100, 'length_m' => 118, 'breadth_m' => 21, 'crew_complement' => 56, 'lane_meters' => 460, 'vehicle_lanes' => 5,
            'max_vehicle_weight_t' => 45, 'max_vehicle_length_m' => 19.5, 'cargo_capacity_t' => 600, 'cargo_capacity_cbm' => 1800, 'service_speed_kn' => 17, 'fuel_type' => 'Marine diesel oil',
            'fuel_item_id' => $item['FUEL-MDO'], 'fuel_tank_l' => 180000, 'fuel_rate_lph' => 330, 'home_port_id' => $port['CEB'], 'pax_capacity' => 0, 'cost' => 180000000, 'acc' => 27400000],
    ];
    $plans = [
        'SOB' => [['TOU', 'Tourist', 'seat', 'Main deck', 1, 18, 10, 10], ['BUS', 'Business', 'seat', 'Upper deck', 1, 6, 6, 4]],
        'VSP' => [['ECO', 'Economy (open air)', 'bunk', 'Deck 3 aft', 0, 0, 0, 0, 260], ['TOU', 'Tourist', 'bunk', 'Deck 3', 1, 16, 8, 8], ['BUS', 'Business class', 'seat', 'Deck 4', 1, 8, 6, 4], ['CAB', 'Private cabin (berth)', 'cabin', 'Deck 4', 1, 6, 4, 0]],
        'MEX' => [['ECO', 'Economy', 'bunk', 'Deck 3', 0, 0, 0, 0, 420], ['TOU', 'Tourist', 'bunk', 'Deck 3', 1, 20, 8, 10], ['BUS', 'Business class', 'seat', 'Deck 5', 1, 10, 6, 6], ['CAB', 'State room (berth)', 'cabin', 'Deck 5', 1, 8, 4, 0]],
    ];
    $vid = [];
    $faCat = (int)DB::value("SELECT id FROM asset_categories WHERE name = 'Vessels and Floating Equipment'");
    $locId = DB::insert('asset_locations', ['name' => 'Fleet']);
    foreach ($vessels as $code => $v) {
        $cost = $v['cost'];
        $accDep = $v['acc'];
        unset($v['cost'], $v['acc']);
        $id = Fleet::save($v + ['code' => $code]);
        $rows = [];
        foreach ($plans[$code] as $p) {
            $rows[] = ['code' => $p[0], 'name' => $p[1], 'kind' => $p[2], 'deck' => $p[3], 'reserved_seating' => $p[4], 'seat_rows' => $p[5], 'seat_cols' => $p[6], 'priority_seats' => $p[7], 'capacity' => $p[8] ?? 0];
        }
        Fleet::saveAccommodation($id, $rows);
        $vid[$code] = $id;
        $faId = DB::insert('fixed_assets', ['asset_no' => Seq::next('asset'), 'description' => $v['name'], 'category_id' => $faCat, 'location' => 'Fleet', 'location_id' => $locId,
            'class_id' => ShipGL::vesselClass($id), 'acquisition_date' => ($v['year_built'] + 1) . '-01-15', 'cost' => $cost, 'salvage_value' => round($cost * .1), 'useful_life_years' => 25,
            'method' => 'straight_line', 'opening_accum_dep' => $accDep, 'opening_through' => date('Y-m-t', strtotime($day(-40) . ' -1 month')), 'serial_no' => $v['official_no']]);
        DB::update('shp_vessels', ['fixed_asset_id' => $faId], 'id = :id', ['id' => $id]);
        foreach ([
            // type, issuer, days left, critical, years of validity
            ['Certificate of Public Convenience (MARINA)', 'MARINA', 300, 1, 5], ['Passenger Ship Safety Certificate', 'MARINA', 140, 1, 1], ['Load Line Certificate', 'Class society', 400, 1, 5],
            ['Radio Station License (NTC)', 'NTC', 25, 1, 1], ['International Tonnage Certificate', 'MARINA', 2000, 0, 10], ['P&I / Passenger Liability Insurance', 'Pioneer Marine Insurance', 60, 1, 1],
            ['Minimum Safe Manning Certificate', 'MARINA', 210, 1, 5], ['Garbage Management Plan Approval', 'PCG', 18, 0, 3],
        ] as [$type, $issuer, $days, $crit, $years]) {
            $exp = $day($days + mt_rand(-5, 20));
            DB::insert('shp_certificates', ['vessel_id' => $id, 'cert_type' => $type, 'cert_no' => strtoupper(substr($code, 0, 3)) . '-' . mt_rand(10000, 99999), 'issuer' => $issuer,
                'issued_on' => date('Y-m-d', strtotime($exp . " -$years years")), 'expires_on' => $exp, 'is_critical' => $crit, 'alert_days' => 30]);
        }
    }
    // canteen POS terminal on each RoPax
    foreach (['VSP', 'MEX'] as $code) {
        $sid = (int)DB::value('SELECT site_id FROM shp_vessels WHERE id = :id', ['id' => $vid[$code]]);
        $tid = DB::insert('pos_terminals', ['code' => 'CAN-' . $code, 'name' => 'Canteen, ' . $vessels[$code]['name'], 'site_id' => $sid, 'cash_account_id' => $acct('1002'), 'receipt_prefix' => $code . '-', 'class_id' => ShipGL::vesselClass($vid[$code])]);
        foreach (['canteen1', 'purser'] as $u) {
            DB::insert('user_terminals', ['user_id' => demo_id('users', 'username', $u), 'terminal_id' => $tid]);
            DB::insert('user_sites', ['user_id' => demo_id('users', 'username', $u), 'site_id' => $sid]);
        }
    }

    // ---------------------------------------------------------------- fares, vehicle rates, cargo tariffs, promos
    $from = $day(-60);
    $fares = [
        'CEB-TAG' => ['TOU' => [800, 0], 'BUS' => [1150, 0]], 'TAG-CEB' => ['TOU' => [800, 0], 'BUS' => [1150, 0]],
        'CEB-ORM' => ['ECO' => [720, 50], 'TOU' => [980, 50], 'BUS' => [1250, 50], 'CAB' => [2600, 50]], 'ORM-CEB' => ['ECO' => [720, 50], 'TOU' => [980, 50], 'BUS' => [1250, 50], 'CAB' => [2600, 50]],
        'CEB-CGY' => ['ECO' => [1350, 80], 'TOU' => [1750, 80], 'BUS' => [2300, 80], 'CAB' => [4800, 80]], 'CGY-CEB' => ['ECO' => [1350, 80], 'TOU' => [1750, 80], 'BUS' => [2300, 80], 'CAB' => [4800, 80]],
    ];
    foreach ($fares as $r => $list) {
        foreach ($list as $code => [$base, $sur]) {
            DB::insert('shp_fares', ['route_id' => $route[$r], 'accom_code' => $code, 'base_fare' => $base, 'peak_fare' => round($base * 1.15), 'fuel_surcharge' => $sur,
                'tax_code_id' => $tax['VAT12'], 'effective_from' => $from, 'approved_by' => demo_id('users', 'username', 'gm'), 'created_by' => demo_id('users', 'username', 'tktmgr')]);
        }
    }
    // an older fare kept in the history (ended the day before the current one)
    DB::insert('shp_fares', ['route_id' => $route['CEB-TAG'], 'accom_code' => 'TOU', 'base_fare' => 750, 'fuel_surcharge' => 0, 'tax_code_id' => $tax['VAT12'],
        'effective_from' => $day(-200), 'effective_to' => date('Y-m-d', strtotime($from . ' -1 day')), 'notes' => 'Previous fare']);
    DB::insert('shp_peak_dates', ['name' => 'Undas (All Saints\' and All Souls\' Day) travel', 'date_from' => $day(-3), 'date_to' => $day(3)]);
    $vc = DB::pairs('SELECT code, id FROM shp_vehicle_classes');
    foreach (['CEB-ORM', 'ORM-CEB', 'CEB-CGY', 'CGY-CEB'] as $r) {
        $mult = str_contains($r, 'CGY') ? 1.7 : 1.0;
        foreach (['MC' => 1100, 'CAR' => 3800, 'VAN' => 4600, 'TRK6' => 8200, 'BUS' => 12500, 'TRK10' => 15800, 'TRL' => 22000] as $c => $rate) {
            DB::insert('shp_vehicle_rates', ['route_id' => $route[$r], 'vehicle_class_id' => $vc[$c], 'rate' => round($rate * $mult, -1), 'includes_driver' => 0, 'effective_from' => $from]);
        }
    }
    foreach (['general' => [2.20, 1650, 450, 150, .3], 'perishable' => [3.10, 2100, 600, 200, .5], 'dangerous' => [4.50, 2900, 1200, 400, .8]] as $cc => [$kg, $cbm, $min, $hand, $ins]) {
        DB::insert('shp_cargo_tariffs', ['route_id' => null, 'commodity_class' => $cc, 'rate_per_kg' => $kg, 'rate_per_cbm' => $cbm, 'min_charge' => $min, 'handling_fee' => $hand, 'insurance_pct' => $ins, 'effective_from' => $from]);
    }
    DB::insert('shp_promos', ['code' => 'SAKAY10', 'name' => 'Online launch promo', 'discount_pct' => 10, 'valid_from' => $day(-30), 'valid_to' => $day(30), 'max_uses' => 500, 'max_per_booking' => 6]);
    DB::insert('shp_fees', ['code' => 'TFEE-CGY', 'name' => 'Terminal Fee (Cagayan de Oro)', 'fee_type' => 'terminal', 'applies_to' => 'passenger', 'calc' => 'per_pax', 'amount' => 45,
        'ownership' => 'agent', 'liability_account_id' => $acct('2150'), 'vendor_id' => $vend['Philippine Ports Authority - PMO Cebu'], 'port_id' => $port['CGY'], 'exempt_categories' => 'INF', 'sort_order' => 15]);

    // ---------------------------------------------------------------- crew, equipment, planned maintenance
    $ranks = [
        ['Master', 'deck', 2400], ['Chief Mate', 'deck', 1800], ['Second Mate', 'deck', 1400], ['Chief Engineer', 'engine', 2400], ['Second Engineer', 'engine', 1600],
        ['Third Engineer', 'engine', 1300], ['Able Seaman', 'deck', 900], ['Able Seaman', 'deck', 900], ['Ordinary Seaman', 'deck', 750], ['Oiler', 'engine', 800], ['Oiler', 'engine', 800],
        ['Purser', 'hotel', 1100], ['Steward', 'hotel', 650], ['Cook', 'hotel', 700],
    ];
    $first = ['Juan', 'Pedro', 'Jose', 'Mario', 'Ramon', 'Elmer', 'Rogelio', 'Dante', 'Arnel', 'Jun', 'Nestor', 'Romeo', 'Edwin', 'Ronald', 'Rey', 'Bernard', 'Felix', 'Joel', 'Allan', 'Noel'];
    $last = ['Dela Cruz', 'Bautista', 'Villanueva', 'Gonzales', 'Ramos', 'Aquino', 'Mendoza', 'Castillo', 'Navarro', 'Ocampo', 'Cabrera', 'Rosales', 'Pacana', 'Alcantara', 'Tumulak', 'Ybanez', 'Cuizon', 'Sanchez', 'Ouano', 'Lim'];
    $crewBy = [];
    $n = 0;
    foreach ($vid as $code => $id) {
        $count = $code === 'SOB' ? 14 : ($code === 'VSP' ? 42 : 56);
        for ($i = 0; $i < $count; $i++) {
            [$rank, $dept, $allow] = $ranks[$i < count($ranks) ? $i : 6 + ($i % 8)];
            if ($i >= count($ranks)) {
                $rank = ['Able Seaman', 'Ordinary Seaman', 'Oiler', 'Wiper', 'Steward', 'Messman', 'Bosun', 'Electrician'][$i % 8];
                $dept = in_array($rank, ['Oiler', 'Wiper', 'Electrician'], true) ? 'engine' : (in_array($rank, ['Steward', 'Messman'], true) ? 'hotel' : 'deck');
            }
            $cid = DB::insert('shp_crew', ['crew_no' => sprintf('CR-%04d', ++$n), 'last_name' => $last[mt_rand(0, 19)], 'first_name' => $first[mt_rand(0, 19)], 'rank_title' => $rank,
                'department' => $dept, 'vessel_id' => $id, 'status' => 'active', 'mobile' => '09' . mt_rand(10, 99) . '-' . mt_rand(100, 999) . '-' . mt_rand(1000, 9999),
                'seafarer_id' => 'SIRB-' . mt_rand(100000, 999999), 'daily_allowance' => $allow, 'hire_date' => $day(-mt_rand(300, 3000))]);
            foreach ([['STCW Basic Safety Training', 1500], ['Medical Fitness Certificate', 365]] as [$ct, $life]) {
                $exp = $day(mt_rand(20, $life));
                DB::insert('shp_crew_certs', ['crew_id' => $cid, 'cert_type' => $ct, 'cert_no' => strtoupper(substr($ct, 0, 3)) . mt_rand(10000, 99999), 'issued_on' => date('Y-m-d', strtotime($exp . " -$life days")), 'expires_on' => $exp]);
            }
            if (in_array($rank, ['Master', 'Chief Mate', 'Chief Engineer', 'Second Engineer'], true)) {
                DB::insert('shp_crew_certs', ['crew_id' => $cid, 'cert_type' => 'Certificate of Competency (' . $rank . ')', 'cert_no' => 'COC' . mt_rand(10000, 99999), 'issued_on' => $day(-900), 'expires_on' => $day(mt_rand(120, 900))]);
            }
            $crewBy[$code][] = $cid;
        }
    }
    // one crew certificate already expired (shows on the readiness check and the expiry report), on a relief AB not signed on
    $relief = DB::insert('shp_crew', ['crew_no' => sprintf('CR-%04d', ++$n), 'last_name' => 'Cuizon', 'first_name' => 'Ariel', 'rank_title' => 'Able Seaman (relief)', 'department' => 'deck', 'vessel_id' => $vid['VSP'], 'status' => 'on_leave', 'daily_allowance' => 900]);
    DB::insert('shp_crew_certs', ['crew_id' => $relief, 'cert_type' => 'Medical Fitness Certificate', 'cert_no' => 'MED' . mt_rand(10000, 99999), 'issued_on' => $day(-380), 'expires_on' => $day(-15)]);

    $equip = [];
    foreach ($vid as $code => $id) {
        $sys = fn(string $c, string $nm, ?int $parent, string $lvl, string $crit, array $x = []) => DB::insert('shp_equipment', array_merge(['vessel_id' => $id, 'parent_id' => $parent, 'code' => $c, 'name' => $nm, 'level' => $lvl, 'criticality' => $crit, 'installed_on' => ($vessels[$code]['year_built'] + 1) . '-01-15'], $x));
        $prop = $sys('PROP', 'Propulsion', null, 'system', 'critical');
        $equip[$code]['ME-P'] = $sys('ME-P', 'Main Engine (port)', $prop, 'equipment', 'critical', ['maker' => 'Yanmar', 'model' => $code === 'SOB' ? '12AYM-WGT' : '6EY26W', 'serial_no' => 'YM' . mt_rand(100000, 999999), 'running_hours' => mt_rand(18000, 42000)]);
        $equip[$code]['ME-S'] = $sys('ME-S', 'Main Engine (starboard)', $prop, 'equipment', 'critical', ['maker' => 'Yanmar', 'model' => $code === 'SOB' ? '12AYM-WGT' : '6EY26W', 'serial_no' => 'YM' . mt_rand(100000, 999999), 'running_hours' => mt_rand(18000, 42000)]);
        $elec = $sys('ELEC', 'Electrical plant', null, 'system', 'high');
        $equip[$code]['GEN1'] = $sys('GEN1', 'Generator No. 1', $elec, 'equipment', 'high', ['maker' => 'Cummins', 'model' => '6BTA5.9', 'running_hours' => mt_rand(9000, 30000)]);
        $safety = $sys('SAFE', 'Safety systems', null, 'system', 'critical');
        $equip[$code]['FIRE'] = $sys('FIRE', 'Emergency fire pump', $safety, 'equipment', 'critical', ['maker' => 'Shinko', 'model' => 'HJ-50']);
        $equip[$code]['LSA'] = $sys('LSA', 'Life rafts and davit', $safety, 'equipment', 'critical', ['maker' => 'Viking', 'model' => '25DKF']);
        $nav = $sys('NAV', 'Navigation', null, 'system', 'high');
        $equip[$code]['RADAR'] = $sys('RADAR', 'X-band radar', $nav, 'equipment', 'high', ['maker' => 'Furuno', 'model' => 'FAR-1518']);
        $equip[$code]['STEER'] = $sys('STEER', 'Steering gear (hydraulic)', $prop, 'equipment', 'critical', ['maker' => 'Kawasaki', 'model' => 'FE21']);
        if ($code !== 'SOB') {
            $equip[$code]['RAMP'] = $sys('RAMP', 'Stern ramp hydraulics', null, 'equipment', 'high', ['maker' => 'MacGregor', 'model' => 'SR-12']);
        }
        foreach ([
            ['ME-P', 'Main engine (port): change lube oil and filters', 'hours', null, 500, "Drain lube oil\nReplace lube oil filter\nReplace fuel filter\nRefill and record oil level\nRun-up and check for leaks"],
            ['ME-S', 'Main engine (starboard): change lube oil and filters', 'hours', null, 500, "Drain lube oil\nReplace lube oil filter\nReplace fuel filter\nRefill and record oil level\nRun-up and check for leaks"],
            ['GEN1', 'Generator No. 1: 250-hour service', 'hours', null, 250, "Change oil\nCheck belts\nCheck coolant\nLoad test"],
            ['FIRE', 'Emergency fire pump: weekly test run', 'calendar', 7, null, "Start and run 15 minutes\nCheck discharge pressure\nRecord in log"],
            ['LSA', 'Life rafts: annual servicing', 'survey', 365, null, "Send to service station\nCheck hydrostatic release units"],
            ['RADAR', 'Radar: monthly performance check', 'calendar', 30, null, "Check magnetron hours\nPerformance monitor test"],
            ['STEER', 'Steering gear: monthly test and hydraulic check', 'calendar', 30, null, "Test both pumps\nCheck hydraulic oil level and hoses\nRudder angle indicator check"],
        ] as [$eq, $name, $basis, $days, $hours, $check]) {
            $hrs = $hours ? (float)DB::value('SELECT running_hours FROM shp_equipment WHERE id = :e', ['e' => $equip[$code][$eq]]) : null;
            $last = $days ? $day(-mt_rand(1, (int)$days + 6)) : null;
            DB::insert('shp_pm_plans', ['vessel_id' => $id, 'equipment_id' => $equip[$code][$eq], 'name' => $name, 'basis' => $basis, 'interval_days' => $days, 'interval_hours' => $hours,
                'lead_days' => 5, 'last_done_on' => $last, 'last_done_hours' => $hrs ? $hrs - mt_rand(200, 520) : null,
                'next_due_on' => $last ? date('Y-m-d', strtotime($last . " +$days days")) : null, 'next_due_hours' => $hrs ? $hrs - mt_rand(200, 520) + $hours : null,
                'checklist' => $check, 'est_hours' => $hours ? 6 : 2, 'is_regulatory' => $basis === 'survey' ? 1 : 0]);
        }
    }

    // ---------------------------------------------------------------- opening stock: spares at the shore warehouse, fuel bunkered on each vessel
    Ship::$clock = $at($day(-38), '09:00');
    demo_as('apclerk');
    $buyLines = [];
    foreach (['SP-FFILT' => 30, 'SP-OFILT' => 30, 'SP-IMP' => 8, 'SP-BELT' => 12, 'SP-GASK' => 8, 'SP-INJ' => 12, 'SP-HOSE' => 6, 'SP-BATT' => 6, 'SP-ZINC' => 40] as $code => $q) {
        $cost = (float)DB::value('SELECT cost FROM items WHERE id = :i', ['i' => $item[$code]]);
        $buyLines[] = ['item_id' => $item[$code], 'description' => DB::value('SELECT name FROM items WHERE id = :i', ['i' => $item[$code]]), 'qty' => $q, 'rate' => $cost, 'tax_code_id' => $tax['VAT12'], 'tax_amount' => round($q * $cost * .12, 2), 'site_id' => $shore];
    }
    ShipGL::bill($vend['Marine Engine Parts Trading'], $day(-38), 'MEPT-SI-20418', $buyLines, 'Spare parts stock for the fleet', null, $shore);
    ShipGL::bill($vend['Seaborne Ship Chandlers'], $day(-38), 'SSC-4471', [
        ['item_id' => $item['SP-LJ'], 'description' => 'Life jackets', 'qty' => 120, 'rate' => 1350, 'tax_code_id' => $tax['VAT12'], 'tax_amount' => 19440, 'site_id' => $shore],
        ['item_id' => $item['LUBE-40'], 'description' => 'Lube oil SAE 40', 'qty' => 2000, 'rate' => 185, 'tax_code_id' => $tax['VAT12'], 'tax_amount' => 44400, 'site_id' => $shore],
    ], 'Ship stores and lube oil', null, $shore);
    demo_as('warehouse');
    foreach ($vid as $code => $id) {
        $tank = (float)$vessels[$code]['fuel_tank_l'];
        Fuel::bunker($id, round($tank * .85), 61.80, $vend['Petron Marine Fuels'], 'PTR-DR-' . mt_rand(100000, 999999), $day(-38), 'Opening bunkering');
    }

    // ---------------------------------------------------------------- the schedule
    demo_as('opsmgr');
    Ship::$clock = $at($day(-36), '09:00');
    $schedule = [];
    for ($o = -14; $o <= 7; $o++) {
        $d = $day($o);
        $dow = (int)date('N', strtotime($d));
        $schedule[] = ['SOB', 'CEB-TAG', $d . ' 08:00'];
        $schedule[] = ['SOB', 'TAG-CEB', $d . ' 13:00'];
        if (in_array($dow, [1, 3, 5], true)) {
            $schedule[] = ['VSP', 'CEB-ORM', $d . ' 21:00'];
        }
        if (in_array($dow, [2, 4, 6], true)) {
            $schedule[] = ['VSP', 'ORM-CEB', $d . ' 21:00'];
        }
        if (in_array($dow, [2, 4, 7], true)) {
            $schedule[] = ['MEX', 'CEB-CGY', $d . ' 19:00'];
        }
        if (in_array($dow, [1, 3, 6], true)) {
            $schedule[] = ['MEX', 'CGY-CEB', $d . ' 19:00'];
        }
    }
    $voyages = [];
    foreach ($schedule as [$v, $r, $etd]) {
        $voyages[] = Voyages::create(['route_id' => $route[$r], 'vessel_id' => $vid[$v], 'etd' => $etd, 'status' => 'on_sale', 'berth' => null]);
    }

    // ---------------------------------------------------------------- daily operations, replayed day by day in time order
    // The sales of every voyage are planned first (how many, when, through which channel); then each day is
    // played in order: that day's sales and payments into the cashier shifts of the day, cargo booked for the
    // next day's sailings, cancellations, the day's sailings, collections, and at night the shifts close and the
    // cash goes to the bank the next morning. Nothing is ever paid into a shift that was already counted.
    $cats = DB::pairs('SELECT code, id FROM shp_fare_categories');
    $catMix = array_merge(array_fill(0, 70, 'REG'), array_fill(0, 9, 'SC'), array_fill(0, 4, 'PWD'), array_fill(0, 8, 'STU'), array_fill(0, 1, 'MOV'), array_fill(0, 6, 'CHD'), array_fill(0, 2, 'INF'));
    $pmix = ['cash', 'cash', 'cash', 'cash', 'cash', 'cash', 'ewallet', 'ewallet', 'ewallet', 'card', 'card', 'maya'];
    $vehMix = ['MC', 'MC', 'CAR', 'CAR', 'CAR', 'VAN', 'VAN', 'TRK6', 'TRK6', 'TRK10', 'BUS', 'TRL'];
    $firstN = ['Maria', 'Ana', 'Rosa', 'Liza', 'Grace', 'Joy', 'Mae', 'Kristine', 'Angel', 'Jasmine', 'Mark', 'John', 'Paul', 'James', 'Carlo', 'Kevin', 'Ryan', 'Jerome', 'Neil', 'Vincent', 'Aiza', 'Rhea', 'Cherry', 'Dodong', 'Inday'];
    $lastN = ['Abellana', 'Alcoseba', 'Bacalso', 'Cabahug', 'Cuyos', 'Dumaguit', 'Enriquez', 'Gacasan', 'Labra', 'Maglasang', 'Ouano', 'Pacquiao', 'Quijano', 'Rosales', 'Sumalinog',
        'Tagalog', 'Uy', 'Villamor', 'Yap', 'Zamora', 'Arcenas', 'Borromeo', 'Cañete', 'Durano', 'Fernandez', 'Gullas', 'Lim', 'Osmeña', 'Sy', 'Tan'];
    $offices = ['CEB' => $office['CEB-P4'], 'TAG' => $office['TAG-T'], 'ORM' => $office['ORM-T'], 'CGY' => $office['CGY-T']];
    $cashierOf = ['CEB' => 'ticket1', 'TAG' => 'portagent', 'ORM' => 'ticket2', 'CGY' => 'portagent'];
    $shift = [];   // "office:date" => shift id
    $openShift = function (string $portCode, string $date) use (&$shift, $offices, $cashierOf, $at, $today) {
        $k = $cashierOf[$portCode] . ':' . $date;
        if (!isset($shift[$k])) {
            demo_as($cashierOf[$portCode]);
            $prev = Cashier::current((int)Auth::id());
            if ($prev) {
                Ship::$clock = substr($prev['opened_at'], 0, 10) . ' 22:30:00';
                Cashier::close((int)$prev['id'], Cashier::totals((int)$prev['id'])['expected']);
            }
            Ship::$clock = $date === $today ? min($at($date, '05:45'), date('Y-m-d H:i:s', time() - 4 * 3600)) : $at($date, '05:45');
            $shift[$k] = Cashier::open($offices[$portCode], 5000);
        }
        return $shift[$k];
    };
    $pax = function () use ($catMix, $cats, $firstN, $lastN, $day) {
        $c = $catMix[mt_rand(0, count($catMix) - 1)];
        $age = match ($c) { 'SC' => mt_rand(60, 84), 'CHD' => mt_rand(3, 11), 'INF' => mt_rand(0, 2), 'STU' => mt_rand(12, 22), default => mt_rand(19, 58) };
        return ['last_name' => $lastN[mt_rand(0, count($lastN) - 1)], 'first_name' => $firstN[mt_rand(0, count($firstN) - 1)], 'birth_date' => date('Y-m-d', strtotime($day(-$age * 365 - mt_rand(10, 300)))),
            'gender' => mt_rand(0, 1) ? 'F' : 'M', 'nationality' => mt_rand(0, 40) ? 'Filipino' : ['American', 'Korean', 'Japanese', 'German'][mt_rand(0, 3)], 'contact_no' => '09' . mt_rand(10, 99) . mt_rand(1000000, 9999999),
            'category_id' => $cats[$c], 'id_no' => in_array($c, ['SC', 'PWD', 'STU', 'MOV'], true) ? (string)mt_rand(10000000, 99999999) : '',
            'emergency_name' => $lastN[mt_rand(0, count($lastN) - 1)] . ' family', 'emergency_phone' => '09' . mt_rand(10, 99) . mt_rand(1000000, 9999999)];
    };


    $refundsMade = 0;
    $byDate = [];
    foreach ($voyages as $vId) {
        $byDate[substr((string)DB::value('SELECT etd FROM shp_voyages WHERE id = :id', ['id' => $vId]), 0, 10)][] = $vId;
    }
    ksort($byDate);
    $depositQueue = [];

    // ---------------- 1. the sales each voyage will get, planned up front
    $sales = [];
    foreach ($voyages as $vId) {
        $v = Ship::voyage($vId);
        $date = substr($v['etd'], 0, 10);
        $offset = (int)round((strtotime($date) - strtotime($today)) / 86400);
        $isFast = $v['vessel_type'] === 'fastcraft';
        $origin = $v['origin_code'];
        $cap = Booking::capacity($v);
        $target = (int)round(array_sum(array_column($cap, 'capacity')) * ($offset > 2 ? mt_rand(10, 24) : mt_rand(38, 66)) / 100 * ($isFast ? .72 : .3));
        $target = max(8, min($target, $offset > 0 ? 70 : 160));
        $sold = 0;
        while ($sold < $target) {
            $daysBefore = mt_rand(0, 6);
            $bookDate = date('Y-m-d', strtotime($date . " -$daysBefore days"));
            if ($bookDate > $today) {
                $bookDate = $today;
            }
            $bookTime = $bookDate === $date ? sprintf('%02d:%02d', max(6, (int)substr($v['etd'], 11, 2) - mt_rand(1, 4)), mt_rand(0, 59)) : sprintf('%02d:%02d', mt_rand(7, 18), mt_rand(0, 59));
            $when = $at($bookDate, $bookTime);
            if ($when >= date('Y-m-d H:i:s', strtotime($v['booking_close'])) || ($bookDate === $today && $when > date('Y-m-d H:i:s'))) {
                $when = min(date('Y-m-d H:i:s', strtotime($v['booking_close']) - 3600), date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -10 minutes')));
            }
            $roll = mt_rand(1, 100);
            $channel = $roll <= 72 ? 'counter' : ($roll <= 86 ? 'portal' : ($roll <= 95 ? 'agent' : 'corporate'));
            $n = min($target - $sold, $channel === 'corporate' ? mt_rand(3, 6) : mt_rand(1, 4));
            $list = [];
            $accoms = array_keys($cap);
            $accom = $accoms[mt_rand(0, count($accoms) - 1)];
            if (!$isFast && mt_rand(1, 100) <= 55) {
                $accom = 'ECO';
            }
            for ($i = 0; $i < $n; $i++) {
                $list[] = $pax() + ['accom_code' => $accom];
            }
            $veh = [];
            if (!$isFast && mt_rand(1, 100) <= 28 && $channel !== 'corporate') {
                $cls = $vehMix[mt_rand(0, count($vehMix) - 1)];
                $k = DB::row('SELECT * FROM shp_vehicle_classes WHERE id = :id', ['id' => $vc[$cls]]);
                $list[0]['category_id'] = $cats['REG'];
                $list[0]['birth_date'] = $day(-35 * 365);
                $list[0]['id_no'] = '';
                $veh[] = ['vehicle_class_id' => $vc[$cls], 'plate_no' => chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . ' ' . mt_rand(1000, 9999),
                    'make_model' => ['MC' => 'Honda Click 125', 'CAR' => 'Toyota Vios', 'VAN' => 'Toyota HiAce', 'TRK6' => 'Isuzu Elf', 'TRK10' => 'Hino 10-wheeler', 'BUS' => 'Hyundai Universe', 'TRL' => 'Volvo FM tractor + trailer'][$cls],
                    'length_m' => round((float)$k['max_length_m'] * mt_rand(70, 98) / 100, 1), 'weight_t' => round((float)$k['max_weight_t'] * mt_rand(40, 95) / 100, 1),
                    'operator_name' => $cls === 'BUS' ? 'Bohol Countryside Bus Lines' : $list[0]['first_name'] . ' ' . $list[0]['last_name'], 'driver_index' => 0,
                    'declared_cargo' => in_array($cls, ['TRK6', 'TRK10', 'TRL'], true) ? ['Rice in sacks', 'Construction materials', 'Bottled beverages', 'Dry goods'][mt_rand(0, 3)] : null,
                    'restrictions_ack' => 1];
            }
            $h = ['voyage_id' => $vId, 'channel' => $channel, 'office_id' => $channel === 'portal' ? $office['WEB'] : $offices[$origin],
                'contact_name' => $list[0]['first_name'] . ' ' . $list[0]['last_name'], 'contact_phone' => $list[0]['contact_no'],
                'contact_email' => mt_rand(0, 2) ? strtolower(str_replace([' ', 'ñ'], ['', 'n'], $list[0]['first_name'] . '.' . $list[0]['last_name'])) . '@mail.test' : null];
            if ($channel === 'agent') {
                $h['agent_customer_id'] = $cust['Island Hopper Travel & Tours'];
                $h['customer_id'] = $cust['Island Hopper Travel & Tours'];
            } elseif ($channel === 'corporate') {
                $h['customer_id'] = $cust['DepEd Region VII'];
                $h['contact_name'] = 'DepEd Region VII (official travel)';
            }
            if ($channel === 'portal' && mt_rand(1, 5) === 1) {
                $h['promo_code'] = 'SAKAY10';
            }
            $pch = $pmix[mt_rand(0, count($pmix) - 1)];
            $sales[substr($when, 0, 10)][] = ['when' => $when, 'voyage' => $vId, 'origin' => $origin, 'channel' => $channel, 'h' => $h, 'pax' => $list, 'veh' => $veh, 'pay' => $pch,
                'delay' => $channel === 'portal' ? mt_rand(15, 90) : 2];
            $sold += $n;
        }
    }
    $bookingsOf = [];
    $firstDay = min(array_merge(array_keys($sales), array_keys($byDate)));

    // ---------------- 2. day by day
    for ($date = $firstDay; $date <= $today; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
        // a. the day's sales and payments, in the order they happened
        $list = $sales[$date] ?? [];
        usort($list, fn($x, $y) => strcmp($x['when'], $y['when']));
        foreach ($list as $s) {
            $channel = $s['channel'];
            $origin = $s['origin'];
            try {
                Ship::$clock = $s['when'];
                demo_as($channel === 'counter' ? $cashierOf[$origin] : ($channel === 'agent' ? 'portagent' : 'ticket2'));
                $bid = Booking::hold($s['h'], $s['pax'], $s['veh']);
            } catch (UserError $e) {
                continue;   // the voyage or the class is full
            }
            // paid: at the counter at once, portal reservations at an office before the hold runs out, agent / corporate on account
            if ($channel === 'counter' || $channel === 'portal') {
                $pch = $s['pay'];
                $pay = ['channel' => $pch, 'provider_ref' => $pch === 'cash' ? null : strtoupper($pch[0]) . mt_rand(10000000, 99999999), 'idempotency_key' => 'demo-pay-' . $bid];
                Ship::$clock = min(date('Y-m-d H:i:s', strtotime($s['when']) + 60 * $s['delay']), date('Y-m-d H:i:s', time() - 60), $at($date, '22:15'));
                if ($pch === 'cash') {
                    $pay['shift_id'] = $openShift($origin, $date);
                    demo_as($cashierOf[$origin]);
                    Ship::$clock = max(Ship::$clock, (string)DB::value('SELECT opened_at FROM shp_cashier_shifts WHERE id = :s', ['s' => $pay['shift_id']]));
                }
            } else {
                $pay = ['channel' => $channel === 'agent' ? 'agent' : 'corporate'];
            }
            $pay['date'] = substr(Ship::$clock, 0, 10);
            try {
                Booking::pay($bid, $pay);
            } catch (UserError $e) {
                continue;   // the hold ran out before it was paid (left as an expired reservation)
            }
            if ($pay['channel'] === 'cash') {
                $depositQueue[$pay['date']][] = (int)DB::value('SELECT txn_id FROM shp_payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid]);
            }
            $bookingsOf[$s['voyage']][] = $bid;
        }

        // b. cargo booked the day before sailing (on the last day, for every sailing of the coming week)
        $cargoDays = $date === $today ? range(1, 6) : [1];
        foreach ($cargoDays as $ahead) {
            foreach ($byDate[date('Y-m-d', strtotime($date . " +$ahead days"))] ?? [] as $vId) {
                $v = Ship::voyage($vId);
                if ($v['vessel_type'] === 'fastcraft' || $v['status'] === 'cancelled') {
                    continue;
                }
                $origin = $v['origin_code'];
                $shippers = [['Visayan Agri Traders Corp.', 'credit', 'general', 'Rice, 50 kg sacks'], ['Mindanao Fresh Produce Coop', 'prepaid', 'perishable', 'Bananas and pineapples in crates'],
                    ['Cebu Hardware Depot', 'credit', 'general', 'Hardware and construction materials'], [null, 'prepaid', 'general', 'Household goods (balikbayan boxes)'], [null, 'prepaid', 'dangerous', 'LPG cylinders (declared)']];
                for ($c = 0, $nc = mt_rand(2, 4); $c < $nc; $c++) {
                    [$sh, $terms2, $cc, $desc] = $shippers[mt_rand(0, count($shippers) - 1)];
                    $pk = mt_rand(5, 80);
                    $kg = $cc === 'dangerous' ? $pk * 30 : $pk * mt_rand(20, 50);
                    Ship::$clock = $at($date, '10:' . sprintf('%02d', mt_rand(10, 59)));
                    if (Ship::$clock > date('Y-m-d H:i:s', time() - 7200)) {
                        Ship::$clock = date('Y-m-d H:i:s', time() - 7200);
                    }
                    demo_as('cargoclerk');
                    try {
                        $cid = Cargo::save(['route_id' => $v['route_id'], 'voyage_id' => $vId, 'shipper_customer_id' => $sh ? $cust[$sh] : null, 'shipper_name' => $sh ?: $lastN[mt_rand(0, 29)] . ' family',
                            'shipper_phone' => '09' . mt_rand(100000000, 999999999), 'consignee_name' => ['Agus Trading', 'Lim Store', 'Fresh Mart CDO', 'Ormoc Builders', 'Mrs. Dagohoy'][mt_rand(0, 4)],
                            'consignee_phone' => '09' . mt_rand(100000000, 999999999), 'commodity_class' => $cc, 'description' => $desc, 'payment_terms' => $sh ? $terms2 : 'prepaid', 'status' => 'booked',
                            'special_handling' => $cc === 'perishable' ? 'Keep ventilated' : ($cc === 'dangerous' ? 'Stow on open deck, away from heat' : null)],
                            [['description' => $desc, 'packages' => $pk, 'packing' => $cc === 'perishable' ? 'crate' : 'sack', 'weight_kg' => $kg, 'volume_cbm' => round($kg / 380, 2), 'declared_value' => $kg * mt_rand(40, 120)]]);
                        if (DB::value('SELECT payment_terms FROM shp_cargo WHERE id = :c', ['c' => $cid]) === 'prepaid') {
                            $cashDay = substr(Ship::$clock, 0, 10);
                            Cargo::pay($cid, 'cash', null, $openShift($origin, $cashDay));
                            $depositQueue[$cashDay][] = (int)DB::value('SELECT paid_txn_id FROM shp_cargo WHERE id = :c', ['c' => $cid]);
                            demo_as('cargoclerk');
                        }
                        if ($date < $today || $ahead === 1 && substr($v['etd'], 0, 10) <= $today) {
                            Cargo::transition($cid, 'accepted', ['note' => 'Received at the pier, tally ' . $pk . ' packages']);
                        }
                    } catch (UserError $e) {
                    }
                }
            }
        }

        // c. a passenger cancels two days before sailing (refund approved by the ticketing manager and paid out, or still waiting)
        foreach ($byDate[date('Y-m-d', strtotime($date . ' +2 days'))] ?? [] as $vId) {
            if (empty($bookingsOf[$vId]) || mt_rand(1, 6) !== 1) {
                continue;
            }
            $v = Ship::voyage($vId);
            $origin = $v['origin_code'];
            $bid = $bookingsOf[$vId][mt_rand(0, count($bookingsOf[$vId]) - 1)];
            $t = DB::row("SELECT t.* FROM shp_tickets t JOIN shp_bookings b ON b.id = t.booking_id WHERE t.booking_id = :b AND t.status = 'issued' AND b.channel IN ('counter', 'portal') LIMIT 1", ['b' => $bid]);
            Ship::$clock = min($at($date, sprintf('16:%02d', mt_rand(0, 59))), date('Y-m-d H:i:s', time() - 3600));
            if (!$t || Ship::$clock <= (string)DB::value('SELECT confirmed_at FROM shp_bookings WHERE id = :b', ['b' => $bid])) {
                continue;
            }
            demo_as($cashierOf[$origin]);
            $rid = Booking::requestRefund($bid, [(int)$t['id']], [], 'passenger_cancel', ['Change of travel plans', 'Family emergency', 'Missed connecting bus', 'Illness'][mt_rand(0, 3)], 'cash');
            $approval = (int)DB::value("SELECT id FROM shp_approvals WHERE ref_table = 'shp_refunds' AND ref_id = :r", ['r' => $rid]);
            if ($date === $today && mt_rand(0, 1)) {
                continue;   // left waiting for the manager
            }
            demo_as('tktmgr');
            ShipApproval::decide($approval, true, 'Within policy.');
            demo_as($cashierOf[$origin]);
            Booking::payRefund($rid, 'cash', $openShift($origin, substr(Ship::$clock, 0, 10)));
            $refundsMade++;
        }

        foreach ($byDate[$date] ?? [] as $vId) {
            $v = Ship::voyage($vId);
            $etd = strtotime($v['etd']);
            if ($etd > time() + 1800) {
                continue;
            }
            $isFast = $v['vessel_type'] === 'fastcraft';
            $code = $isFast ? 'SOB' : ($v['vessel_code']);
            // boarding opens an hour before; crew signs on
            Ship::$clock = date('Y-m-d H:i:s', $etd - 3600);
            demo_as('opsmgr');
            Voyages::transition($vId, 'boarding', ['at' => Ship::$clock]);
            $crew = DB::column('SELECT id FROM shp_crew WHERE vessel_id = :v AND status = \'active\' ORDER BY id', ['v' => $v['vessel_id']]);
            foreach ($crew as $cr) {
                DB::insert('shp_voyage_crew', ['voyage_id' => $vId, 'crew_id' => $cr, 'position' => DB::value('SELECT rank_title FROM shp_crew WHERE id = :c', ['c' => $cr])]);
            }
            // cargo and vehicles are loaded, passengers board through the gate
            demo_as('cargoclerk');
            foreach (DB::column("SELECT id FROM shp_cargo WHERE voyage_id = :v AND status = 'accepted'", ['v' => $vId]) as $cid) {
                Cargo::transition($cid, 'loaded', ['note' => 'Loaded, tally checked']);
            }
            demo_as('boarding1');
            $lane = 0;
            foreach (DB::all("SELECT * FROM shp_vehicles WHERE voyage_id = :v AND status = 'issued'", ['v' => $vId]) as $x) {
                DB::update('shp_vehicles', ['deck' => 'Car deck', 'lane_no' => (string)(1 + $lane % max(1, (int)DB::value('SELECT vehicle_lanes FROM shp_vessels WHERE id = :s', ['s' => $v['vessel_id']]))), 'lane_position' => intdiv($lane, 4) + 1], 'id = :id', ['id' => $x['id']]);
                $lane++;
                if (mt_rand(1, 20) > 1) {
                    Ship::$clock = date('Y-m-d H:i:s', $etd - 3000 + mt_rand(0, 1200));
                    Boarding::scan($vId, Ship::qrPayload('vehicle', $x['vehicle_no'], $x['qr_sig']), 'board', ['device_id' => 'GATE-1']);
                }
            }
            $tickets = DB::all("SELECT * FROM shp_tickets WHERE voyage_id = :v AND status IN ('issued', 'checked_in') ORDER BY RAND(" . $vId . ')', ['v' => $vId]);
            $dupDone = mt_rand(1, 4) !== 1;   // now and then a passenger shows the same code at the second gate
            foreach ($tickets as $t) {
                if (mt_rand(1, 100) <= 7) {
                    continue;   // no-show
                }
                Ship::$clock = date('Y-m-d H:i:s', $etd - mt_rand(700, 3400));
                Boarding::scan($vId, Ship::qrPayload('pax', $t['ticket_no'], $t['qr_sig']), 'board', ['device_id' => 'GATE-' . mt_rand(1, 2)]);
                if (!$dupDone && mt_rand(1, 6) === 1) {
                    Boarding::scan($vId, Ship::qrPayload('pax', $t['ticket_no'], $t['qr_sig']), 'board', ['device_id' => 'GATE-2']);   // duplicate refused
                    $dupDone = true;
                }
            }
            if (mt_rand(1, 9) === 1) {
                $other = DB::row("SELECT ticket_no, qr_sig FROM shp_tickets WHERE voyage_id <> :v AND status = 'issued' ORDER BY id DESC LIMIT 1", ['v' => $vId]);
                if ($other) {
                    Boarding::scan($vId, Ship::qrPayload('pax', $other['ticket_no'], $other['qr_sig']), 'board', ['device_id' => 'GATE-1']);   // wrong voyage refused
                }
            }
            // readiness: checklist, weather, clearance, final manifest, release, departure
            Ship::$clock = date('Y-m-d H:i:s', $etd - 600);
            demo_as('captain');
            DB::run('UPDATE shp_voyage_checklist SET is_done = 1, done_by = :u, done_at = :t WHERE voyage_id = :v', ['u' => Auth::id(), 't' => Ship::$clock, 'v' => $vId]);
            DB::update('shp_voyages', ['weather_ack' => 1, 'weather_note' => ['PAGASA: fair weather, slight to moderate seas', 'Moderate seas, wave height 1.0 to 2.0 m', 'Gale warning lifted; moderate seas'][mt_rand(0, 2)],
                'clearance_ref' => 'PCG-' . $v['origin_code'] . '-' . date('ymd', $etd) . '-' . mt_rand(100, 999)], 'id = :id', ['id' => $vId]);
            Manifest::generate($vId, true, 'Final manifest before departure');
            $mid = (int)DB::value("SELECT id FROM shp_manifests WHERE voyage_id = :v AND status = 'final' ORDER BY id DESC LIMIT 1", ['v' => $vId]);
            Manifest::sign($mid);
            try {
                Voyages::transition($vId, 'ready', ['at' => Ship::$clock]);
            } catch (UserError $e) {
                // a hold or a check that is not met keeps the voyage at the pier (shown on the voyage page)
                Ship::voyageEvent($vId, 'note', 'Departure held: ' . $e->getMessage());
                continue;
            }
            $tank = (float)$vessels[$code]['fuel_tank_l'];
            if (Fuel::rob((int)$v['vessel_id']) < $tank * .4) {
                $robNow = Fuel::rob((int)$v['vessel_id']);
                Ship::$clock = date('Y-m-d H:i:s', $etd - 5 * 3600);
                demo_as('warehouse');
                Fuel::bunker((int)$v['vessel_id'], round($tank * .92 - $robNow), round(61.5 + mt_rand(0, 250) / 100, 2), $vend['Petron Marine Fuels'], 'PTR-DR-' . mt_rand(100000, 999999), substr(Ship::$clock, 0, 10), 'Bunkering before departure');
                demo_as('captain');
                Ship::$clock = date('Y-m-d H:i:s', $etd - 600);
            }
            $late = mt_rand(1, 100) <= 18 ? mt_rand(10, 75) : mt_rand(0, 6);
            $depart = date('Y-m-d H:i:s', $etd + 60 * $late);
            if (strtotime($depart) > time()) {
                continue;   // still at the pier today
            }
            Ship::$clock = $depart;
            $robDep = Fuel::rob((int)$v['vessel_id']);
            Voyages::transition($vId, 'departed', ['at' => $depart, 'fuel_rob_departure' => $robDep,
                'delay_cause' => $late > 10 ? ['Late arrival of connecting bus passengers', 'Cargo loading took longer', 'Waiting for PCG inspection', 'Port congestion'][mt_rand(0, 3)] : null]);
            $arrive = date('Y-m-d H:i:s', strtotime($v['eta']) + 60 * ($late + mt_rand(-10, 25)));
            if (strtotime($arrive) > time()) {
                continue;   // at sea now
            }
            Ship::$clock = $arrive;
            Voyages::transition($vId, 'arrived', ['at' => $arrive]);
            $hours = (float)DB::value('SELECT sailing_hours FROM shp_voyages WHERE id = :id', ['id' => $vId]);
            $burn = round($hours * (float)$vessels[$code]['fuel_rate_lph'] * mt_rand(95, 109) / 100);
            demo_as('chiefeng');
            Fuel::issue($vId, $robDep, $robDep - $burn, 'From tank soundings at departure and arrival');
            foreach (['ME-P', 'ME-S'] as $eq) {
                $cur = (float)DB::value('SELECT running_hours FROM shp_equipment WHERE id = :e', ['e' => $equip[$code][$eq]]);
                Cmms::reading($equip[$code][$eq], $cur + round($hours, 1), $arrive, $vId);
            }
            // voyage costs: port charges at the origin; pilotage and tug on the big ships
            demo_as('opsmgr');
            $class = ShipGL::vesselClass((int)$v['vessel_id']);
            $vat = $tax['VAT12'];
            $pc = $isFast ? 4200 : ($code === 'VSP' ? 9800 : 14500);
            DB::insert('shp_voyage_costs', ['voyage_id' => $vId, 'cost_type' => 'port_charges', 'description' => 'Port dues and dockage, ' . $v['origin_name'], 'vendor_id' => $vend['Philippine Ports Authority - PMO Cebu'],
                'account_id' => $acct('5320'), 'amount' => $pc, 'tax_code_id' => $tax['NT'], 'pay_mode' => 'bill', 'ref_no' => 'PPA-' . $vId, 'created_by' => Auth::id()]);
            $costId = (int)DB::value('SELECT MAX(id) FROM shp_voyage_costs');
            $txn = ShipGL::once('cost:' . $costId, ['source_type' => 'cost', 'source_id' => $costId, 'voyage_id' => $vId, 'vessel_id' => (int)$v['vessel_id'], 'memo' => 'Port charges ' . $v['voyage_no']],
                fn() => ShipGL::bill($vend['Philippine Ports Authority - PMO Cebu'], substr($arrive, 0, 10), 'PPA-' . $vId, [['account_id' => $acct('5320'), 'amount' => $pc, 'tax_code_id' => $tax['NT'], 'description' => 'Port charges ' . $v['voyage_no']]], 'Port charges ' . $v['voyage_no'], $class));
            DB::update('shp_voyage_costs', ['txn_id' => $txn], 'id = :id', ['id' => $costId]);
            if (!$isFast) {
                $pil = $code === 'MEX' ? 6500 : 4800;
                DB::insert('shp_voyage_costs', ['voyage_id' => $vId, 'cost_type' => 'pilotage', 'description' => 'Pilotage, ' . $v['dest_name'], 'vendor_id' => $vend['Harbor Pilots Association of Cebu'],
                    'account_id' => $acct('5330'), 'amount' => $pil, 'tax_code_id' => $vat, 'tax_amount' => round($pil * .12, 2), 'pay_mode' => 'bill', 'ref_no' => 'HPAC-' . $vId, 'created_by' => Auth::id()]);
                $costId = (int)DB::value('SELECT MAX(id) FROM shp_voyage_costs');
                $txn = ShipGL::once('cost:' . $costId, ['source_type' => 'cost', 'source_id' => $costId, 'voyage_id' => $vId, 'vessel_id' => (int)$v['vessel_id'], 'memo' => 'Pilotage ' . $v['voyage_no']],
                    fn() => ShipGL::bill($vend['Harbor Pilots Association of Cebu'], substr($arrive, 0, 10), 'HPAC-' . $vId, [['account_id' => $acct('5330'), 'amount' => $pil, 'tax_code_id' => $vat, 'tax_amount' => round($pil * .12, 2), 'description' => 'Pilotage ' . $v['voyage_no']]], 'Pilotage ' . $v['voyage_no'], $class));
                DB::update('shp_voyage_costs', ['txn_id' => $txn], 'id = :id', ['id' => $costId]);
                // crew meal allowance of the trip
                $allow = (float)DB::value('SELECT COALESCE(SUM(c.daily_allowance), 0) FROM shp_voyage_crew vc JOIN shp_crew c ON c.id = vc.crew_id WHERE vc.voyage_id = :v', ['v' => $vId]) * .25;
                $allow = round($allow, 2);
                $bank = $acct('1006');
                DB::insert('shp_voyage_costs', ['voyage_id' => $vId, 'cost_type' => 'crew_allowance', 'description' => 'Crew meal allowance, ' . $v['voyage_no'], 'account_id' => $acct('5340'), 'amount' => $allow,
                    'pay_mode' => 'check', 'bank_account_id' => $bank, 'created_by' => Auth::id()]);
                $costId = (int)DB::value('SELECT MAX(id) FROM shp_voyage_costs');
                $txn = ShipGL::once('cost:' . $costId, ['source_type' => 'cost', 'source_id' => $costId, 'voyage_id' => $vId, 'vessel_id' => (int)$v['vessel_id'], 'memo' => 'Crew allowance ' . $v['voyage_no']],
                    fn() => ShipGL::check($bank, null, 'Crew of ' . $v['vessel_name'], substr($arrive, 0, 10), null, [['account_id' => $acct('5340'), 'amount' => $allow, 'description' => 'Crew meal allowance ' . $v['voyage_no']]], 'Crew allowance ' . $v['voyage_no'], $class));
                DB::update('shp_voyage_costs', ['txn_id' => $txn], 'id = :id', ['id' => $costId]);
            }
            // the Master signs the report; the voyage is completed and its revenue recognized
            demo_as('captain');
            Voyages::signReport($vId, 'Departed ' . fdatetime($depart) . ', arrived ' . fdatetime($arrive) . '. ' . ($late > 10 ? 'Delay of ' . $late . ' minutes at departure. ' : '') . 'Uneventful passage. All passengers and vehicles discharged safely.',
                ['distance_nm' => $v['route_nm']]);
            // cargo is delivered at the destination (two exceptions in the sample)
            demo_as('cargoclerk');
            foreach (DB::all("SELECT * FROM shp_cargo WHERE voyage_id = :v AND status = 'landed'", ['v' => $vId]) as $cg) {
                Ship::$clock = date('Y-m-d H:i:s', strtotime($arrive) + 3600 * mt_rand(2, 20));
                if (Ship::$clock > date('Y-m-d H:i:s')) {
                    continue;
                }
                $r = mt_rand(1, 40);
                if ($r === 1) {
                    Cargo::transition((int)$cg['id'], 'short_landed', ['note' => 'Two packages missing at discharge tally', 'received_qty' => (int)$cg['packages'] - 2]);
                } elseif ($r === 2) {
                    Cargo::transition((int)$cg['id'], 'damaged', ['note' => 'Three crates crushed; photos taken at the pier']);
                } else {
                    Cargo::transition((int)$cg['id'], 'delivered', ['received_qty' => (int)$cg['packages'], 'delivered_to' => $cg['consignee_name'], 'pod_notes' => 'Received in good order']);
                }
            }
            demo_as('opsmgr');
            Ship::$clock = date('Y-m-d H:i:s', strtotime($arrive) + 1800);
            Voyages::transition($vId, 'completed', ['at' => Ship::$clock]);
        }

        // ---------------- Fridays: corporate, agent and cargo accounts pay their invoices older than ten days
        if ($date < $today && (int)date('N', strtotime($date)) === 5) {
            demo_as('arclerk');
            Ship::$clock = $at($date, '14:00');
            foreach ($cust as $cname => $cid) {
                $apps = [];
                $sum = 0.0;
                foreach (Balances::openReceivables($cid) as $inv) {
                    if ($inv['txn_date'] <= date('Y-m-d', strtotime($date . ' -10 days'))) {
                        $apps[] = ['target_txn_id' => (int)$inv['id'], 'amount' => (float)$inv['open_balance']];
                        $sum += (float)$inv['open_balance'];
                    }
                }
                if ($apps) {
                    Txn::save('payment', ['txn_date' => $date, 'customer_id' => $cid, 'account_id' => $acct('1010'), 'account2_id' => Accts::get('ar'), 'total' => r2($sum),
                        'ref_no' => 'CHK-' . mt_rand(100000, 999999), 'memo' => 'Payment of invoices'], [], null, ['keep_totals' => true, 'applications' => $apps]);
                }
            }
        }

        // ---------------- end of day: shifts close, cash goes to the bank the next morning
        if ($date < $today) {
            foreach ($shift as $k => $sid) {
                if (str_ends_with($k, ':' . $date) && DB::value("SELECT status FROM shp_cashier_shifts WHERE id = :id", ['id' => $sid]) === 'open') {
                    $s = DB::row('SELECT * FROM shp_cashier_shifts WHERE id = :id', ['id' => $sid]);
                    Auth::actAs((int)$s['cashier_id']);
                    Access::reset();
                    Ship::$clock = $at($date, '22:30');
                    $exp = Cashier::totals($sid)['expected'];
                    $diff = mt_rand(1, 12) === 1 ? (mt_rand(0, 1) ? -1 : 1) * mt_rand(20, 300) : 0;
                    Cashier::close($sid, $exp + $diff, $diff ? 'Counted twice' : '');
                    if ($date < $day(-1) || mt_rand(0, 1)) {
                        demo_as('tktmgr');
                        Cashier::review($sid, 'Checked against the payment list');
                    }
                }
            }
            if (!empty($depositQueue[$date])) {
                demo_as('treasurer');
                Ship::$clock = $at($day((int)round((strtotime($date) - strtotime($today)) / 86400) + 1), '10:00');
                $uf = Accts::get('undeposited_funds');
                $lines = [];
                foreach (array_unique(array_filter($depositQueue[$date])) as $sr) {
                    $t = DB::row('SELECT id, total, customer_id FROM transactions WHERE id = :t AND account_id = :a', ['t' => $sr, 'a' => $uf]);
                    if ($t) {
                        $lines[] = ['line_kind' => 'deposit', 'account_id' => $uf, 'amount' => (float)$t['total'], 'ref_txn_id' => $t['id'], 'customer_id' => $t['customer_id']];
                    }
                }
                if ($lines && substr(Ship::$clock, 0, 10) <= $today) {
                    Txn::save('deposit', ['txn_date' => substr(Ship::$clock, 0, 10), 'account_id' => $acct('1010'), 'ref_no' => 'DS-' . date('md', strtotime($date)), 'memo' => 'Ticket and freight collections of ' . fdate($date), 'status' => 'open'], $lines);
                }
            }
        }
    }

    // a few unpaid portal reservations on the coming sailings
    foreach ($byDate as $d => $ids) {
        if ($d <= $today) {
            continue;
        }
        foreach ($ids as $vId) {
            if (mt_rand(1, 3) !== 1) {
                continue;
            }
            $v = Ship::voyage($vId);
            Ship::$clock = date('Y-m-d H:i:s', time() - 20 * 60);
            demo_as('ticket2');
            try {
                Booking::hold(['voyage_id' => $vId, 'channel' => 'portal', 'office_id' => $office['WEB'], 'contact_name' => 'Portal reservation', 'contact_email' => 'reserve' . $vId . '@mail.test'],
                    [$pax() + ['accom_code' => array_keys(Booking::capacity($v))[0]]], [], ['hold_minutes' => 240]);
            } catch (UserError $e) {
            }
        }
    }

    // ---------------------------------------------------------------- maintenance history and the live safety hold
    $woFlow = function (string $code, string $eq, string $title, string $type, int $daysAgo, array $parts, float $hours, ?array $service = null, bool $critical = false, ?int $defectId = null) use ($vid, $equip, $item, $shore, $at, $day, $vend) {
        Ship::$clock = $at($day(-$daysAgo), '07:30');
        demo_as('planner');
        $wo = $defectId ? (int)DB::value('SELECT work_order_id FROM shp_defects WHERE id = :d', ['d' => $defectId]) : Cmms::createWo(['vessel_id' => $vid[$code], 'equipment_id' => $equip[$code][$eq], 'wo_type' => $type, 'title' => $title, 'status' => 'triaged', 'safety_critical' => $critical ? 1 : 0, 'assigned_to' => demo_id('users', 'username', 'tech1')]);
        demo_as('fleetmgr');
        if (DB::value('SELECT status FROM shp_work_orders WHERE id = :w', ['w' => $wo]) === 'triaged') {
            Cmms::transition($wo, 'approved', ['note' => 'Go ahead']);
        }
        demo_as('tech1');
        Cmms::transition($wo, 'in_progress', ['at' => Ship::$clock]);
        Cmms::addLabor($wo, 'Joel Sumalinog', demo_id('users', 'username', 'tech1'), $day(-$daysAgo), $hours);
        Cmms::addLabor($wo, 'Ricky Bacalso', demo_id('users', 'username', 'tech2'), $day(-$daysAgo), max(1, $hours - 2));
        demo_as('warehouse');
        foreach ($parts as $code2 => $q) {
            Cmms::issuePart($wo, $item[$code2], $shore, $q, $day(-$daysAgo));
        }
        if ($service) {
            demo_as('planner');
            Cmms::addService($wo, $vend[$service[0]], $service[1], $service[2], $service[3], true, $day(-$daysAgo));
        }
        Ship::$clock = $at($day(-$daysAgo), '15:30');
        demo_as('tech1');
        Cmms::transition($wo, 'inspection', ['at' => Ship::$clock, 'findings' => 'Worn parts replaced', 'root_cause' => 'Normal wear', 'reading_before' => 'Fault present', 'reading_after' => 'Within limits']);
        demo_as('chiefeng');
        Cmms::transition($wo, 'completed', ['inspection_result' => 'pass', 'note' => 'Tested under load, within tolerances']);
        demo_as('fleetmgr');
        Cmms::transition($wo, 'closed', ['note' => 'Accepted']);
        return $wo;
    };
    $woFlow('VSP', 'ME-P', 'Main engine (port): change lube oil and filters', 'preventive', 18, ['SP-OFILT' => 2, 'SP-FFILT' => 2], 6);
    $woFlow('MEX', 'GEN1', 'Generator No. 1: 250-hour service and V-belt replacement', 'preventive', 12, ['SP-BELT' => 1, 'SP-OFILT' => 1], 4);
    $woFlow('SOB', 'ME-S', 'Main engine (starboard): replace sea water pump impeller', 'corrective', 9, ['SP-IMP' => 1], 5, ['Marine Engine Parts Trading', 'Impeller housing machining', 4500, 'MEPT-JO-7781']);
    // a safety-critical defect last week: hold, repair, inspection, two-person release
    Ship::$clock = $at($day(-6), '22:40');
    demo_as('captain');
    $def = Cmms::reportDefect(['vessel_id' => $vid['MEX'], 'equipment_id' => $equip['MEX']['STEER'], 'title' => 'Steering gear pump No. 2 losing hydraulic pressure', 'severity' => 'critical', 'safety_critical' => 1,
        'description' => 'Pressure drops below 70 bar on hard-over test. Pump No. 1 normal.', 'operating_impact' => 'No redundancy on steering']);
    Ship::$clock = $at($day(-5), '06:30');
    demo_as('chiefeng');
    Cmms::triage($def, 'critical', true, true, demo_id('users', 'username', 'tech1'), 'Replace hose assembly and check pump');
    $woFlow('MEX', 'STEER', '', 'corrective', 5, ['SP-HOSE' => 1], 7, ['Cebu Shipyard and Engineering Works', 'Steering pump overhaul and pressure test', 18500, 'CSEW-SR-1182'], true, $def);
    $hold = (int)DB::value('SELECT hold_id FROM shp_defects WHERE id = :d', ['d' => $def]);
    Ship::$clock = $at($day(-5), '17:00');
    demo_as('chiefeng');
    Holds::technicalRelease($hold, 'Pump No. 2 holds 95 bar on hard-over test after the hose replacement and overhaul. Test log attached to WO.');
    demo_as('fleetmgr');
    Holds::release($hold, 'Technical release reviewed; Master informed; MARINA notified by e-mail.');
    // a critical defect open today on the fast craft: its next departures are held
    Ship::$clock = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -40 minutes'));
    demo_as('captain');
    $def2 = Cmms::reportDefect(['vessel_id' => $vid['SOB'], 'equipment_id' => $equip['SOB']['FIRE'], 'title' => 'Emergency fire pump does not start on weekly test', 'severity' => 'critical', 'safety_critical' => 1,
        'description' => 'Starter motor clicks, engine does not turn over.', 'operating_impact' => 'Emergency fire pump unavailable']);
    demo_as('chiefeng');
    Cmms::triage($def2, 'critical', true, true, demo_id('users', 'username', 'tech2'), 'Check starter and battery; replace battery');
    // planned maintenance due now
    demo_as('planner');
    Cmms::raiseDue();
    // a defect still waiting for triage
    demo_as('tech2');
    Cmms::reportDefect(['vessel_id' => $vid['VSP'], 'equipment_id' => $equip['VSP']['RAMP'], 'title' => 'Hydraulic oil weeping at stern ramp cylinder seal', 'severity' => 'minor', 'description' => 'Slow seep, about one drop a minute while the ramp is down.']);

    // ---------------------------------------------------------------- purchasing: requisition, canvass, PO, receipt and bill, payment
    Ship::$clock = $at($day(-11), '09:00');
    demo_as('planner');
    $pr = Procure::save(['department' => 'Engine', 'vessel_id' => $vid['MEX'], 'site_id' => $shore, 'needed_by' => $day(-4), 'priority' => 'urgent', 'justification' => 'Restock steering and filter spares after the steering repair'],
        [['item_id' => $item['SP-HOSE'], 'qty' => 4], ['item_id' => $item['SP-FFILT'], 'qty' => 20], ['item_id' => $item['SP-OFILT'], 'qty' => 20]]);
    Procure::submit($pr);
    demo_as('fleetmgr');
    ShipApproval::decide((int)DB::value("SELECT id FROM shp_approvals WHERE ref_table = 'shp_requisitions' AND ref_id = :r", ['r' => $pr]), true, 'Approved within the repairs budget');
    demo_as('procure');
    $lines = DB::all('SELECT * FROM shp_requisition_lines WHERE pr_id = :p ORDER BY line_no', ['p' => $pr]);
    $q1 = Procure::addQuote($pr, $vend['Marine Engine Parts Trading'], [$lines[0]['id'] => 5100, $lines[1]['id'] => 1820, $lines[2]['id'] => 1420], 'MEPT-Q-551', $day(-10), 3, 'Net 30');
    Procure::addQuote($pr, $vend['Seaborne Ship Chandlers'], [$lines[0]['id'] => 5600, $lines[1]['id'] => 1900, $lines[2]['id'] => 1500], 'SSC-Q-210', $day(-10), 5, 'Net 15');
    $po = Procure::order($pr, $q1);
    Ship::$clock = $at($day(-7), '14:00');
    demo_as('apclerk');
    $poLines = DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY line_no', ['t' => $po]);
    $billLines = [];
    foreach ($poLines as $l) {
        $billLines[] = ['line_kind' => 'item', 'item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'uom_qty' => $l['qty'], 'uom_rate' => $l['rate'],
            'amount' => $l['amount'], 'tax_code_id' => $l['tax_code_id'], 'tax_amount' => $l['tax_amount'], 'site_id' => $shore, 'source_line_id' => $l['id'], 'class_id' => $l['class_id']];
    }
    $bill = Txn::save('bill', ['txn_date' => $day(-7), 'vendor_id' => $vend['Marine Engine Parts Trading'], 'account_id' => Accts::get('ap'), 'terms_id' => $terms['Net 30'],
        'due_date' => $day(23), 'ref_no' => 'MEPT-SI-20577', 'site_id' => $shore, 'memo' => 'Received with PO; from requisition', 'received_via' => 'bill'], $billLines);
    Ship::$clock = $at($day(-2), '11:00');
    demo_as('treasurer');
    $owed = (float)DB::value('SELECT total FROM transactions WHERE id = :t', ['t' => $bill]);
    Txn::save('bill_payment', ['txn_date' => $day(-2), 'vendor_id' => $vend['Marine Engine Parts Trading'], 'account_id' => $acct('1010'), 'account2_id' => Accts::get('ap'), 'total' => $owed, 'memo' => 'Payment of MEPT-SI-20577'],
        [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $bill, 'amount' => $owed]]]);
    // another requisition waiting for approval
    demo_as('chiefeng');
    $pr2 = Procure::save(['department' => 'Deck', 'vessel_id' => $vid['VSP'], 'site_id' => $shore, 'needed_by' => $day(10), 'priority' => 'normal', 'justification' => 'Replace life jackets due for condemnation at the annual survey'],
        [['item_id' => $item['SP-LJ'], 'qty' => 60]]);
    Procure::submit($pr2);

    // ---------------------------------------------------------------- dry-dock project of the fast craft
    demo_as('fleetmgr');
    DB::insert('shp_drydock', ['dd_no' => Ship::no('shp_drydock', 'DD-', 4), 'vessel_id' => $vid['SOB'], 'title' => 'Annual dry-docking and intermediate survey', 'yard_vendor_id' => $vend['Cebu Shipyard and Engineering Works'],
        'survey_type' => 'Intermediate survey (class)', 'planned_start' => $day(45), 'planned_end' => $day(59), 'budget' => 6800000, 'quoted_amount' => 6250000, 'capex_assessment' => 'mixed',
        'status' => 'planned', 'notes' => 'Hull blasting and painting, propeller polishing, shaft seal renewal, survey items. Capital items (shaft seals) and repairs to be split per the accounting policy.', 'created_by' => Auth::id()]);
    $dd = (int)DB::value('SELECT MAX(id) FROM shp_drydock');
    foreach ([['Mobilization and docking', 45, 1250000], ['Hull works and painting', 52, 2600000], ['Undocking and sea trial', 59, 2400000]] as [$nm, $o, $amt]) {
        DB::insert('shp_drydock_milestones', ['drydock_id' => $dd, 'name' => $nm, 'due_date' => $day($o), 'amount' => $amt]);
    }

    // ---------------------------------------------------------------- fuel sounding with a variance waiting for approval; a bunkering this week
    Ship::$clock = $at($day(-3), '08:00');
    demo_as('chiefeng');
    Fuel::sounding($vid['VSP'], round(Fuel::rob($vid['VSP']) - 180), $at($day(-3), '08:00'), 'Morning sounding, all tanks');
    demo_as('warehouse');
    foreach (['VSP', 'MEX'] as $code) {
        $space = (float)$vessels[$code]['fuel_tank_l'] - Fuel::rob($vid[$code]);
        if ($space > 5000) {
            Fuel::bunker($vid[$code], round($space * .8), 63.40, $vend['Petron Marine Fuels'], 'PTR-DR-' . mt_rand(100000, 999999), $day(-3), 'Weekly bunkering');
        }
    }

    // ---------------------------------------------------------------- a sailing cancelled by a gale warning
    // some passengers already refunded in full, some moved free of charge to the next sailing, the rest still to be served
    $cx = DB::row("SELECT v.id FROM shp_voyages v JOIN shp_vessels s ON s.id = v.vessel_id WHERE s.code = 'VSP' AND v.status = 'on_sale' AND v.voyage_date BETWEEN :a AND :b ORDER BY v.etd LIMIT 1",
        ['a' => $day(1), 'b' => $day(4)]);
    if ($cx) {
        $cxId = (int)$cx['id'];
        $cv = Ship::voyage($cxId);
        Ship::$clock = date('Y-m-d H:i:s', time() - 3 * 3600);
        demo_as('opsmgr');
        Voyages::transition($cxId, 'cancelled', ['reason' => 'Gale warning over the Camotes Sea; no sailing for vessels of this size',
            'authority' => 'PCG Station Cebu advisory; Operations Manager']);
        $next = (int)DB::value("SELECT id FROM shp_voyages WHERE route_id = :r AND status = 'on_sale' AND etd > :e ORDER BY etd LIMIT 1", ['r' => $cv['route_id'], 'e' => $cv['etd']]);
        $affected = DB::all("SELECT t.id, t.booking_id FROM shp_tickets t JOIN shp_bookings b ON b.id = t.booking_id
                              WHERE t.voyage_id = :v AND t.status = 'issued' AND b.channel IN ('counter', 'portal') ORDER BY t.id", ['v' => $cxId]);
        $cxOrigin = $cv['origin_code'];
        foreach ($affected as $i => $t) {
            Ship::$clock = date('Y-m-d H:i:s', time() - 2 * 3600 + $i * 60);
            if ($i % 4 === 0) {
                demo_as($cashierOf[$cxOrigin]);
                $rid = Booking::requestRefund((int)$t['booking_id'], [(int)$t['id']], [], 'voyage_cancelled', 'Voyage ' . $cv['voyage_no'] . ' cancelled (gale warning); passenger chose a full refund', 'cash');
                if ($i === 0) {
                    continue;   // waiting for the ticketing manager in the Approvals Queue
                }
                demo_as('tktmgr');
                ShipApproval::decide((int)DB::value("SELECT id FROM shp_approvals WHERE ref_table = 'shp_refunds' AND ref_id = :r", ['r' => $rid]), true, 'Cancelled voyage: full refund');
                demo_as($cashierOf[$cxOrigin]);
                Booking::payRefund($rid, 'cash', $openShift($cxOrigin, $today));
            } elseif ($i % 4 === 1 && $next) {
                demo_as($cashierOf[$cxOrigin]);
                try {
                    Booking::transfer((int)$t['id'], $next, ['free' => true]);
                } catch (UserError $e) {
                }
            }
        }
    }

    // ---------------------------------------------------------------- a passenger with a portal account: one trip paid, one reserved
    $puId = DB::insert('shp_portal_users', ['email' => 'juan.delacruz@mail.test', 'password_hash' => password_hash('Sakay@2026', PASSWORD_DEFAULT), 'full_name' => 'Juan Dela Cruz',
        'mobile' => '09171234567', 'status' => 'active', 'last_login_at' => date('Y-m-d H:i:s', time() - 86400)]);
    $sob = DB::column("SELECT v.id FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id WHERE r.code = 'CEB-TAG' AND v.status = 'on_sale' AND v.voyage_date >= :d ORDER BY v.etd", ['d' => $day(2)]);
    if (count($sob) >= 2) {
        $who = [['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birth_date' => $day(-36 * 365), 'gender' => 'M', 'nationality' => 'Filipino', 'category_id' => $cats['REG'], 'accom_code' => 'BUS'],
            ['last_name' => 'Dela Cruz', 'first_name' => 'Remedios', 'birth_date' => $day(-67 * 365), 'gender' => 'F', 'nationality' => 'Filipino', 'category_id' => $cats['SC'], 'id_no' => 'OSCA-0451187', 'accom_code' => 'BUS']];
        Ship::$clock = date('Y-m-d H:i:s', time() - 26 * 3600);
        demo_as('ticket2');
        $pb = Booking::hold(['voyage_id' => $sob[0], 'channel' => 'portal', 'office_id' => $office['WEB'], 'portal_user_id' => $puId, 'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan.delacruz@mail.test', 'contact_phone' => '09171234567', 'notes' => 'Reserved online.'], $who);
        Ship::$clock = date('Y-m-d H:i:s', time() - 25 * 3600);
        Booking::pay($pb, ['channel' => 'ewallet', 'provider_ref' => 'G' . mt_rand(10000000, 99999999), 'idempotency_key' => 'demo-portal-' . $pb, 'date' => substr(Ship::$clock, 0, 10)]);
        Ship::$clock = date('Y-m-d H:i:s', time() - 30 * 60);
        Booking::hold(['voyage_id' => $sob[count($sob) - 1], 'channel' => 'portal', 'office_id' => $office['WEB'], 'portal_user_id' => $puId, 'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan.delacruz@mail.test', 'contact_phone' => '09171234567', 'notes' => 'Reserved online.'], [$who[0]], [], ['hold_minutes' => 600]);
    }

    // ---------------------------------------------------------------- a power outage at the Cebu ticket office this morning
    // tickets were sold from the manual series; three stubs are encoded since, one waits, and one repeats a series number
    $mv = (int)DB::value("SELECT v.id FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id WHERE r.code = 'CEB-TAG' AND v.status = 'on_sale' AND v.voyage_date = :d ORDER BY v.etd LIMIT 1", ['d' => $day(1)]);
    if ($mv) {
        $sid = $openShift('CEB', $today);
        demo_as('ticket1');
        $outage = 'Power outage at Cebu Pier 4, 07:10 to 08:05; sold from the manual series MT-2026';
        $issuedAt = min($at($today, '07:35'), date('Y-m-d H:i:s', time() - 3 * 3600));
        $mvv = Ship::voyage($mv);
        $stubs = [['MT-2026-0141', 'Bacalso', 'Liza', 'REG', '1991-03-14', ''], ['MT-2026-0142', 'Bacalso', 'Ramon', 'REG', '1988-07-02', ''],
            ['MT-2026-0143', 'Quijano', 'Pedro', 'SC', '1953-11-20', 'OSCA-0217745'], ['MT-2026-0144', 'Yap', 'Christine', 'REG', '1996-01-30', ''], ['MT-2026-0142', 'Uy', 'Benjie', 'REG', '1985-05-09', '']];
        foreach ($stubs as $i => [$series, $last, $first, $cc, $born, $idNo]) {
            $q = Fares::quotePax($mvv, 'TOU', Fares::category($cats[$cc]));
            $dupOf = DB::value("SELECT id FROM shp_manual_tickets WHERE series_no = :s AND status <> 'void' ORDER BY id LIMIT 1", ['s' => $series]);
            Ship::$clock = $issuedAt;
            // the repeated number was written on a stub for the next day's sailing
            $stubVoyage = $i === 4 ? ((int)DB::value("SELECT v.id FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id WHERE r.code = 'CEB-TAG' AND v.status = 'on_sale' AND v.etd > :e ORDER BY v.etd LIMIT 1", ['e' => $mvv['etd']]) ?: $mv) : $mv;
            if ($stubVoyage === $mv && $dupOf) {
                continue;
            }
            $mid = DB::insert('shp_manual_tickets', ['voyage_id' => $stubVoyage, 'series_no' => $series, 'passenger_name' => $last . ', ' . $first, 'category_id' => $cats[$cc], 'accom_code' => 'TOU',
                'amount' => $q['total'], 'issued_by' => Auth::id(), 'issued_at' => $issuedAt, 'status' => $dupOf ? 'duplicate' : 'recorded', 'duplicate_of' => $dupOf ?: null,
                'incident_note' => $outage, 'created_by' => Auth::id()]);
            Ship::audit('create', 'tkt', 'shp_manual_ticket', $mid, 'Manual ticket ' . $series . ($dupOf ? ' (series used twice)' : ''), ['amount' => $q['total'], 'reason' => $outage, 'page_key' => 'tkt.manual']);
            if ($i < 3) {
                // encoded after the power came back: a booking paid into the cashier's shift, the e-ticket carries the stub number
                Ship::$clock = date('Y-m-d H:i:s', strtotime($issuedAt) + 3600 + 120 * $i);
                $bid = Booking::hold(['voyage_id' => $mv, 'channel' => 'counter', 'office_id' => $office['CEB-P4'], 'contact_name' => $first . ' ' . $last, 'notes' => 'Manual ticket ' . $series . ' (' . $outage . ')'],
                    [['last_name' => $last, 'first_name' => $first, 'birth_date' => $born, 'gender' => in_array($first, ['Liza', 'Christine'], true) ? 'F' : 'M', 'nationality' => 'Filipino',
                        'id_no' => $idNo, 'category_id' => $cats[$cc], 'accom_code' => 'TOU']]);
                Booking::pay($bid, ['channel' => 'cash', 'shift_id' => $sid, 'idempotency_key' => 'manual:' . $mid, 'date' => $today]);
                $tid = (int)DB::value('SELECT id FROM shp_tickets WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid]);
                DB::update('shp_tickets', ['manual_ticket_no' => $series], 'id = :id', ['id' => $tid]);
                DB::update('shp_manual_tickets', ['status' => 'encoded', 'ticket_id' => $tid], 'id = :id', ['id' => $mid]);
                Ship::audit('encode', 'tkt', 'shp_manual_ticket', $mid, 'Manual ticket ' . $series . ' encoded as booking', ['amount' => $q['total'], 'reason' => 'Amounts agree', 'page_key' => 'tkt.manual']);
            }
        }
        Notify::staff('manual:dup:demo', 'manual_duplicate', ['Ticketing Manager', 'Internal Auditor'], 'Manual ticket number used twice',
            'Stub MT-2026-0142 was recorded twice (Bacalso, Ramon and Uy, Benjie). Check the physical stubs before encoding.', 'shp_manual_tickets', null);
    }

    // ---------------------------------------------------------------- customer service
    $cs = [
        ['lost_baggage', 'Left a backpack in Tourist row 12', 'normal', 'resolved', 'found'],
        ['refund_followup', 'When will my refund be released?', 'normal', 'closed', 'refunded'],
        ['delayed_voyage', 'Voyage left 70 minutes late; missed my connection', 'high', 'in_progress', null],
        ['accessibility', 'Wheelchair assistance needed for my mother', 'high', 'resolved', 'answered'],
        ['complaint', 'Air-conditioning in Business class not working', 'normal', 'open', null],
        ['booking_help', 'Cannot find the senior citizen discount on the portal', 'low', 'resolved', 'answered'],
        ['rebooking', 'Move my family of four to Sunday', 'normal', 'closed', 'rebooked'],
        ['damaged_cargo', 'Crushed crates of produce on arrival', 'urgent', 'in_progress', null],
    ];
    $agents = ['csagent', 'tktmgr'];
    foreach ($cs as $i => [$catc, $subject, $prio, $status, $res]) {
        Ship::$clock = $at($day(-mt_rand(1, 14)), sprintf('%02d:%02d', mt_rand(8, 17), mt_rand(0, 59)));
        demo_as('csagent');
        $t = DB::row("SELECT t.*, b.contact_email, b.contact_phone FROM shp_tickets t JOIN shp_bookings b ON b.id = t.booking_id WHERE t.status IN ('boarded', 'issued') ORDER BY t.id DESC LIMIT 1 OFFSET " . ($i * 7));
        $cg = $catc === 'damaged_cargo' ? DB::row("SELECT * FROM shp_cargo WHERE status = 'damaged' LIMIT 1") : null;
        $caseId = Care::open(['category' => $catc, 'channel' => ['desk', 'phone', 'portal', 'email'][$i % 4], 'subject' => $subject, 'priority' => $prio,
            'description' => $subject . '. Customer called the hotline.', 'customer_name' => $cg ? $cg['shipper_name'] : $t['first_name'] . ' ' . $t['last_name'],
            'customer_email' => $t['contact_email'] ?? null, 'customer_phone' => $cg ? $cg['shipper_phone'] : ($t['contact_phone'] ?? null),
            'booking_id' => $cg ? null : $t['booking_id'], 'ticket_id' => $cg ? null : $t['id'], 'voyage_id' => $cg ? $cg['voyage_id'] : $t['voyage_id'], 'cargo_id' => $cg['id'] ?? null,
            'assigned_to' => demo_id('users', 'username', $agents[$i % 2])]);
        Care::act($caseId, 'reply', 'We are looking into this and will update you within the day.', false);
        if ($cg) {
            Cargo::transition((int)$cg['id'], 'claimed', ['case_id' => $caseId, 'note' => 'Claim filed, case ' . DB::value('SELECT case_no FROM shp_cases WHERE id = :c', ['c' => $caseId])]);
            Care::requestCompensation($caseId, 'voucher', 8500, 'Three crates of bananas crushed in the hold; shipper accepts a freight credit');
        }
        if ($catc === 'delayed_voyage') {
            Care::requestCompensation($caseId, 'voucher', 500, 'Goodwill credit for the delay');
            demo_as('tktmgr');
            ShipApproval::decide((int)DB::value("SELECT id FROM shp_approvals WHERE ref_table = 'shp_cases' AND ref_id = :c", ['c' => $caseId]), true, 'Goodwill within policy');
            demo_as('csagent');
        }
        if ($res) {
            Care::update($caseId, ['status' => $status, 'resolution_code' => $res, 'resolution_notes' => 'Resolved with the customer.']);
        } elseif ($status !== 'open') {
            Care::update($caseId, ['status' => $status]);
        }
    }

    // ---------------------------------------------------------------- port fees of the past fortnight remitted; depreciation of last month
    Ship::$clock = $at($day(-1), '15:00');
    demo_as('accountant');
    $feeId = (int)DB::value("SELECT id FROM shp_fees WHERE code = 'TFEE'");
    $vs = DB::column("SELECT DISTINCT v.id FROM shp_voyages v WHERE v.status = 'completed' AND v.voyage_date <= :d", ['d' => $day(-8)]);
    if ($vs) {
        PortFees::remit($feeId, array_map('intval', $vs), $acct('1010'), $day(-1), 'PPA-OR-' . mt_rand(100000, 999999));
    }
    $lm = strtotime($day(-40) . ' first day of next month');
    if (date('Y-m', $lm) < date('Y-m', strtotime($today))) {
        Ship::$clock = date('Y-m-t 17:00:00', $lm);
        Depreciation::run((int)date('Y', $lm), (int)date('n', $lm), null, 'Monthly depreciation');
    }

    // ---------------------------------------------------------------- the current shifts stay open; the clock returns to now
    Ship::$clock = null;
    demo_as('admin');
    Care::escalateOverdue();
    Holds::syncCertificates();
}
