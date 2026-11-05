<?php
declare(strict_types=1);

/**
 * Fare engine: the fare in force on a route and date (with its history), passenger privileges given by law
 * (Senior Citizen, PWD, Student, Medal of Valor), promos that never stack on a statutory privilege, and the
 * third-party charges (terminal fee, passenger insurance) kept as separate lines with their owner.
 *
 * Every quote is fully itemized so the passenger sees base fare, discount, surcharge, VAT and fees before paying.
 */
final class Fares
{
    public static function fare(int $routeId, string $accomCode, string $date): ?array
    {
        return DB::row('SELECT * FROM shp_fares WHERE route_id = :r AND accom_code = :a AND effective_from <= :d
                         AND (effective_to IS NULL OR effective_to >= :d2) ORDER BY effective_from DESC, id DESC LIMIT 1',
            ['r' => $routeId, 'a' => $accomCode, 'd' => $date, 'd2' => $date]);
    }

    public static function isPeak(string $date): bool
    {
        return (bool)DB::value('SELECT 1 FROM shp_peak_dates WHERE date_from <= :d AND date_to >= :d2 LIMIT 1', ['d' => $date, 'd2' => $date]);
    }

    public static function category(int $id): array
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $c = DB::row('SELECT * FROM shp_fare_categories WHERE id = :id', ['id' => $id]);
            if (!$c) {
                fail('Choose the passenger category of every passenger.');
            }
            $cache[$id] = $c;
        }
        return $cache[$id];
    }

    public static function categoryOptions(): array
    {
        return DB::pairs('SELECT id, name FROM shp_fare_categories WHERE is_active = 1 ORDER BY sort_order, name');
    }

    public static function regularCategory(): int
    {
        return (int)(DB::value("SELECT id FROM shp_fare_categories WHERE code = 'REG'") ?: DB::value('SELECT id FROM shp_fare_categories ORDER BY sort_order LIMIT 1'));
    }

    public static function promo(?string $code, int $routeId, string $date): ?array
    {
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            return null;
        }
        $p = DB::row('SELECT * FROM shp_promos WHERE code = :c AND is_active = 1', ['c' => $code]);
        if (!$p) {
            fail('Promo code ' . $code . ' is not valid.');
        }
        if (($p['valid_from'] && $date < $p['valid_from']) || ($p['valid_to'] && $date > $p['valid_to'])) {
            fail('Promo code ' . $code . ' is not valid for a voyage on ' . fdate($date) . '.');
        }
        if ($p['route_id'] && (int)$p['route_id'] !== $routeId) {
            fail('Promo code ' . $code . ' is not valid on this route.');
        }
        if ($p['max_uses'] !== null && (int)$p['used_count'] >= (int)$p['max_uses']) {
            fail('Promo code ' . $code . ' has been used up.');
        }
        return $p;
    }

    /** Fees charged to one passenger of this category from the origin port */
    public static function paxFees(array $category, int $originPortId): array
    {
        $out = [];
        if ((int)$category['fee_exempt'] === 1) {
            return $out;
        }
        foreach (DB::all("SELECT * FROM shp_fees WHERE is_active = 1 AND applies_to = 'passenger' AND (port_id IS NULL OR port_id = :p) ORDER BY sort_order, id", ['p' => $originPortId]) as $f) {
            $exempt = array_filter(array_map('trim', explode(',', strtoupper((string)$f['exempt_categories']))));
            if (in_array(strtoupper($category['code']), $exempt, true) || (float)$f['amount'] <= 0) {
                continue;
            }
            $out[] = self::feeRow($f);
        }
        return $out;
    }

    public static function vehicleFees(int $originPortId): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM shp_fees WHERE is_active = 1 AND applies_to = 'vehicle' AND (port_id IS NULL OR port_id = :p) ORDER BY sort_order, id", ['p' => $originPortId]) as $f) {
            if ((float)$f['amount'] > 0) {
                $out[] = self::feeRow($f);
            }
        }
        return $out;
    }

    private static function feeRow(array $f): array
    {
        $acct = $f['ownership'] === 'principal'
            ? (int)($f['revenue_account_id'] ?: Accts::get('shp_change_fees'))
            : (int)($f['liability_account_id'] ?: Accts::get($f['fee_type'] === 'insurance' ? 'shp_insurance_payable' : 'shp_terminal_payable'));
        return ['fee_id' => (int)$f['id'], 'code' => $f['code'], 'name' => $f['name'], 'amount' => r2($f['amount']), 'ownership' => $f['ownership'], 'account_id' => $acct];
    }

    /**
     * The itemized price of one passenger.
     * A statutory privilege (VAT-exempt ones take the fare net of VAT first, then the discount) is never
     * combined with a promo: when both are asked, the privilege applies and the promo is dropped with a note.
     */
    public static function quotePax(array $voyage, string $accomCode, array $category, ?array $promo = null, ?float $overrideGross = null): array
    {
        $date = substr((string)$voyage['etd'], 0, 10);
        $fare = self::fare((int)$voyage['route_id'], $accomCode, $date);
        if (!$fare) {
            fail('There is no fare for ' . $accomCode . ' on route ' . ($voyage['route_code'] ?? '') . ' for ' . fdate($date) . '. Set it under Shipping Set-up > Fare Matrix & Rates.');
        }
        $gross = $overrideGross ?? (float)(self::isPeak($date) && $fare['peak_fare'] !== null ? $fare['peak_fare'] : $fare['base_fare']);
        $surchargeGross = (float)$fare['fuel_surcharge'];
        $exempt = (int)$category['vat_exempt'] === 1;
        $pct = (float)$category['discount_pct'];
        $notes = [];
        $promoPct = 0.0;
        if ($promo) {
            if ((int)$category['is_statutory'] === 1) {
                $notes[] = 'Promo ' . $promo['code'] . ' not applied: a statutory privilege cannot be combined with a promo.';
            } else {
                $promoPct = (float)$promo['discount_pct'];
            }
        }
        if ($exempt) {
            // e.g. senior citizen / PWD: the VAT is taken out, then the discount is computed on the amount net of VAT
            [$base] = ShipGL::splitVat($gross);
            $discount = r2($base * $pct / 100);
            $fareNet = r2($base - $discount);
            $vat = 0.0;
            [$sNet] = ShipGL::splitVat($surchargeGross);
            $sVat = 0.0;
            $taxCode = ShipGL::taxCode('EX');
        } else {
            $discount = r2($gross * $pct / 100);
            $promoAmt = r2(($gross - $discount) * $promoPct / 100);
            [$fareNet, $vat] = ShipGL::splitVat($gross - $discount - $promoAmt);
            [$sNet, $sVat] = ShipGL::splitVat($surchargeGross);
            $taxCode = ShipGL::taxCode('VAT12');
        }
        $promoAmt ??= 0.0;
        $fees = self::paxFees($category, (int)$voyage['origin_port_id']);
        $feesTotal = r2(array_sum(array_column($fees, 'amount')));
        $total = r2($fareNet + $vat + $sNet + $sVat + $feesTotal);
        return [
            'fare_id' => (int)$fare['id'], 'fare_gross' => r2($gross), 'discount' => $discount, 'promo' => $promoAmt,
            'fare_net' => $fareNet, 'vat' => r2($vat + $sVat), 'fare_vat' => r2($vat), 'surcharge_gross' => r2($surchargeGross),
            'surcharge_net' => $sNet, 'surcharge_vat' => r2($sVat), 'fees' => $fees, 'fees_total' => $feesTotal, 'total' => $total,
            'tax_code_id' => $taxCode, 'vat_exempt' => $exempt, 'notes' => $notes,
        ];
    }

    public static function vehicleRate(int $routeId, int $classId, string $date): ?array
    {
        return DB::row('SELECT * FROM shp_vehicle_rates WHERE route_id = :r AND vehicle_class_id = :c AND effective_from <= :d
                         AND (effective_to IS NULL OR effective_to >= :d2) ORDER BY effective_from DESC, id DESC LIMIT 1',
            ['r' => $routeId, 'c' => $classId, 'd' => $date, 'd2' => $date]);
    }

    public static function quoteVehicle(array $voyage, int $vehicleClassId): array
    {
        $date = substr((string)$voyage['etd'], 0, 10);
        $rate = self::vehicleRate((int)$voyage['route_id'], $vehicleClassId, $date);
        if (!$rate) {
            $cls = (string)DB::value('SELECT name FROM shp_vehicle_classes WHERE id = :id', ['id' => $vehicleClassId]);
            fail('There is no vehicle rate for ' . $cls . ' on this route for ' . fdate($date) . '. Set it under Shipping Set-up > Fare Matrix & Rates.');
        }
        [$net, $vat] = ShipGL::splitVat((float)$rate['rate']);
        $fees = self::vehicleFees((int)$voyage['origin_port_id']);
        $feesTotal = r2(array_sum(array_column($fees, 'amount')));
        return ['rate_id' => (int)$rate['id'], 'fare_gross' => r2($rate['rate']), 'fare_net' => $net, 'vat' => $vat, 'fees' => $fees,
            'fees_total' => $feesTotal, 'total' => r2($net + $vat + $feesTotal), 'tax_code_id' => ShipGL::taxCode('VAT12'), 'includes_driver' => (int)$rate['includes_driver']];
    }

    public static function cargoTariff(int $routeId, string $commodity, string $date): ?array
    {
        return DB::row('SELECT * FROM shp_cargo_tariffs WHERE (route_id = :r OR route_id IS NULL) AND commodity_class = :c AND effective_from <= :d
                         AND (effective_to IS NULL OR effective_to >= :d2) ORDER BY route_id IS NULL, effective_from DESC, id DESC LIMIT 1',
            ['r' => $routeId, 'c' => $commodity, 'd' => $date, 'd2' => $date]);
    }

    /** Freight on the chargeable basis: the higher of weight and volume, never below the minimum */
    public static function quoteCargo(int $routeId, string $commodity, float $kg, float $cbm, float $declared, string $date, float $discountPct = 0): array
    {
        $t = self::cargoTariff($routeId, $commodity, $date);
        if (!$t) {
            fail('There is no cargo tariff for "' . $commodity . '" cargo on this route for ' . fdate($date) . '. Set it under Shipping Set-up > Fare Matrix & Rates.');
        }
        $byKg = r2($kg * (float)$t['rate_per_kg']);
        $byCbm = r2($cbm * (float)$t['rate_per_cbm']);
        $freight = max($byKg, $byCbm, (float)$t['min_charge']);
        $basis = $freight === (float)$t['min_charge'] && $freight > max($byKg, $byCbm) ? 'min' : ($byCbm > $byKg ? 'cbm' : 'kg');
        $discount = r2($freight * $discountPct / 100);
        $handling = r2((float)$t['handling_fee']);
        $insurance = r2($declared * (float)$t['insurance_pct'] / 100);
        [$fNet, $fVat] = ShipGL::splitVat($freight - $discount);
        [$hNet, $hVat] = ShipGL::splitVat($handling);
        $total = r2($fNet + $fVat + $hNet + $hVat + $insurance);
        return ['tariff_id' => (int)$t['id'], 'basis' => $basis, 'freight_gross' => r2($freight), 'discount' => $discount, 'handling' => $handling,
            'insurance' => $insurance, 'freight_net' => $fNet, 'freight_vat' => $fVat, 'handling_net' => $hNet, 'handling_vat' => $hVat,
            'net' => r2($fNet + $hNet), 'vat' => r2($fVat + $hVat), 'total' => $total, 'by_kg' => $byKg, 'by_cbm' => $byCbm, 'min' => (float)$t['min_charge']];
    }
}
