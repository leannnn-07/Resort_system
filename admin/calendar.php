<?php
require_once __DIR__ . '/../includes/layout.php';
$staffMember = require_login($pdo);

const WINDOW_DAYS = 14;

$start = (string)($_GET['start'] ?? '');
if (!valid_date($start)) {
    $start = today();
}
$end   = add_days($start, WINDOW_DAYS);
$days  = [];
for ($i = 0; $i < WINDOW_DAYS; $i++) {
    $days[] = add_days($start, $i);
}

$rooms = $pdo->query(
    'SELECT r.room_id, r.room_number, r.status, t.type_name
       FROM room r JOIN room_type t ON t.room_type_id = r.room_type_id
      WHERE r.resort_id = ' . resort_id() . ' AND r.archived_at IS NULL AND t.archived_at IS NULL
      ORDER BY r.room_number'
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT rr.room_id, v.reservation_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, g.last_name
       FROM reservation_room rr
       JOIN reservation v ON v.reservation_id = rr.reservation_id
       JOIN guest g ON g.guest_id = v.guest_id
      WHERE v.status <> 'Cancelled'
        AND v.check_in_date < :end AND v.check_out_date > :start"
);
$stmt->execute(['end' => $end, 'start' => $start]);

// map[room_id][date] = booking (a night is booked from check-in up to, not including, check-out)
$map = [];
foreach ($stmt->fetchAll() as $b) {
    foreach ($days as $d) {
        if ($d >= $b['check_in_date'] && $d < $b['check_out_date']) {
            $map[$b['room_id']][$d] = $b;
        }
    }
}

// Day-use cottages get their own rows showing booked vs available units per day.
$cottages = $pdo->query("SELECT price_item_id, item_name, COALESCE(quantity_available, 5) AS qty_avail FROM price_item WHERE resort_id = " . resort_id() . " AND category = 'Cottage (day use)' AND archived_at IS NULL ORDER BY sort_order, item_name")->fetchAll();
$cStmt = $pdo->prepare(
    "SELECT rc.price_item_id, v.reservation_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, rc.quantity, g.last_name
       FROM reservation_cottage rc
       JOIN reservation v ON v.reservation_id = rc.reservation_id
       JOIN guest g ON g.guest_id = v.guest_id
      WHERE v.status <> 'Cancelled' AND v.resort_id = " . resort_id() . "
        AND v.check_in_date < :end AND v.check_out_date > :start
      ORDER BY v.reservation_id"
);
$cStmt->execute(['end' => $end, 'start' => $start]);
$cmap = [];
foreach ($cStmt->fetchAll() as $b) {
    foreach ($days as $d) {
        if ($d >= $b['check_in_date'] && $d < $b['check_out_date']) {
            $cmap[$b['price_item_id']][$d][] = $b;
        }
    }
}

// Pools (booked whole, by the day) get their own rows under the rooms.
$pools = $pdo->query('SELECT pool_id, name FROM pool WHERE resort_id = ' . resort_id() . ' AND archived_at IS NULL ORDER BY name')->fetchAll();
$stmt = $pdo->prepare(
    "SELECT rp.pool_id, v.reservation_id, v.booking_ref, v.check_in_date, v.check_out_date, v.status, g.last_name
       FROM reservation_pool rp
       JOIN reservation v ON v.reservation_id = rp.reservation_id
       JOIN guest g ON g.guest_id = v.guest_id
      WHERE v.status <> 'Cancelled' AND v.resort_id = " . resort_id() . "
        AND v.check_in_date < :end AND v.check_out_date > :start"
);
$stmt->execute(['end' => $end, 'start' => $start]);
$pmap = [];
foreach ($stmt->fetchAll() as $b) {
    foreach ($days as $d) {
        if ($d >= $b['check_in_date'] && $d < $b['check_out_date']) {
            $pmap[$b['pool_id']][$d] = $b;
        }
    }
}

/** One calendar row's day cells: free cells, and a merged cell for each booking. */
function cal_cells(array $days, array $rowMap): void
{
    $i = 0;
    $n = count($days);
    while ($i < $n) {
        $d = $days[$i];
        $b = $rowMap[$d] ?? null;
        if (!$b) {
            echo '<td class="free' . ($d === today() ? ' is-today' : '') . '"></td>';
            $i++;
            continue;
        }
        $span = 1;
        while ($i + $span < $n && (($rowMap[$days[$i + $span]]['reservation_id'] ?? null) === $b['reservation_id'])) {
            $span++;
        }
        echo '<td class="booked ' . h(strtolower($b['status'])) . '" colspan="' . $span . '">'
           . '<a href="reservation_view.php?id=' . (int)$b['reservation_id'] . '" title="' . h($b['booking_ref']) . '">' . h($b['last_name']) . '</a></td>';
        $i += $span;
    }
}

admin_start('Calendar', 'calendar');
?>
<?php admin_head('Calendar', fdate($start) . ' onwards',
    '<a class="btn" href="calendar.php?start=' . h(add_days($start, -WINDOW_DAYS)) . '">Previous 2 weeks</a>'
  . '<a class="btn" href="calendar.php">Today</a>'
  . '<a class="btn" href="calendar.php?start=' . h(add_days($start, WINDOW_DAYS)) . '">Next 2 weeks</a>'); ?>

<p class="muted legend">
  <span class="key pending"></span> Pending
  <span class="key confirmed"></span> Confirmed
  <span class="key checked-in"></span> Checked in
  <span class="key checked-out"></span> Checked out
</p>

<section class="panel flush">
  <div class="table-scroll">
    <table class="cal">
      <thead>
        <tr>
          <th class="sticky">Room</th>
          <?php foreach ($days as $d): ?>
            <th class="<?= $d === today() ? 'is-today' : '' ?>"><?= h(date('D', strtotime($d))) ?><br><?= h(date('M j', strtotime($d))) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rooms as $room): ?>
        <tr>
          <th class="sticky"><?= h($room['room_number']) ?> <span class="muted"><?= h($room['type_name']) ?></span>
            <?php if ($room['status'] === 'Maintenance'): ?><?= badge('Maintenance') ?><?php endif; ?></th>
          <?php cal_cells($days, $map[$room['room_id']] ?? []); ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($cottages): ?>
        <tr class="cal-divider"><th class="sticky" colspan="<?= WINDOW_DAYS + 1 ?>">Day-use cottages</th></tr>
        <?php foreach ($cottages as $cot): $avail = max(1, (int)$cot['qty_avail']); ?>
          <tr>
            <th class="sticky"><?= h($cot['item_name']) ?> <span class="muted">(<?= $avail ?> units)</span></th>
            <?php foreach ($days as $d):
                $list = $cmap[$cot['price_item_id']][$d] ?? [];
                if (!$list): ?>
                  <td class="free<?= $d === today() ? ' is-today' : '' ?>"></td>
                <?php else:
                    $qty = array_sum(array_map(fn($x) => (int)$x['quantity'], $list));
                    $first = $list[0];
                    $cls = strtolower((string)$first['status']);
                    $lbl = count($list) === 1 ? $first['last_name'] : ($qty . '/' . $avail . ' booked');
                    $href = count($list) === 1 ? ('reservation_view.php?id=' . (int)$first['reservation_id']) : 'reservations.php';
                    $tip = implode(', ', array_map(fn($x) => $x['last_name'] . ' (' . $x['booking_ref'] . ')', $list));
                ?>
                  <td class="booked <?= h($cls) ?>"><a href="<?= h($href) ?>" title="<?= h($tip) ?>"><?= h($lbl) ?></a></td>
                <?php endif;
            endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($pools): ?>
        <tr class="cal-divider"><th class="sticky" colspan="<?= WINDOW_DAYS + 1 ?>">Pools</th></tr>
        <?php foreach ($pools as $pool): ?>
          <tr>
            <th class="sticky"><?= h($pool['name']) ?></th>
            <?php cal_cells($days, $pmap[$pool['pool_id']] ?? []); ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php admin_end();
