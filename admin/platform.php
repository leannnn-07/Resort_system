<?php
require __DIR__ . '/../includes/layout.php';

$staffMember = require_login($pdo, true);   // super admin only

$rows = $pdo->query(
    "SELECT r.resort_id, r.name, r.slug, r.location, r.is_active,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id) AS rooms,
            (SELECT ROUND(AVG(w.rating), 1) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS rating,
            (SELECT COUNT(*) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published') AS reviews,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status IN ('Pending','Confirmed','Checked-in')) AS active_bookings,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status IN ('Pending','Confirmed')
                AND v.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS arrivals,
            (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
               FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id WHERE v.resort_id = r.resort_id) AS collected,
            (SELECT GROUP_CONCAT(s.full_name ORDER BY s.full_name SEPARATOR ', ') FROM staff s WHERE s.resort_id = r.resort_id AND s.access = 'Admin') AS admins
       FROM resort r ORDER BY r.sort_order, r.name"
)->fetchAll();

$tot = ['resorts' => count($rows), 'rooms' => 0, 'active' => 0, 'arrivals' => 0, 'collected' => 0.0];
foreach ($rows as $r) {
    $tot['rooms']     += (int)$r['rooms'];
    $tot['active']    += (int)$r['active_bookings'];
    $tot['arrivals']  += (int)$r['arrivals'];
    $tot['collected'] += (float)$r['collected'];
}
$accounts = (int)$pdo->query('SELECT COUNT(*) FROM staff WHERE password_hash IS NOT NULL')->fetchColumn();

admin_start('Platform overview', 'platform');
admin_head('Platform overview', 'Every resort on the site at a glance', '<a class="btn primary" href="resorts.php">Add a resort</a>');
?>
<section class="metric-strip" aria-label="Totals">
  <div class="metric"><b><?= (int)$tot['resorts'] ?></b><span>Resorts</span></div>
  <div class="metric"><b><?= (int)$tot['rooms'] ?></b><span>Rooms</span></div>
  <div class="metric"><b><?= (int)$tot['active'] ?></b><span>Active bookings</span></div>
  <div class="metric"><b><?= (int)$tot['arrivals'] ?></b><span>Arrivals, next 7 days</span></div>
  <div class="metric"><b><?= h(money($tot['collected'])) ?></b><span>Collected</span></div>
  <div class="metric"><b><?= $accounts ?></b><span>Sign-ins</span></div>
</section>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No resorts yet. <a href="resorts.php">Add the first resort</a>.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Resort</th><th>Resort admin</th><th class="num">Rooms</th><th class="num" title="Average of public guest reviews">Rating</th><th class="num" title="Pending, confirmed and checked-in">Bookings</th><th class="num" title="Arrivals in the next 7 days">Arrivals</th><th class="num">Collected</th><th>Status</th><th class="right"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['name']) ?></strong><small class="muted-line"><?= h($r['location']) ?></small></td>
          <td class="wrap"><?= $r['admins'] ? h($r['admins']) : '<span class="muted">None yet</span>' ?></td>
          <td class="num"><?= (int)$r['rooms'] ?></td>
          <td class="num"><?= (int)$r['active_bookings'] ?></td>
          <td class="num"><?= (int)$r['arrivals'] ?></td>
          <td class="num"><?= h(money($r['collected'])) ?></td>
          <td><?= badge($r['is_active'] ? 'Open' : 'Hidden') ?></td>
          <td class="row-actions">
            <a class="btn sm" href="index.php?resort=<?= (int)$r['resort_id'] ?>">Manage</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Who can do what</h2></div>
  <table class="plain">
    <tbody>
      <tr><th scope="row">Super admin</th><td>Runs the site. Adds and edits resorts, creates admin accounts for any resort, and can open any resort’s desk.</td></tr>
      <tr><th scope="row">Resort admin</th><td>Runs one resort: reservations, rooms, buildings, pools, room types, services, prices, staff accounts and settings. Can’t see other resorts.</td></tr>
      <tr><th scope="row">Staff</th><td>Front desk for one resort: reservations, calendar, guests, and room status (for example Cleaning to Available).</td></tr>
    </tbody>
  </table>
</section>
<?php admin_end();
