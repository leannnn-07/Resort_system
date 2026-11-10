<?php
require __DIR__ . '/../includes/layout.php';
$staffMember = require_login($pdo);

$status = $_GET['status'] ?? '';
$q      = trim((string)($_GET['q'] ?? ''));

$where  = ['v.resort_id = ' . resort_id()];
$params = [];
if ($status !== '' && array_key_exists($status, RESERVATION_FLOW)) {
    $where[]  = 'v.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = "(CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR g.email LIKE ? OR v.booking_ref = ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = strtoupper($q);
}

$sql = "SELECT v.*, CONCAT(g.first_name, ' ', g.last_name) AS guest,
               " . ROOMS_LABEL_SQL . " AS rooms,
               (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
                  FROM payment p WHERE p.reservation_id = v.reservation_id) AS paid
          FROM reservation v JOIN guest g ON g.guest_id = v.guest_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY v.check_in_date DESC, v.reservation_id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_start('Reservations', 'reservations');
?>
<?php admin_head('Reservations', ($q !== '' || $status !== '') ? plural(count($rows), 'reservation') . ' match' . (count($rows) === 1 ? 'es' : '') : 'Most recent first', '<a class="btn primary" href="reservation_new.php">New reservation</a>'); ?>

<form method="get" class="filters">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search guest name, email or booking reference" aria-label="Search">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (array_keys(RESERVATION_FLOW) as $s): ?>
      <option<?= $s === $status ? ' selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="btn" href="reservations.php">Clear</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No reservations match. <a href="reservation_new.php">Create a reservation</a>.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Ref</th><th>Guest</th><th>Rooms</th><th class="num">Guests</th><th>Check-in</th><th>Check-out</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th class="right"><span class="sr-only">Open</span></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $balance = (float)$r['total_amount'] - (float)$r['paid']; ?>
        <tr>
          <td class="strong"><?= h($r['booking_ref']) ?></td>
          <td><?= h($r['guest']) ?></td>
          <td><?= h($r['rooms']) ?></td>
          <td class="num"><a href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>#guests" title="See guest names"><?= (int)$r['num_guests'] ?></a></td>
          <td class="nowrap"><?= h(date('M j, Y', strtotime($r['check_in_date']))) ?></td>
          <td class="nowrap"><?= h(date('M j, Y', strtotime($r['check_out_date']))) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="num"><?= h(money($r['total_amount'])) ?></td>
          <td class="num<?= $balance > 0.004 && $r['status'] !== 'Cancelled' ? ' due' : '' ?>"><?= h(money($balance)) ?></td>
          <td class="row-actions"><a class="btn sm" href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_end();
