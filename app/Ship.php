<?php
declare(strict_types=1);

/**
 * Shared helpers of the shipping operations: status names, plain-text status labels, document numbers,
 * look-ups of voyages and vessels, privacy masking, QR signatures and the voyage event log.
 *
 * Every shipping record that changes money goes through ShipGL, which records the accounting document
 * with the same posting engine (Txn::save) the accounting screens use.
 */
final class Ship
{
    public const VOYAGE_STATUS = [
        'draft' => 'Draft', 'published' => 'Published', 'on_sale' => 'On sale', 'boarding' => 'Boarding',
        'ready' => 'Ready to sail', 'departed' => 'Departed', 'arrived' => 'Arrived', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    /** Allowed voyage status changes: from => [to, ...] */
    public const VOYAGE_FLOW = [
        'draft'     => ['published', 'cancelled'],
        'published' => ['on_sale', 'cancelled', 'draft'],
        'on_sale'   => ['boarding', 'cancelled'],
        'boarding'  => ['ready', 'on_sale', 'cancelled'],
        'ready'     => ['departed', 'boarding', 'cancelled'],
        'departed'  => ['arrived'],
        'arrived'   => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public const TICKET_STATUS = [
        'held' => 'Held (unpaid)', 'issued' => 'Issued', 'checked_in' => 'Checked in', 'boarded' => 'Boarded', 'no_show' => 'No-show',
        'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'transferred' => 'Transferred', 'expired' => 'Expired hold',
    ];

    /** Ticket statuses that hold a place on the voyage */
    public const LIVE_TICKET = ['held', 'issued', 'checked_in', 'boarded'];
    public const PAID_TICKET = ['issued', 'checked_in', 'boarded', 'no_show'];

    public const CARGO_STATUS = [
        'quotation' => 'Quotation', 'booked' => 'Booked', 'accepted' => 'Accepted at port', 'loaded' => 'Loaded', 'in_transit' => 'In transit',
        'landed' => 'Landed', 'delivered' => 'Delivered', 'short_landed' => 'Short-landed', 'damaged' => 'Damaged', 'claimed' => 'Claim filed',
        'closed' => 'Closed', 'cancelled' => 'Cancelled', 'rolled_over' => 'Rolled over',
    ];

    public const CARGO_FLOW = [
        'quotation'    => ['booked', 'cancelled'],
        'booked'       => ['accepted', 'cancelled', 'rolled_over'],
        'accepted'     => ['loaded', 'rolled_over', 'cancelled'],
        'rolled_over'  => ['accepted', 'cancelled'],
        'loaded'       => ['in_transit'],
        'in_transit'   => ['landed', 'short_landed'],
        'landed'       => ['delivered', 'damaged', 'short_landed'],
        'short_landed' => ['delivered', 'claimed', 'closed'],
        'damaged'      => ['delivered', 'claimed', 'closed'],
        'delivered'    => ['claimed', 'closed'],
        'claimed'      => ['closed'],
        'closed'       => [],
        'cancelled'    => [],
    ];

    public const WO_STATUS = [
        'reported' => 'Reported', 'triaged' => 'Triaged', 'approved' => 'Approved', 'planned' => 'Planned', 'waiting_parts' => 'Waiting parts',
        'in_progress' => 'In progress', 'inspection' => 'For inspection', 'completed' => 'Completed', 'closed' => 'Closed',
        'deferred' => 'Deferred', 'cancelled' => 'Cancelled',
    ];

    public const WO_FLOW = [
        'reported'      => ['triaged', 'cancelled'],
        'triaged'       => ['approved', 'deferred', 'cancelled'],
        'approved'      => ['planned', 'waiting_parts', 'in_progress', 'deferred'],
        'planned'       => ['waiting_parts', 'in_progress', 'deferred'],
        'waiting_parts' => ['in_progress', 'planned'],
        'in_progress'   => ['inspection', 'waiting_parts'],
        'inspection'    => ['completed', 'in_progress'],
        'completed'     => ['closed'],
        'deferred'      => ['approved', 'cancelled'],
        'closed'        => [],
        'cancelled'     => [],
    ];

    public const VESSEL_TYPES = ['ropax' => 'RoPax (passengers and vehicles)', 'passenger' => 'Passenger ship', 'fastcraft' => 'Fast craft', 'roro' => 'RoRo / vehicle carrier', 'cargo' => 'Cargo / freight'];

    public const CASE_CATEGORIES = [
        'booking_help' => 'Booking assistance', 'rebooking' => 'Rebooking', 'refund_followup' => 'Refund follow-up', 'missed_departure' => 'Missed departure',
        'lost_baggage' => 'Lost baggage', 'cargo_claim' => 'Cargo claim', 'damaged_cargo' => 'Damaged cargo', 'accessibility' => 'Accessibility',
        'delayed_voyage' => 'Delayed voyage', 'complaint' => 'Complaint', 'other' => 'Other service issue',
    ];

    // ------------------------------------------------------------------ labels

    /** Plain text status label (coloured text with a small marker, never a capsule) */
    public static function label(?string $status, array $names = []): string
    {
        $s = (string)$status;
        $text = $names[$s] ?? self::VOYAGE_STATUS[$s] ?? self::TICKET_STATUS[$s] ?? self::CARGO_STATUS[$s] ?? self::WO_STATUS[$s] ?? ucwords(str_replace('_', ' ', $s));
        return '<span class="st st-' . h(self::tone($s)) . '">' . h($text) . '</span>';
    }

    public static function tone(string $s): string
    {
        return match ($s) {
            'completed', 'boarded', 'delivered', 'closed', 'paid', 'approved', 'accepted', 'released', 'resolved', 'done', 'captured', 'settled', 'final', 'sent', 'issued', 'pass', 'loaded', 'active', 'collected', 'remitted', 'posted', 'reviewed', 'encoded', 'reconciled' => 'good',
            'on_sale', 'published', 'boarding', 'ready', 'departed', 'arrived', 'in_transit', 'checked_in', 'in_progress', 'planned', 'triaged', 'booked', 'landed', 'open', 'queued', 'read' => 'info',
            'held', 'pending', 'requested', 'waiting_parts', 'inspection', 'deferred', 'waiting', 'reported', 'quotation', 'draft', 'submitted', 'canvass', 'no_provider', 'needs_review', 'rolled_over', 'recorded' => 'warn',
            'cancelled', 'refunded', 'expired', 'no_show', 'rejected', 'failed', 'short_landed', 'damaged', 'claimed', 'critical', 'no_sail', 'fail', 'duplicate', 'wrong_voyage', 'unpaid', 'invalid', 'void' => 'bad',
            default => 'muted',
        };
    }

    public static function severityLabel(string $sev): string
    {
        $tone = ['critical' => 'bad', 'major' => 'warn', 'minor' => 'info', 'high' => 'warn', 'urgent' => 'bad', 'normal' => 'info', 'low' => 'muted', 'medium' => 'info'][$sev] ?? 'muted';
        return '<span class="st st-' . $tone . '">' . h(ucfirst($sev)) . '</span>';
    }

    // ------------------------------------------------------------------ settings, numbers, time

    public static function setting(string $key, string $default = ''): string
    {
        return Settings::get('shp_' . $key, $default);
    }

    public static function intSetting(string $key, int $default = 0): int
    {
        $v = self::setting($key, (string)$default);
        return is_numeric($v) ? (int)$v : $default;
    }

    /** Next document number from the sequences table (created on first use with the prefix given) */
    public static function no(string $key, string $prefix, int $pad = 5): string
    {
        if (!DB::value('SELECT 1 FROM sequences WHERE seq_key = :k', ['k' => $key])) {
            try {
                DB::insert('sequences', ['seq_key' => $key, 'prefix' => $prefix, 'next_no' => 1, 'pad' => $pad]);
            } catch (PDOException $e) {
                // made by a parallel request a moment ago
            }
        }
        return Seq::next($key);
    }

    /** Set only by the command-line demo loader and the tests, to replay past days; null in normal use */
    public static ?string $clock = null;

    public static function now(): string
    {
        return self::$clock ?? date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return substr(self::now(), 0, 10);
    }

    public static function minutesBetween(string $a, string $b): int
    {
        return (int)round((strtotime($b) - strtotime($a)) / 60);
    }

    public static function age(?string $birth, ?string $on = null): ?int
    {
        if (!$birth || !valid_date($birth)) {
            return null;
        }
        $b = new DateTime($birth);
        $o = new DateTime($on ?: self::today());
        return $b > $o ? 0 : (int)$b->diff($o)->y;
    }

    // ------------------------------------------------------------------ look-ups

    public static function voyage(int $id, bool $lock = false): ?array
    {
        return DB::row(
            'SELECT v.*, r.code AS route_code, r.name AS route_name, r.origin_port_id, r.dest_port_id, r.distance_nm AS route_nm, r.transit_minutes,
                    po.code AS origin_code, po.name AS origin_name, pd.code AS dest_code, pd.name AS dest_name,
                    s.code AS vessel_code, s.name AS vessel_name, s.vessel_type, s.pax_capacity, s.lane_meters, s.max_vehicle_weight_t, s.max_vehicle_length_m,
                    s.cargo_capacity_t, s.class_id AS vessel_class_id, s.site_id AS vessel_site_id, s.fuel_item_id, s.fuel_rate_lph
               FROM shp_voyages v
               JOIN shp_routes r ON r.id = v.route_id
               JOIN shp_ports po ON po.id = r.origin_port_id
               JOIN shp_ports pd ON pd.id = r.dest_port_id
               JOIN shp_vessels s ON s.id = v.vessel_id
              WHERE v.id = :id' . ($lock ? ' FOR UPDATE' : ''),
            ['id' => $id]
        );
    }

    public static function vessel(int $id): ?array
    {
        return DB::row('SELECT * FROM shp_vessels WHERE id = :id', ['id' => $id]);
    }

    public static function voyageTitle(array $v): string
    {
        return $v['voyage_no'] . ' ' . $v['origin_code'] . '-' . $v['dest_code'] . ' ' . date('M j, g:i A', strtotime($v['etd']));
    }

    public static function vesselOptions(bool $activeOnly = true): array
    {
        return DB::pairs('SELECT id, CONCAT(name, " (", code, ")") FROM shp_vessels' . ($activeOnly ? " WHERE status <> 'retired'" : '') . ' ORDER BY name');
    }

    public static function routeOptions(bool $activeOnly = true): array
    {
        return DB::pairs('SELECT r.id, CONCAT(r.code, " ", po.name, " to ", pd.name) FROM shp_routes r JOIN shp_ports po ON po.id = r.origin_port_id
                           JOIN shp_ports pd ON pd.id = r.dest_port_id' . ($activeOnly ? ' WHERE r.is_active = 1' : '') . ' ORDER BY r.code');
    }

    public static function portOptions(): array
    {
        return DB::pairs('SELECT id, CONCAT(name, " (", code, ")") FROM shp_ports WHERE is_active = 1 ORDER BY name');
    }

    public static function officeOptions(): array
    {
        return DB::pairs('SELECT id, name FROM shp_offices WHERE is_active = 1 ORDER BY name');
    }

    /** The offices a user may sell at (all of them for a user with none assigned) */
    public static function userOffices(?int $userId = null): array
    {
        $userId ??= Auth::id();
        $mine = DB::pairs('SELECT o.id, o.name FROM shp_user_offices uo JOIN shp_offices o ON o.id = uo.office_id WHERE uo.user_id = :u AND o.is_active = 1 ORDER BY o.name', ['u' => $userId]);
        return $mine ?: self::officeOptions();
    }

    public static function userName(?int $id): string
    {
        if (!$id) {
            return '';
        }
        static $cache = [];
        return $cache[$id] ??= (string)DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function voyageOptions(array $statuses = ['published', 'on_sale', 'boarding', 'ready'], ?int $routeId = null, int $days = 60): array
    {
        [$in, $p] = DB::in('st', $statuses);
        $sql = "SELECT v.id, CONCAT(v.voyage_no, ' ', po.code, '-', pd.code, ' ', DATE_FORMAT(v.etd, '%b %e %l:%i %p'), ' ', s.name) FROM shp_voyages v
                  JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                  JOIN shp_vessels s ON s.id = v.vessel_id
                 WHERE v.status IN $in AND v.etd <= :until";
        $p['until'] = date('Y-m-d 23:59:59', strtotime(self::today() . " +$days days"));
        if ($routeId) {
            $sql .= ' AND v.route_id = :r';
            $p['r'] = $routeId;
        }
        return DB::pairs($sql . ' ORDER BY v.etd', $p);
    }

    // ------------------------------------------------------------------ privacy

    public static function canPii(): bool
    {
        return (bool)Access::special('shp_view_pii');
    }

    public static function maskName(string $last, string $first): string
    {
        if (self::canPii()) {
            return trim($last . ', ' . $first, ', ');
        }
        return mb_substr($last, 0, 1) . str_repeat('*', max(2, mb_strlen($last) - 1)) . ', ' . mb_substr($first, 0, 1) . '.';
    }

    public static function maskPhone(?string $phone): string
    {
        $phone = (string)$phone;
        if ($phone === '' || self::canPii()) {
            return $phone;
        }
        return str_repeat('*', max(0, strlen($phone) - 3)) . substr($phone, -3);
    }

    // ------------------------------------------------------------------ QR signatures

    public static function secret(): string
    {
        $s = Settings::get('shp_qr_secret');
        if ($s === '') {
            $s = bin2hex(random_bytes(24));
            if (DB::value('SELECT 1 FROM settings WHERE setting_key = :k', ['k' => 'shp_qr_secret'])) {
                DB::run('UPDATE settings SET setting_value = :v WHERE setting_key = :k', ['v' => $s, 'k' => 'shp_qr_secret']);
            } else {
                DB::insert('settings', ['setting_key' => 'shp_qr_secret', 'setting_value' => $s]);
            }
            Settings::flush();
        }
        return $s;
    }

    /** Tamper-resistant signature of a ticket or vehicle pass: changing the number or the voyage breaks it */
    public static function sign(string $kind, string $no, int $voyageId): string
    {
        return strtoupper(substr(hash_hmac('sha256', $kind . '|' . $no . '|' . $voyageId, self::secret()), 0, 12));
    }

    /** What the QR code holds: GST1.<ticket no>.<signature> (passenger) or GSV1.<pass no>.<signature> (vehicle) */
    public static function qrPayload(string $kind, string $no, string $sig): string
    {
        return ($kind === 'vehicle' ? 'GSV1' : 'GST1') . '.' . $no . '.' . $sig;
    }

    // ------------------------------------------------------------------ logs

    public static function voyageEvent(int $voyageId, string $type, string $details, ?string $from = null, ?string $to = null, ?string $at = null): void
    {
        DB::insert('shp_voyage_events', [
            'voyage_id' => $voyageId, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'details' => mb_substr($details, 0, 500), 'user_id' => Auth::id(), 'event_at' => $at ?? self::now(),
        ]);
    }

    public static function audit(string $action, string $module, string $type, $id, string $ref, array $opts = []): void
    {
        Audit::log($action, $module, $type, $id, $ref, $opts);
    }

    /** Keeps only the changed fields of two rows (for the audit trail) */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            if (in_array($k, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $old = $before[$k] ?? null;
            if ((string)$old !== (string)$v) {
                $out[] = ['field' => $k, 'old' => $old, 'new' => $v];
            }
        }
        return $out;
    }

    /** Server-side check of a status change */
    public static function assertFlow(array $flow, string $from, string $to, string $what): void
    {
        if (!in_array($to, $flow[$from] ?? [], true)) {
            fail(ucfirst($what) . ' cannot go from "' . strip_tags(self::label($from)) . '" to "' . strip_tags(self::label($to)) . '".');
        }
    }
}
