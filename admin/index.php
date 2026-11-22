<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payments.php';
$staffMember = require_login($pdo);

$today = today();
$first = explode(' ', trim($staffMember['full_name']))[0] ?? 'there';

/** Smooth SVG path (Catmull-Rom converted to cubic curves). */
function smooth_path(array $pts): string
{
    $n = count($pts);
    $d = sprintf('M%.1f,%.1f', $pts[0][0], $pts[0][1]);
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max($i - 1, 0)];
        $p1 = $pts[$i];
        $p2 = $pts[$i + 1];
        $p3 = $pts[min($i + 2, $n - 1)];
        $d .= sprintf(
            ' C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
            $p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6,
            $p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6,
            $p2[0], $p2[1]
        );
    }
    return $d;
}

// ---------- Numbers ----------
$RID = resort_id();
$totalRooms = (int)$pdo->query("SELECT COUNT(*) FROM room WHERE status <> 'Maintenance' AND archived_at IS NULL AND resort_id = $RID")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT rr.room_id)
       FROM reservation_room rr JOIN reservation v ON v.reservation_id = rr.reservation_id
       JOIN room rm ON rm.room_id = rr.room_id AND rm.status <> 'Maintenance'
      WHERE v.status IN ('Confirmed','Checked-in') AND v.resort_id = $RID
        AND v.check_in_date <= :d AND v.check_out_date > :d2"
);
$stmt->execute(['d' => $today, 'd2' => $today]);
$occupied = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE resort_id = $RID AND check_in_date = ? AND status IN ('Pending','Confirmed')");
$stmt->execute([$today]);
$arrivals = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE resort_id = $RID AND check_out_date = ? AND status = 'Checked-in'");
$stmt->execute([$today]);
$departures = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE resort_id = $RID AND status = 'Pending' AND check_out_date >= ?");
$stmt->execute([$today]);
$pendingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
       FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
      WHERE v.resort_id = $RID AND p.payment_date >= ?"
);
$stmt->execute([date('Y-m-01')]);
$collected = (float)$stmt->fetchColumn();

// ---------- Occupancy for the next 14 days ----------
const DAYS = 14;
$stmt = $pdo->prepare(
    "SELECT v.check_in_date, v.check_out_date
       FROM reservation_room rr JOIN reservation v ON v.reservation_id = rr.reservation_id
       JOIN room rm ON rm.room_id = rr.room_id AND rm.status <> 'Maintenance'
      WHERE v.status <> 'Cancelled' AND v.resort_id = $RID AND v.check_in_date < :e AND v.check_out_date > :s"
);
$stmt->execute(['e' => add_days($today, DAYS), 's' => $today]);
$occ = array_fill(0, DAYS, 0);
foreach ($stmt->fetchAll() as $b) {
    for ($i = 0; $i < DAYS; $i++) {
        $d = add_days($today, $i);
        if ($d >= $b['check_in_date'] && $d < $b['check_out_date']) {
            $occ[$i]++;
        }
    }
}
$W = 320; $H = 150; $padL = 26; $padR = 12; $padT = 16; $padB = 26;
$yMax = max($totalRooms, max($occ), 1);
$pts = [];
for ($i = 0; $i < DAYS; $i++) {
    $pts[] = [
        $padL + ($W - $padL - $padR) * $i / (DAYS - 1),
        $padT + ($H - $padT - $padB) * (1 - $occ[$i] / $yMax),
    ];
}
$peak   = array_search(max($occ), $occ, true);
$linePath = smooth_path($pts);
$baseY    = $H - $padB;
$areaPath = $linePath . sprintf(' L%.1f,%.1f L%.1f,%.1f Z', $pts[DAYS - 1][0], $baseY, $pts[0][0], $baseY);
$bubbleX  = min(max($pts[$peak][0] - 14, 0), $W - 28);
$bubbleY  = $pts[$peak][1] < 38 ? $pts[$peak][1] + 10 : $pts[$peak][1] - 30;

// ---------- Latest bookings (for the money card) ----------
$recent = $pdo->query(
    "SELECT v.reservation_id, v.booking_ref, v.total_amount, v.booking_scope, v.check_in_date, v.check_out_date,
            CONCAT(g.last_name, ', ', g.first_name) AS guest,
            " . ROOMS_LABEL_SQL . " AS what,
            (SELECT COUNT(*) FROM reservation_room rr WHERE rr.reservation_id = v.reservation_id AND rr.rate_applied > 0) AS room_count
       FROM reservation v JOIN guest g ON g.guest_id = v.guest_id
      WHERE v.resort_id = $RID
      ORDER BY v.reservation_id DESC LIMIT 8"
)->fetchAll();
$latest = $recent[0] ?? null;
$spark  = '';
if (count($recent) >= 2) {
    $vals = array_reverse(array_map(fn($r) => (float)$r['total_amount'], $recent));
    $lo = min($vals); $hi = max($vals); $range = max($hi - $lo, 1);
    $sp = [];
    foreach ($vals as $i => $v) {
        $sp[] = [4 + 112 * $i / (count($vals) - 1), 30 - 24 * (($v - $lo) / $range)];
    }
    $spark = smooth_path($sp);
}

// ---------- Lists ----------
$listSql = "SELECT v.reservation_id, v.booking_ref, v.booking_scope, v.check_in_date, v.check_out_date, v.status, v.num_guests, v.source, v.total_amount,
                   COALESCE((SELECT SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END) FROM payment p WHERE p.reservation_id = v.reservation_id), 0) AS paid,
                   CONCAT(g.last_name, ', ', g.first_name) AS guest,
                   " . ROOMS_LABEL_SQL . " AS rooms
              FROM reservation v JOIN guest g ON g.guest_id = v.guest_id AND v.resort_id = $RID ";

$stmt = $pdo->prepare($listSql . "WHERE v.status = 'Pending' AND v.check_out_date >= ? ORDER BY v.check_in_date LIMIT 10");
$stmt->execute([$today]);
$pending = $stmt->fetchAll();

$stmt = $pdo->prepare(
    $listSql . "WHERE v.status IN ('Confirmed','Checked-in')
                  AND v.check_out_date >= :today AND v.check_in_date <= :limit
                ORDER BY v.check_in_date, v.reservation_id"
);
$stmt->execute(['today' => $today, 'limit' => add_days($today, 7)]);
$upcoming = $stmt->fetchAll();

/** Renders a list of reservations as rounded rows. */
function reservation_rows(array $rows, string $lastLabel): void
{
    ?>
    <div class="pill-cols"><span>Guest</span><span>Stay</span><span>Guests</span><span><?= h($lastLabel) ?></span></div>
    <div class="pill-rows">
    <?php foreach ($rows as $r):
        $isCot = ($r['booking_scope'] ?? '') === 'Cottage';
        $stayLabel = ($r['booking_scope'] ?? 'Rooms') === 'Rooms' ? ('Room ' . $r['rooms']) : (string)$r['rooms'];
        $dateLabel = $isCot
            ? (date('M j', strtotime($r['check_in_date'])) . ' (Day use)')
            : (date('M j', strtotime($r['check_in_date'])) . ' to ' . date('M j', strtotime($r['check_out_date'])));
    ?>
      <a class="pill-row" href="reservation_view.php?id=<?= (int)$r['reservation_id'] ?>">
        <span class="avatar"><?= h(initials($r['guest'])) ?></span>
        <span class="pr-main"><b><?= h($r['guest']) ?></b><small><?= h($stayLabel) ?> · <?= h($r['booking_ref']) ?></small></span>
        <span class="pr-dates"><?= h($dateLabel) ?></span>
        <span class="pr-meta"><?= (int)$r['num_guests'] ?></span>
        <?= reservation_status_badge($r, (float)($r['paid'] ?? 0)) ?>
        <span class="pr-go"><?= icon('arrow', 16) ?></span>
      </a>
    <?php endforeach; ?>
    </div>
    <?php
}

admin_start('Dashboard', 'index');
?>
<?php admin_head('Dashboard', date('l, F j')); ?>

<div class="dash-grid">
  <section class="card hero-card" aria-labelledby="hero-h">
    <div>
      <p class="eyebrow">Today at <?= h($config['resort']) ?></p>
      <h2 class="big" id="hero-h">Welcome back,<br><?= h($first) ?></h2>
      <p class="muted"><?= $occupied ?> of <?= $totalRooms ?> rooms are occupied tonight.</p>
      <a class="btn primary" href="reservation_new.php">New reservation</a>
    </div>
    <div class="metric-strip" aria-label="Today at a glance">
      <a class="metric" href="reservations.php?filter=arrivals"><b><?= $arrivals ?></b><span><i class="dot lime"></i>Arrivals</span></a>
      <a class="metric" href="reservations.php?filter=departures"><b><?= $departures ?></b><span><i class="dot gold"></i>Departures</span></a>
      <a class="metric" href="reservations.php?status=Pending"><b><?= $pendingCount ?></b><span><i class="dot orange"></i>To confirm</span></a>
      <a class="metric" href="records.php?status=Paid&amp;period=month"><b><?= h($config['currency'] . number_format($collected)) ?></b><span><i class="dot pink"></i>Collected</span></a>
      <a class="round" href="reservations.php" aria-label="All reservations"><?= icon('arrow', 18) ?></a>
    </div>
  </section>

  <section class="card chart" aria-label="Rooms occupied over the next 14 days">
    <h2>Rooms occupied, next 14 days</h2>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Line chart of occupied rooms per night">
      <defs>
        <linearGradient id="occFill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#0b7f97" stop-opacity="0.22"/>
          <stop offset="1" stop-color="#0b7f97" stop-opacity="0"/>
        </linearGradient>
      </defs>
      <?php foreach ([0, 0.5, 1] as $f):
          $gy = $padT + ($H - $padT - $padB) * (1 - $f); ?>
        <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($gy, 1) ?>" y2="<?= round($gy, 1) ?>" stroke="rgba(15,46,72,0.1)" stroke-dasharray="3 4"/>
        <text class="chart-t" x="<?= $padL - 6 ?>" y="<?= round($gy + 3, 1) ?>" text-anchor="end"><?= (int)round($yMax * $f) ?></text>
      <?php endforeach; ?>
      <path d="<?= $areaPath ?>" fill="url(#occFill)"/>
      <path d="<?= $linePath ?>" fill="none" stroke="#0b7f97" stroke-width="2.2" stroke-linecap="round"/>
      <circle cx="<?= round($pts[$peak][0], 1) ?>" cy="<?= round($pts[$peak][1], 1) ?>" r="4" fill="#ffffff" stroke="#0b7f97" stroke-width="2.2"/>
      <rect x="<?= round($bubbleX, 1) ?>" y="<?= round($bubbleY, 1) ?>" width="28" height="19" rx="6" fill="#0f2e48"/>
      <text x="<?= round($bubbleX + 14, 1) ?>" y="<?= round($bubbleY + 13, 1) ?>" text-anchor="middle" font-size="11" font-weight="700" fill="#ffffff"><?= (int)$occ[$peak] ?></text>
      <text class="chart-t" x="<?= $padL ?>" y="<?= $H - 6 ?>"><?= h(date('M j', strtotime($today))) ?></text>
      <text class="chart-t" x="<?= round(($padL + $W - $padR) / 2) ?>" y="<?= $H - 6 ?>" text-anchor="middle"><?= h(date('M j', strtotime(add_days($today, 7)))) ?></text>
      <text class="chart-t" x="<?= $W - $padR ?>" y="<?= $H - 6 ?>" text-anchor="end"><?= h(date('M j', strtotime(add_days($today, DAYS - 1)))) ?></text>
    </svg>
  </section>

  <section class="card" aria-label="Latest booking">
    <h2>Latest booking</h2>
    <?php if ($latest): ?>
      <a class="latest" href="reservation_view.php?id=<?= (int)$latest['reservation_id'] ?>" style="text-decoration:none;color:inherit">
        <div>
          <div class="big-num"><?= h(money($latest['total_amount'])) ?></div>
          <div class="latest-who">
            <span class="avatar"><?= h(initials($latest['guest'])) ?></span>
            <span><?= h($latest['guest']) ?><br><?= h($latest['booking_scope'] === 'Rooms' ? plural((int)$latest['room_count'], 'room') : $latest['what']) ?>, <?= $latest['booking_scope'] === 'Cottage' ? h(date('M j', strtotime($latest['check_in_date']))) . ' (Day use)' : h(date('M j', strtotime($latest['check_in_date']))) . ' to ' . h(date('M j', strtotime($latest['check_out_date']))) ?></span>
          </div>
        </div>
        <?php if ($spark): ?>
          <svg viewBox="0 0 120 36" role="img" aria-label="Recent booking totals"><path d="<?= $spark ?>" fill="none" stroke="#0b7f97" stroke-width="2.2" stroke-linecap="round"/></svg>
        <?php endif; ?>
      </a>
    <?php else: ?>
      <p class="muted">No bookings yet.</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($pending): ?>
<section class="card list-card" style="margin-bottom:12px">
  <div class="list-head"><h2>Needs confirmation</h2><span class="muted"><?= count($pending) ?> waiting</span></div>
  <?php reservation_rows($pending, 'Status'); ?>
</section>
<?php endif; ?>

<section class="card list-card">
  <div class="list-head"><h2>Stays in the next 7 days</h2><a href="reservations.php">All reservations</a></div>
  <?php if (!$upcoming): ?>
    <p class="empty">No confirmed stays coming up.</p>
  <?php else: reservation_rows($upcoming, 'Status'); endif; ?>
</section>
<?php admin_end();
