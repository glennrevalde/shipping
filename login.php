<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

// already logged in: into a company, or (several companies) on the platform pages as a Super Admin
if (!DB::isBlocked() && Auth::user()) {
    redirect(url('home.dashboard'));
}
if (Tenant::isMulti() && !Tenant::id() && Platform::admin()) {
    redirect(purl());
}

$error = '';
if (is_post()) {
    verify_csrf();
    // which company (or the platform) the username belongs to is decided on the server: app/Login.php
    [$ok, $error, $to] = Login::attempt((string)p('username'), (string)($_POST['password'] ?? ''));
    if ($ok) {
        redirect($to);
    }
}
$app = app_name();
$multi = Tenant::isMulti();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<script>document.documentElement.className += " js";</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in - <?= h($app) ?></title>
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/shipping.css')) ?>">
<meta name="theme-color" content="#0a1f35">
</head>
<body class="sea-bg">
<?= ship_sea_scene() ?>
<main class="login-wrap">
  <div class="card login-card">
    <div class="card-body p-4 p-md-5">
      <div class="d-flex align-items-center gap-3 mb-4">
        <span class="brand-mark"><?= ship_brand_svg() ?></span>
        <div><h1 class="mb-0"><?= h($app) ?></h1><div class="text-muted small"><?= $multi ? 'Log in to your company' : h(Settings::get('company_name')) ?></div></div>
      </div>
      <?php foreach (take_flash() as [$type, $msg]): ?>
        <div class="alert alert-<?= h($type) ?> py-2"><?= h($msg) ?></div>
      <?php endforeach; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="u">Username</label>
          <input class="form-control" id="u" name="username" value="<?= h(p('username')) ?>" required autofocus autocapitalize="none" spellcheck="false"></div>
        <div class="mb-4"><label class="form-label" for="pw">Password</label>
          <input class="form-control" id="pw" type="password" name="password" required></div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Log in</button>
      </form>
      <?php if ($multi): ?>
      <div class="login-join mt-3 small">New employee? <a href="<?= h(base_url() . '/register.php') ?>">Join your company with its Company Code</a></div>
      <?php endif; ?>
      <div class="d-flex justify-content-between align-items-center mt-4 small">
        <a href="<?= h(base_url() . '/portal.php') ?>"><i class="bi bi-ticket-perforated"></i> Passenger portal</a>
        <?php if (Manual::available()): ?><a href="<?= h(Manual::forPage('login')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a><?php endif; ?>
      </div>
      <p class="text-muted small mt-3 mb-0">Every log-in, failed attempt and action is recorded in the audit trail.</p>
    </div>
  </div>
</main>
</body>
</html>
