<?php
declare(strict_types=1);

/**
 * Cargo shipments (waybills): quotation, booking, acceptance at the port, loading, transit, landing, delivery
 * with proof of delivery, and exceptions (short-landed, damaged, claims, roll-over).
 *
 * Money: a prepaid (walk-in) shipment is paid on a sales receipt with the freight deferred until the voyage is
 * completed; a credit shipment is invoiced to the shipper's account once (event key) after delivery. A delivery
 * with fewer packages than shipped or with damage noted can never be recorded as a clean proof of delivery.
 */
final class Cargo
{
    public static function find(int $id): ?array
    {
        return DB::row('SELECT c.*, v.voyage_no, v.etd, v.status AS voyage_status, v.vessel_id, r.code AS route_code, po.name AS origin_name, pd.name AS dest_name,
                               cu.name AS shipper_account
                          FROM shp_cargo c JOIN shp_routes r ON r.id = c.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                          LEFT JOIN shp_voyages v ON v.id = c.voyage_id LEFT JOIN customers cu ON cu.id = c.shipper_customer_id WHERE c.id = :id', ['id' => $id]);
    }

    /** Saves a quotation or booking with its packages; the price is worked out from the tariff */
    public static function save(array $d, array $items, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $items, $id) {
            $old = $id ? DB::row('SELECT * FROM shp_cargo WHERE id = :id FOR UPDATE', ['id' => $id]) : null;
            if ($old && !in_array($old['status'], ['quotation', 'booked'], true)) {
                fail('A shipment can be changed only while it is a quotation or booked.');
            }
            if ($old && ($old['paid_txn_id'] || $old['invoice_txn_id'])) {
                fail('This shipment is already paid or billed; cancel it and book again to change it.');
            }
            $routeId = (int)($d['route_id'] ?? 0);
            if (!DB::value('SELECT 1 FROM shp_routes WHERE id = :id', ['id' => $routeId])) {
                fail('Choose the route.');
            }
            foreach (['shipper_name' => 'shipper', 'consignee_name' => 'consignee', 'description' => 'description of the goods'] as $k => $label) {
                if (trim((string)($d[$k] ?? '')) === '') {
                    fail('Enter the ' . $label . '.');
                }
            }
            $voyageId = !empty($d['voyage_id']) ? (int)$d['voyage_id'] : null;
            if ($voyageId) {
                $v = DB::row('SELECT route_id, status, etd FROM shp_voyages WHERE id = :id', ['id' => $voyageId]);
                if (!$v || (int)$v['route_id'] !== $routeId) {
                    fail('The voyage chosen is not on this route.');
                }
                if (!in_array($v['status'], ['published', 'on_sale', 'boarding', 'ready'], true)) {
                    fail('The voyage chosen is not open for cargo.');
                }
            }
            $kg = 0.0;
            $cbm = 0.0;
            $declared = 0.0;
            $pk = 0;
            $clean = [];
            foreach ($items as $it) {
                if (trim((string)($it['description'] ?? '')) === '' && (float)($it['weight_kg'] ?? 0) <= 0) {
                    continue;
                }
                $vol = (float)($it['volume_cbm'] ?? 0);
                if ($vol <= 0 && (float)($it['length_cm'] ?? 0) > 0) {
                    $vol = round((float)$it['length_cm'] * (float)$it['width_cm'] * (float)$it['height_cm'] / 1000000 * max(1, (int)($it['packages'] ?? 1)), 3);
                }
                $it['volume_cbm'] = $vol;
                $kg += (float)($it['weight_kg'] ?? 0);
                $cbm += $vol;
                $declared += (float)($it['declared_value'] ?? 0);
                $pk += max(1, (int)($it['packages'] ?? 1));
                $clean[] = $it;
            }
            if (!$clean) {
                fail('Add at least one package line with its weight.');
            }
            $date = $voyageId ? substr((string)DB::value('SELECT etd FROM shp_voyages WHERE id = :id', ['id' => $voyageId]), 0, 10) : Ship::today();
            $q = Fares::quoteCargo($routeId, (string)($d['commodity_class'] ?? 'general'), $kg, $cbm, $declared, $date, (float)($d['discount_pct'] ?? 0));
            $terms = ($d['payment_terms'] ?? 'prepaid') === 'credit' ? 'credit' : 'prepaid';
            if ($terms === 'credit' && empty($d['shipper_customer_id'])) {
                fail('A credit shipment is billed to a customer account: choose the shipper\'s account.');
            }
            if ($terms === 'credit') {
                $cust = DB::row('SELECT name, credit_limit FROM customers WHERE id = :id', ['id' => (int)$d['shipper_customer_id']]);
                $limit = (float)($cust['credit_limit'] ?? 0);
                if ($limit > 0 && !Access::special('credit_limit_override')) {
                    $owed = Balances::customerBalance((int)$d['shipper_customer_id']);
                    if ($owed + $q['total'] > $limit + 0.005) {
                        fail($cust['name'] . ' would owe ' . money($owed + $q['total']) . ', above its credit limit of ' . money($limit) . '. Take payment, or ask someone who may exceed credit limits.');
                    }
                }
            }
            $row = [
                'voyage_id' => $voyageId, 'route_id' => $routeId, 'shipper_customer_id' => !empty($d['shipper_customer_id']) ? (int)$d['shipper_customer_id'] : null,
                'shipper_name' => mb_substr(trim((string)$d['shipper_name']), 0, 120), 'shipper_phone' => $d['shipper_phone'] ?? null,
                'consignee_name' => mb_substr(trim((string)$d['consignee_name']), 0, 120), 'consignee_phone' => $d['consignee_phone'] ?? null,
                'consignee_address' => $d['consignee_address'] ?? null, 'commodity_class' => (string)($d['commodity_class'] ?? 'general'),
                'description' => mb_substr(trim((string)$d['description']), 0, 255), 'packages' => $pk, 'weight_kg' => r2($kg), 'volume_cbm' => round($cbm, 3),
                'chargeable_basis' => $q['basis'], 'declared_value' => r2($declared), 'special_handling' => $d['special_handling'] ?? null, 'tariff_id' => $q['tariff_id'],
                'freight_gross' => $q['freight_gross'], 'handling_fee' => $q['handling'], 'insurance_fee' => $q['insurance'], 'discount_amount' => $q['discount'],
                'freight_net' => $q['net'], 'vat_amount' => $q['vat'], 'total_amount' => $q['total'], 'payment_terms' => $terms,
                'status' => $old ? $old['status'] : (($d['status'] ?? 'quotation') === 'booked' ? 'booked' : 'quotation'), 'updated_at' => Ship::now(),
            ];
            if ($old) {
                DB::update('shp_cargo', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM shp_cargo_items WHERE cargo_id = :c', ['c' => $id]);
            } else {
                $row['waybill_no'] = Ship::no('shp_waybill', 'WB-', 7);
                $row['created_by'] = Auth::id();
                $id = DB::insert('shp_cargo', $row);
                DB::insert('shp_cargo_events', ['cargo_id' => $id, 'to_status' => $row['status'], 'details' => 'Shipment entered (' . $row['status'] . ')', 'user_id' => Auth::id()]);
            }
            $n = 0;
            foreach ($clean as $it) {
                DB::insert('shp_cargo_items', ['cargo_id' => $id, 'line_no' => ++$n, 'description' => mb_substr((string)($it['description'] ?: $d['description']), 0, 160),
                    'packages' => max(1, (int)($it['packages'] ?? 1)), 'packing' => $it['packing'] ?? null, 'weight_kg' => (float)($it['weight_kg'] ?? 0),
                    'length_cm' => (float)($it['length_cm'] ?? 0), 'width_cm' => (float)($it['width_cm'] ?? 0), 'height_cm' => (float)($it['height_cm'] ?? 0),
                    'volume_cbm' => (float)$it['volume_cbm'], 'declared_value' => (float)($it['declared_value'] ?? 0), 'marks' => $it['marks'] ?? null]);
            }
            Ship::audit($old ? 'edit' : 'create', 'cgo', 'shp_cargo', $id, 'Waybill ' . ($old['waybill_no'] ?? $row['waybill_no']), ['amount' => $q['total'], 'page_key' => 'cgo.shipments']);
            return $id;
        });
    }

    /** Prepaid freight paid at the counter: a sales receipt, freight deferred until the voyage is completed */
    public static function pay(int $id, string $channel, ?string $providerRef = null, ?int $shiftId = null, ?string $date = null): int
    {
        return DB::tx(function () use ($id, $channel, $providerRef, $shiftId, $date) {
            $c = DB::row('SELECT * FROM shp_cargo WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$c || $c['payment_terms'] !== 'prepaid') {
                fail('Only a prepaid shipment is paid at the counter; a credit shipment is invoiced after delivery.');
            }
            if ($c['paid_txn_id']) {
                fail('Waybill ' . $c['waybill_no'] . ' is already paid.');
            }
            if (!in_array($c['status'], ['quotation', 'booked', 'accepted'], true)) {
                fail('This shipment cannot be paid in its present status.');
            }
            if (!in_array($channel, ['cash', 'card', 'ewallet', 'maya', 'bank_transfer'], true)) {
                fail('Choose how the freight is paid.');
            }
            if ($channel !== 'cash' && trim((string)$providerRef) === '') {
                fail('Enter the payment reference.');
            }
            if ($providerRef && DB::value('SELECT 1 FROM shp_payments WHERE channel = :c AND provider_ref = :r', ['c' => $channel, 'r' => $providerRef])) {
                fail('Payment reference ' . $providerRef . ' was already used.');
            }
            $vesselId = $c['voyage_id'] ? (int)DB::value('SELECT vessel_id FROM shp_voyages WHERE id = :v', ['v' => $c['voyage_id']]) : null;
            $class = ShipGL::vesselClass($vesselId);
            $date = $date ?? Ship::today();
            $lines = self::lines($c, $class, true);
            [$acct, $pmId] = ShipGL::channelAccounts($channel);
            $customer = (int)($c['shipper_customer_id'] ?: ShipGL::walkInCustomer());
            $memo = 'Freight, waybill ' . $c['waybill_no'] . ' (' . $c['shipper_name'] . ' to ' . $c['consignee_name'] . ')';
            $txn = ShipGL::once('cargo:' . $id . ':paid', ['source_type' => 'cargo', 'source_id' => $id, 'voyage_id' => $c['voyage_id'], 'vessel_id' => $vesselId, 'memo' => $memo],
                fn() => ShipGL::salesReceipt($customer, $date, $acct, $pmId, $lines, $memo, $class, $c['waybill_no']));
            if ($channel === 'cash' && !$shiftId && PHP_SAPI !== 'cli') {
                $shiftId = Cashier::requireOpen((int)Auth::id());
            }
            Cashier::assertOpen($shiftId);
            DB::insert('shp_payments', ['payment_no' => Ship::no('shp_payment', 'PAY-', 7), 'cargo_id' => $id, 'channel' => $channel, 'payment_method_id' => $pmId,
                'provider_ref' => $providerRef ?: null, 'amount' => $c['total_amount'], 'status' => 'captured', 'shift_id' => $shiftId, 'txn_id' => $txn,
                'received_by' => Auth::id(), 'received_at' => Ship::now(), 'notes' => $memo]);
            DB::update('shp_cargo', ['paid_txn_id' => $txn, 'status' => $c['status'] === 'quotation' ? 'booked' : $c['status'], 'updated_at' => Ship::now(),
                'revenue_done' => Ship::setting('revenue_policy', 'deferred') === 'on_sale' ? 1 : 0], 'id = :id', ['id' => $id]);
            DB::insert('shp_cargo_events', ['cargo_id' => $id, 'from_status' => $c['status'], 'to_status' => $c['status'] === 'quotation' ? 'booked' : $c['status'], 'details' => 'Freight paid ' . money($c['total_amount']) . ' (' . $channel . ')', 'user_id' => Auth::id()]);
            return (int)$txn;
        });
    }

    /** The sales lines of a shipment: freight, handling and cargo insurance */
    private static function lines(array $c, ?int $class, bool $deferred): array
    {
        $vat = ShipGL::taxCode('VAT12');
        [$fNet, $fVat] = ShipGL::splitVat((float)$c['freight_gross'] - (float)$c['discount_amount']);
        [$hNet, $hVat] = ShipGL::splitVat((float)$c['handling_fee']);
        $acctF = $deferred ? ShipGL::freightSaleAccount('cargo') : Accts::get('shp_cargo_revenue');
        $acctH = $deferred && Ship::setting('revenue_policy', 'deferred') !== 'on_sale' ? Accts::get('shp_deferred_freight') : Accts::get('shp_handling_revenue');
        $lines = [ShipGL::line(ShipGL::item('SHP-CARGO'), 'Freight ' . $c['waybill_no'] . ': ' . $c['description'] . ' (' . qty($c['weight_kg']) . ' kg, ' . qty($c['volume_cbm']) . ' cbm, ' . $c['chargeable_basis'] . ')', $fNet, $fVat, $vat, $acctF, $class)];
        if ($hNet > 0) {
            $lines[] = ShipGL::line(ShipGL::item('SHP-HANDL'), 'Handling ' . $c['waybill_no'], $hNet, $hVat, $vat, $acctH, $class);
        }
        if ((float)$c['insurance_fee'] > 0) {
            // cargo insurance is passed on to the insurer: a payable, not revenue
            $lines[] = ShipGL::line(ShipGL::item('SHP-CINS'), 'Cargo insurance ' . $c['waybill_no'] . ' (declared ' . money($c['declared_value']) . ')', (float)$c['insurance_fee'], 0, ShipGL::taxCode('NT'), Accts::get('shp_insurance_payable'), $class);
        }
        return $lines;
    }

    public static function transition(int $id, string $to, array $ctx = []): void
    {
        DB::tx(function () use ($id, $to, $ctx) {
            $c = DB::row('SELECT * FROM shp_cargo WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$c) {
                fail('Shipment not found.');
            }
            Ship::assertFlow(Ship::CARGO_FLOW, $c['status'], $to, 'the shipment');
            $upd = ['status' => $to, 'updated_at' => Ship::now()];
            $note = trim((string)($ctx['note'] ?? ''));
            switch ($to) {
                case 'accepted':
                    if (!$c['voyage_id']) {
                        fail('Assign the shipment to a voyage before accepting it.');
                    }
                    if ($c['payment_terms'] === 'prepaid' && !$c['paid_txn_id']) {
                        fail('A prepaid shipment is accepted only after the freight is paid.');
                    }
                    break;
                case 'loaded':
                    $v = Ship::voyage((int)$c['voyage_id']);
                    if (!in_array($v['status'], ['boarding', 'ready', 'on_sale'], true)) {
                        fail('Cargo is loaded while the voyage is loading (on sale, boarding or ready).');
                    }
                    $loaded = (float)DB::value("SELECT COALESCE(SUM(weight_kg), 0) FROM shp_cargo WHERE voyage_id = :v AND status = 'loaded'", ['v' => $c['voyage_id']]);
                    if ((float)$v['cargo_capacity_t'] > 0 && ($loaded + (float)$c['weight_kg']) / 1000 > (float)$v['cargo_capacity_t'] + 0.0001) {
                        fail('Loading ' . qty($c['weight_kg']) . ' kg would put the voyage over its cargo capacity of ' . qty($v['cargo_capacity_t']) . ' t.');
                    }
                    break;
                case 'rolled_over':
                    if (empty($ctx['new_voyage_id'])) {
                        fail('Choose the voyage the cargo is rolled over to.');
                    }
                    $nv = DB::row('SELECT route_id, voyage_no, status FROM shp_voyages WHERE id = :id', ['id' => (int)$ctx['new_voyage_id']]);
                    if (!$nv || (int)$nv['route_id'] !== (int)$c['route_id'] || !in_array($nv['status'], ['published', 'on_sale', 'boarding'], true)) {
                        fail('Choose an open voyage of the same route.');
                    }
                    $upd['voyage_id'] = (int)$ctx['new_voyage_id'];
                    $note = trim('Rolled over to ' . $nv['voyage_no'] . '. ' . $note);
                    break;
                case 'delivered':
                    $received = isset($ctx['received_qty']) && $ctx['received_qty'] !== '' ? (int)$ctx['received_qty'] : null;
                    if ($received === null || trim((string)($ctx['delivered_to'] ?? '')) === '') {
                        fail('Enter the number of packages handed over and the name of the receiver (proof of delivery).');
                    }
                    if ($received < (int)$c['packages'] && $c['status'] === 'landed') {
                        fail('Only ' . $received . ' of ' . $c['packages'] . ' packages: record the shipment as short-landed first; a short delivery is never a clean proof of delivery.');
                    }
                    if (!empty($ctx['damage'])) {
                        fail('Damage noted at delivery: record the shipment as damaged first.');
                    }
                    $upd['received_qty'] = $received;
                    $upd['delivered_to'] = mb_substr((string)$ctx['delivered_to'], 0, 120);
                    $upd['delivered_at'] = $ctx['at'] ?? Ship::now();
                    $upd['pod_notes'] = $ctx['pod_notes'] ?? null;
                    break;
                case 'short_landed':
                case 'damaged':
                    if ($note === '') {
                        fail('Describe the exception (packages missing, damage seen).');
                    }
                    if (isset($ctx['received_qty']) && $ctx['received_qty'] !== '') {
                        $upd['received_qty'] = (int)$ctx['received_qty'];
                    }
                    break;
                case 'claimed':
                    if (empty($ctx['case_id'])) {
                        fail('A claim is opened as a support case (Customer Service > Support Cases & Claims).');
                    }
                    break;
                case 'cancelled':
                    if ($c['paid_txn_id']) {
                        fail('A paid shipment is cancelled through a refund (credit memo) in the accounting; ask the cargo supervisor.');
                    }
                    break;
            }
            DB::update('shp_cargo', $upd, 'id = :id', ['id' => $id]);
            DB::insert('shp_cargo_events', ['cargo_id' => $id, 'from_status' => $c['status'], 'to_status' => $to, 'details' => $note !== '' ? mb_substr($note, 0, 500) : null,
                'photo_path' => $ctx['photo_path'] ?? null, 'user_id' => Auth::id()]);
            Ship::audit('status', 'cgo', 'shp_cargo', $id, 'Waybill ' . $c['waybill_no'] . ': ' . $c['status'] . ' to ' . $to, ['reason' => $note, 'page_key' => 'cgo.shipments']);
            if (in_array($to, ['short_landed', 'damaged'], true)) {
                Notify::staff('cargo:' . $id . ':' . $to, 'cargo_exception', ['Cargo Clerk', 'Operations Manager', 'Customer Service'], 'Cargo exception: ' . $c['waybill_no'] . ' ' . str_replace('_', '-', $to), $note, 'shp_cargo', $id);
            }
            if ($to === 'delivered' && $c['payment_terms'] === 'credit') {
                self::invoice($id);
            }
        });
    }

    /** Credit shipment: one invoice after delivery (a second call returns the same invoice) */
    public static function invoice(int $id): int
    {
        $c = DB::row('SELECT * FROM shp_cargo WHERE id = :id', ['id' => $id]);
        if (!$c || $c['payment_terms'] !== 'credit') {
            fail('Only a credit shipment is invoiced.');
        }
        $vesselId = $c['voyage_id'] ? (int)DB::value('SELECT vessel_id FROM shp_voyages WHERE id = :v', ['v' => $c['voyage_id']]) : null;
        $class = ShipGL::vesselClass($vesselId);
        $memo = 'Freight, waybill ' . $c['waybill_no'] . ' delivered to ' . $c['consignee_name'];
        $txn = ShipGL::once('cargo:' . $id . ':invoice', ['source_type' => 'cargo', 'source_id' => $id, 'voyage_id' => $c['voyage_id'], 'vessel_id' => $vesselId, 'memo' => $memo],
            fn() => ShipGL::invoice((int)$c['shipper_customer_id'], Ship::today(), self::lines($c, $class, false), $memo, $class, $c['waybill_no']));
        DB::update('shp_cargo', ['invoice_txn_id' => $txn, 'revenue_done' => 1], 'id = :id', ['id' => $id]);
        return (int)$txn;
    }

    public static function events(int $id): array
    {
        return DB::all('SELECT e.*, u.full_name FROM shp_cargo_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.cargo_id = :c ORDER BY e.id', ['c' => $id]);
    }
}
