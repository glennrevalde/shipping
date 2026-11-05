<?php
declare(strict_types=1);

/**
 * Template-driven notices: booking confirmations, schedule changes, cancellations, refunds, expiry alerts, due work.
 *
 * Every notice is one row per event, channel and recipient (event key, so a re-run never sends twice), with its
 * attempts and the provider's answer. In-app notices are shown in the top bar. E-mail and SMS go out only through a
 * provider that is actually connected; with none (the default), the notice is kept with the status "No provider",
 * so nothing is ever reported as sent when it was not.
 */
final class Notify
{
    public static function send(string $eventKey, string $eventType, string $channel, string $recipientType, string $recipient, string $subject, string $body, ?string $refTable = null, ?int $refId = null, ?int $userId = null): void
    {
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }
        $status = 'queued';
        $provider = null;
        if ($channel === 'email' || $channel === 'sms') {
            $configured = Ship::setting($channel === 'email' ? 'email_provider' : 'sms_provider');
            if ($configured === '') {
                $status = 'no_provider';
                $provider = 'No ' . ($channel === 'email' ? 'e-mail' : 'SMS') . ' provider is connected (Shipping Policies). Kept for when one is.';
            }
        }
        try {
            DB::run('INSERT INTO shp_notifications (event_key, event_type, channel, recipient_type, recipient, user_id, subject, body, status, provider_status, ref_table, ref_id)
                     VALUES (:k, :t, :c, :rt, :r, :u, :s, :b, :st, :ps, :tb, :ri)', [
                'k' => mb_substr($eventKey, 0, 120), 't' => $eventType, 'c' => $channel, 'rt' => $recipientType, 'r' => mb_substr($recipient, 0, 160),
                'u' => $userId, 's' => mb_substr($subject, 0, 160), 'b' => mb_substr($body, 0, 1000), 'st' => $status, 'ps' => $provider,
                'tb' => $refTable, 'ri' => $refId,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // the same notice for the same event was already queued
        }
    }

    /** An in-app notice to every active user of the roles named (and to administrators) */
    public static function staff(string $eventKey, string $eventType, array $roleNames, string $subject, string $body, ?string $refTable = null, ?int $refId = null): void
    {
        [$in, $p] = DB::in('rn', $roleNames ?: ['-']);
        $users = DB::column("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                              WHERE u.status = 'active' AND u.is_platform = 0 AND (r.name IN $in OR r.is_admin = 1)", $p);
        foreach ($users as $uid) {
            self::send($eventKey, $eventType, 'in_app', 'internal', 'user:' . $uid, $subject, $body, $refTable, $refId, (int)$uid);
        }
    }

    public static function user(int $userId, string $eventKey, string $eventType, string $subject, string $body, ?string $refTable = null, ?int $refId = null): void
    {
        self::send($eventKey, $eventType, 'in_app', 'internal', 'user:' . $userId, $subject, $body, $refTable, $refId, $userId);
    }

    /** A notice to a passenger, agent or cargo customer: e-mail and SMS when the contact has them */
    public static function customer(string $eventKey, string $eventType, string $recipientType, ?string $email, ?string $phone, string $subject, string $body, ?string $refTable = null, ?int $refId = null): void
    {
        if ($email) {
            self::send($eventKey, $eventType, 'email', $recipientType, $email, $subject, $body, $refTable, $refId);
        }
        if ($phone) {
            self::send($eventKey, $eventType, 'sms', $recipientType, $phone, $subject, mb_substr($body, 0, 300), $refTable, $refId);
        }
    }

    public static function unread(int $userId, int $limit = 8): array
    {
        return DB::all("SELECT * FROM shp_notifications WHERE user_id = :u AND channel = 'in_app' AND read_at IS NULL ORDER BY id DESC LIMIT " . (int)$limit, ['u' => $userId]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int)DB::value("SELECT COUNT(*) FROM shp_notifications WHERE user_id = :u AND channel = 'in_app' AND read_at IS NULL", ['u' => $userId]);
    }

    public static function markRead(int $userId, ?int $id = null): void
    {
        $sql = "UPDATE shp_notifications SET read_at = :n, status = 'read' WHERE user_id = :u AND channel = 'in_app' AND read_at IS NULL";
        $p = ['n' => Ship::now(), 'u' => $userId];
        if ($id) {
            $sql .= ' AND id = :id';
            $p['id'] = $id;
        }
        DB::run($sql, $p);
    }

    /** Retries the notices waiting for a provider (only does anything once a provider is connected) */
    public static function retry(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $row = DB::row('SELECT * FROM shp_notifications WHERE id = :id', ['id' => (int)$id]);
            if (!$row || !in_array($row['status'], ['no_provider', 'failed', 'queued'], true) || $row['channel'] === 'in_app') {
                continue;
            }
            $configured = Ship::setting($row['channel'] === 'email' ? 'email_provider' : 'sms_provider');
            DB::update('shp_notifications', [
                'attempts' => (int)$row['attempts'] + 1, 'last_attempt_at' => Ship::now(),
                'status' => $configured === '' ? 'no_provider' : 'queued',
                'provider_status' => $configured === '' ? 'Still no provider connected.' : 'Queued for ' . $configured . '.',
            ], 'id = :id', ['id' => (int)$id]);
            $n++;
        }
        return $n;
    }
}
