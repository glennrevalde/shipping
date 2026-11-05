<?php
declare(strict_types=1);

/**
 * Boarding Scanner: the gate officer picks the voyage and scans e-tickets (camera or typed number) for check-in
 * or boarding, and vehicle passes at the ramp. Every scan is checked and logged by Boarding::scan; boarded counts
 * come only from accepted scans. When the connection drops, scans are kept on the device (if the policy allows)
 * and sent in the order they were made when it comes back; conflicts come back for the supervisor to resolve.
 *
 *   ?voyage=ID          the scanner of that voyage
 *   ?voyage=ID&log=1    every scan of the voyage, accepted or not
 */

$page = 'tkt.boarding';
$voyageId = gint('voyage');


$v = $voyageId ? Ship::voyage($voyageId) : null;

// ================================================================== voyage picker
if (!$v) {
    $rows = DB::all("SELECT v.*, po.code origin_code, pd.code dest_code, s.name vessel_name, COALESCE(v.pax_quota, s.pax_capacity) cap,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'boarded')) paid,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status = 'boarded') boarded
                       FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                       JOIN shp_vessels s ON s.id = v.vessel_id
                      WHERE v.status IN ('boarding', 'ready') OR (v.status = 'on_sale' AND v.etd BETWEEN :a AND :b)
                      ORDER BY FIELD(v.status, 'boarding', 'ready', 'on_sale'), v.etd LIMIT 30",
        ['a' => date('Y-m-d H:i:s', strtotime(Ship::now()) - 6 * 3600), 'b' => date('Y-m-d H:i:s', strtotime(Ship::now()) + 30 * 3600)]);
    layout_start('Boarding Scanner');
    echo '<p class="small text-muted">Choose the voyage at your gate. Voyages open for boarding come first; those leaving within the next day follow.</p><div class="voyage-list">';
    foreach ($rows as $r) {
        $pc = $r['paid'] ? 100 * $r['boarded'] / $r['paid'] : 0;
        echo voyage_card($r, url($page, ['voyage' => $r['id']]), '<div class="small"><b>' . (int)$r['boarded'] . '</b> of ' . (int)$r['paid'] . ' paid boarded</div><div style="min-width:150px" class="mt-1">' . meter($pc, 'good') . '</div>');
    }
    if (!$rows) {
        echo empty_note('No voyage is boarding or leaving within the next day.', 'qr-code-scan');
    }
    echo '</div>';
    layout_end();
    return;
}

$actions = btn_link(url($page), 'Other voyage', 'btn-outline-secondary', 'arrow-left-right') . ' '
    . (g('log') ? btn_link(url($page, ['voyage' => $v['id']]), 'Scanner', 'btn-outline-primary', 'qr-code-scan') : btn_link(url($page, ['voyage' => $v['id'], 'log' => 1]), 'Scan log', 'btn-outline-secondary', 'list-check')) . ' '
    . (Access::page('tkt.manifest') ? btn_link(url('tkt.manifest', ['voyage' => $v['id']]), 'Manifest', 'btn-outline-secondary', 'card-checklist') : '');

// ================================================================== scan log
if (g('log')) {
    $result = (string)g('result');
    $par = ['v' => $v['id']];
    $sql = 'SELECT s.*, u.full_name, t.ticket_no, t.last_name, t.first_name, x.vehicle_no, x.plate_no FROM shp_boarding_scans s LEFT JOIN users u ON u.id = s.operator_id
             LEFT JOIN shp_tickets t ON t.id = s.ticket_id LEFT JOIN shp_vehicles x ON x.id = s.vehicle_id WHERE s.voyage_id = :v';
    if ($result === 'rejected') {
        $sql .= " AND s.result <> 'accepted'";
    } elseif ($result !== '') {
        $sql .= ' AND s.result = :r';
        $par['r'] = $result;
    }
    $rows = DB::all($sql . ' ORDER BY s.scanned_at DESC, s.id DESC LIMIT 500', $par);
    $byResult = DB::pairs('SELECT result, COUNT(*) FROM shp_boarding_scans WHERE voyage_id = :v GROUP BY result', ['v' => $v['id']]);
    layout_start('Scan log ' . $v['voyage_no'], ['actions' => $actions]);
    $names = ['accepted' => 'Accepted', 'duplicate' => 'Duplicate', 'conflict' => 'Offline conflict', 'wrong_voyage' => 'Wrong voyage', 'cancelled' => 'Cancelled / moved', 'unpaid' => 'Unpaid', 'invalid' => 'Invalid', 'not_open' => 'Boarding not open'];
    $tabs = ['' => ['All', url($page, ['voyage' => $v['id'], 'log' => 1]), array_sum($byResult)], 'rejected' => ['Refused', url($page, ['voyage' => $v['id'], 'log' => 1, 'result' => 'rejected']), array_sum($byResult) - (int)($byResult['accepted'] ?? 0)]];
    foreach ($byResult as $k => $n) {
        $tabs[$k] = [$names[$k] ?? $k, url($page, ['voyage' => $v['id'], 'log' => 1, 'result' => $k]), $n];
    }
    echo tabs_line($tabs, $result);
    ?>
    <div class="card reveal"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Scanned</th><th>Ticket / pass</th><th>Kind</th><th>Result</th><th>Message</th><th>Officer</th><th>Device</th></tr></thead><tbody>
      <?php foreach ($rows as $s): ?>
        <tr><td class="text-nowrap"><?= h(date('M j g:i:s A', strtotime($s['scanned_at']))) ?><?= (int)$s['is_offline'] ? '<div class="small text-warning-emphasis">sent later (offline)</div>' : '' ?></td>
          <td><?= h($s['ticket_no'] ?: ($s['vehicle_no'] ?: mb_strimwidth((string)$s['scan_code'], 0, 28, '...'))) ?>
            <div class="small text-muted"><?= $s['last_name'] ? h(Ship::maskName($s['last_name'], $s['first_name'])) : h((string)$s['plate_no']) ?></div></td>
          <td><?= h(['board' => 'Boarding', 'checkin' => 'Check-in', 'vehicle' => 'Vehicle'][$s['scan_type']] ?? $s['scan_type']) ?></td>
          <td><?= Ship::label($s['result'], $names) ?></td><td class="small"><?= h((string)$s['message']) ?></td>
          <td class="small"><?= h((string)$s['full_name']) ?></td><td class="small text-muted"><?= h((string)$s['device_id']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7"><?= empty_note('No scan yet.', 'qr-code') ?></td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php
    layout_end();
    return;
}

// ================================================================== the scanner
$c = Boarding::counts((int)$v['id']);
$limit = Booking::paxLimit($v);
$open = in_array($v['status'], ['boarding', 'ready'], true);
$canScan = Access::can($page, 'create');
$offlineOk = Ship::setting('offline_scans', '1') === '1';
layout_start('Boarding Scanner', ['actions' => $actions, 'js' => ['vendor/qr/jsQR.js']]);
echo voyage_card($v, Access::page('ops.voyages') ? url('ops.voyages', ['id' => $v['id']]) : null,
    '<div class="small text-muted">' . h($v['route_name']) . '</div><div class="small">Boarding opens ' . h(date('g:i A', strtotime($v['etd']) - 60 * (int)$v['boarding_open_min'])) . ($v['berth'] ? ', berth ' . h($v['berth']) : '') . '</div>');
if (!$open): ?>
  <div class="alert alert-warning mt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>Boarding of this voyage is not open, so every scan is refused (and logged). The voyage is put to Boarding on its dispatch page.</div>
    <?php if (Access::can('ops.voyages', 'edit')): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url('ops.voyages', ['id' => $v['id']])) ?>">Open the voyage</a><?php endif; ?>
  </div>
<?php endif; ?>
<div class="scan-shell mt-3" id="scanner" data-api="<?= h(url('shp.api')) ?>" data-voyage="<?= (int)$v['id'] ?>" data-csrf="<?= h(csrf_token()) ?>" data-offline="<?= $offlineOk ? '1' : '0' ?>">
  <div>
    <div class="scan-view" id="scanView">
      <video id="cam" playsinline muted></video><canvas id="camCanvas" hidden></canvas>
      <div class="frame"></div><div class="beam"></div>
      <div class="idle"><div><i class="bi bi-qr-code-scan d-block mb-2" style="font-size:2.4rem"></i>Point the camera at the QR code of the e-ticket or vehicle pass.<br><span id="camMsg"></span></div></div>
      <?php if ($canScan): ?><button type="button" class="btn btn-light btn-sm cam-btn" id="camBtn"><i class="bi bi-camera-video"></i> <span>Start camera</span></button><?php endif; ?>
    </div>
    <?php if ($canScan): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
      <div class="scan-modes" role="radiogroup" aria-label="Scan for">
        <label><input type="radio" name="mode" value="board" checked><span>Boarding</span></label>
        <label><input type="radio" name="mode" value="checkin"><span>Check-in</span></label>
      </div>
      <span class="net-dot" id="net">Online</span>
    </div>
    <form class="input-group mt-2" id="manual" autocomplete="off">
      <input class="form-control" id="code" placeholder="Ticket no. (ET-...) or pass no. (VP-...)" inputmode="text" autocapitalize="characters">
      <button class="btn btn-primary"><i class="bi bi-arrow-return-left"></i> Scan</button>
    </form>
    <div class="small text-muted mt-1">A typed number means the officer checked the passenger's ID against the ticket.</div>
    <div class="small mt-2" id="queueBox" hidden><span class="st st-warn"><span id="queueN">0</span> scan(s) kept on this device</span> <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="syncBtn">Send now</button></div>
    <?php else: ?>
      <p class="small text-muted mt-3">Your role can watch the boarding count but cannot scan tickets.</p>
    <?php endif; ?>
  </div>
  <div>
    <div class="scan-result" id="result"><div class="big text-muted">Ready</div><div class="small text-muted">The result of each scan shows here.</div></div>
    <div class="scan-stats mt-3">
      <div><div class="lbl">Paid</div><div class="scan-count" id="cPaid"><?= (int)$c['paid'] ?></div></div>
      <div><div class="lbl">Checked in</div><div class="scan-count" id="cChk"><?= (int)$c['checked_in'] ?></div></div>
      <div><div class="lbl">Boarded, limit <?= $limit ?></div><div class="scan-count" id="cBrd"><?= (int)$c['boarded'] ?></div></div>
      <div><div class="lbl">Vehicles loaded</div><div class="scan-count" id="cVeh"><?= (int)$c['vehicles_loaded'] ?></div></div>
    </div>
    <div class="mt-2"><?= meter($c['paid'] ? 100 * $c['boarded'] / max(1, $c['paid']) : 0, 'good') ?></div>
    <div class="card reveal mt-3"><div class="card-header d-flex justify-content-between"><span>This device</span><span class="small text-muted"><span id="cRej"><?= (int)$c['rejected_scans'] ?></span> refused on this voyage</span></div>
      <ul class="scan-feed card-body py-1" id="feed"><li class="text-muted justify-content-center" id="feedEmpty">No scan yet on this device.</li></ul></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var root = document.getElementById('scanner');
  var api = root.getAttribute('data-api'), voyage = root.getAttribute('data-voyage'), csrf = root.getAttribute('data-csrf');
  var offlineOk = root.getAttribute('data-offline') === '1';
  var qKey = 'gship_scanq_' + voyage;
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var device = store.get('gship_device');
  if (!device) { device = 'GATE-' + Math.random().toString(36).slice(2, 7).toUpperCase(); store.set('gship_device', device); }
  var resultBox = document.getElementById('result'), view = document.getElementById('scanView');
  var feed = document.getElementById('feed');
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function pad(n) { return String(n).padStart(2, '0'); }
  function stamp() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function mode() { var m = document.querySelector('input[name=mode]:checked'); return m ? m.value : 'board'; }

  // ---------------- sound and touch feedback
  var ctx = null;
  function beep(ok) {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = ok ? 880 : 220; g.gain.value = 0.08;
      o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 60 : [80, 60, 80]);
  }
  var NAMES = { duplicate: 'Already scanned', conflict: 'Conflict', wrong_voyage: 'Wrong voyage', cancelled: 'Not valid', unpaid: 'Not paid', invalid: 'Refused', not_open: 'Boarding not open' };
  function show(r, queued, nofeed) {
    var ok = !!r.ok;
    resultBox.className = 'scan-result ' + (queued ? 'queued' : (ok ? 'ok' : 'no'));
    var title = queued ? 'Kept on this device' : (ok ? (r.message || 'Accepted') : (NAMES[r.result] || 'Refused'));
    var h = '<div class="big">' + esc(title) + '</div>';
    if (r.name) h += '<div class="who mt-1">' + esc(r.name) + '</div>';
    var f = [];
    if (r.ticket_no) f.push(esc(r.ticket_no));
    if (r.category) f.push(esc(r.category));
    if (r.accom) f.push(esc(r.accom) + (r.seat ? ', seat ' + esc(r.seat) : ''));
    if (f.length) h += '<div class="facts">' + f.map(function (x) { return '<span>' + x + '</span>'; }).join('') + '</div>';
    if (!ok || queued) h += '<div class="small mt-2">' + esc(r.message) + '</div>';
    resultBox.innerHTML = h;
    view.classList.remove('flash-ok', 'flash-no'); void view.offsetWidth;
    view.classList.add(queued || ok ? 'flash-ok' : 'flash-no');
    setTimeout(function () { view.classList.remove('flash-ok', 'flash-no'); }, 700);
    beep(ok || queued);
    if (nofeed) return;
    var empty = document.getElementById('feedEmpty'); if (empty) empty.remove();
    var li = document.createElement('li');
    li.innerHTML = '<span>' + (queued ? '<span class="st st-warn">Kept</span>' : '<span class="st st-' + (ok ? 'good' : 'bad') + '">' + (ok ? 'OK' : esc(title)) + '</span>') + ' ' + esc(r.ticket_no || r.code || '') + ' <span class="text-muted">' + esc(r.name || '') + '</span></span><span class="t">' + new Date().toLocaleTimeString() + '</span>';
    feed.insertBefore(li, feed.firstChild);
    while (feed.children.length > 40) feed.removeChild(feed.lastChild);
  }
  function counts(c) {
    if (!c) return;
    document.getElementById('cPaid').textContent = c.paid;
    document.getElementById('cChk').textContent = c.checked_in;
    document.getElementById('cBrd').textContent = c.boarded;
    document.getElementById('cVeh').textContent = c.vehicles_loaded;
    document.getElementById('cRej').textContent = c.rejected_scans;
    var m = document.querySelector('.scan-stats + div .meter > span'); if (m) m.style.width = (c.paid ? Math.min(100, 100 * c.boarded / c.paid) : 0) + '%';
  }

  // ---------------- the offline queue
  function queue() { try { return JSON.parse(store.get(qKey) || '[]'); } catch (e) { return []; } }
  function saveQueue(q) { store.set(qKey, JSON.stringify(q)); paintQueue(); }
  function paintQueue() {
    var n = queue().length, box = document.getElementById('queueBox');
    if (!box) return;
    box.hidden = n === 0; document.getElementById('queueN').textContent = n;
  }
  function online(on) { var n = document.getElementById('net'); if (!n) return; n.classList.toggle('off', !on); n.textContent = on ? 'Online' : 'Offline' + (offlineOk ? ', scans kept on this device' : ''); }
  function keep(code) {
    if (!offlineOk) { show({ ok: false, result: 'invalid', message: 'No connection, and offline scanning is switched off. Wait for the connection and scan again.' }); return; }
    var q = queue(); q.push({ code: code, type: mode(), scanned_at: stamp(), device_id: device }); saveQueue(q);
    show({ ok: false, code: code, ticket_no: code.split('.')[1] || code, message: 'No connection. The scan is kept and sent when the connection is back; check the ticket and ID by eye meanwhile.' }, true);
  }
  function post(data) {
    var fd = new FormData(); fd.append('_csrf', csrf);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(api + '&a=' + data.a, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var syncing = false;
  function sync() {
    var q = queue();
    if (!q.length || syncing || !navigator.onLine) return;
    syncing = true;
    post({ a: 'sync', voyage_id: voyage, scans: JSON.stringify(q) }).then(function (d) {
      syncing = false;
      if (!d.ok) { show({ ok: false, message: d.error }, false, true); return; }
      saveQueue(queue().slice(q.length));
      var bad = d.results.filter(function (r) { return !r.ok; });
      d.results.forEach(function (r) {
        var li = document.createElement('li');
        li.innerHTML = '<span><span class="st st-' + (r.ok ? 'good' : 'bad') + '">' + (r.ok ? 'Sent' : esc(NAMES[r.result] || r.result)) + '</span> ' + esc(r.ticket_no || r.code) + ' <span class="text-muted">' + esc(r.ok ? '' : r.message) + '</span></span><span class="t">synced</span>';
        feed.insertBefore(li, feed.firstChild);
      });
      counts(d.counts);
      if (bad.length) show({ ok: false, result: 'conflict', message: bad.length + ' of the scans kept offline were refused when sent (see the list and the scan log).' }, false, true);
      else show({ ok: true, message: d.results.length + ' scan(s) kept offline were sent and accepted.' }, false, true);
    }).catch(function () { syncing = false; });
  }

  // ---------------- one scan
  var busy = false;
  function scan(code) {
    code = (code || '').trim().toUpperCase();
    if (!code || busy) return;
    if (/^\d{4,10}$/.test(code)) code = 'ET-' + code.padStart(8, '0');
    if (!navigator.onLine) { keep(code); return; }
    busy = true;
    post({ a: 'scan', voyage_id: voyage, code: code, type: mode(), device_id: device }).then(function (d) {
      busy = false;
      if (!d.ok) { show({ ok: false, result: 'invalid', message: d.error }); return; }
      show(d.scan); counts(d.counts);
    }).catch(function () { busy = false; keep(code); });
  }
  var manual = document.getElementById('manual');
  if (manual) manual.addEventListener('submit', function (e) { e.preventDefault(); var i = document.getElementById('code'); scan(i.value); i.value = ''; i.focus(); });
  var sb = document.getElementById('syncBtn'); if (sb) sb.addEventListener('click', sync);
  window.addEventListener('online', function () { online(true); sync(); });
  window.addEventListener('offline', function () { online(false); });
  online(navigator.onLine); paintQueue(); sync();
  setInterval(sync, 20000);
  setInterval(function () {
    if (!navigator.onLine || document.hidden) return;
    fetch(api + '&a=counts&voyage=' + voyage, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d.ok) counts(d.counts); }).catch(function () {});
  }, 15000);

  // ---------------- camera
  var btn = document.getElementById('camBtn'), video = document.getElementById('cam'), canvas = document.getElementById('camCanvas');
  var stream = null, last = '', lastAt = 0, timer = null;
  function stop() {
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; clearInterval(timer); view.classList.remove('live');
    if (btn) btn.querySelector('span').textContent = 'Start camera';
  }
  function tick() {
    if (!stream || video.readyState < 2 || typeof jsQR !== 'function') return;
    var w = video.videoWidth, h = video.videoHeight; if (!w || !h) return;
    var scale = Math.min(1, 640 / Math.max(w, h));
    canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
    var g = canvas.getContext('2d', { willReadFrequently: true });
    g.drawImage(video, 0, 0, canvas.width, canvas.height);
    var img = g.getImageData(0, 0, canvas.width, canvas.height);
    var found = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
    if (found && found.data) {
      var now = Date.now();
      if (found.data === last && now - lastAt < 3000) return;
      last = found.data; lastAt = now; scan(found.data);
    }
  }
  if (btn) btn.addEventListener('click', function () {
    if (stream) { stop(); return; }
    var msg = document.getElementById('camMsg');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { msg.textContent = 'This browser cannot use the camera here (it needs a secure https address). Type the ticket number instead.'; return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
      stream = s; video.srcObject = s; video.play(); view.classList.add('live');
      btn.querySelector('span').textContent = 'Stop camera';
      timer = setInterval(tick, 180);
    }).catch(function (e) { msg.textContent = 'The camera could not start (' + (e && e.name ? e.name : 'no permission') + '). Type the ticket number instead.'; });
  });
  document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
});
</script>
<?php
layout_end();
