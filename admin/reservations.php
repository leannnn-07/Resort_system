<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payments.php';
$staffMember = require_login($pdo);

$status = $_GET['status'] ?? '';
$filter = (string)($_GET['filter'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));

$where  = ['v.resort_id = ' . resort_id()];
$params = [];
if ($filter === 'arrivals') {
    $where[]  = "v.check_in_date = ? AND v.status IN ('Pending','Confirmed')";
    $params[] = today();
} elseif ($filter === 'departures') {
    $where[]  = "v.check_out_date = ? AND v.status = 'Checked-in'";
    $params[] = today();
} else {
    $filter = '';
}
if ($status !== '' && array_key_exists($status, RESERVATION_FLOW)) {
    $where[]  = 'v.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = "(CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR CONCAT(g.last_name, ', ', g.first_name) LIKE ? OR g.email LIKE ? OR v.booking_ref = ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = strtoupper($q);
}

$sql = "SELECT v.*, CONCAT(g.last_name, ', ', g.first_name) AS guest,
               " . ROOMS_LABEL_SQL . " AS rooms,
               (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
                  FROM payment p WHERE p.reservation_id = v.reservation_id) AS paid,
               (SELECT COUNT(*) FROM payment p WHERE p.reservation_id = v.reservation_id AND p.payment_status = 'Pending') AS pending_payments
          FROM reservation v JOIN guest g ON g.guest_id = v.guest_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY v.check_in_date DESC, v.reservation_id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_start('Reservations', 'reservations');
?>
<?php
$subLabel = $filter === 'arrivals'
    ? 'Today’s arrivals · ' . plural(count($rows), 'reservation')
    : ($filter === 'departures'
        ? 'Today’s departures · ' . plural(count($rows), 'reservation')
        : (($q !== '' || $status !== '') ? plural(count($rows), 'reservation') . ' match' . (count($rows) === 1 ? 'es' : '') : ''));
admin_head('Reservations', $subLabel, '<a class="btn primary" href="reservation_new.php">New reservation</a>'); ?>

<?= admin_quick_book_bar() ?>

<form method="get" class="filters">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search guest name, email or booking reference" aria-label="Search">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (array_keys(RESERVATION_FLOW) as $s): ?>
      <option<?= $s === $status ? ' selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== '' || $status !== '' || $filter !== ''): ?><a class="btn" href="reservations.php">Clear</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No reservations match. <a href="reservation_new.php">Create a reservation</a>.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Ref</th><th>Guest</th><th>Cottage / Rooms</th><th class="num">Guests</th><th>Check-in</th><th>Check-out</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th class="right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $balance = (float)$r['total_amount'] - (float)$r['paid'];
          $pendPay = (int)($r['pending_payments'] ?? 0);
          $awaitPay = $pendPay === 0 && $r['status'] === 'Pending' && ($r['source'] ?? '') === 'Online'
                   && (float)($r['required_downpayment'] ?? 0) > (float)$r['paid'] + 0.004;
      ?>
        <tr>
          <td class="strong nowrap"><?= h($r['booking_ref']) ?></td>
          <td><?= h($r['guest']) ?></td>
          <td><?= h($r['rooms']) ?></td>
          <td class="num"><a href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>#guests" title="See guest names"><?= (int)$r['num_guests'] ?></a></td>
          <td class="nowrap"><?= h(date('M j, Y', strtotime($r['check_in_date']))) ?></td>
          <td class="nowrap"><?= ($r['booking_scope'] ?? '') === 'Cottage' ? '<span class="muted">Day use</span>' : h(date('M j, Y', strtotime($r['check_out_date']))) ?></td>
          <td><?= reservation_status_badge($r, (float)$r['paid']) ?><?php if (($r['discount_status'] ?? '') === 'Pending'): ?><span class="muted-line"><a href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>">Approve discount (<?= h((string)($r['discount_type'] ?: 'Discount')) ?>)</a></span><?php endif; ?><?php if ($pendPay > 0): ?><span class="muted-line"><a href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>#payments">Verify reference (<?= $pendPay ?>)</a></span><?php elseif ($awaitPay): ?><span class="muted-line">Downpayment unpaid</span><?php endif; ?></td>
          <td class="num"><?= h(money($r['total_amount'])) ?></td>
          <td class="num<?= $balance > 0.004 && $r['status'] !== 'Cancelled' ? ' due' : '' ?>"><?= h(money($balance)) ?></td>
          <td class="row-actions"><div class="act-group"><a class="btn sm" href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>">Open</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_end();
