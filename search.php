<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/trips.php';

$singleResort = optional_resort($pdo);

$mode       = (($_GET['mode'] ?? '') === 'rooms' || (empty($_GET['mode']) && !empty($_GET['check_out']))) ? 'rooms' : 'cottage';
$in         = (string)($_GET['check_in'] ?? add_days(today(), 1));
$out        = (string)($_GET['check_out'] ?? add_days($in ?: today(), 2));
$adults     = max(1, (int)($_GET['adults'] ?? 2));
$children   = max(0, (int)($_GET['children'] ?? 0));
$guests     = $adults + $children;
$searchMode = $mode;

$problems    = [];
$allCottages = [];   // each item has 'resort' => row + cottage fields + 'ent' + 'thumb'
$allRooms    = [];   // each item has 'resort' => row + room_type fields + 'thumb'
$n           = 1;

if ($mode === 'cottage') {
    $out      = valid_date($in) ? add_days($in, 1) : add_days(today(), 2);
    $problems = validate_stay($in, $out, $adults, $children, 50);
} else {
    $problems = validate_stay($in, $out, $adults, $children);
    if (!$problems) {
        $n = nights($in, $out);
    }
}

if (!$problems) {
    $resortList = $singleResort
        ? [$singleResort]
        : $pdo->query('SELECT * FROM resort WHERE is_active = 1 AND archived_at IS NULL ORDER BY sort_order, name')->fetchAll();

    foreach ($resortList as $rRow) {
        with_resort($rRow, function () use ($pdo, $rRow, $mode, $in, $out, $adults, $children, &$allCottages, &$allRooms): void {
            if ($mode === 'cottage') {
                $ent = cottage_entrance_total($pdo, (int)$rRow['resort_id'], $adults, $children);
                foreach (cottage_availability($pdo, $in) as $c) {
                    $ph = room_photos((string)$c['item_name']);
                    $c['resort'] = $rRow;
                    $c['ent']    = $ent;
                    $c['total']  = round((float)$c['price'] + $ent['total'], 2);
                    $c['thumb']  = $ph ? $ph[0]['url'] : resort_hero();
                    $allCottages[] = $c;
                }
            } else {
                foreach (room_type_availability($pdo, $in, $out) as $t) {
                    $ph = room_photos((string)$t['type_name']);
                    $t['resort'] = $rRow;
                    $t['thumb']  = $ph ? $ph[0]['url'] : resort_hero();
                    $allRooms[]  = $t;
                }
            }
        });
    }

    // When searching across all resorts, put available options first, sorted by lowest price.
    if (!$singleResort) {
        if ($mode === 'cottage') {
            usort($allCottages, function (array $a, array $b) use ($guests): int {
                $okA = ((int)$a['free'] > 0 && $guests <= (int)$a['max_guests']) ? 0 : 1;
                $okB = ((int)$b['free'] > 0 && $guests <= (int)$b['max_guests']) ? 0 : 1;
                return $okA <=> $okB ?: ($a['total'] <=> $b['total']);
            });
        } else {
            $maxR = (int)$config['max_rooms_per_booking'];
            usort($allRooms, function (array $a, array $b) use ($guests, $maxR): int {
                $needA = rooms_needed($guests, (int)$a['max_occupancy']);
                $needB = rooms_needed($guests, (int)$b['max_occupancy']);
                $okA   = ((int)$a['free_rooms'] >= $needA && $needA <= $maxR) ? 0 : 1;
                $okB   = ((int)$b['free_rooms'] >= $needB && $needB <= $maxR) ? 0 : 1;
                return $okA <=> $okB ?: ((float)$a['base_rate'] <=> (float)$b['base_rate']);
            });
        }
    }
}

$pageTitle = $mode === 'cottage'
    ? ($singleResort ? 'Available cottages' : 'Available cottages across all resorts')
    : ($singleResort ? 'Available rooms' : 'Available rooms across all resorts');
site_start($pageTitle, $singleResort ? 'rooms' : 'all');
?>
<div class="page-head<?= !$singleResort ? ' has-back' : '' ?>">
  <div class="ph-left">
    <?php if (!$singleResort): ?><a class="btn sm page-back" href="resorts.php">&larr; All resorts</a><?php endif; ?>
    <h1><?= h($pageTitle) ?></h1>
  </div>
</div>

<?php if (!$problems && (($mode === 'cottage' && $allCottages) || ($mode !== 'cottage' && $allRooms))): ?>
<div class="rooms-name-search" data-stay-filter-wrap>
  <span class="rns-ico" aria-hidden="true"><?= icon('search', 18) ?></span>
  <input type="search" id="rooms-name-filter" value="<?= h(trim((string)($_GET['q'] ?? ''))) ?>" placeholder="<?= $mode === 'cottage' ? 'Filter by cottage or resort name…' : 'Filter by room or resort name…' ?>" aria-label="Filter results by name" maxlength="80" autocomplete="off" data-stay-filter>
  <button type="button" class="btn sm" data-stay-filter-clear<?= trim((string)($_GET['q'] ?? '')) === '' ? ' hidden' : '' ?>>Clear</button>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/search_form.php'; ?>

<div data-stay-filter-scope>
<?php if ($problems): ?>
  <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
<?php elseif ($mode === 'cottage'): ?>
  <p class="stay-line">
    <?= h(fdate($in)) ?> · Day use · <?= h(plural($guests, 'guest')) ?> arriving
  </p>

  <?php
    $bookable = 0;
    foreach ($allCottages as $c) {
        if ((int)$c['free'] > 0 && $guests <= (int)$c['max_guests']) {
            $bookable++;
        }
    }
  ?>
  <?php if (!$allCottages): ?>
    <div class="notice" role="status">
      No day-use cottages listed yet.
      <a href="<?= h(ru('search.php?' . http_build_query(['mode' => 'rooms', 'check_in' => $in, 'check_out' => add_days($in, 1), 'adults' => $adults, 'children' => $children]))) ?>">View overnight rooms instead →</a>
    </div>
  <?php elseif ($bookable === 0): ?>
    <div class="notice error" role="alert">
      No single cottage is available for <?= h(plural($guests, 'guest')) ?> on <?= h(fdate($in)) ?>. Try another date or fewer guests.
    </div>
  <?php endif; ?>

  <div class="type-list">
    <?php foreach ($allCottages as $c):
        $rRow  = $c['resort'];
        $slug  = (string)$rRow['slug'];
        $free  = (int)$c['free'];
        $cap   = (int)$c['max_guests'];
        $ok    = $free > 0 && $guests <= $cap;
        $ent   = $c['ent'];
        $total = (float)$c['total'];
        $thumb = (string)$c['thumb'];
        $detailUrl = 'room.php?' . http_build_query([
            'r' => $slug, 'cottage' => (int)$c['price_item_id'],
            'check_in' => $in, 'adults' => $guests,
        ]);
    ?>
      <article class="type-row has-photo<?= $ok ? '' : ' is-off' ?>">
        <a class="tr-photo<?= $thumb !== '' ? '' : ' no-photo' ?>" href="<?= h($detailUrl) ?>"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?> aria-label="View <?= h($c['item_name']) ?>"></a>
        <div>
          <?php if (!$singleResort): ?>
            <p class="muted" style="margin:0 0 2px;font-size:12.5px">
              <a href="landing.php?r=<?= h(rawurlencode($slug)) ?>"><?= h($rRow['name']) ?></a><?= !empty($rRow['location']) ? ' · ' . h(address_short((string)$rRow['location'])) : '' ?>
            </p>
          <?php endif; ?>
          <h2><a href="<?= h($detailUrl) ?>"><?= h($c['item_name']) ?></a></h2>
          <p><?= h($c['note'] ?: 'Single cottage · Day use') ?></p>
          <p class="muted">
            Up to <?= $cap ?> guests ·
            <?php if ($free === 0): ?>
              Fully booked on this date.
            <?php elseif ($guests > $cap): ?>
              Fits up to <?= $cap ?> guests (your group is <?= $guests ?>).
            <?php else: ?>
              <?= $free <= 2 ? 'Only ' . h(plural($free, 'cottage')) . ' left.' : h(plural($free, 'cottage')) . ' available.' ?>
            <?php endif; ?>
          </p>
        </div>

        <div class="type-side">
          <p class="price"><?= h(money($c['price'])) ?> <small><?= h($c['unit'] ?: 'per cottage') ?></small></p>
          <?php if ($ent['total'] > 0.004): ?>
            <p class="muted">+ Entrance (<?= h(plural($guests, 'guest')) ?>): <?= h(money($ent['total'])) ?></p>
            <p class="muted">Total: <strong><?= h(money($total)) ?></strong></p>
          <?php endif; ?>
          <?php if ($ok): ?>
            <form method="get" action="checkout.php" class="reserve">
              <input type="hidden" name="r" value="<?= h($slug) ?>">
              <?php if (!$singleResort): ?><input type="hidden" name="all_resorts" value="1"><?php endif; ?>
              <input type="hidden" name="scope" value="Cottage">
              <input type="hidden" name="target" value="<?= (int)$c['price_item_id'] ?>">
              <input type="hidden" name="check_in" value="<?= h($in) ?>">
              <input type="hidden" name="check_out" value="<?= h(add_days($in, 1)) ?>">
              <input type="hidden" name="adults" value="<?= $adults ?>">
              <input type="hidden" name="children" value="<?= $children ?>">
              <button class="btn primary" type="submit">Book cottage</button>
              <?= trip_add_button() ?>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($singleResort): ?>
  <p class="muted" style="margin-top:16px">
    <a href="search.php?<?= h(http_build_query(['mode' => 'cottage', 'check_in' => $in, 'adults' => $adults, 'children' => $children])) ?>">Search all resorts</a>
    · <a href="<?= h(ru('exclusive.php?' . http_build_query(['check_in' => $in, 'check_out' => add_days($in, 1), 'adults' => $adults, 'children' => $children]))) ?>">Book whole pool, building or resort</a>
  </p>
  <?php endif; ?>
<?php else: ?>
  <p class="stay-line">
    <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?> ·
    <?= h(plural($n, 'night')) ?> ·
    <?= h(plural($guests, 'guest')) ?>
  </p>

  <?php
  $bookable = 0;
  foreach ($allRooms as $t) {
      $need = rooms_needed($guests, (int)$t['max_occupancy']);
      if ((int)$t['free_rooms'] >= $need && $need <= $config['max_rooms_per_booking']) {
          $bookable++;
      }
  }
  ?>
  <?php if ($bookable === 0): ?>
    <div class="notice error" role="alert">
      No rooms available for those dates and group size. Try other dates or <a href="<?= h(ru('search.php?' . http_build_query(['mode' => 'cottage', 'check_in' => $in, 'adults' => $adults, 'children' => $children]))) ?>">check day-use cottages</a>.
    </div>
  <?php endif; ?>

  <div class="type-list">
    <?php foreach ($allRooms as $t):
        $rRow    = $t['resort'];
        $slug    = (string)$rRow['slug'];
        $free    = (int)$t['free_rooms'];
        $cap     = (int)$t['max_occupancy'];
        $need    = rooms_needed($guests, $cap);
        $maxPick = min($free, (int)$config['max_rooms_per_booking']);
        $ok      = $free >= $need && $need <= $maxPick;
        $thumb   = (string)$t['thumb'];
        $roomUrl = 'room.php?' . http_build_query([
            'id' => (int)$t['room_type_id'], 'r' => $slug,
            'check_in' => $in, 'check_out' => $out, 'adults' => $guests,
        ]);
        ?>
      <article class="type-row has-photo<?= $ok ? '' : ' is-off' ?>">
        <a class="tr-photo<?= $thumb !== '' ? '' : ' no-photo' ?>" href="<?= h($roomUrl) ?>"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?> aria-label="View the <?= h($t['type_name']) ?>"></a>
        <div>
          <?php if (!$singleResort): ?>
            <p class="muted" style="margin:0 0 2px;font-size:12.5px">
              <a href="landing.php?r=<?= h(rawurlencode($slug)) ?>"><?= h($rRow['name']) ?></a><?= !empty($rRow['location']) ? ' · ' . h(address_short((string)$rRow['location'])) : '' ?>
            </p>
          <?php endif; ?>
          <h2><a href="<?= h($roomUrl) ?>"><?= h($t['type_name']) ?></a></h2>
          <p><?= h($t['description']) ?></p>
          <p class="muted">
            Sleeps up to <?= $cap ?> per room ·
            <?php if ($free === 0): ?>
              Sold out.
            <?php elseif ($need > (int)$config['max_rooms_per_booking']): ?>
              Needs <?= h(plural($need, 'room')) ?> (max <?= (int)$config['max_rooms_per_booking'] ?> online).
            <?php elseif (!$ok): ?>
              Only <?= h(plural($free, 'room')) ?> free.
            <?php else: ?>
              <?= $free <= 3 ? 'Only ' . h(plural($free, 'room')) . ' left.' : 'Available.' ?>
            <?php endif; ?>
          </p>
        </div>

        <div class="type-side">
          <p class="price"><?= h(money($t['base_rate'])) ?> <small>per night</small></p>
          <?php if ($ok): ?>
            <p class="muted"><?= h(plural($need, 'room')) ?> · <?= h(plural($n, 'night')) ?>: <strong><?= h(money((float)$t['base_rate'] * $n * $need)) ?></strong></p>
            <form method="get" action="checkout.php" class="reserve">
              <input type="hidden" name="r" value="<?= h($slug) ?>">
              <?php if (!$singleResort): ?><input type="hidden" name="all_resorts" value="1"><?php endif; ?>
              <input type="hidden" name="type" value="<?= (int)$t['room_type_id'] ?>">
              <input type="hidden" name="check_in" value="<?= h($in) ?>">
              <input type="hidden" name="check_out" value="<?= h($out) ?>">
              <input type="hidden" name="adults" value="<?= $adults ?>">
              <input type="hidden" name="children" value="<?= $children ?>">
              <?php if ($maxPick > $need): ?>
                <label class="inline"><span>Rooms</span>
                  <select name="rooms">
                    <?php for ($r = $need; $r <= $maxPick; $r++): ?>
                      <option value="<?= $r ?>"><?= $r ?></option>
                    <?php endfor; ?>
                  </select>
                </label>
              <?php else: ?>
                <input type="hidden" name="rooms" value="<?= $need ?>">
              <?php endif; ?>
              <button class="btn primary" type="submit">Book now</button>
              <?= trip_add_button() ?>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<p class="panel empty" data-stay-filter-empty hidden>No matching cottages or rooms found.</p>
</div>
<?php site_end();
