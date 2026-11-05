<?php
declare(strict_types=1);

/** Helpers for the command-line tools. */

function split_sql(string $sql): array
{
    $stmts = [];
    $delim = ';';
    $buf = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trim = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trim, $m)) {
            $delim = $m[1];
            continue;
        }
        if ($buf === '' && ($trim === '' || str_starts_with($trim, '--'))) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), $delim)) {
            $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
            if ($stmt !== '') {
                $stmts[] = $stmt;
            }
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $stmts[] = trim($buf);
    }
    return $stmts;
}

function run_sql_file(PDO $pdo, string $file): void
{
    foreach (split_sql(file_get_contents($file)) as $stmt) {
        $pdo->exec($stmt);
    }
}

function server_pdo(): PDO
{
    $c = $GLOBALS['CONFIG']['db'];
    return new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function sql_value($v, PDO $pdo): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    return $pdo->quote((string)$v);
}

/**
 * INSERT statements for every row of every table (load with FK checks off).
 * Each statement holds at most 100 rows and about 256 KB, well under MySQL 5.5's default 1 MB max_allowed_packet.
 */
function export_data(PDO $pdo, array $tables): string
{
    $out = '';
    foreach ($tables as $t) {
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        $head = "INSERT INTO `$t` (`" . implode('`,`', array_keys($rows[0])) . "`) VALUES\n";
        $out .= "\n-- $t (" . count($rows) . " rows)\n";
        $vals = [];
        $bytes = 0;
        foreach ($rows as $r) {
            $v = '(' . implode(',', array_map(fn($x) => sql_value($x, $pdo), array_values($r))) . ')';
            if ($vals && (count($vals) >= 100 || $bytes + strlen($v) > 256000)) {
                $out .= $head . implode(",\n", $vals) . ";\n";
                $vals = [];
                $bytes = 0;
            }
            $vals[] = $v;
            $bytes += strlen($v) + 2;
        }
        $out .= $head . implode(",\n", $vals) . ";\n";
    }
    return $out;
}

function schema_tables(string $schemaFile): array
{
    preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', file_get_contents($schemaFile), $m);
    return $m[1];
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

/**
 * Switches multi-company on for the database just installed: it becomes Company 1, its administrator the
 * Company Owner, and the Super Admin superadmin / Super@12345 is created (asked for a new password at first
 * log-in). With $demo a second, empty shipping company is added from Company 1's set-up, the way the Super
 * Admin adds one from the platform pages.
 */
function setup_platform(bool $demo): array
{
    $admin = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
    Auth::actAs($admin);
    $company = ['name' => Settings::get('company_name'), 'address' => Settings::get('company_address'), 'contact_person' => 'System Administrator',
        'phone' => Settings::get('company_phone'), 'email' => Settings::get('company_email'), 'tin' => Settings::get('company_tin')];
    $r = Provision::setupPrimary(['username' => 'superadmin', 'full_name' => 'Platform Super Admin', 'email' => 'superadmin@gsmart.test', 'password' => 'Super@12345'],
        $company, $admin, true);
    Master::sql()->run("UPDATE super_admins SET must_change_password = 1 WHERE username = 'superadmin'");
    if ($demo) {
        $sa = (int)Master::sql()->value("SELECT id FROM super_admins WHERE username = 'superadmin'");
        $id = Provision::create(['name' => 'Mindanao Ferry Express Corp.', 'address' => 'Macabalan Port, Cagayan de Oro City', 'contact_person' => 'Rogelio Abarquez',
            'phone' => '(088) 856 1234', 'email' => 'admin@mfe.test', 'tin' => '009-876-543-000', 'require_approval' => 1,
            'notes' => 'A second shipping company, added by the Super Admin from the set-up of Company 1'],
            ['username' => 'mfeowner', 'full_name' => 'Rogelio Abarquez', 'email' => 'owner@mfe.test', 'password' => 'Owner@12345'], (int)$r['company_id'], $sa);
        $c = Master::company($id, true);
        if (($c['status'] ?? '') !== 'active') {
            throw new RuntimeException('The second demo company could not be set up: ' . ($c['last_error'] ?? 'unknown error'));
        }
        Tenant::use(Master::company((int)$r['company_id'], true));
    }
    return $r;
}
