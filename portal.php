<?php
declare(strict_types=1);

/**
 * The passenger portal: the public face of the shipping line.
 *
 *   search sailings -> choose a voyage -> passengers and classes -> itemized fare (VAT, statutory privileges,
 *   terminal fee and insurance shown on their own lines) -> reserve (a hold of shp_portal_hold_minutes)
 *   -> pay at any ticket office (or online once a payment gateway is set up) -> e-ticket with its signed QR code
 *
 * It also lets a passenger look up the status of their trip by booking reference and last name, see their
 * bookings, cancel an unpaid reservation and send a concern to customer service (a support case).
 *
 * Portal accounts (shp_portal_users) are separate from staff accounts and use their own session cookie, so a
 * staff member logged in on the same browser is never mixed up with a passenger. With several shipping lines
 * (multi-company), the passenger first chooses the line; each line's bookings stay in its own database.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('GSHIPPORTAL');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

function portal_url(array $q = []): string
{
    return base_url() . '/portal.php' . ($q ? '?' . http_build_query($q) : '');
}

function portal_go(array $q = []): never
{
    redirect(portal_url($q));
}

// ------------------------------------------------------------------ which shipping line
$lines = [];
$line = null;
if (Tenant::isMulti()) {
    foreach (Master::companies() as $c) {
        if ($c['status'] === 'active' && (string)$c['db_name'] !== '') {
            $lines[(int)$c['id']] = $c;
        }
    }
    if (isset($_GET['line']) && isset($lines[(int)$_GET['line']])) {
        if ((int)($_SESSION['portal_line'] ?? 0) !== (int)$_GET['line']) {
            unset($_SESSION['portal_uid']);   // an account belongs to one shipping line
        }
        $_SESSION['portal_line'] = (int)$_GET['line'];
    }
    $line = $lines[(int)($_SESSION['portal_line'] ?? 0)] ?? (count($lines) === 1 ? reset($lines) : null);
    if ($line) {
        Tenant::use($line);
    }
}
$ready = !Tenant::isMulti() || $line !== null;
$view = $ready ? (string)g('v', 'search') : 'lines';
if ($view === 'lines' && !Tenant::isMulti()) {
    $view = 'search';
}

// ------------------------------------------------------------------ the passenger's account
$pu = null;
if ($ready && !empty($_SESSION['portal_uid'])) {
    if (time() - (int)($_SESSION['portal_seen'] ?? 0) > 3600) {
        unset($_SESSION['portal_uid']);
        flash('info', 'You were logged out after an hour without activity.');
    } else {
        $pu = DB::row("SELECT * FROM shp_portal_users WHERE id = :id AND status = 'active'", ['id' => $_SESSION['portal_uid']]);
        $_SESSION['portal_seen'] = time();
    }
}
$needLogin = ['book', 'mine', 'booking'];
if ($ready && in_array($view, $needLogin, true) && !$pu) {
    $_SESSION['portal_after'] = $_SERVER['QUERY_STRING'] ?? '';
    flash('info', 'Log in or create a free account to reserve and see your bookings.');
    portal_go(['v' => 'login']);
}

$error = '';
$review = null;   // the itemized fare shown before reserving
if ($ready && is_post()) {
    verify_csrf();
    try {
        $act = (string)p('act');
        if ($act === 'login') {
            $email = strtolower(trim((string)p('email')));
            $u = DB::row('SELECT * FROM shp_portal_users WHERE email = :e', ['e' => $email]);
            if ($u && $u['locked_until'] && $u['locked_until'] > date('Y-m-d H:i:s')) {
                fail('Too many wrong passwords. Try again after ' . date('g:i A', strtotime($u['locked_until'])) . '.');
            }
            if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
                if ($u) {
                    $fails = (int)$u['failed_attempts'] + 1;
                    DB::update('shp_portal_users', $fails >= 5 ? ['failed_attempts' => 0, 'locked_until' => date('Y-m-d H:i:s', time() + 900)] : ['failed_attempts' => $fails], 'id = :id', ['id' => $u['id']]);
                }
                fail('That email and password do not match an account.');
            }
            if ($u['status'] !== 'active') {
                fail('This account is closed. Please call our customer service.');
            }
            session_regenerate_id(true);
            DB::update('shp_portal_users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
            $_SESSION['portal_uid'] = (int)$u['id'];
            $_SESSION['portal_seen'] = time();
            $after = (string)($_SESSION['portal_after'] ?? '');
            unset($_SESSION['portal_after']);
            redirect(portal_url() . ($after !== '' ? '?' . $after : '?v=mine'));
        }
        if ($act === 'register') {
            $email = strtolower(trim((string)p('email')));
            $name = trim((string)p('full_name'));
            $pw = (string)($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('Enter your full name and a valid email address.');
            }
            if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
                fail('Use a password of at least 8 characters with letters and numbers.');
            }
            if ($pw !== (string)($_POST['password2'] ?? '')) {
                fail('The two passwords are not the same.');
            }
            if (DB::value('SELECT 1 FROM shp_portal_users WHERE email = :e', ['e' => $email])) {
                fail('There is already an account with this email. Log in instead.');
            }
            $id = DB::insert('shp_portal_users', ['email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => mb_substr($name, 0, 120),
                'mobile' => mb_substr(trim((string)p('mobile')), 0, 30) ?: null, 'status' => 'active', 'last_login_at' => date('Y-m-d H:i:s')]);
            session_regenerate_id(true);
            $_SESSION['portal_uid'] = $id;
            $_SESSION['portal_seen'] = time();
            flash('success', 'Welcome aboard, ' . $name . '. Your account is ready.');
            $after = (string)($_SESSION['portal_after'] ?? '');
            unset($_SESSION['portal_after']);
            redirect(portal_url() . ($after !== '' ? '?' . $after : ''));
        }
        if ($act === 'logout') {
            unset($_SESSION['portal_uid'], $_SESSION['portal_seen']);
            session_regenerate_id(true);
            flash('info', 'You are logged out.');
            portal_go();
        }
        if ($act === 'review' || $act === 'reserve') {
            if (!$pu) {
                portal_go(['v' => 'login']);
            }
            $vid = (int)p('voyage_id');
            $v = Ship::voyage($vid);
            if (!$v || !in_array($v['status'], ['on_sale', 'boarding'], true)) {
                fail('This voyage is no longer on sale. Please choose another.');
            }
            $pax = [];
            foreach ((array)($_POST['pax'] ?? []) as $row) {
                if (!is_array($row) || trim((string)($row['last_name'] ?? '')) . trim((string)($row['first_name'] ?? '')) === '') {
                    continue;
                }
                $pax[] = array_map(fn($x) => is_string($x) ? trim($x) : $x, array_intersect_key($row, array_flip(['last_name', 'first_name', 'middle_name', 'birth_date', 'gender', 'nationality', 'category_id', 'id_no', 'accom_code', 'special_assist'])));
            }
            if (!$pax) {
                fail('Add at least one passenger.');
            }
            if ($act === 'review') {
                $promo = Fares::promo((string)p('promo_code'), (int)$v['route_id'], substr($v['etd'], 0, 10));
                $qlines = [];
                $cap = Booking::capacity($v);
                foreach ($pax as $i => $x) {
                    if (!isset($cap[$x['accom_code'] ?? ''])) {
                        fail('Passenger ' . ($i + 1) . ': choose a class.');
                    }
                    $cat = Fares::category((int)($x['category_id'] ?? 0));
                    $q = Fares::quotePax($v, (string)$x['accom_code'], $cat, $promo);
                    $qlines[] = ['pax' => $x, 'cat' => $cat, 'q' => $q, 'class' => $cap[$x['accom_code']]['name']];
                }
                $review = ['v' => $v, 'lines' => $qlines, 'promo' => $promo];
                $view = 'book';
                $_GET['voyage'] = (string)$vid;
            } else {
                if (p('agree') !== '1') {
                    fail('Please read and accept the conditions of carriage.');
                }
                $bid = Booking::hold(['voyage_id' => $vid, 'channel' => 'portal', 'portal_user_id' => (int)$pu['id'], 'contact_name' => $pu['full_name'],
                    'contact_email' => $pu['email'], 'contact_phone' => trim((string)p('contact_phone')) ?: $pu['mobile'], 'promo_code' => p('promo_code'),
                    'notes' => 'Reserved online.'], $pax);
                $b = Booking::find($bid);
                Notify::customer('booking:' . $bid . ':reserved', 'booking_reserved', 'passenger', $pu['email'], $b['contact_phone'], 'Reservation ' . $b['booking_ref'] . ' is on hold',
                    'Your reservation ' . $b['booking_ref'] . ' for voyage ' . $v['voyage_no'] . ' (' . $v['origin_name'] . ' to ' . $v['dest_name'] . ', ' . fdatetime($v['etd']) . ') is held until '
                    . fdatetime($b['hold_expires_at']) . '. Total ' . money($b['total_amount']) . '. Pay at any of our ticket offices before then to receive your e-tickets.', 'shp_bookings', $bid);
                flash('success', 'Reserved. Your booking reference is ' . $b['booking_ref'] . '.');
                portal_go(['v' => 'booking', 'id' => $bid]);
            }
        }
        if ($act === 'cancel') {
            $b = $pu ? DB::row('SELECT * FROM shp_bookings WHERE id = :id AND portal_user_id = :u', ['id' => (int)p('id'), 'u' => $pu['id']]) : null;
            if (!$b) {
                fail('Booking not found.');
            }
            if ($b['status'] !== 'held') {
                fail('Only an unpaid reservation can be cancelled here. For a paid ticket, send us a refund or change request.');
            }
            Booking::release((int)$b['id'], 'cancelled', 'Cancelled online by the passenger.');
            flash('success', 'Reservation ' . $b['booking_ref'] . ' is cancelled and its seats are released.');
            portal_go(['v' => 'mine']);
        }
        if ($act === 'help') {
            $_SESSION['portal_cases'] = array_values(array_filter((array)($_SESSION['portal_cases'] ?? []), fn($t) => $t > time() - 3600));
            if (count($_SESSION['portal_cases']) >= 5) {
                fail('You have sent several concerns in the last hour. Please call our hotline for anything urgent.');
            }
            $name = $pu ? $pu['full_name'] : trim((string)p('customer_name'));
            $email = $pu ? $pu['email'] : trim((string)p('customer_email'));
            if (!$pu && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('Enter an email address where we can reply.');
            }
            $bid = null;
            $ref = strtoupper(trim((string)p('booking_ref')));
            if ($ref !== '') {
                $b = DB::row('SELECT * FROM shp_bookings WHERE booking_ref = :r', ['r' => $ref]);
                if ($b && (($pu && (int)$b['portal_user_id'] === (int)$pu['id']) || strcasecmp((string)$b['contact_email'], $email) === 0)) {
                    $bid = (int)$b['id'];
                }
            }
            $cid = Care::open(['category' => p('category'), 'channel' => 'portal', 'subject' => p('subject'), 'description' => p('description'), 'priority' => 'normal',
                'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => p('customer_phone') ?: ($pu['mobile'] ?? null),
                'portal_user_id' => $pu['id'] ?? null, 'booking_id' => $bid]);
            $_SESSION['portal_cases'][] = time();
            flash('success', 'Thank you. Your concern is case ' . DB::value('SELECT case_no FROM shp_cases WHERE id = :id', ['id' => $cid]) . '; we will reply by email.');
            portal_go(['v' => 'help']);
        }
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $error = $e->getMessage();
        if (in_array(p('act'), ['review', 'reserve'], true)) {
            $view = 'book';
            $_GET['voyage'] = (string)p('voyage_id');
        }
    }
}

$company = $ready ? (Settings::get('company_name') ?: app_name()) : app_name();
$title = ['search' => 'Book a trip', 'book' => 'Passengers', 'mine' => 'My bookings', 'booking' => 'Booking', 'status' => 'Trip status', 'help' => 'Help and concerns',
    'login' => 'Log in', 'register' => 'Create an account', 'lines' => 'Choose your shipping line'][$view] ?? 'Book a trip';
$nav = ['search' => ['Book a trip', 'compass'], 'status' => ['Trip status', 'broadcast'], 'mine' => ['My bookings', 'ticket-perforated'], 'help' => ['Help', 'life-preserver']];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<script>document.documentElement.className += " js";</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title . ' - ' . $company) ?></title>
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/shipping.css')) ?>">
<meta name="theme-color" content="#0a1f35">
</head>
<body class="sea-bg portal-page">
<?= ship_sea_scene() ?>
<header class="portal-bar no-print">
  <div class="portal-bar-in">
    <a class="portal-brand" href="<?= h(portal_url()) ?>"><span class="brand-mark"><?= ship_brand_svg() ?></span><span><b><?= h($company) ?></b><small>Passenger portal</small></span></a>
    <?php if ($ready): ?>
    <nav class="portal-nav">
      <?php foreach ($nav as $k => [$label, $icon]): ?><a class="<?= $view === $k || ($k === 'mine' && $view === 'booking') || ($k === 'search' && $view === 'book') ? 'on' : '' ?>" href="<?= h(portal_url(['v' => $k])) ?>"><i class="bi bi-<?= h($icon) ?>"></i><span><?= h($label) ?></span></a><?php endforeach; ?>
    </nav>
    <div class="portal-acct">
      <?php if ($pu): ?>
        <span class="d-none d-md-inline small"><?= h($pu['full_name']) ?></span>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="logout"><button class="btn btn-sm btn-light">Log out</button></form>
      <?php else: ?>
        <a class="btn btn-sm btn-light" href="<?= h(portal_url(['v' => 'login'])) ?>">Log in</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</header>
<main class="portal-main">
<?php foreach (take_flash() as [$type, $msg]): ?><div class="alert alert-<?= h($type) ?> py-2 portal-alert"><?= h($msg) ?></div><?php endforeach; ?>
<?php if ($error): ?><div class="alert alert-danger py-2 portal-alert"><?= h($error) ?></div><?php endif; ?>
<?php
// ================================================================== choose the shipping line
if ($view === 'lines'): ?>
  <section class="portal-panel">
    <h1 class="portal-h">Where are you sailing with?</h1>
    <p class="text-muted">Choose the shipping line to see its sailings and your bookings with it.</p>
    <div class="line-grid">
      <?php foreach ($lines as $c): ?>
        <a class="line-card reveal" href="<?= h(portal_url(['line' => $c['id']])) ?>"><span class="brand-mark"><?= ship_brand_svg() ?></span><b><?= h($c['name']) ?></b><span class="small text-muted"><?= h((string)($c['address'] ?? '')) ?></span></a>
      <?php endforeach; ?>
      <?php if (!$lines): ?><?= empty_note('No shipping line is taking bookings at the moment.') ?><?php endif; ?>
    </div>
  </section>
<?php
// ================================================================== search
elseif ($view === 'search'):
    $ports = DB::pairs('SELECT DISTINCT p.id, p.name FROM shp_routes r JOIN shp_ports p ON p.id = r.origin_port_id WHERE r.is_active = 1 ORDER BY p.name');
    $dests = DB::pairs('SELECT DISTINCT p.id, p.name FROM shp_routes r JOIN shp_ports p ON p.id = r.dest_port_id WHERE r.is_active = 1 ORDER BY p.name');
    $from = (int)g('from');
    $to = (int)g('to');
    $date = gdate('date', Ship::today());
    if ($date < Ship::today()) {
        $date = Ship::today();
    }
    $sql = "SELECT v.id FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id
             WHERE v.status IN ('on_sale', 'boarding') AND v.etd > :now AND (v.booking_close IS NULL OR v.booking_close > :now2)";
    $par = ['now' => Ship::now(), 'now2' => Ship::now()];
    $searched = $from && $to;
    if ($searched) {
        $sql .= ' AND r.origin_port_id = :o AND r.dest_port_id = :d AND v.voyage_date = :dt';
        $par += ['o' => $from, 'd' => $to, 'dt' => $date];
    }
    $ids = DB::column($sql . ' ORDER BY v.etd LIMIT ' . ($searched ? 20 : 8), $par);
    $near = [];
    if ($searched && !$ids) {
        $near = DB::column("SELECT DISTINCT v.voyage_date FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id WHERE v.status IN ('on_sale', 'boarding') AND r.origin_port_id = :o AND r.dest_port_id = :d
                             AND v.voyage_date >= :t AND v.etd > :n ORDER BY v.voyage_date LIMIT 5", ['o' => $from, 'd' => $to, 't' => Ship::today(), 'n' => Ship::now()]);
    }
    ?>
  <section class="portal-panel portal-search">
    <h1 class="portal-h">Where to next?</h1>
    <form class="search-row" method="get">
      <input type="hidden" name="v" value="search">
      <div><label class="form-label">From</label><select class="form-select" name="from" required><?= options($ports, $from ?: null, 'Port of origin') ?></select></div>
      <button type="button" class="swap-btn" title="Swap" aria-label="Swap the ports" onclick="var f=this.form.from,t=this.form.to,a=f.value;f.value=t.value;t.value=a"><i class="bi bi-arrow-left-right"></i></button>
      <div><label class="form-label">To</label><select class="form-select" name="to" required><?= options($dests, $to ?: null, 'Destination') ?></select></div>
      <div><label class="form-label">Date</label><input class="form-control" type="date" name="date" value="<?= h($date) ?>" min="<?= h(Ship::today()) ?>"></div>
      <div class="d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-search"></i> Find sailings</button></div>
    </form>
  </section>
  <section class="portal-panel mt-3">
    <h2 class="portal-h2"><?= $searched ? 'Sailings on ' . h(date('l, F j', strtotime($date))) : 'Next sailings' ?></h2>
    <?php if ($searched && !$ids): ?>
      <?= empty_note('No sailing on that day.') ?>
      <?php if ($near): ?><p class="small mb-0 text-center">Nearest dates with sailings:
        <?php foreach ($near as $d): ?><a class="ms-2" href="<?= h(portal_url(['v' => 'search', 'from' => $from, 'to' => $to, 'date' => $d])) ?>"><?= h(date('D, M j', strtotime($d))) ?></a><?php endforeach; ?></p><?php endif; ?>
    <?php endif; ?>
    <div class="voyage-list">
    <?php foreach ($ids as $vid):
        $v = Ship::voyage((int)$vid);
        Booking::expireHolds((int)$vid);
        $cap = Booking::capacity($v);
        $peak = Fares::isPeak(substr($v['etd'], 0, 10));
        $classes = '';
        $any = false;
        foreach ($cap as $c) {
            $f = Fares::fare((int)$v['route_id'], $c['code'], substr($v['etd'], 0, 10));
            if (!$f) {
                continue;
            }
            $gross = (float)($peak && $f['peak_fare'] !== null ? $f['peak_fare'] : $f['base_fare']) + (float)$f['fuel_surcharge'];
            $any = $any || $c['left'] > 0;
            $classes .= '<div class="fare-chip' . ($c['left'] ? '' : ' full') . '"><span>' . h($c['name']) . '</span><b>₱' . money($gross) . '</b><small>' . ($c['left'] ? ($c['left'] <= 10 ? 'Only ' . $c['left'] . ' left' : $c['left'] . ' left') : 'Full') . '</small></div>';
        }
        $extra = '<div class="fare-chips">' . $classes . '</div>'
            . ($any ? '<a class="btn btn-primary btn-sm mt-2" href="' . h(portal_url(['v' => 'book', 'voyage' => $v['id']])) . '">Choose this sailing</a>' : '<div class="small text-muted mt-2">Fully booked</div>');
        echo voyage_card($v, null, $extra);
    endforeach; ?>
    </div>
    <p class="small text-muted mt-3 mb-0">Fares shown are per adult in Philippine pesos, VAT and fuel surcharge included; the terminal fee and passenger insurance are added at the next step. Senior citizens, persons with disability, students and Medal of Valor awardees get their statutory discount on presenting their ID.</p>
  </section>
<?php
// ================================================================== passengers and fare
elseif ($view === 'book'):
    $v = Ship::voyage((int)g('voyage'));
    if (!$v || !in_array($v['status'], ['on_sale', 'boarding'], true)) {
        echo '<section class="portal-panel">' . empty_note('This sailing is no longer on sale.') . '<p class="text-center mb-0"><a href="' . h(portal_url()) . '">Find another sailing</a></p></section>';
    } else {
        $cap = Booking::capacity($v);
        $classOpts = [];
        foreach ($cap as $c) {
            if ($c['left'] > 0 && Fares::fare((int)$v['route_id'], $c['code'], substr($v['etd'], 0, 10))) {
                $classOpts[$c['code']] = $c['name'] . ' (' . $c['left'] . ' left)';
            }
        }
        $cats = DB::all('SELECT * FROM shp_fare_categories WHERE is_active = 1 ORDER BY sort_order, name');
        $catOpts = array_column($cats, 'name', 'id');
        $needId = array_map('intval', array_column(array_filter($cats, fn($c) => (int)$c['requires_id'] === 1), 'id'));
        $rows = $_POST['pax'] ?? [['nationality' => 'Filipino', 'category_id' => Fares::regularCategory()]];
        $max = Ship::intSetting('max_pax_per_booking', 20);
        echo voyage_card($v, null, '<div class="small text-muted">' . h($v['route_name']) . '</div>');
        if ($review): ?>
        <section class="portal-panel mt-3">
          <h2 class="portal-h2">Your fare</h2>
          <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Passenger</th><th>Class</th><th class="num">Fare</th><th class="num">Discount</th><th class="num">Fuel surcharge</th><th class="num">Fees</th><th class="num">Total</th></tr></thead><tbody>
            <?php $sum = 0.0; foreach ($review['lines'] as $l): $q = $l['q']; $sum += $q['total']; ?>
              <tr><td><b><?= h($l['pax']['last_name'] . ', ' . $l['pax']['first_name']) ?></b><div class="small text-muted"><?= h($l['cat']['name']) ?></div></td><td><?= h($l['class']) ?></td>
                <td class="num"><?= money($q['fare_gross']) ?></td><td class="num"><?= $q['discount'] + $q['promo'] > 0 ? '-' . money($q['discount'] + $q['promo']) : '' ?></td>
                <td class="num"><?= money($q['surcharge_net'] + $q['surcharge_vat']) ?></td><td class="num"><?= money($q['fees_total']) ?></td><td class="num fw-semibold"><?= money($q['total']) ?></td></tr>
              <?php foreach ($q['notes'] as $n): ?><tr><td colspan="7" class="small text-warning-emphasis"><?= h($n) ?></td></tr><?php endforeach; ?>
            <?php endforeach; ?>
            <tr class="grid-totals"><td colspan="6" class="fw-semibold">Total to pay</td><td class="num fw-bold">₱<?= money($sum) ?></td></tr>
          </tbody></table></div>
          <p class="small text-muted">VAT is included in the fare and surcharge; senior citizen and PWD fares are VAT-exempt, with the 20% discount computed on the fare net of VAT. Fees are the terminal fee and passenger insurance collected for their owners.</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="reserve"><input type="hidden" name="voyage_id" value="<?= (int)$v['id'] ?>">
            <input type="hidden" name="promo_code" value="<?= h(p('promo_code')) ?>"><input type="hidden" name="contact_phone" value="<?= h(p('contact_phone')) ?>">
            <?php foreach ($review['lines'] as $i => $l): foreach ($l['pax'] as $k => $val): ?><input type="hidden" name="pax[<?= $i ?>][<?= h($k) ?>]" value="<?= h((string)$val) ?>"><?php endforeach; endforeach; ?>
            <details class="small mb-2"><summary>Conditions of carriage</summary><p class="mt-2" style="white-space:pre-line"><?= h(Ship::setting('ticket_terms')) ?></p>
              <p>A reservation is held for <?= (int)Ship::intSetting('portal_hold_minutes', 120) ?> minutes. Cancellation and change fees follow our published policy: <?= (int)Ship::intSetting('cancel_fee_pct', 20) ?>% of the fare when cancelled earlier than <?= (int)Ship::intSetting('cancel_late_hours', 24) ?> hours before departure, <?= (int)Ship::intSetting('cancel_late_fee_pct', 50) ?>% after that.</p></details>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="agree" value="1" id="agree" required><label class="form-check-label" for="agree">I have read the conditions of carriage, and the names and details above are correct.</label></div>
            <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary"><i class="bi bi-lock"></i> Reserve for <?= (int)Ship::intSetting('portal_hold_minutes', 120) ?> minutes</button>
              <a class="btn btn-outline-secondary" href="<?= h(portal_url(['v' => 'book', 'voyage' => $v['id']])) ?>">Change passengers</a></div>
            <p class="small text-muted mt-2 mb-0"><?= Ship::setting('gateway_provider') === '' ? 'Online payment is not switched on yet: pay at any of our ticket offices before the reservation runs out, and your e-tickets are issued at once.' : 'You can pay online or at any of our ticket offices.' ?></p>
          </form>
        </section>
        <?php else: ?>
        <form method="post" class="portal-panel mt-3" id="paxForm"><?= csrf_field() ?><input type="hidden" name="act" value="review"><input type="hidden" name="voyage_id" value="<?= (int)$v['id'] ?>">
          <h2 class="portal-h2">Who is travelling?</h2>
          <p class="small text-muted">Write the names as they appear on a valid ID: they go on the passenger manifest the Coast Guard checks.</p>
          <div id="paxRows">
          <?php foreach (array_values((array)$rows) as $i => $r): $r = (array)$r; ?>
            <fieldset class="pax-card" data-i="<?= $i ?>">
              <legend>Passenger <span class="n"><?= $i + 1 ?></span><button type="button" class="btn btn-link btn-sm text-danger float-end p-0 pax-del" <?= $i === 0 ? 'hidden' : '' ?>>Remove</button></legend>
              <div class="row g-2">
                <div class="col-md-4"><label class="form-label">Last name</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][last_name]" value="<?= h($r['last_name'] ?? '') ?>" required></div>
                <div class="col-md-4"><label class="form-label">First name</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][first_name]" value="<?= h($r['first_name'] ?? '') ?>" required></div>
                <div class="col-md-4"><label class="form-label">Middle name</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][middle_name]" value="<?= h($r['middle_name'] ?? '') ?>"></div>
                <div class="col-6 col-md-3"><label class="form-label">Birth date</label><input class="form-control form-control-sm" type="date" name="pax[<?= $i ?>][birth_date]" value="<?= h($r['birth_date'] ?? '') ?>" required max="<?= h(Ship::today()) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label">Sex</label><select class="form-select form-select-sm" name="pax[<?= $i ?>][gender]" required><?= options(['M' => 'Male', 'F' => 'Female'], $r['gender'] ?? null, '') ?></select></div>
                <div class="col-md-3"><label class="form-label">Nationality</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][nationality]" value="<?= h($r['nationality'] ?? 'Filipino') ?>" required></div>
                <div class="col-md-4"><label class="form-label">Class</label><select class="form-select form-select-sm" name="pax[<?= $i ?>][accom_code]" required><?= options($classOpts, $r['accom_code'] ?? array_key_first($classOpts)) ?></select></div>
                <div class="col-md-5"><label class="form-label">Fare type</label><select class="form-select form-select-sm pax-cat" name="pax[<?= $i ?>][category_id]"><?= options($catOpts, $r['category_id'] ?? Fares::regularCategory()) ?></select></div>
                <div class="col-md-3 pax-id" <?= in_array((int)($r['category_id'] ?? 0), $needId, true) ? '' : 'hidden' ?>><label class="form-label">ID number</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][id_no]" value="<?= h($r['id_no'] ?? '') ?>" autocomplete="off"></div>
                <div class="col-md-4"><label class="form-label">Assistance needed (optional)</label><input class="form-control form-control-sm" name="pax[<?= $i ?>][special_assist]" value="<?= h($r['special_assist'] ?? '') ?>" placeholder="Wheelchair, stretcher ..."></div>
              </div>
            </fieldset>
          <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="paxAdd"><i class="bi bi-person-plus"></i> Add a passenger</button>
          <div class="row g-2 mt-3">
            <div class="col-md-4"><label class="form-label">Mobile number for trip updates</label><input class="form-control form-control-sm" name="contact_phone" value="<?= h(p('contact_phone', $pu['mobile'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Promo code</label><input class="form-control form-control-sm" name="promo_code" value="<?= h(p('promo_code')) ?>" style="text-transform:uppercase"></div>
          </div>
          <p class="small text-muted mt-2">A discount for seniors, PWD, students and Medal of Valor awardees is given when the ID is shown at check-in; a child or infant fare needs the correct birth date. Seats are assigned automatically.</p>
          <button class="btn btn-primary mt-1"><i class="bi bi-calculator"></i> See the fare</button>
        </form>
        <script>
        (function () {
          var rows = document.getElementById('paxRows'), max = <?= (int)$max ?>, needId = <?= json_encode($needId) ?>;
          function renum() {
            rows.querySelectorAll('.pax-card').forEach(function (fs, i) {
              fs.querySelector('.n').textContent = i + 1;
              fs.querySelector('.pax-del').hidden = i === 0;
              fs.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/pax\[\d+\]/, 'pax[' + i + ']'); });
            });
          }
          function idToggle(sel) { sel.closest('.pax-card').querySelector('.pax-id').hidden = needId.indexOf(parseInt(sel.value, 10)) < 0; }
          document.getElementById('paxAdd').addEventListener('click', function () {
            var all = rows.querySelectorAll('.pax-card');
            if (all.length >= max) { alert('One booking can have at most ' + max + ' passengers.'); return; }
            var c = all[all.length - 1].cloneNode(true);
            c.querySelectorAll('input').forEach(function (el) { if (el.name.indexOf('[nationality]') < 0) el.value = ''; });
            rows.appendChild(c); renum(); idToggle(c.querySelector('.pax-cat'));
            c.querySelector('input').focus();
          });
          rows.addEventListener('click', function (e) { var b = e.target.closest('.pax-del'); if (b) { b.closest('.pax-card').remove(); renum(); } });
          rows.addEventListener('change', function (e) { if (e.target.classList.contains('pax-cat')) idToggle(e.target); });
        })();
        </script>
        <?php endif;
    }
// ================================================================== my bookings
elseif ($view === 'mine'):
    Booking::expireHolds();
    $rows = DB::all("SELECT b.*, v.voyage_no, v.etd, v.status voyage_status, po.code oc, pd.code dc,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.booking_id = b.id) pax
                       FROM shp_bookings b
                       LEFT JOIN shp_voyages v ON v.id = (SELECT MIN(t.voyage_id) FROM shp_tickets t WHERE t.booking_id = b.id)
                       LEFT JOIN shp_routes r ON r.id = v.route_id LEFT JOIN shp_ports po ON po.id = r.origin_port_id LEFT JOIN shp_ports pd ON pd.id = r.dest_port_id
                      WHERE b.portal_user_id = :u ORDER BY b.id DESC LIMIT 100", ['u' => $pu['id']]);
    ?>
  <section class="portal-panel">
    <div class="d-flex justify-content-between align-items-center mb-2"><h1 class="portal-h mb-0">My bookings</h1><a class="btn btn-primary btn-sm" href="<?= h(portal_url()) ?>"><i class="bi bi-plus-lg"></i> New trip</a></div>
    <div class="booking-list">
    <?php foreach ($rows as $b): ?>
      <a class="booking-row reveal" href="<?= h(portal_url(['v' => 'booking', 'id' => $b['id']])) ?>">
        <div><b class="ref"><?= h($b['booking_ref']) ?></b><div class="small text-muted"><?= (int)$b['pax'] ?> passenger<?= (int)$b['pax'] === 1 ? '' : 's' ?></div></div>
        <div><b><?= h(($b['oc'] ?? '') . ' to ' . ($b['dc'] ?? '')) ?></b><div class="small text-muted"><?= $b['etd'] ? h(date('D, M j, g:i A', strtotime($b['etd']))) : '' ?></div></div>
        <div class="text-end"><?= Ship::label($b['status']) ?><div class="small">₱<?= money($b['total_amount']) ?></div></div>
      </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><?= empty_note('No booking yet. Your next trip starts with a search.', 'compass') ?><?php endif; ?>
    </div>
  </section>
<?php
// ================================================================== one booking
elseif ($view === 'booking'):
    $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id AND portal_user_id = :u', ['id' => (int)g('id'), 'u' => $pu['id']]);
    if ($b) {
        Booking::expireHolds();
        $b = Booking::find((int)$b['id']);
    }
    if (!$b) {
        echo '<section class="portal-panel">' . empty_note('Booking not found.') . '</section>';
    } else {
        $tickets = Booking::tickets((int)$b['id']);
        $v = $tickets ? Ship::voyage((int)$tickets[0]['voyage_id']) : null;
        $paid = array_filter($tickets, fn($t) => in_array($t['status'], ['issued', 'checked_in', 'boarded'], true));
        ?>
  <section class="portal-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
      <div><h1 class="portal-h mb-0">Booking <?= h($b['booking_ref']) ?></h1><div class="mt-1"><?= Ship::label($b['status']) ?></div></div>
      <div class="text-end"><div class="small text-muted">Total</div><div class="fs-4 fw-bold">₱<?= money($b['total_amount']) ?></div></div>
    </div>
    <?php if ($v): ?><?= voyage_card($v, null, '<div class="small text-muted">' . h($v['route_name']) . '</div>' . ($v['status'] === 'cancelled' ? '<div class="small text-danger">' . h((string)$v['cancel_reason']) . '</div>' : ((int)$v['delay_minutes'] && $v['delay_cause'] ? '<div class="small text-warning-emphasis">' . h($v['delay_cause']) . '</div>' : ''))) ?><?php endif; ?>
    <?php if ($b['status'] === 'held'): ?>
      <div class="hold-box mt-3">
        <div><i class="bi bi-hourglass-split"></i></div>
        <div><b>Reserved until <?= h(date('g:i A, M j', strtotime($b['hold_expires_at']))) ?></b> <span class="small text-muted" data-countdown="<?= h(date('c', strtotime($b['hold_expires_at']))) ?>"></span>
          <div class="small">Pay ₱<?= money($b['total_amount']) ?> at any of our ticket offices with booking reference <b><?= h($b['booking_ref']) ?></b>. Your e-tickets appear here once paid. Seats not paid by then are released.</div>
          <div class="small text-muted mt-1">Ticket offices: <?= h(implode(', ', DB::column("SELECT name FROM shp_offices WHERE is_active = 1 AND office_type <> 'online' ORDER BY name"))) ?></div></div>
        <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this reservation and release the seats?">Cancel reservation</button></form>
      </div>
    <?php endif; ?>
  </section>
  <?php if ($paid && $v): ?>
  <h2 class="portal-h2 text-white mt-4 mb-2 no-print">Your e-tickets</h2>
    <?php foreach ($paid as $t): ?>
    <article class="eticket reveal">
      <div class="main">
        <div class="head"><div class="d-flex align-items-center gap-2"><span class="brand-mark sm"><?= ship_brand_svg() ?></span><b><?= h($company) ?></b></div><span class="small text-muted">E-ticket <?= h($t['ticket_no']) ?></span></div>
        <div class="ports"><div><b><?= h($v['origin_code']) ?></b><span><?= h($v['origin_name']) ?></span></div><div class="sea-track" style="--p:0%"><span class="ship"><?= ship_icon() ?></span></div><div class="text-end"><b><?= h($v['dest_code']) ?></b><span><?= h($v['dest_name']) ?></span></div></div>
        <dl class="facts">
          <div><dt>Passenger</dt><dd><?= h($t['last_name'] . ', ' . $t['first_name']) ?></dd></div>
          <div><dt>Departure</dt><dd><?= h(date('M j, Y g:i A', strtotime($v['etd']))) ?></dd></div>
          <div><dt>Vessel</dt><dd><?= h($v['vessel_name']) ?></dd></div>
          <div><dt>Class</dt><dd><?= h($t['accom_code']) ?></dd></div>
          <div><dt>Seat</dt><dd><?= h($t['seat_no'] ?: 'Open') ?></dd></div>
          <div><dt>Fare type</dt><dd><?= h($t['cat_name']) ?></dd></div>
          <div><dt>Voyage</dt><dd><?= h($v['voyage_no']) ?></dd></div>
          <div><dt>Booking</dt><dd><?= h($b['booking_ref']) ?></dd></div>
          <div><dt>Status</dt><dd><?= strip_tags(Ship::label($t['status'])) ?></dd></div>
        </dl>
        <table class="money"><tr><td>Fare before discount</td><td><?= money($t['fare_gross']) ?></td></tr><?php if ((float)$t['discount_amount'] + (float)$t['promo_amount'] > 0): ?><tr><td>Discount</td><td>-<?= money((float)$t['discount_amount'] + (float)$t['promo_amount']) ?></td></tr><?php endif; ?><tr><td>Fare and fuel surcharge paid (VAT <?= money($t['vat_amount']) ?>)</td><td><?= money((float)$t['fare_net'] + (float)$t['surcharge_net'] + (float)$t['vat_amount']) ?></td></tr><tr><td>Terminal fee and insurance</td><td><?= money($t['fees_total']) ?></td></tr><tr class="tot"><td>Total paid</td><td>₱<?= money($t['total']) ?></td></tr></table>
        <div class="terms"><?= h(Ship::setting('ticket_terms')) ?></div>
      </div>
      <div class="stub"><div class="qr" data-qr="<?= h(Ship::qrPayload('ticket', $t['ticket_no'], (string)$t['qr_sig'])) ?>"></div><b><?= h($t['ticket_no']) ?></b><span class="small text-muted">Show this code at the gate</span></div>
    </article>
    <?php endforeach; ?>
    <div class="text-center no-print"><button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Print or save the e-tickets</button>
      <a class="btn btn-light ms-2" href="<?= h(portal_url(['v' => 'help', 'ref' => $b['booking_ref'], 'cat' => 'rebooking'])) ?>">Change or refund</a></div>
  <?php elseif ($tickets): ?>
  <section class="portal-panel mt-3"><h2 class="portal-h2">Passengers</h2><ul class="list-unstyled mb-0">
    <?php foreach ($tickets as $t): ?><li class="d-flex justify-content-between border-bottom py-2"><span><?= h($t['last_name'] . ', ' . $t['first_name']) ?> <span class="small text-muted"><?= h($t['cat_name'] . ', ' . $t['accom_code']) ?></span></span><span class="text-nowrap">₱<?= money($t['total']) ?><span class="ms-2"><?= Ship::label($t['status']) ?></span></span></li><?php endforeach; ?>
  </ul></section>
  <?php endif; ?>
  <script>
  document.querySelectorAll('[data-countdown]').forEach(function (el) {
    var end = new Date(el.getAttribute('data-countdown')).getTime();
    (function tick() { var s = Math.max(0, Math.round((end - Date.now()) / 1000)); el.textContent = s ? '(' + Math.floor(s / 60) + ' min ' + (s % 60) + ' s left)' : '(time is up)'; if (s) setTimeout(tick, 1000); })();
  });
  </script>
        <?php
    }
// ================================================================== trip status (no account needed)
elseif ($view === 'status'):
    $ref = strtoupper(trim((string)g('ref')));
    $last = trim((string)g('last'));
    $found = null;
    if ($ref !== '' && $last !== '') {
        $_SESSION['portal_lookups'] = array_values(array_filter((array)($_SESSION['portal_lookups'] ?? []), fn($t) => $t > time() - 900));
        if (count($_SESSION['portal_lookups']) >= 20) {
            $error = 'Too many look-ups. Please wait a few minutes.';
        } else {
            $_SESSION['portal_lookups'][] = time();
            $found = DB::row('SELECT b.* FROM shp_bookings b WHERE b.booking_ref = :r AND EXISTS (SELECT 1 FROM shp_tickets t WHERE t.booking_id = b.id AND t.last_name = :l)', ['r' => $ref, 'l' => $last]);
        }
    }
    ?>
  <section class="portal-panel">
    <h1 class="portal-h">Is my boat on time?</h1>
    <form class="search-row status-row" method="get"><input type="hidden" name="v" value="status">
      <div><label class="form-label">Booking reference</label><input class="form-control" name="ref" value="<?= h($ref) ?>" required style="text-transform:uppercase"></div>
      <div><label class="form-label">Last name of a passenger</label><input class="form-control" name="last" value="<?= h($last) ?>" required></div>
      <div class="d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-broadcast"></i> Check</button></div>
    </form>
    <?php if ($error): ?><div class="alert alert-warning mt-3 mb-0"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ref !== '' && $last !== '' && !$found && !$error): ?><div class="mt-3"><?= empty_note('No booking matches that reference and last name.') ?></div><?php endif; ?>
    <?php if ($found):
        foreach (DB::column('SELECT DISTINCT voyage_id FROM shp_tickets WHERE booking_id = :b', ['b' => $found['id']]) as $vid):
            $v = Ship::voyage((int)$vid);
            $say = match ($v['status']) {
                'cancelled' => 'Cancelled: ' . $v['cancel_reason'] . '. You may move to another sailing free of charge or get a full refund.',
                'departed' => 'Sailing. Departed ' . date('g:i A', strtotime((string)$v['atd'])) . ', expected in ' . $v['dest_name'] . ' around ' . date('g:i A', strtotime($v['eta'])) . '.',
                'arrived', 'completed' => 'Arrived ' . date('M j, g:i A', strtotime((string)($v['ata'] ?: $v['eta']))) . '.',
                'boarding', 'ready' => 'Boarding is open' . ($v['berth'] ? ' at berth ' . $v['berth'] : '') . '. Please proceed to the gate with your e-ticket and ID.',
                default => ((int)$v['delay_minutes'] > 0 || $v['delay_cause'] ? 'New departure time ' . date('M j, g:i A', strtotime($v['etd'])) . ($v['delay_cause'] ? ' (' . $v['delay_cause'] . ')' : '') . '.' : 'On schedule. Boarding opens ' . (int)$v['boarding_open_min'] . ' minutes before departure.'),
            };
            echo '<div class="mt-3">' . voyage_card($v, null, '<div class="small" style="max-width:260px">' . h($say) . '</div>') . '</div>';
        endforeach;
    endif; ?>
  </section>
<?php
// ================================================================== help
elseif ($view === 'help'):
    $mine = $pu ? DB::pairs("SELECT booking_ref, booking_ref FROM shp_bookings WHERE portal_user_id = :u ORDER BY id DESC LIMIT 30", ['u' => $pu['id']]) : [];
    $cases = $pu ? DB::all('SELECT case_no, subject, status, created_at, resolution_notes FROM shp_cases WHERE portal_user_id = :u ORDER BY id DESC LIMIT 10', ['u' => $pu['id']]) : [];
    ?>
  <div class="row g-3">
    <div class="col-lg-7"><form method="post" class="portal-panel h-100"><?= csrf_field() ?><input type="hidden" name="act" value="help">
      <h1 class="portal-h">How can we help?</h1>
      <p class="small text-muted">For a refund or change of a paid ticket, a lost item, a delayed trip or a cargo claim, tell us here and our customer service team replies by email. For anything urgent on the day of travel, see the staff at the terminal.</p>
      <div class="row g-2">
        <?php if (!$pu): ?>
          <div class="col-md-6"><label class="form-label">Your name</label><input class="form-control form-control-sm" name="customer_name" value="<?= h(p('customer_name')) ?>" required></div>
          <div class="col-md-6"><label class="form-label">Email</label><input class="form-control form-control-sm" type="email" name="customer_email" value="<?= h(p('customer_email')) ?>" required></div>
          <div class="col-md-6"><label class="form-label">Mobile (optional)</label><input class="form-control form-control-sm" name="customer_phone" value="<?= h(p('customer_phone')) ?>"></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">About</label><select class="form-select form-select-sm" name="category"><?= options(Ship::CASE_CATEGORIES, p('category', g('cat', 'booking_help'))) ?></select></div>
        <div class="col-md-6"><label class="form-label">Booking reference (if any)</label>
          <?php if ($mine): ?><select class="form-select form-select-sm" name="booking_ref"><?= options($mine, p('booking_ref', g('ref')), '') ?></select>
          <?php else: ?><input class="form-control form-control-sm" name="booking_ref" value="<?= h(p('booking_ref', g('ref'))) ?>" style="text-transform:uppercase"><?php endif; ?></div>
        <div class="col-12"><label class="form-label">Subject</label><input class="form-control form-control-sm" name="subject" value="<?= h(p('subject')) ?>" required maxlength="160"></div>
        <div class="col-12"><label class="form-label">Tell us what happened</label><textarea class="form-control form-control-sm" name="description" rows="5" required><?= h(p('description')) ?></textarea></div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-send"></i> Send</button>
    </form></div>
    <div class="col-lg-5"><section class="portal-panel h-100">
      <h2 class="portal-h2">Your concerns</h2>
      <?php if (!$pu): ?><p class="small text-muted mb-0"><a href="<?= h(portal_url(['v' => 'login'])) ?>">Log in</a> to follow up on the concerns you sent.</p>
      <?php else: ?>
        <?php foreach ($cases as $c): ?><div class="border-bottom py-2"><div class="d-flex justify-content-between"><b><?= h($c['case_no']) ?></b><?= Ship::label($c['status']) ?></div><div class="small"><?= h($c['subject']) ?></div>
          <?php if ($c['resolution_notes']): ?><div class="small text-muted mt-1"><?= h($c['resolution_notes']) ?></div><?php endif; ?></div><?php endforeach; ?>
        <?php if (!$cases): ?><?= empty_note('Nothing sent yet.', 'chat-square-text') ?><?php endif; ?>
      <?php endif; ?>
    </section></div>
  </div>
<?php
// ================================================================== log in / create an account
elseif ($view === 'login' || $view === 'register'): ?>
  <section class="portal-panel portal-narrow">
    <?php if ($view === 'login'): ?>
      <h1 class="portal-h">Welcome back</h1>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="login">
        <div class="mb-2"><label class="form-label" for="pe">Email</label><input class="form-control" id="pe" type="email" name="email" value="<?= h(p('email')) ?>" required autofocus></div>
        <div class="mb-3"><label class="form-label" for="pp">Password</label><input class="form-control" id="pp" type="password" name="password" required></div>
        <button class="btn btn-primary w-100">Log in</button>
      </form>
      <p class="small mt-3 mb-0">New here? <a href="<?= h(portal_url(['v' => 'register'])) ?>">Create a free account</a> to reserve and keep your e-tickets in one place.</p>
    <?php else: ?>
      <h1 class="portal-h">Create your account</h1>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="register">
        <div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="full_name" value="<?= h(p('full_name')) ?>" required></div>
        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h(p('email')) ?>" required></div>
        <div class="mb-2"><label class="form-label">Mobile number</label><input class="form-control" name="mobile" value="<?= h(p('mobile')) ?>" placeholder="09XX XXX XXXX"></div>
        <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
          <div class="col-6"><label class="form-label">Repeat it</label><input class="form-control" type="password" name="password2" required minlength="8"></div></div>
        <button class="btn btn-primary w-100">Create account</button>
      </form>
      <p class="small mt-3 mb-0">Already have one? <a href="<?= h(portal_url(['v' => 'login'])) ?>">Log in</a>. We use your email and mobile number only for your bookings and trip notices.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php if ($ready && $view !== 'lines' && Tenant::isMulti() && count($lines) > 1): ?><p class="text-center small mt-4 no-print"><a class="text-white-50" href="<?= h(portal_url(['v' => 'lines'])) ?>">Sailing with another shipping line?</a></p><?php endif; ?>
<p class="text-center small mt-3 no-print portal-foot"><a href="<?= h(base_url() . '/login.php') ?>">Staff log-in</a></p>
</main>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('vendor/qr/qrcode.js')) ?>"></script>
<script src="<?= h(asset('js/ship.js')) ?>"></script>
</body>
</html>
