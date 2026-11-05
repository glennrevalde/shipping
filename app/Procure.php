<?php
declare(strict_types=1);

/**
 * Purchase requisitions from maintenance and operations, approval by another person, comparative canvass of
 * supplier quotations, and the purchase order itself, made as an accounting Purchase Order so that receiving
 * (Receive Items), the supplier invoice (Enter Bill for Received Items: PO, receipt and invoice matched) and
 * payment (Pay Bills) follow the usual accounting flow.
 *
 * An emergency purchase for a vessel (with the emergency-purchase permission) is ordered first and reviewed
 * after the fact; it is never hidden from the approval and audit records.
 */
final class Procure
{
    public static function save(array $h, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($h, $lines, $id) {
            $old = $id ? DB::row('SELECT * FROM shp_requisitions WHERE id = :id FOR UPDATE', ['id' => $id]) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('A requisition can be changed only while it is a draft or after it was rejected.');
            }
            if ($old && (int)$old['requested_by'] !== (int)Auth::id() && !Access::can('fleet.requisitions', 'edit')) {
                fail('Only the requester can change this requisition.');
            }
            $clean = [];
            $total = 0.0;
            foreach ($lines as $l) {
                $desc = trim((string)($l['description'] ?? ''));
                $item = !empty($l['item_id']) ? DB::row('SELECT id, name, unit, cost FROM items WHERE id = :i', ['i' => (int)$l['item_id']]) : null;
                if ($desc === '' && !$item) {
                    continue;
                }
                $qty = (float)($l['qty'] ?? 0);
                if ($qty <= 0) {
                    fail('Every line needs a quantity.');
                }
                $cost = (float)($l['est_unit_cost'] ?? 0) ?: (float)($item['cost'] ?? 0);
                $clean[] = ['item_id' => $item['id'] ?? null, 'description' => mb_substr($desc ?: $item['name'], 0, 160), 'part_no' => $l['part_no'] ?? null,
                    'qty' => $qty, 'unit' => ($l['unit'] ?? '') ?: ($item['unit'] ?? 'pc'), 'est_unit_cost' => $cost];
                $total += $qty * $cost;
            }
            if (!$clean) {
                fail('Add at least one line.');
            }
            $row = [
                'department' => $h['department'] ?? null, 'vessel_id' => !empty($h['vessel_id']) ? (int)$h['vessel_id'] : null,
                'wo_id' => !empty($h['wo_id']) ? (int)$h['wo_id'] : null, 'site_id' => !empty($h['site_id']) ? (int)$h['site_id'] : null,
                'needed_by' => !empty($h['needed_by']) && valid_date($h['needed_by']) ? $h['needed_by'] : null,
                'priority' => in_array($h['priority'] ?? '', ['normal', 'urgent', 'emergency'], true) ? $h['priority'] : 'normal',
                'justification' => $h['justification'] ?? null, 'est_total' => r2($total), 'budget_note' => self::budgetNote($h, $total), 'updated_at' => Ship::now(),
            ];
            if ($old) {
                $row['status'] = 'draft';
                DB::update('shp_requisitions', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM shp_requisition_lines WHERE pr_id = :p', ['p' => $id]);
            } else {
                $row += ['pr_no' => Ship::no('shp_pr', 'PR-', 6), 'requested_by' => (int)Auth::id(), 'status' => 'draft'];
                $id = DB::insert('shp_requisitions', $row);
            }
            $n = 0;
            foreach ($clean as $l) {
                DB::insert('shp_requisition_lines', $l + ['pr_id' => $id, 'line_no' => ++$n]);
            }
            return $id;
        });
    }

    /** Budget check: the vessel's budget for repairs this year against what is already spent and committed */
    private static function budgetNote(array $h, float $total): ?string
    {
        if (empty($h['vessel_id'])) {
            return null;
        }
        $class = ShipGL::vesselClass((int)$h['vessel_id']);
        if (!$class) {
            return null;
        }
        $acct = Accts::get('shp_repairs_expense');
        $year = substr(Ship::today(), 0, 4);
        $budget = DB::value("SELECT SUM(bl.amount) FROM budget_lines bl JOIN budgets b ON b.id = bl.budget_id
                              WHERE bl.account_id = :a AND b.fiscal_year = :y AND b.kind = 'pl' AND b.is_active = 1 AND b.class_id = :c", ['a' => $acct, 'y' => $year, 'c' => $class]);
        if (!$budget) {
            return 'No repairs budget set for this vessel this year.';
        }
        $spent = (float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                                    WHERE j.account_id = :a AND j.class_id = :c AND YEAR(t.txn_date) = :y', ['a' => $acct, 'c' => $class, 'y' => $year]);
        $left = (float)$budget - $spent;
        return 'Repairs budget ' . money($budget) . ', spent ' . money($spent) . ', left ' . money($left) . ($total > $left ? ' (this request is above what is left)' : '') . '.';
    }

    public static function submit(int $id): void
    {
        DB::tx(function () use ($id) {
            $r = DB::row('SELECT * FROM shp_requisitions WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$r || !in_array($r['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft requisition is submitted.');
            }
            if ($r['priority'] === 'emergency') {
                if (!Access::special('shp_emergency_purchase')) {
                    fail('An emergency purchase needs the emergency-purchase permission. Submit it as urgent instead.');
                }
                // ordered at once; reviewed after the fact by another person
                DB::update('shp_requisitions', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => Ship::now()], 'id = :id', ['id' => $id]);
                ShipApproval::request('requisition', 'shp_requisitions', $id, $r['pr_no'], (float)$r['est_total'], 'After-the-fact review of EMERGENCY purchase ' . $r['pr_no'] . ': ' . ($r['justification'] ?? ''));
                Ship::audit('emergency_purchase', 'fleet', 'shp_pr', $id, $r['pr_no'], ['amount' => $r['est_total'], 'reason' => (string)$r['justification'], 'page_key' => 'fleet.requisitions']);
                return;
            }
            DB::update('shp_requisitions', ['status' => 'submitted'], 'id = :id', ['id' => $id]);
            ShipApproval::request('requisition', 'shp_requisitions', $id, $r['pr_no'], (float)$r['est_total'], 'Requisition ' . $r['pr_no'] . ' (' . money($r['est_total']) . '): ' . ($r['justification'] ?? ''));
        });
    }

    public static function decision(int $id, bool $approve, string $notes): void
    {
        $r = DB::row('SELECT * FROM shp_requisitions WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($r['priority'] === 'emergency' && in_array($r['status'], ['approved', 'canvass', 'ordered', 'closed'], true)) {
            DB::update('shp_requisitions', ['emergency_review_by' => Auth::id(), 'emergency_review_at' => Ship::now()], 'id = :id', ['id' => $id]);
            return;
        }
        if ($r['status'] !== 'submitted') {
            fail('The requisition is no longer waiting for approval.');
        }
        DB::update('shp_requisitions', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $approve ? Auth::id() : null, 'approved_at' => $approve ? Ship::now() : null], 'id = :id', ['id' => $id]);
    }

    public static function addQuote(int $prId, int $vendorId, array $prices, ?string $ref, ?string $date, ?int $days, ?string $terms): int
    {
        return DB::tx(function () use ($prId, $vendorId, $prices, $ref, $date, $days, $terms) {
            $r = DB::row('SELECT * FROM shp_requisitions WHERE id = :id FOR UPDATE', ['id' => $prId]);
            if (!$r || !in_array($r['status'], ['approved', 'canvass'], true)) {
                fail('Quotations are entered on an approved requisition.');
            }
            if (DB::value('SELECT 1 FROM shp_quotes WHERE pr_id = :p AND vendor_id = :v', ['p' => $prId, 'v' => $vendorId])) {
                fail('This supplier already has a quotation on the requisition.');
            }
            $qid = DB::insert('shp_quotes', ['pr_id' => $prId, 'vendor_id' => $vendorId, 'quote_ref' => $ref, 'quote_date' => $date ?: Ship::today(), 'delivery_days' => $days, 'terms_note' => $terms]);
            $total = 0.0;
            foreach (DB::all('SELECT * FROM shp_requisition_lines WHERE pr_id = :p', ['p' => $prId]) as $l) {
                $price = (float)($prices[$l['id']] ?? 0);
                if ($price <= 0) {
                    fail('Enter the supplier\'s price for every line (' . $l['description'] . ').');
                }
                $amt = r2($price * (float)$l['qty']);
                DB::insert('shp_quote_lines', ['quote_id' => $qid, 'pr_line_id' => $l['id'], 'unit_price' => $price, 'amount' => $amt]);
                $total += $amt;
            }
            DB::update('shp_quotes', ['total' => r2($total)], 'id = :id', ['id' => $qid]);
            DB::update('shp_requisitions', ['status' => 'canvass'], 'id = :id', ['id' => $prId]);
            return $qid;
        });
    }

    /** Picks the winning quotation and makes the accounting purchase order (once) */
    public static function order(int $prId, int $quoteId, string $reason = ''): int
    {
        return DB::tx(function () use ($prId, $quoteId, $reason) {
            $r = DB::row('SELECT * FROM shp_requisitions WHERE id = :id FOR UPDATE', ['id' => $prId]);
            if (!$r || !in_array($r['status'], ['approved', 'canvass'], true)) {
                fail('The purchase order is made from an approved requisition.');
            }
            $q = DB::row('SELECT * FROM shp_quotes WHERE id = :id AND pr_id = :p', ['id' => $quoteId, 'p' => $prId]);
            if (!$q) {
                fail('Choose the quotation to order from.');
            }
            $quotes = (int)DB::value('SELECT COUNT(*) FROM shp_quotes WHERE pr_id = :p', ['p' => $prId]);
            $cheapest = (float)DB::value('SELECT MIN(total) FROM shp_quotes WHERE pr_id = :p', ['p' => $prId]);
            if ($r['priority'] !== 'emergency' && $quotes < 2 && trim($reason) === '') {
                fail('Canvass at least two suppliers, or give the reason for a single quotation (sole supplier, maker\'s part).');
            }
            if ((float)$q['total'] > $cheapest + 0.005 && trim($reason) === '') {
                fail('This is not the lowest quotation: give the reason for choosing it (delivery time, quality, warranty).');
            }
            $class = ShipGL::vesselClass($r['vessel_id'] ? (int)$r['vessel_id'] : null);
            $vat = ShipGL::taxCode('VAT12');
            $lines = [];
            foreach (DB::all('SELECT l.*, ql.unit_price FROM shp_requisition_lines l JOIN shp_quote_lines ql ON ql.pr_line_id = l.id AND ql.quote_id = :q WHERE l.pr_id = :p ORDER BY l.line_no', ['q' => $quoteId, 'p' => $prId]) as $l) {
                $amt = r2((float)$l['qty'] * (float)$l['unit_price']);
                $line = ['description' => $l['description'] . ($l['part_no'] ? ' (P/N ' . $l['part_no'] . ')' : ''), 'qty' => (float)$l['qty'], 'rate' => (float)$l['unit_price'],
                    'uom_qty' => (float)$l['qty'], 'uom_rate' => (float)$l['unit_price'], 'amount' => $amt, 'tax_code_id' => $vat, 'tax_amount' => r2($amt * ShipGL::vatRate() / 100),
                    'class_id' => $class, 'site_id' => $r['site_id']];
                if ($l['item_id']) {
                    $line += ['line_kind' => 'item', 'item_id' => (int)$l['item_id']];
                } else {
                    $line += ['line_kind' => 'expense', 'account_id' => Accts::get('shp_repairs_expense')];
                }
                $lines[] = $line;
            }
            $memo = 'From requisition ' . $r['pr_no'] . ($r['wo_id'] ? ' (work order ' . DB::value('SELECT wo_no FROM shp_work_orders WHERE id = :w', ['w' => $r['wo_id']]) . ')' : '');
            $po = ShipGL::once('pr:' . $prId . ':po', ['source_type' => 'requisition', 'source_id' => $prId, 'vessel_id' => $r['vessel_id'], 'work_order_id' => $r['wo_id'], 'memo' => $memo],
                fn() => Txn::save('purchase_order', ['txn_date' => Ship::today(), 'vendor_id' => (int)$q['vendor_id'], 'site_id' => $r['site_id'],
                    'due_date' => $r['needed_by'] ?: date('Y-m-d', strtotime(Ship::today() . ' +' . max(1, (int)$q['delivery_days']) . ' days')), 'memo' => $memo, 'class_id' => $class, 'ref_no' => $r['pr_no']],
                    $lines, null, ['reason' => $memo . ($reason ? ' - ' . $reason : '')]));
            DB::run('UPDATE shp_quotes SET is_selected = (id = :q) WHERE pr_id = :p', ['q' => $quoteId, 'p' => $prId]);
            DB::update('shp_requisitions', ['status' => 'ordered', 'selected_quote_id' => $quoteId, 'po_txn_id' => $po], 'id = :id', ['id' => $prId]);
            Ship::audit('purchase_order', 'fleet', 'shp_pr', $prId, $r['pr_no'] . ' ordered', ['amount' => $q['total'], 'reason' => $reason, 'page_key' => 'fleet.requisitions']);
            if ($r['wo_id']) {
                DB::run("UPDATE shp_work_orders SET status = 'waiting_parts' WHERE id = :w AND status IN ('approved', 'planned')", ['w' => $r['wo_id']]);
            }
            return (int)$po;
        });
    }
}
