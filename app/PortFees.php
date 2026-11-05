<?php
declare(strict_types=1);

/**
 * Third-party charges collected with tickets (terminal fees, passenger insurance): each is its own line with its
 * owner. Collected as an agent, it sits in a payable account until remitted, so it is never booked as company
 * revenue. This class reconciles them by voyage (passengers, exempt, assessed, collected, refunded, remitted)
 * and remits what is outstanding with a check to the authority, clearing the payable.
 */
final class PortFees
{
    public static function byVoyage(string $from, string $to, ?int $feeId = null): array
    {
        $sql = "SELECT v.id AS voyage_id, v.voyage_no, v.etd, v.status AS voyage_status, s.id AS fee_id, s.code AS fee_code, s.name AS fee_name, s.ownership,
                       COUNT(f.id) AS lines_n,
                       SUM(CASE WHEN f.status IN ('collected', 'remitted') THEN f.amount ELSE 0 END) AS collected,
                       SUM(CASE WHEN f.status = 'refunded' THEN f.amount ELSE 0 END) AS refunded,
                       SUM(CASE WHEN f.status = 'remitted' THEN f.amount ELSE 0 END) AS remitted,
                       SUM(CASE WHEN f.status = 'collected' THEN f.amount ELSE 0 END) AS outstanding,
                       SUM(CASE WHEN f.status = 'collected' AND t.status = 'boarded' THEN 1 ELSE 0 END) AS boarded_paid
                  FROM shp_ticket_fees f JOIN shp_fees s ON s.id = f.fee_id
                  LEFT JOIN shp_tickets t ON t.id = f.ticket_id LEFT JOIN shp_vehicles x ON x.id = f.vehicle_id
                  JOIN shp_voyages v ON v.id = COALESCE(t.voyage_id, x.voyage_id)
                 WHERE v.voyage_date BETWEEN :f AND :t AND f.status <> 'held' AND f.status <> 'waived'";
        $p = ['f' => $from, 't' => $to];
        if ($feeId) {
            $sql .= ' AND f.fee_id = :fee';
            $p['fee'] = $feeId;
        }
        $rows = DB::all($sql . ' GROUP BY v.id, v.voyage_no, v.etd, v.status, s.id, s.code, s.name, s.ownership ORDER BY v.etd, s.code', $p);
        foreach ($rows as &$r) {
            $c = Boarding::counts((int)$r['voyage_id']);
            $r['pax_boarded'] = $c['boarded'];
            $r['exempt'] = (int)DB::value("SELECT COUNT(*) FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                                            WHERE t.voyage_id = :v AND t.status IN ('boarded', 'no_show', 'issued', 'checked_in')
                                              AND NOT EXISTS (SELECT 1 FROM shp_ticket_fees f WHERE f.ticket_id = t.id AND f.fee_id = :fee)", ['v' => $r['voyage_id'], 'fee' => $r['fee_id']]);
        }
        unset($r);
        return $rows;
    }

    /** Remits the outstanding collections of one fee on the voyages ticked: a check to the authority */
    public static function remit(int $feeId, array $voyageIds, int $bankAcct, string $date, string $refNo): int
    {
        if (!$voyageIds) {
            fail('Tick the voyages whose collections are remitted.');
        }
        if (trim($refNo) === '') {
            fail('Enter the reference of the remittance (statement / official receipt of the authority).');
        }
        return DB::tx(function () use ($feeId, $voyageIds, $bankAcct, $date, $refNo) {
            $fee = DB::row('SELECT * FROM shp_fees WHERE id = :id', ['id' => $feeId]);
            if (!$fee || $fee['ownership'] !== 'agent') {
                fail('Only a charge collected for a third party is remitted.');
            }
            if (!$fee['vendor_id']) {
                fail('Set the authority (supplier) the ' . $fee['name'] . ' is remitted to, under Shipping Set-up > Fees & Third-party Charges.');
            }
            [$in, $p] = DB::in('rv', array_map('intval', $voyageIds));
            $p['fee'] = $feeId;
            $rows = DB::all("SELECT f.id, f.amount, f.account_id FROM shp_ticket_fees f LEFT JOIN shp_tickets t ON t.id = f.ticket_id LEFT JOIN shp_vehicles x ON x.id = f.vehicle_id
                              WHERE f.fee_id = :fee AND f.status = 'collected' AND COALESCE(t.voyage_id, x.voyage_id) IN $in FOR UPDATE", $p);
            if (!$rows) {
                fail('There is nothing outstanding to remit on the voyages ticked.');
            }
            $byAcct = [];
            foreach ($rows as $r) {
                $byAcct[(int)$r['account_id']] = ($byAcct[(int)$r['account_id']] ?? 0) + (float)$r['amount'];
            }
            $lines = [];
            foreach ($byAcct as $acct => $amt) {
                $lines[] = ['account_id' => $acct, 'amount' => r2($amt), 'description' => $fee['name'] . ' collected on ' . count($voyageIds) . ' voyage(s), remitted'];
            }
            $total = r2(array_sum($byAcct));
            $memo = 'Remittance of ' . $fee['name'] . ' (' . count($rows) . ' collection(s))';
            $key = 'remit:' . $feeId . ':' . md5(implode(',', array_column($rows, 'id')));
            $txn = ShipGL::once($key, ['source_type' => 'fee_remit', 'source_id' => $feeId, 'memo' => $memo],
                fn() => ShipGL::check($bankAcct, (int)$fee['vendor_id'], null, $date, $refNo, $lines, $memo, null));
            [$in2, $p2] = DB::in('rf', array_column($rows, 'id'));
            DB::run("UPDATE shp_ticket_fees SET status = 'remitted', remit_txn_id = :t WHERE id IN $in2", $p2 + ['t' => $txn]);
            Ship::audit('remit', 'cgo', 'shp_fee', $feeId, $memo, ['amount' => $total, 'page_key' => 'cgo.port_fees']);
            return (int)$txn;
        });
    }
}
