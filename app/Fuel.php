<?php
declare(strict_types=1);

/**
 * Fuel on board: the vessel's tanks are its inventory site, so the remaining on board (ROB) is the quantity on
 * hand of the fuel item there, at moving weighted-average cost.
 *
 *  bunker()   a bunkering delivery: a Bill to the fuel supplier that receives the liters into the vessel's site
 *  issue()    the fuel burnt on a voyage (ROB at departure less ROB at arrival): an inventory adjustment to
 *             Voyage Fuel Expense with the vessel's class, linked to the voyage. Variance against the planned
 *             consumption (sailing hours x liters per hour) above the threshold is flagged.
 *  sounding() a tank sounding that differs from the book ROB becomes an adjustment that someone else approves;
 *             the stock can never go below zero.
 */
final class Fuel
{
    public static function rob(int $vesselId): float
    {
        $v = DB::row('SELECT fuel_item_id, site_id FROM shp_vessels WHERE id = :id', ['id' => $vesselId]);
        if (!$v || !$v['fuel_item_id'] || !$v['site_id']) {
            return 0.0;
        }
        return (float)DB::value('SELECT COALESCE(qty_on_hand, 0) FROM item_site_stock WHERE item_id = :i AND site_id = :s', ['i' => $v['fuel_item_id'], 's' => $v['site_id']]);
    }

    private static function vesselFuel(int $vesselId): array
    {
        $v = DB::row('SELECT * FROM shp_vessels WHERE id = :id', ['id' => $vesselId]);
        if (!$v) {
            fail('Choose the vessel.');
        }
        if (!$v['fuel_item_id'] || !$v['site_id']) {
            fail($v['name'] . ' has no fuel item or stores site set (Fleet & Maintenance > Vessel Registry).');
        }
        return $v;
    }

    public static function bunker(int $vesselId, float $liters, float $unitCost, int $vendorId, string $refNo, string $date, string $notes = ''): int
    {
        if ($liters <= 0 || $unitCost <= 0) {
            fail('Enter the liters delivered and the price per liter.');
        }
        if (trim($refNo) === '') {
            fail('Enter the supplier\'s delivery receipt / invoice number.');
        }
        return DB::tx(function () use ($vesselId, $liters, $unitCost, $vendorId, $refNo, $date, $notes) {
            $v = self::vesselFuel($vesselId);
            if ((float)$v['fuel_tank_l'] > 0 && self::rob($vesselId) + $liters > (float)$v['fuel_tank_l'] * 1.001) {
                fail('The tanks of ' . $v['name'] . ' hold ' . qty($v['fuel_tank_l']) . ' L; with ' . qty(self::rob($vesselId)) . ' L on board, ' . qty($liters) . ' L does not fit.');
            }
            $class = ShipGL::vesselClass($vesselId);
            [$net, $vat] = [r2($liters * $unitCost), 0.0];
            $taxId = ShipGL::taxCode('VAT12');
            $vat = r2($net * ShipGL::vatRate() / 100);
            $memo = 'Bunkering ' . qty($liters) . ' L for ' . $v['name'];
            $txn = ShipGL::bill($vendorId, $date, $refNo, [[
                'item_id' => (int)$v['fuel_item_id'], 'description' => $memo, 'qty' => $liters, 'rate' => $unitCost, 'tax_code_id' => $taxId, 'tax_amount' => $vat,
                'site_id' => (int)$v['site_id'], 'class_id' => $class,
            ]], $memo, $class, (int)$v['site_id']);
            DB::insert('shp_postings', ['event_key' => 'bunker:' . $txn, 'source_type' => 'fuel', 'source_id' => $vesselId, 'vessel_id' => $vesselId, 'txn_id' => $txn,
                'amount' => r2($net + $vat), 'memo' => $memo, 'created_by' => Auth::id()]);
            DB::insert('shp_fuel_logs', ['vessel_id' => $vesselId, 'log_type' => 'bunkering', 'log_at' => $date . ' ' . date('H:i:s'), 'item_id' => $v['fuel_item_id'],
                'liters' => $liters, 'rob_book' => self::rob($vesselId), 'unit_cost' => $unitCost, 'amount' => $net, 'vendor_id' => $vendorId, 'ref_no' => $refNo,
                'txn_id' => $txn, 'notes' => $notes ?: null, 'created_by' => Auth::id()]);
            Ship::audit('bunkering', 'ops', 'shp_fuel', $vesselId, $memo, ['amount' => $net + $vat, 'page_key' => 'ops.fuel']);
            return $txn;
        });
    }

    /** Fuel burnt on a voyage, from the soundings at departure and arrival */
    public static function issue(int $voyageId, float $robDeparture, float $robArrival, string $notes = ''): array
    {
        return DB::tx(function () use ($voyageId, $robDeparture, $robArrival, $notes) {
            $voy = Ship::voyage($voyageId, true);
            if (!in_array($voy['status'], ['departed', 'arrived', 'completed'], true)) {
                fail('Fuel used is recorded once the voyage has departed.');
            }
            if (DB::value("SELECT 1 FROM shp_fuel_logs WHERE voyage_id = :v AND log_type = 'issue' AND status = 'posted'", ['v' => $voyageId])) {
                fail('The fuel used on ' . $voy['voyage_no'] . ' is already recorded.');
            }
            $used = r2($robDeparture - $robArrival);
            if ($used <= 0) {
                fail('The ROB at arrival has to be lower than at departure.');
            }
            $v = self::vesselFuel((int)$voy['vessel_id']);
            $class = ShipGL::vesselClass((int)$v['id']);
            $memo = 'Fuel used on voyage ' . $voy['voyage_no'] . ' (' . qty($used) . ' L)';
            $result = [];
            $txn = ShipGL::once('voyage:' . $voyageId . ':fuel', ['source_type' => 'fuel', 'source_id' => $voyageId, 'voyage_id' => $voyageId, 'vessel_id' => (int)$v['id'], 'memo' => $memo],
                function () use ($v, $used, $class, $memo, $voy, &$result) {
                    $result = ShipGL::issueStock(substr($voy['ata'] ?: $voy['atd'] ?: Ship::now(), 0, 10), (int)$v['site_id'], (int)$v['fuel_item_id'], $used,
                        Accts::get('shp_fuel_expense'), $class, $memo, $voy['voyage_no']);
                    return $result[0];
                });
            $cost = $result[1] ?? 0.0;
            $planned = $voy['sailing_hours'] && (float)$v['fuel_rate_lph'] > 0 ? r2((float)$voy['sailing_hours'] * (float)$v['fuel_rate_lph']) : null;
            $variance = $planned !== null ? r2($used - $planned) : null;
            DB::insert('shp_fuel_logs', ['vessel_id' => $v['id'], 'voyage_id' => $voyageId, 'log_type' => 'issue', 'log_at' => $voy['ata'] ?: Ship::now(),
                'item_id' => $v['fuel_item_id'], 'liters' => -$used, 'rob_book' => self::rob((int)$v['id']), 'rob_sounding' => $robArrival, 'variance_l' => $variance,
                'unit_cost' => $used > 0 ? round($cost / $used, 4) : 0, 'amount' => $cost, 'txn_id' => $txn, 'notes' => $notes ?: null, 'created_by' => Auth::id()]);
            DB::update('shp_voyages', ['fuel_rob_departure' => $robDeparture, 'fuel_rob_arrival' => $robArrival, 'fuel_planned_l' => $planned], 'id = :id', ['id' => $voyageId]);
            $threshold = (float)Ship::setting('fuel_variance_pct', '2');
            if ($planned && abs($variance) > $planned * $threshold / 100) {
                Notify::staff('voyage:' . $voyageId . ':fuel_variance', 'fuel_variance', ['Fleet Manager', 'Chief Engineer', 'Operations Manager'], 'Fuel variance on ' . $voy['voyage_no'],
                    'Used ' . qty($used) . ' L against ' . qty($planned) . ' L planned (' . ($variance > 0 ? '+' : '') . qty($variance) . ' L).', 'shp_voyages', $voyageId);
            }
            return ['txn_id' => $txn, 'liters' => $used, 'cost' => $cost, 'planned' => $planned, 'variance' => $variance];
        });
    }

    /** A tank sounding: a difference from the book ROB waits for approval before the stock is adjusted */
    public static function sounding(int $vesselId, float $measured, string $at, string $notes = ''): int
    {
        $v = self::vesselFuel($vesselId);
        $book = self::rob($vesselId);
        $diff = r2($measured - $book);
        $id = DB::insert('shp_fuel_logs', ['vessel_id' => $vesselId, 'log_type' => abs($diff) >= 0.5 ? 'adjustment' : 'sounding', 'log_at' => $at, 'item_id' => $v['fuel_item_id'],
            'liters' => $diff, 'rob_book' => $book, 'rob_sounding' => $measured, 'variance_l' => $diff, 'status' => abs($diff) >= 0.5 ? 'pending' : 'posted',
            'notes' => $notes ?: null, 'created_by' => Auth::id()]);
        if (abs($diff) >= 0.5) {
            ShipApproval::request('fuel_adjustment', 'shp_fuel_logs', $id, $v['code'] . ' sounding', abs($diff) * (float)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $v['fuel_item_id']]),
                'Sounding of ' . $v['name'] . ': ' . qty($measured) . ' L measured, ' . qty($book) . ' L in the books (' . ($diff > 0 ? '+' : '') . qty($diff) . ' L). ' . $notes);
        }
        return $id;
    }

    public static function adjustmentDecision(int $logId, bool $approve, string $notes): void
    {
        $l = DB::row('SELECT * FROM shp_fuel_logs WHERE id = :id FOR UPDATE', ['id' => $logId]);
        if (!$l || $l['status'] !== 'pending') {
            fail('The fuel adjustment is no longer waiting for approval.');
        }
        if (!$approve) {
            DB::update('shp_fuel_logs', ['status' => 'rejected', 'notes' => trim(($l['notes'] ?? '') . ' Rejected: ' . $notes)], 'id = :id', ['id' => $logId]);
            return;
        }
        $v = self::vesselFuel((int)$l['vessel_id']);
        $class = ShipGL::vesselClass((int)$v['id']);
        // the stock moves by the difference measured against the books of today
        $diff = r2((float)$l['rob_sounding'] - self::rob((int)$v['id']));
        $memo = 'Fuel sounding adjustment ' . $v['name'] . ' (' . ($diff > 0 ? '+' : '') . qty($diff) . ' L)';
        $txn = abs($diff) < 0.01 ? null : ShipGL::once('fuel_adj:' . $logId, ['source_type' => 'fuel', 'source_id' => $logId, 'vessel_id' => (int)$v['id'], 'memo' => $memo],
            fn() => ShipGL::adjustStock(substr($l['log_at'], 0, 10), (int)$v['site_id'], (int)$v['fuel_item_id'], $diff, Accts::get('shp_fuel_variance'), $class, $memo));
        DB::update('shp_fuel_logs', ['status' => 'posted', 'txn_id' => $txn, 'liters' => $diff], 'id = :id', ['id' => $logId]);
    }
}
