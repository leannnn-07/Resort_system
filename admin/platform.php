<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payments.php';

$staffMember = require_login($pdo, true);   // super admin only

$today = today();
$next7 = add_days($today, 7);

$stmt = $pdo->prepare(
    "SELECT r.resort_id, r.name, r.slug, r.location, r.hero_image, r.is_active,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.archived_at IS NULL) AS rooms,
            (SELECT COUNT(*) FROM price_item pi WHERE pi.resort_id = r.resort_id AND pi.category = 'Cottage (day use)' AND pi.archived_at IS NULL) AS cottage_types,
            (SELECT COALESCE(SUM(COALESCE(pi.quantity_available, 5)), 0) FROM price_item pi WHERE pi.resort_id = r.resort_id AND pi.category = 'Cottage (day use)' AND pi.archived_at IS NULL) AS cottage_units,
            (SELECT ROUND(AVG(w.rating), 1) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS rating,
            (SELECT COUNT(*) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS reviews,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status IN ('Pending','Confirmed','Checked-in')) AS active_bookings,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status IN ('Pending','Confirmed')
                AND v.check_in_date BETWEEN :d1 AND :d2) AS arrivals,
            (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
               FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id WHERE v.resort_id = r.resort_id) AS collected,
            (SELECT GROUP_CONCAT(s.full_name ORDER BY s.full_name SEPARATOR ', ') FROM staff s WHERE s.resort_id = r.resort_id AND s.access = 'Admin' AND s.archived_at IS NULL) AS admins
       FROM resort r WHERE r.archived_at IS NULL ORDER BY r.sort_order, r.name"
);
$stmt->execute(['d1' => $today, 'd2' => $next7]);
$rows = $stmt->fetchAll();

$tot = ['resorts' => count($rows), 'rooms' => 0, 'cottages' => 0, 'active' => 0, 'arrivals' => 0, 'collected' => 0.0];
foreach ($rows as $r) {
    $tot['rooms']     += (int)$r['rooms'];
    $tot['cottages']  += (int)$r['cottage_units'];
    $tot['active']    += (int)$r['active_bookings'];
    $tot['arrivals']  += (int)$r['arrivals'];
    $tot['collected'] += (float)$r['collected'];
}
$accounts = (int)$pdo->query('SELECT COUNT(*) FROM staff WHERE ' . STAFF_CAN_LOGIN)->fetchColumn();

// Live drill-down lists from the database for clickable metric modals.
$activeList = $pdo->query(
    "SELECT v.reservation_id, v.resort_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, v.total_amount,
            rs.name AS resort_name,
            CONCAT(g.first_name, ' ', g.last_name) AS guest,
            " . ROOMS_LABEL_SQL . " AS what,
            COALESCE((SELECT SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END) FROM payment p WHERE p.reservation_id = v.reservation_id), 0) AS paid
       FROM reservation v
       JOIN resort rs ON rs.resort_id = v.resort_id
       JOIN guest g   ON g.guest_id = v.guest_id
      WHERE v.status IN ('Pending','Confirmed','Checked-in')
      ORDER BY v.check_in_date ASC, v.reservation_id DESC
      LIMIT 100"
)->fetchAll();

$arrStmt = $pdo->prepare(
    "SELECT v.reservation_id, v.resort_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, v.total_amount,
            rs.name AS resort_name,
            CONCAT(g.first_name, ' ', g.last_name) AS guest,
            " . ROOMS_LABEL_SQL . " AS what,
            COALESCE((SELECT SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END) FROM payment p WHERE p.reservation_id = v.reservation_id), 0) AS paid
       FROM reservation v
       JOIN resort rs ON rs.resort_id = v.resort_id
       JOIN guest g   ON g.guest_id = v.guest_id
      WHERE v.status IN ('Pending','Confirmed')
        AND v.check_in_date BETWEEN ? AND ?
      ORDER BY v.check_in_date ASC, v.reservation_id DESC
      LIMIT 100"
);
$arrStmt->execute([$today, $next7]);
$arrivalList = $arrStmt->fetchAll();

$paidList = $pdo->query(
    "SELECT p.payment_id, p.reservation_id, p.payment_date, p.method, p.amount, p.payment_status, p.reference_no,
            v.resort_id, v.booking_ref, rs.name AS resort_name,
            CONCAT(g.first_name, ' ', g.last_name) AS guest
       FROM payment p
       JOIN reservation v ON v.reservation_id = p.reservation_id
       JOIN resort rs     ON rs.resort_id = v.resort_id
       JOIN guest g       ON g.guest_id = v.guest_id
      WHERE p.payment_status IN ('Paid', 'Refunded')
      ORDER BY p.payment_id DESC
      LIMIT 100"
)->fetchAll();

admin_start('Dashboard', 'platform');
admin_head('Dashboard', '', '<a class="btn primary" href="resort_new.php">Add a resort</a>');
?>
<section class="metric-strip is-grid" aria-label="Totals">
  <a class="metric" href="resorts.php"><b><?= (int)$tot['resorts'] ?></b><span>Resorts</span></a>
  <button type="button" class="metric" data-form-open="platform-rooms-modal"><b><?= (int)$tot['rooms'] ?></b><span>Rooms</span></button>
  <button type="button" class="metric" data-form-open="platform-active-modal"><b><?= (int)$tot['active'] ?></b><span>Active bookings</span></button>
  <button type="button" class="metric" data-form-open="platform-arrivals-modal"><b><?= (int)$tot['arrivals'] ?></b><span>Arrivals, next 7 days</span></button>
  <button type="button" class="metric" data-form-open="platform-collected-modal"><b><?= h(money($tot['collected'])) ?></b><span>Collected</span></button>
  <a class="metric" href="accounts.php"><b><?= $accounts ?></b><span>Staff accounts</span></a>
</section>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No resorts yet. <a href="resort_new.php">Add the first resort</a>.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Resort</th>
          <th>Resort admin</th>
          <th class="num">Rooms</th>
          <th class="num" title="Average of public guest reviews">Rating</th>
          <th class="num" title="Pending, confirmed and checked-in">Bookings</th>
          <th class="num" title="Arrivals in the next 7 days">Arrivals</th>
          <th class="num">Collected</th>
          <th>Status</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int)$r['resort_id']; ?>
        <tr>
          <td class="col-primary">
            <div class="res-cell">
              <?= admin_thumb_html($r['hero_image'] ?? null, (string)$r['name']) ?>
              <div class="res-cell-body">
                <strong><a href="index.php?resort=<?= $rid ?>"><?= h($r['name']) ?></a></strong>
                <small class="muted-line" title="<?= h((string)$r['location']) ?>"><?= h(address_short((string)$r['location']) ?: (string)$r['location']) ?></small>
              </div>
            </div>
          </td>
          <td class="wrap"><?= $r['admins'] ? h($r['admins']) : '<span class="muted">None yet</span>' ?></td>
          <td class="num"><a href="rooms.php?resort=<?= $rid ?>"><?= (int)$r['rooms'] ?></a></td>
          <td class="num"><?= $r['rating'] !== null ? '<strong>' . h($r['rating']) . '</strong> <small class="muted">(' . (int)$r['reviews'] . ')</small>' : '<span class="muted">–</span>' ?></td>
          <td class="num"><a href="reservations.php?resort=<?= $rid ?>"><?= (int)$r['active_bookings'] ?></a></td>
          <td class="num"><a href="reservations.php?resort=<?= $rid ?>"><?= (int)$r['arrivals'] ?></a></td>
          <td class="num"><a href="records.php?resort=<?= $rid ?>&amp;status=Paid"><?= h(money($r['collected'])) ?></a></td>
          <td class="nowrap"><?= badge($r['is_active'] ? 'Open' : 'Hidden') ?></td>
          <td class="row-actions">
            <div class="act-group">
              <a class="btn sm" href="analytics.php?view=resort&amp;resort_id=<?= $rid ?>">Analytics</a>
              <a class="btn sm" href="index.php?resort=<?= $rid ?>">Manage</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<!-- Modal: Rooms & Cottages across resorts -->
<div class="modal" id="platform-rooms-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="prm-t">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="prm-t">Rooms &amp; cottages by resort (<?= (int)$tot['rooms'] ?> rooms · <?= (int)$tot['cottages'] ?> cottages)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Resort</th><th class="num">Rooms</th><th class="num">Cottages</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $rid = (int)$r['resort_id']; ?>
          <tr>
            <td><strong><?= h($r['name']) ?></strong><small class="muted-line" title="<?= h((string)$r['location']) ?>"><?= h(address_short((string)$r['location']) ?: (string)$r['location']) ?></small></td>
            <td class="num"><a href="rooms.php?resort=<?= $rid ?>"><?= (int)$r['rooms'] ?></a></td>
            <td class="num"><a href="pricelist.php?resort=<?= $rid ?>"><?= (int)$r['cottage_units'] ?> <small class="muted">(<?= (int)$r['cottage_types'] ?> types)</small></a></td>
            <td class="row-actions">
              <div class="act-group">
                <a class="btn sm" href="rooms.php?resort=<?= $rid ?>">Rooms</a>
                <a class="btn sm" href="pricelist.php?resort=<?= $rid ?>">Cottages</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Modal: Active bookings across all resorts -->
<div class="modal" id="platform-active-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="pam-t">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="pam-t">Active bookings (<?= count($activeList) ?>)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <?php if (!$activeList): ?>
      <p class="muted">No active bookings right now.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Ref</th><th>Resort</th><th>Guest</th><th>Dates</th><th>Status</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($activeList as $b): ?>
          <tr>
            <td class="strong"><?= h($b['booking_ref']) ?></td>
            <td><?= h($b['resort_name']) ?><br><small class="muted"><?= h($b['what']) ?></small></td>
            <td><?= h($b['guest']) ?></td>
            <td class="nowrap"><?= h(date('M j', strtotime($b['check_in_date']))) ?> – <?= h(date('M j', strtotime($b['check_out_date']))) ?></td>
            <td><?= reservation_status_badge($b, (float)$b['paid']) ?></td>
            <td class="num"><?= h(money($b['total_amount'])) ?></td>
            <td class="right"><a class="btn sm" href="reservation_view.php?id=<?= (int)$b['reservation_id'] ?>&amp;resort=<?= (int)$b['resort_id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Modal: Arrivals in the next 7 days -->
<div class="modal" id="platform-arrivals-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="parm-t">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="parm-t">Arrivals, next 7 days (<?= count($arrivalList) ?>)</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <?php if (!$arrivalList): ?>
      <p class="muted">No arrivals scheduled in the next 7 days.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Arrival</th><th>Ref</th><th>Resort</th><th>Guest</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($arrivalList as $b): ?>
          <tr>
            <td class="nowrap strong"><?= h(date('M j, Y', strtotime($b['check_in_date']))) ?></td>
            <td><?= h($b['booking_ref']) ?></td>
            <td><?= h($b['resort_name']) ?><br><small class="muted"><?= h($b['what']) ?></small></td>
            <td><?= h($b['guest']) ?></td>
            <td><?= reservation_status_badge($b, (float)$b['paid']) ?></td>
            <td class="right"><a class="btn sm" href="reservation_view.php?id=<?= (int)$b['reservation_id'] ?>&amp;resort=<?= (int)$b['resort_id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<!-- Modal: Collected payments across all resorts -->
<div class="modal" id="platform-collected-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="pcm-t">
  <div class="modal-card form-card wide-card">
    <div class="modal-head">
      <h2 id="pcm-t">Collected payments · <?= h(money($tot['collected'])) ?></h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <?php if (!$paidList): ?>
      <p class="muted">No verified payments recorded yet.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Date</th><th>Resort</th><th>Booking</th><th>Method</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($paidList as $p): ?>
          <tr>
            <td class="nowrap"><?= h(date('M j, Y', strtotime($p['payment_date']))) ?></td>
            <td><a href="records.php?resort=<?= (int)$p['resort_id'] ?>&amp;status=Paid"><?= h($p['resort_name']) ?></a></td>
            <td><strong><?= h($p['booking_ref']) ?></strong><br><small class="muted"><?= h($p['guest']) ?></small></td>
            <td><?= h($p['method']) ?><?= !empty($p['reference_no']) ? '<br><small class="muted">' . h($p['reference_no']) . '</small>' : '' ?></td>
            <td class="num"><?= $p['payment_status'] === 'Refunded' ? '−' : '' ?><?= h(money($p['amount'])) ?></td>
            <td class="right"><a class="btn sm" href="reservation_view.php?id=<?= (int)$p['reservation_id'] ?>&amp;resort=<?= (int)$p['resort_id'] ?>#payments">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="modal-actions" style="margin-top:16px"><button class="btn" type="button" data-modal-close>Close</button></div>
  </div>
</div>

<?php admin_end();
