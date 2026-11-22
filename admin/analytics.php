<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payments.php';

$staffMember = require_login($pdo, true);   // super admin only

$ranges = [
    'all'  => 'All time',
    '7d'   => 'Last 7 days',
    '30d'  => 'Last 30 days',
    '90d'  => 'Last 90 days',
    'year' => 'This year',
];
$range = array_key_exists((string)($_GET['range'] ?? ''), $ranges) ? (string)$_GET['range'] : 'all';

$allResorts = $pdo->query(
    "SELECT r.resort_id, r.name, r.slug, r.location, r.hero_image, r.is_active,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id AND x.archived_at IS NULL) AS room_count,
            (SELECT COUNT(*) FROM building b WHERE b.resort_id = r.resort_id AND b.archived_at IS NULL) AS bldg_count,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id AND p.archived_at IS NULL) AS pool_count
       FROM resort r
      WHERE r.archived_at IS NULL
      ORDER BY r.sort_order, r.name"
)->fetchAll();
$resortsById = [];
foreach ($allResorts as $ar) {
    $resortsById[(int)$ar['resort_id']] = $ar;
}

// Dropdown-driven mode: resort_id = 0 -> 'all' (platform-wide), resort_id > 0 -> 'resort' (single resort).
$reqRid       = (int)($_GET['resort_id'] ?? 0);
$resortFilter = ($reqRid > 0 && isset($resortsById[$reqRid])) ? $reqRid : 0;
$activeResort = $resortFilter > 0 ? $resortsById[$resortFilter] : null;
$view         = $activeResort ? 'resort' : 'all';

$today = today();
$sinceDate = match ($range) {
    '7d'   => add_days($today, -6),
    '30d'  => add_days($today, -29),
    '90d'  => add_days($today, -89),
    'year' => date('Y-01-01', strtotime($today)),
    default => null,
};

$resWhere = [];
$resParams = [];
if ($sinceDate !== null) {
    $resWhere[] = 'DATE(v.created_at) >= :since';
    $resParams['since'] = $sinceDate;
}
if ($resortFilter > 0) {
    $resWhere[] = 'v.resort_id = :rid';
    $resParams['rid'] = $resortFilter;
}
$resSqlWhere = $resWhere ? 'WHERE ' . implode(' AND ', $resWhere) : '';

$payWhere = [];
$payParams = [];
if ($sinceDate !== null) {
    $payWhere[] = 'p.payment_date >= :since';
    $payParams['since'] = $sinceDate;
}
if ($resortFilter > 0) {
    $payWhere[] = 'v.resort_id = :rid';
    $payParams['rid'] = $resortFilter;
}
$paySqlWhere = $payWhere ? 'WHERE ' . implode(' AND ', $payWhere) : '';

$revWhere = ["w.status = 'Published'"];
$revParams = [];
if ($sinceDate !== null) {
    $revWhere[] = 'DATE(w.created_at) >= :since';
    $revParams['since'] = $sinceDate;
}
if ($resortFilter > 0) {
    $revWhere[] = 'w.resort_id = :rid';
    $revParams['rid'] = $resortFilter;
}
$revSqlWhere = 'WHERE ' . implode(' AND ', $revWhere);

// 1. Headline KPIs
$st = $pdo->prepare(
    "SELECT COUNT(*) AS total_bookings,
            SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END) AS gross_value,
            SUM(CASE WHEN v.status <> 'Cancelled' THEN 1 ELSE 0 END) AS active_or_done,
            SUM(CASE WHEN v.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN v.status <> 'Cancelled' THEN v.num_guests ELSE 0 END) AS total_guests
       FROM reservation v $resSqlWhere"
);
$st->execute($resParams);
$kpi = $st->fetch() ?: [];

$totalBookings  = (int)($kpi['total_bookings'] ?? 0);
$grossValue     = (float)($kpi['gross_value'] ?? 0);
$nonCancelled   = (int)($kpi['active_or_done'] ?? 0);
$cancelledCount = (int)($kpi['cancelled_count'] ?? 0);
$totalGuests    = (int)($kpi['total_guests'] ?? 0);
$avgBookingVal  = $nonCancelled > 0 ? ($grossValue / $nonCancelled) : 0.0;
$cancelRate     = $totalBookings > 0 ? round(($cancelledCount / $totalBookings) * 100, 1) : 0.0;

$platformSharePct = null;
if ($view === 'resort') {
    $pWhere = $sinceDate !== null ? 'WHERE DATE(v.created_at) >= :since' : '';
    $pst = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END), 0) FROM reservation v $pWhere");
    $pst->execute($sinceDate !== null ? ['since' => $sinceDate] : []);
    $platGross = (float)$pst->fetchColumn();
    $platformSharePct = $platGross > 0 ? round(($grossValue / $platGross) * 100, 1) : 0.0;
}

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0) AS collected,
            COALESCE(SUM(CASE WHEN p.payment_status = 'Pending' THEN p.amount ELSE 0 END), 0) AS pending_amt,
            SUM(CASE WHEN p.payment_status = 'Pending' THEN 1 ELSE 0 END) AS pending_cnt
       FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
      $paySqlWhere"
);
$st->execute($payParams);
$payKpi = $st->fetch() ?: [];
$collectedTotal = (float)($payKpi['collected'] ?? 0);
$pendingPayAmt  = (float)($payKpi['pending_amt'] ?? 0);
$pendingPayCnt  = (int)($payKpi['pending_cnt'] ?? 0);
$collectionPct  = $grossValue > 0 ? min(100, round(($collectedTotal / $grossValue) * 100, 1)) : 0.0;

$st = $pdo->prepare("SELECT ROUND(AVG(w.rating), 2) AS avg_r, COUNT(*) AS cnt FROM review w $revSqlWhere");
$st->execute($revParams);
$revKpi = $st->fetch() ?: [];
$avgRating   = $revKpi['avg_r'] !== null ? (float)$revKpi['avg_r'] : null;
$reviewCount = (int)($revKpi['cnt'] ?? 0);

// 2. Trend series
$st = $pdo->prepare(
    "SELECT DATE(v.created_at) AS d,
            COUNT(*) AS bookings,
            SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END) AS booked_amt
       FROM reservation v $resSqlWhere
      GROUP BY DATE(v.created_at)
      ORDER BY d ASC"
);
$st->execute($resParams);
$dailyRes = [];
foreach ($st->fetchAll() as $row) {
    $dailyRes[$row['d']] = ['bookings' => (int)$row['bookings'], 'booked' => (float)$row['booked_amt']];
}

$st = $pdo->prepare(
    "SELECT p.payment_date AS d,
            COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0) AS paid_amt
       FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
      $paySqlWhere
      GROUP BY p.payment_date
      ORDER BY d ASC"
);
$st->execute($payParams);
$dailyPay = [];
foreach ($st->fetchAll() as $row) {
    $dailyPay[$row['d']] = (float)$row['paid_amt'];
}

$allDates = array_unique(array_merge(array_keys($dailyRes), array_keys($dailyPay)));
sort($allDates);
$useMonthly = false;
if ($range === 'year' || $range === '90d') {
    $useMonthly = true;
} elseif ($range === 'all' && count($allDates) >= 2) {
    $spanDays = (strtotime(end($allDates)) - strtotime($allDates[0])) / 86400;
    $useMonthly = $spanDays > 45;
}

$trendPoints = [];
if ($useMonthly) {
    $startYm = $sinceDate ? substr($sinceDate, 0, 7) : ($allDates ? substr($allDates[0], 0, 7) : date('Y-m', strtotime('-5 months', strtotime($today))));
    $endYm   = substr($today, 0, 7);
    if ($allDates && substr(end($allDates), 0, 7) > $endYm) {
        $endYm = substr(end($allDates), 0, 7);
    }
    $cur = new DateTime($startYm . '-01');
    $end = new DateTime($endYm . '-01');
    while ($cur <= $end) {
        $ym = $cur->format('Y-m');
        $trendPoints[$ym] = ['label' => $cur->format('M Y'), 'short' => $cur->format('M'), 'bookings' => 0, 'booked' => 0.0, 'collected' => 0.0];
        $cur->modify('+1 month');
    }
    foreach ($dailyRes as $d => $val) {
        $ym = substr($d, 0, 7);
        if (isset($trendPoints[$ym])) {
            $trendPoints[$ym]['bookings'] += $val['bookings'];
            $trendPoints[$ym]['booked']   += $val['booked'];
        }
    }
    foreach ($dailyPay as $d => $amt) {
        $ym = substr($d, 0, 7);
        if (isset($trendPoints[$ym])) {
            $trendPoints[$ym]['collected'] += $amt;
        }
    }
    $trendPoints = array_values($trendPoints);
} else {
    $daysWindow = $range === '7d' ? 7 : ($range === '30d' ? 30 : 14);
    $endDay = $today;
    if ($allDates && end($allDates) > $endDay) {
        $endDay = end($allDates);
    }
    $startDay = $sinceDate ?? add_days($endDay, -($daysWindow - 1));
    if ($range === 'all' && $allDates && $allDates[0] < $startDay) {
        $span = (int)round((strtotime($endDay) - strtotime($allDates[0])) / 86400);
        if ($span <= 31) {
            $startDay = $allDates[0];
        }
    }
    $cur = new DateTime($startDay);
    $end = new DateTime($endDay);
    while ($cur <= $end) {
        $d = $cur->format('Y-m-d');
        $trendPoints[] = [
            'label'     => $cur->format('M j, Y'),
            'short'     => $cur->format('M j'),
            'bookings'  => $dailyRes[$d]['bookings'] ?? 0,
            'booked'    => $dailyRes[$d]['booked'] ?? 0.0,
            'collected' => $dailyPay[$d] ?? 0.0,
        ];
        $cur->modify('+1 day');
    }
}

// 3. Booking Status Breakdown
$st = $pdo->prepare(
    "SELECT v.status, COUNT(*) AS cnt, COALESCE(SUM(v.total_amount), 0) AS amt
       FROM reservation v $resSqlWhere
      GROUP BY v.status"
);
$st->execute($resParams);
$statusRaw = [];
foreach ($st->fetchAll() as $row) {
    $statusRaw[$row['status']] = ['cnt' => (int)$row['cnt'], 'amt' => (float)$row['amt']];
}
$statusMeta = [
    'Confirmed'   => ['label' => 'Confirmed',   'color' => '#0b7f97'],
    'Checked-in'  => ['label' => 'Checked-in',  'color' => '#0f766e'],
    'Pending'     => ['label' => 'Pending',     'color' => '#d97706'],
    'Checked-out' => ['label' => 'Checked-out', 'color' => '#64748b'],
    'Cancelled'   => ['label' => 'Cancelled',   'color' => '#dc2626'],
];
$statusSeries = [];
foreach ($statusMeta as $k => $m) {
    $statusSeries[] = [
        'key'   => $k,
        'label' => $m['label'],
        'color' => $m['color'],
        'cnt'   => $statusRaw[$k]['cnt'] ?? 0,
        'amt'   => $statusRaw[$k]['amt'] ?? 0.0,
    ];
}

// 4. Booking Scope Breakdown (Inverted-Triangle Order)
$st = $pdo->prepare(
    "SELECT v.booking_scope, COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END), 0) AS amt
       FROM reservation v $resSqlWhere
      GROUP BY v.booking_scope"
);
$st->execute($resParams);
$scopeRaw = [];
foreach ($st->fetchAll() as $row) {
    $scopeRaw[$row['booking_scope']] = ['cnt' => (int)$row['cnt'], 'amt' => (float)$row['amt']];
}
$scopeOrder = [
    'Resort'   => ['label' => 'Entire resort',    'color' => '#0f2e48'],
    'Building' => ['label' => 'Whole building',   'color' => '#1d4ed8'],
    'Pool'     => ['label' => 'Entire pool',      'color' => '#0b7f97'],
    'Rooms'    => ['label' => 'Overnight rooms',  'color' => '#0f766e'],
    'Cottage'  => ['label' => 'Day-use cottages', 'color' => '#d97706'],
];
$scopeSeries = [];
$maxScopeAmt = 1.0;
foreach ($scopeOrder as $k => $m) {
    $cnt = $scopeRaw[$k]['cnt'] ?? 0;
    $amt = $scopeRaw[$k]['amt'] ?? 0.0;
    if ($amt > $maxScopeAmt) $maxScopeAmt = $amt;
    $scopeSeries[] = ['key' => $k, 'label' => $m['label'], 'color' => $m['color'], 'cnt' => $cnt, 'amt' => $amt];
}

// 5. Payment Methods Breakdown
$st = $pdo->prepare(
    "SELECT p.method,
            COUNT(*) AS cnt,
            COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0) AS net_amt
       FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
      $paySqlWhere
      GROUP BY p.method
      ORDER BY net_amt DESC, cnt DESC"
);
$st->execute($payParams);
$methodColors = [
    'GCash'         => '#007dfe',
    'Maya'          => '#0f766e',
    'Cash'          => '#0f2e48',
    'Card'          => '#6366f1',
    'Bank Transfer' => '#d97706',
];
$methodSeries = [];
$methodTotalNet = 0.0;
foreach ($st->fetchAll() as $row) {
    $net = max(0.0, (float)$row['net_amt']);
    $methodTotalNet += $net;
    $methodSeries[] = [
        'label' => (string)$row['method'],
        'cnt'   => (int)$row['cnt'],
        'amt'   => $net,
        'color' => $methodColors[$row['method']] ?? '#64748b',
    ];
}

// 6. Rating Distribution
$st = $pdo->prepare("SELECT w.rating, COUNT(*) AS cnt FROM review w $revSqlWhere GROUP BY w.rating");
$st->execute($revParams);
$ratingRaw = [];
foreach ($st->fetchAll() as $row) {
    $ratingRaw[(int)$row['rating']] = (int)$row['cnt'];
}

// 7. Mode-specific datasets
$resortPerf = [];
$maxResortGross = 1.0;
$topUnits = [];
$maxUnitAmt = 1.0;
$recentBookings = [];

if ($view === 'all') {
    $rpParams = [];
    $condV1 = $condV2 = $condV3 = $condP1 = $condW1 = $condW2 = '';
    if ($sinceDate !== null) {
        $condV1 = ' AND DATE(v.created_at) >= :sv1';
        $condV2 = ' AND DATE(v.created_at) >= :sv2';
        $condV3 = ' AND DATE(v.created_at) >= :sv3';
        $condP1 = ' AND p.payment_date >= :sp1';
        $condW1 = ' AND DATE(w.created_at) >= :sw1';
        $condW2 = ' AND DATE(w.created_at) >= :sw2';
        $rpParams += ['sv1' => $sinceDate, 'sv2' => $sinceDate, 'sv3' => $sinceDate, 'sp1' => $sinceDate, 'sw1' => $sinceDate, 'sw2' => $sinceDate];
    }
    $st = $pdo->prepare(
        "SELECT r.resort_id, r.name, r.slug, r.location, r.hero_image, r.is_active,
                (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id $condV1) AS bookings,
                (SELECT COALESCE(SUM(CASE WHEN v.status <> 'Cancelled' THEN v.num_guests ELSE 0 END), 0)
                   FROM reservation v WHERE v.resort_id = r.resort_id $condV2) AS guests,
                (SELECT COALESCE(SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END), 0)
                   FROM reservation v WHERE v.resort_id = r.resort_id $condV3) AS gross_amt,
                (SELECT COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0)
                   FROM payment p JOIN reservation v ON v.reservation_id = p.reservation_id
                  WHERE v.resort_id = r.resort_id $condP1) AS collected_amt,
                (SELECT ROUND(AVG(w.rating), 1) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published' $condW1) AS avg_rating,
                (SELECT COUNT(*) FROM review w WHERE w.resort_id = r.resort_id AND w.status = 'Published' $condW2) AS reviews
           FROM resort r
          WHERE r.archived_at IS NULL
          ORDER BY gross_amt DESC, collected_amt DESC, r.name ASC"
    );
    $st->execute($rpParams);
    $resortPerf = $st->fetchAll();
    foreach ($resortPerf as $rp) {
        $g = max((float)$rp['gross_amt'], (float)$rp['collected_amt']);
        if ($g > $maxResortGross) $maxResortGross = $g;
    }
} else {
    $st = $pdo->prepare(
        "SELECT CASE v.booking_scope
                  WHEN 'Resort'   THEN 'Entire resort'
                  WHEN 'Building' THEN CONCAT('Whole building: ', COALESCE((SELECT b.name FROM building b WHERE b.building_id = v.building_id), 'Building'))
                  WHEN 'Pool'     THEN CONCAT('Entire pool: ', COALESCE((SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1), 'Pool'))
                  WHEN 'Cottage'  THEN COALESCE((SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1), 'Day-use cottage')
                  ELSE COALESCE((SELECT rt.type_name FROM reservation_room rr JOIN room rm ON rm.room_id = rr.room_id JOIN room_type rt ON rt.room_type_id = rm.room_type_id WHERE rr.reservation_id = v.reservation_id LIMIT 1), 'Overnight room')
                END AS unit_label,
                v.booking_scope,
                COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN v.status <> 'Cancelled' THEN v.total_amount ELSE 0 END), 0) AS amt
           FROM reservation v
          $resSqlWhere
          GROUP BY unit_label, v.booking_scope
          ORDER BY amt DESC, cnt DESC
          LIMIT 8"
    );
    $st->execute($resParams);
    $topUnits = $st->fetchAll();
    foreach ($topUnits as $tu) {
        if ((float)$tu['amt'] > $maxUnitAmt) $maxUnitAmt = (float)$tu['amt'];
    }

    $st = $pdo->prepare(
        "SELECT v.reservation_id, v.booking_ref, v.booking_scope, v.check_in_date, v.check_out_date,
                v.num_guests, v.status, v.total_amount,
                g.first_name, g.last_name,
                " . ROOMS_LABEL_SQL . " AS booked_label,
                COALESCE((SELECT SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END)
                            FROM payment p WHERE p.reservation_id = v.reservation_id), 0) AS paid
           FROM reservation v
           JOIN guest g ON g.guest_id = v.guest_id
          $resSqlWhere
          ORDER BY v.created_at DESC, v.reservation_id DESC
          LIMIT 25"
    );
    $st->execute($resParams);
    $recentBookings = $st->fetchAll();
}

function analytics_donut_svg(array $segments, string $centerBig, string $centerSmall): string
{
    $total = 0.0;
    foreach ($segments as $s) {
        $total += max(0.0, (float)$s['value']);
    }
    $r = 56;
    $c = 2 * M_PI * $r;
    $offset = 0.0;
    $arcs = '';
    if ($total > 0) {
        $nonZero = count(array_filter($segments, fn($x) => (float)$x['value'] > 0));
        foreach ($segments as $s) {
            $val = max(0.0, (float)$s['value']);
            if ($val <= 0) continue;
            $len = ($val / $total) * $c;
            $gap = $nonZero > 1 ? 2.5 : 0;
            $dash = max(1.0, $len - $gap);
            $tip = $s['tip'] ?? ($s['label'] . ': ' . $val);
            $arcs .= '<circle class="an-donut-seg" cx="76" cy="76" r="' . $r . '" fill="none"'
                   . ' stroke="' . h($s['color']) . '" stroke-width="18"'
                   . ' stroke-dasharray="' . number_format($dash, 2, '.', '') . ' ' . number_format($c, 2, '.', '') . '"'
                   . ' stroke-dashoffset="' . number_format(-$offset, 2, '.', '') . '"'
                   . ' transform="rotate(-90 76 76)"'
                   . ' data-chart-tip="' . h($tip) . '"/>';
            $offset += $len;
        }
    }
    return '<svg class="an-donut" viewBox="0 0 152 152" role="img" aria-label="' . h($centerBig . ' ' . $centerSmall) . '">'
         . '<circle cx="76" cy="76" r="' . $r . '" fill="none" stroke="#eef2f6" stroke-width="18"/>'
         . $arcs
         . '<text x="76" y="73" text-anchor="middle" class="an-donut-val">' . h($centerBig) . '</text>'
         . '<text x="76" y="90" text-anchor="middle" class="an-donut-lbl">' . h($centerSmall) . '</text>'
         . '</svg>';
}

admin_start('Analytics', 'analytics');
?>

<section class="panel an-header">
  <div class="an-header-left">
    <?php if ($activeResort): ?>
      <div class="res-cell">
        <?= admin_thumb_html($activeResort['hero_image'] ?? null, (string)$activeResort['name']) ?>
        <div class="res-cell-body">
          <h1><?= h($activeResort['name']) ?> <?= badge($activeResort['is_active'] ? 'Open' : 'Hidden') ?></h1>
          <p class="muted" title="<?= h((string)$activeResort['location']) ?>"><?= h(address_short((string)$activeResort['location']) ?: (string)$activeResort['location']) ?> · <?= h(plural((int)$activeResort['room_count'], 'room')) ?> · <?= h(plural((int)$activeResort['bldg_count'], 'building')) ?> · <?= h(plural((int)$activeResort['pool_count'], 'pool')) ?></p>
        </div>
      </div>
    <?php else: ?>
      <div>
        <h1>Analytics</h1>
      </div>
    <?php endif; ?>
  </div>

  <div class="an-header-right">
    <form method="get" class="an-range-form" role="search">
      <select name="resort_id" aria-label="Resort scope" onchange="this.form.submit()">
        <option value="0"<?= $resortFilter === 0 ? ' selected' : '' ?>>All resorts (<?= count($allResorts) ?>)</option>
        <optgroup label="Each resort">
          <?php foreach ($allResorts as $ar): ?>
            <option value="<?= (int)$ar['resort_id'] ?>"<?= (int)$ar['resort_id'] === $resortFilter ? ' selected' : '' ?>><?= h($ar['name']) ?><?= !$ar['is_active'] ? ' (Hidden)' : '' ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>
      <select name="range" aria-label="Time range" onchange="this.form.submit()">
        <?php foreach ($ranges as $k => $lbl): ?>
          <option value="<?= h($k) ?>"<?= $k === $range ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($activeResort): ?>
      <a class="btn" href="index.php?resort=<?= (int)$activeResort['resort_id'] ?>">Manage</a>
    <?php endif; ?>
    <button type="button" class="btn primary" onclick="window.print()">Print report</button>
    <?= admin_pop_notif_bell() ?>
  </div>
</section>

<section class="metric-strip is-grid" aria-label="Key performance metrics">
  <div class="metric"><b><?= h(money($grossValue)) ?></b><span>Gross booked value<?= $platformSharePct !== null ? ' (' . h($platformSharePct) . '% share)' : '' ?></span></div>
  <div class="metric"><b><?= h(money($collectedTotal)) ?></b><span>Collected (<?= h($collectionPct) ?>%)</span></div>
  <div class="metric"><b><?= $totalBookings ?></b><span>Total bookings</span></div>
  <div class="metric"><b><?= h(money($avgBookingVal)) ?></b><span>Avg booking value</span></div>
  <div class="metric"><b><?= $totalGuests ?></b><span>Guests booked</span></div>
  <div class="metric"><b><?= $avgRating !== null ? h(number_format($avgRating, 1)) . ' ★' : '–' ?></b><span>Avg rating (<?= $reviewCount ?>)</span></div>
</section>

<!-- 1. Revenue & Bookings Trend Chart -->
<?php
$W = 860; $H = 260;
$padL = 64; $padR = 24; $padT = 20; $padB = 34;
$plotW = $W - $padL - $padR;
$plotH = $H - $padT - $padB;
$nPts = max(1, count($trendPoints));
$maxVal = 100.0;
$maxCnt = 1;
foreach ($trendPoints as $tp) {
    $maxVal = max($maxVal, $tp['booked'], $tp['collected']);
    $maxCnt = max($maxCnt, $tp['bookings']);
}
$mag = pow(10, floor(log10($maxVal)));
$niceMax = ceil($maxVal / ($mag / 2)) * ($mag / 2);
if ($niceMax < 100) $niceMax = 100;

$bookedCoords = [];
$paidCoords   = [];
$barElems     = '';
$dotElems     = '';
$xLabels      = '';
$stepX = $nPts > 1 ? ($plotW / ($nPts - 1)) : 0;
$barW  = max(8, min(26, ($plotW / max(4, $nPts)) * 0.42));
$labelEvery = $nPts <= 10 ? 1 : ($nPts <= 20 ? 2 : (int)ceil($nPts / 10));

foreach ($trendPoints as $i => $tp) {
    $x = $nPts > 1 ? ($padL + $i * $stepX) : ($padL + $plotW / 2);
    $yBooked = $padT + $plotH - ($tp['booked'] / $niceMax) * $plotH;
    $yPaid   = $padT + $plotH - ($tp['collected'] / $niceMax) * $plotH;
    $bookedCoords[] = number_format($x, 1, '.', '') . ',' . number_format($yBooked, 1, '.', '');
    $paidCoords[]   = number_format($x, 1, '.', '') . ',' . number_format($yPaid, 1, '.', '');

    if ($tp['bookings'] > 0) {
        $bh = max(4, ($tp['bookings'] / $maxCnt) * ($plotH * 0.45));
        $by = $padT + $plotH - $bh;
        $barElems .= '<rect class="an-bar-bg" x="' . number_format($x - $barW / 2, 1, '.', '') . '" y="' . number_format($by, 1, '.', '')
                   . '" width="' . number_format($barW, 1, '.', '') . '" height="' . number_format($bh, 1, '.', '') . '" rx="3"/>';
    }

    $tip = $tp['label'] . ' · Booked: ' . money($tp['booked']) . ' · Collected: ' . money($tp['collected']) . ' · ' . plural($tp['bookings'], 'booking');
    $dotElems .= '<circle class="an-dot is-booked" cx="' . number_format($x, 1, '.', '') . '" cy="' . number_format($yBooked, 1, '.', '') . '" r="4.5" data-chart-tip="' . h($tip) . '"/>';
    $dotElems .= '<circle class="an-dot is-paid" cx="' . number_format($x, 1, '.', '') . '" cy="' . number_format($yPaid, 1, '.', '') . '" r="4" data-chart-tip="' . h($tip) . '"/>';
    $bandW = $nPts > 1 ? $stepX : $plotW;
    $dotElems .= '<rect class="an-hit" x="' . number_format(max($padL, $x - $bandW / 2), 1, '.', '') . '" y="' . $padT
               . '" width="' . number_format($bandW, 1, '.', '') . '" height="' . $plotH . '" fill="transparent" data-chart-tip="' . h($tip) . '"/>';

    if ($i % $labelEvery === 0 || $i === $nPts - 1) {
        $xLabels .= '<text class="an-axis-x" x="' . number_format($x, 1, '.', '') . '" y="' . ($H - 10) . '" text-anchor="middle">' . h($tp['short']) . '</text>';
    }
}
$firstX = $nPts > 1 ? $padL : ($padL + $plotW / 2);
$lastX  = $nPts > 1 ? ($padL + $plotW) : ($padL + $plotW / 2);
$baseY  = $padT + $plotH;
$areaPath = 'M ' . number_format($firstX, 1, '.', '') . ',' . $baseY
          . ' L ' . implode(' L ', $bookedCoords)
          . ' L ' . number_format($lastX, 1, '.', '') . ',' . $baseY . ' Z';
?>
<section class="panel an-panel">
  <div class="an-head">
    <div>
      <h2>Revenue &amp; bookings trend<?= $activeResort ? ' · ' . h($activeResort['name']) : ' · All resorts' ?></h2>
      <p class="muted">Booked value vs collected payments (<?= h($ranges[$range]) ?><?= $useMonthly ? ', monthly' : ', daily' ?>)</p>
    </div>
    <div class="an-legend">
      <span><i style="background:#0f2e48"></i>Booked value</span>
      <span><i style="background:#0b7f97"></i>Collected</span>
      <span><i style="background:#cbd5e1"></i>Bookings count</span>
    </div>
  </div>
  <div class="an-svg-wrap">
    <svg class="an-trend-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Revenue and bookings trend">
      <defs>
        <linearGradient id="anBookedGrad" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#0f2e48" stop-opacity="0.18"/>
          <stop offset="100%" stop-color="#0f2e48" stop-opacity="0.01"/>
        </linearGradient>
      </defs>
      <?php for ($g = 0; $g <= 4; $g++):
          $gy = $padT + ($plotH * $g / 4);
          $gv = $niceMax * (1 - $g / 4);
          $gLbl = $gv >= 1000 ? '₱' . number_format($gv / 1000, $gv >= 10000 ? 0 : 1) . 'k' : '₱' . number_format($gv, 0);
      ?>
        <line class="an-grid" x1="<?= $padL ?>" y1="<?= number_format($gy, 1, '.', '') ?>" x2="<?= $W - $padR ?>" y2="<?= number_format($gy, 1, '.', '') ?>"/>
        <text class="an-axis-y" x="<?= $padL - 8 ?>" y="<?= number_format($gy + 4, 1, '.', '') ?>" text-anchor="end"><?= h($gLbl) ?></text>
      <?php endfor; ?>
      <?= $barElems ?>
      <?php if ($nPts > 1): ?>
        <path d="<?= $areaPath ?>" fill="url(#anBookedGrad)"/>
        <polyline class="an-line is-booked" fill="none" points="<?= implode(' ', $bookedCoords) ?>"/>
        <polyline class="an-line is-paid" fill="none" points="<?= implode(' ', $paidCoords) ?>"/>
      <?php endif; ?>
      <?= $dotElems ?>
      <?= $xLabels ?>
    </svg>
  </div>
</section>

<!-- 2. Two-column grid: (All: Revenue by resort | Resort: Top booked stays & spaces) + Booking Status -->
<div class="an-grid-2">
  <?php if ($view === 'all'): ?>
  <section class="panel an-panel">
    <div class="an-head">
      <div>
        <h2>Revenue by resort</h2>
      </div>
    </div>
    <?php if (!$resortPerf): ?>
      <p class="muted">No resorts yet.</p>
    <?php else: ?>
      <div class="an-bars">
        <?php foreach ($resortPerf as $rp):
            $gAmt = (float)$rp['gross_amt'];
            $cAmt = (float)$rp['collected_amt'];
            $gPct = $maxResortGross > 0 ? round(($gAmt / $maxResortGross) * 100, 1) : 0;
            $cPct = $maxResortGross > 0 ? round(($cAmt / $maxResortGross) * 100, 1) : 0;
            $tip  = $rp['name'] . ' · Booked: ' . money($gAmt) . ' · Collected: ' . money($cAmt) . ' · ' . plural((int)$rp['bookings'], 'booking');
        ?>
        <div class="an-bar-row" data-chart-tip="<?= h($tip) ?>">
          <div class="an-bar-top">
            <a class="an-bar-name" href="analytics.php?view=resort&amp;resort_id=<?= (int)$rp['resort_id'] ?>&amp;range=<?= h($range) ?>"><?= h($rp['name']) ?></a>
            <span class="an-bar-val"><strong><?= h(money($gAmt)) ?></strong> <small class="muted">· <?= h(money($cAmt)) ?> paid</small></span>
          </div>
          <div class="an-track-dual">
            <div class="an-fill is-gross" style="width:<?= $gPct ?>%"></div>
            <div class="an-fill is-collected" style="width:<?= $cPct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="panel an-panel">
    <div class="an-head">
      <div>
        <h2>Top booked stays &amp; spaces</h2>
      </div>
    </div>
    <?php if (!$topUnits): ?>
      <p class="muted">No bookings at this resort in the selected period.</p>
    <?php else: ?>
      <div class="an-bars">
        <?php foreach ($topUnits as $tu):
            $uAmt = (float)$tu['amt'];
            $uCnt = (int)$tu['cnt'];
            $uPct = $maxUnitAmt > 0 ? round(($uAmt / $maxUnitAmt) * 100, 1) : 0;
            $uColor = $scopeOrder[$tu['booking_scope']]['color'] ?? '#0b7f97';
            $tip = $tu['unit_label'] . ' · ' . plural($uCnt, 'booking') . ' · ' . money($uAmt);
        ?>
        <div class="an-bar-row" data-chart-tip="<?= h($tip) ?>">
          <div class="an-bar-top">
            <span class="an-bar-name"><?= h($tu['unit_label']) ?></span>
            <span class="an-bar-val"><strong><?= h(money($uAmt)) ?></strong> <small class="muted">· <?= plural($uCnt, 'booking') ?></small></span>
          </div>
          <div class="an-track">
            <div class="an-fill" style="width:<?= $uPct ?>%;background:<?= h($uColor) ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel an-panel">
    <div class="an-head">
      <div>
        <h2>Booking status mix</h2>
        <p class="muted">Cancellation rate: <?= h($cancelRate) ?>%</p>
      </div>
    </div>
    <?php
    $statusSegs = array_map(fn($s) => [
        'label' => $s['label'],
        'value' => $s['cnt'],
        'color' => $s['color'],
        'tip'   => $s['label'] . ': ' . plural($s['cnt'], 'booking') . ' (' . money($s['amt']) . ')',
    ], $statusSeries);
    ?>
    <div class="an-donut-layout">
      <?= analytics_donut_svg($statusSegs, (string)$totalBookings, 'bookings') ?>
      <ul class="an-stat-list">
        <?php foreach ($statusSeries as $s):
            $pct = $totalBookings > 0 ? round(($s['cnt'] / $totalBookings) * 100, 1) : 0;
        ?>
          <li>
            <span class="an-sl-name"><i style="background:<?= h($s['color']) ?>"></i><?= h($s['label']) ?></span>
            <span class="an-sl-num"><strong><?= $s['cnt'] ?></strong> <small class="muted">(<?= $pct ?>%)</small></span>
            <span class="an-sl-amt"><?= h(money($s['amt'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>

<!-- 3. Two-column grid: Bookings by Category + Payment Methods -->
<div class="an-grid-2">
  <section class="panel an-panel">
    <div class="an-head">
      <div>
        <h2>Bookings by category</h2>
      </div>
    </div>
    <div class="an-bars">
      <?php foreach ($scopeSeries as $sc):
          $pctBar = $maxScopeAmt > 0 ? round(($sc['amt'] / $maxScopeAmt) * 100, 1) : 0;
          $share  = $totalBookings > 0 ? round(($sc['cnt'] / $totalBookings) * 100, 1) : 0;
          $tip    = $sc['label'] . ': ' . plural($sc['cnt'], 'booking') . ' (' . $share . '%) · ' . money($sc['amt']);
      ?>
        <div class="an-bar-row" data-chart-tip="<?= h($tip) ?>">
          <div class="an-bar-top">
            <span class="an-bar-name"><?= h($sc['label']) ?></span>
            <span class="an-bar-val"><strong><?= h(money($sc['amt'])) ?></strong> <small class="muted">· <?= plural($sc['cnt'], 'booking') ?> (<?= $share ?>%)</small></span>
          </div>
          <div class="an-track">
            <div class="an-fill" style="width:<?= $pctBar ?>%;background:<?= h($sc['color']) ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel an-panel">
    <div class="an-head">
      <div>
        <h2>Payment methods</h2>
        <p class="muted"><?= $pendingPayCnt > 0 ? plural($pendingPayCnt, 'payment') . ' (' . money($pendingPayAmt) . ') pending verification' : 'All submitted payments verified' ?></p>
      </div>
    </div>
    <?php
    $paySegs = array_map(fn($m) => [
        'label' => $m['label'],
        'value' => $m['amt'],
        'color' => $m['color'],
        'tip'   => $m['label'] . ': ' . money($m['amt']) . ' (' . plural($m['cnt'], 'payment') . ')',
    ], $methodSeries);
    $compactPaid = $methodTotalNet >= 1000 ? '₱' . number_format($methodTotalNet / 1000, 1) . 'k' : money($methodTotalNet);
    ?>
    <?php if (!$methodSeries): ?>
      <p class="muted">No verified payments in this period.</p>
    <?php else: ?>
    <div class="an-donut-layout">
      <?= analytics_donut_svg($paySegs, $compactPaid, 'collected') ?>
      <ul class="an-stat-list">
        <?php foreach ($methodSeries as $m):
            $pct = $methodTotalNet > 0 ? round(($m['amt'] / $methodTotalNet) * 100, 1) : 0;
        ?>
          <li>
            <span class="an-sl-name"><i style="background:<?= h($m['color']) ?>"></i><?= h($m['label']) ?></span>
            <span class="an-sl-num"><strong><?= $m['cnt'] ?></strong> <small class="muted">(<?= $pct ?>%)</small></span>
            <span class="an-sl-amt"><?= h(money($m['amt'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>
</div>

<!-- 4. Guest Rating Distribution -->
<section class="panel an-panel">
  <div class="an-head">
    <div>
      <h2>Guest ratings breakdown</h2>
      <p class="muted"><?= $reviewCount > 0 ? plural($reviewCount, 'published review') . ' · Average ' . number_format($avgRating, 2) . ' / 5.0' : 'No published reviews in this period.' ?></p>
    </div>
  </div>
  <div class="an-rating-bars">
    <?php for ($star = 5; $star >= 1; $star--):
        $cnt = $ratingRaw[$star] ?? 0;
        $pct = $reviewCount > 0 ? round(($cnt / $reviewCount) * 100, 1) : 0;
    ?>
      <div class="an-rb-row" data-chart-tip="<?= $star ?> stars: <?= plural($cnt, 'review') ?> (<?= $pct ?>%)">
        <span class="an-rb-lbl"><?= $star ?> ★</span>
        <div class="an-track"><div class="an-fill" style="width:<?= $pct ?>%;background:#d97706"></div></div>
        <span class="an-rb-val"><strong><?= $cnt ?></strong> <small class="muted">(<?= $pct ?>%)</small></span>
      </div>
    <?php endfor; ?>
  </div>
</section>

<?php if ($view === 'all'): ?>
<section class="panel flush">
  <div class="list-head">
    <h2>Resort performance comparison</h2>
    <span class="muted"><?= h($ranges[$range]) ?></span>
  </div>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Resort</th>
          <th class="num">Bookings</th>
          <th class="num">Guests</th>
          <th class="num">Booked value</th>
          <th class="num">Collected</th>
          <th class="num">Collection %</th>
          <th class="num">Rating</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($resortPerf as $rp):
          $rid = (int)$rp['resort_id'];
          $gAmt = (float)$rp['gross_amt'];
          $cAmt = (float)$rp['collected_amt'];
          $cPct = $gAmt > 0 ? min(100, round(($cAmt / $gAmt) * 100, 1)) : 0.0;
      ?>
        <tr>
          <td class="col-primary">
            <div class="res-cell">
              <?= admin_thumb_html($rp['hero_image'] ?? null, (string)$rp['name']) ?>
              <div class="res-cell-body">
                <strong><a href="analytics.php?view=resort&amp;resort_id=<?= $rid ?>&amp;range=<?= h($range) ?>"><?= h($rp['name']) ?></a></strong>
                <small class="muted-line" title="<?= h((string)$rp['location']) ?>"><?= h(address_short((string)$rp['location']) ?: (string)$rp['location']) ?></small>
              </div>
            </div>
          </td>
          <td class="num"><?= (int)$rp['bookings'] ?></td>
          <td class="num"><?= (int)$rp['guests'] ?></td>
          <td class="num"><strong><?= h(money($gAmt)) ?></strong></td>
          <td class="num"><?= h(money($cAmt)) ?></td>
          <td class="num"><span class="badge <?= $cPct >= 80 ? 'open' : ($cPct >= 40 ? 'pending' : 'no') ?>"><?= h($cPct) ?>%</span></td>
          <td class="num"><?= $rp['avg_rating'] !== null ? '<strong>' . h($rp['avg_rating']) . '</strong> <small class="muted">(' . (int)$rp['reviews'] . ')</small>' : '<span class="muted">–</span>' ?></td>
          <td class="row-actions">
            <div class="act-group">
              <a class="btn sm primary" href="analytics.php?view=resort&amp;resort_id=<?= $rid ?>&amp;range=<?= h($range) ?>">Analytics</a>
              <a class="btn sm" href="index.php?resort=<?= $rid ?>">Manage</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php else: ?>
<section class="panel flush">
  <div class="list-head">
    <h2>Recent bookings · <?= h($activeResort['name']) ?></h2>
    <a href="reservations.php?resort=<?= (int)$activeResort['resort_id'] ?>">All reservations →</a>
  </div>
  <?php if (!$recentBookings): ?>
    <p class="empty">No bookings at this resort in the selected time range.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Ref</th>
          <th>Guest</th>
          <th>Booked</th>
          <th class="num">Guests</th>
          <th>Dates</th>
          <th>Status</th>
          <th class="num">Total</th>
          <th class="num">Paid</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recentBookings as $b): ?>
        <tr>
          <td class="strong nowrap"><?= h($b['booking_ref']) ?></td>
          <td><?= h(format_name_inv((string)$b['first_name'], (string)$b['last_name'])) ?></td>
          <td><?= h((string)$b['booked_label']) ?></td>
          <td class="num"><?= (int)$b['num_guests'] ?></td>
          <td class="nowrap">
            <?php if (($b['booking_scope'] ?? '') === 'Cottage'): ?>
              <?= h(date('M j, Y', strtotime($b['check_in_date']))) ?> <small class="muted">(Day use)</small>
            <?php else: ?>
              <?= h(date('M j', strtotime($b['check_in_date']))) ?> – <?= h(date('M j, Y', strtotime($b['check_out_date']))) ?>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= reservation_status_badge($b, (float)$b['paid']) ?></td>
          <td class="num"><strong><?= h(money($b['total_amount'])) ?></strong></td>
          <td class="num"><?= h(money($b['paid'])) ?></td>
          <td class="row-actions">
            <div class="act-group">
              <a class="btn sm" href="reservation_view.php?id=<?= (int)$b['reservation_id'] ?>&amp;resort=<?= (int)$activeResort['resort_id'] ?>">Open</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="an-tooltip" id="an-tooltip" role="tooltip" hidden></div>
<?php admin_end();
