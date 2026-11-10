<?php
require __DIR__ . '/../includes/layout.php';
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
      WHERE r.resort_id = ' . resort_id() . '
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

// Pools (booked whole, by the day) get their own rows under the rooms.
$pools = $pdo->query('SELECT pool_id, name FROM pool WHERE resort_id = ' . resort_id() . ' ORDER BY name')->fetchAll();
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
<?php admin_head('Calendar', 'Rooms and pools by night, ' . fdate($start) . ' onwards',
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
<p class="muted">Each night is shown from the check-in day up to the day before check-out. Click a booking to open it.</p>
<?php admin_end();
