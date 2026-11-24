<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_resort($pdo);

$cottageId = (int)($_GET['cottage'] ?? 0);
$id        = (int)($_GET['id'] ?? 0);
$isCottage = $cottageId > 0;

$loggedIn = (int)($_SESSION['guest_id'] ?? 0) > 0;
$arrow    = fn(string $d) => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';

if ($isCottage) {
    $stmt = $pdo->prepare("SELECT * FROM price_item WHERE price_item_id = ? AND resort_id = ? AND category = 'Cottage (day use)' AND archived_at IS NULL");
    $stmt->execute([$cottageId, resort_id()]);
    $cot = $stmt->fetch();
    if (!$cot) {
        redirect(ru('rooms.php'));
    }

    $title  = (string)$cot['item_name'];
    $cap    = max(1, (int)($cot['max_guests'] ?? 15));
    $rate   = (float)$cot['price'];
    $unit   = trim((string)($cot['unit'] ?? '')) ?: 'per cottage';
    $noun   = 'cottage';
    $photos = room_photos($title);
    if (!$photos) {
        $hero = resort_hero();
        if ($hero !== '') {
            $photos = [['url' => $hero, 'fit' => 'cover', 'portrait' => false]];
        }
    }

    $noteText = trim((string)($cot['note'] ?? ''));
    $paras    = $noteText !== '' ? [$noteText] : ['Single day-use cottage at ' . $config['resort'] . '. Ideal for families, friends, and weekend outings.'];

    $in       = isset($_GET['check_in']) ? (string)$_GET['check_in'] : add_days(today(), 1);
    $out      = valid_date($in) ? add_days($in, 1) : add_days(today(), 2);
    $adults   = max(1, (int)($_GET['adults'] ?? min(4, $cap)));
    $children = max(0, (int)($_GET['children'] ?? 0));
    $guests   = $adults + $children;

    $problems = validate_stay($in, $out, $adults, $children, $cap);
    $ent      = cottage_entrance_total($pdo, resort_id(), $adults, $children);
    $totalEst = round($rate + $ent['total'], 2);
    $free     = 0;
    if (!$problems) {
        $ct   = cottage_target($pdo, $cottageId, $in);
        $free = $ct ? (int)$ct['free_qty'] : 0;
    }
    $ok = !$problems && $free > 0;

    $others = $pdo->prepare("SELECT price_item_id, item_name, price FROM price_item WHERE price_item_id <> ? AND resort_id = ? AND category = 'Cottage (day use)' AND archived_at IS NULL ORDER BY sort_order, price");
    $others->execute([$cottageId, resort_id()]);
    $others = $others->fetchAll();

    site_start($title, 'rooms', 'room-detail');
    ?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back" href="<?= h(ru('rooms.php')) ?>">&larr; Back to book</a>
    <h1><?= h($title) ?></h1>
  </div>
</div>

<div class="rd">
  <section class="rd-gallery" aria-label="Photos of <?= h($title) ?>" data-gallery>
    <?php if ($photos): ?>
      <div class="rd-main">
        <img src="<?= h($photos[0]['url']) ?>" alt="<?= h($title) ?>, photo 1 of <?= count($photos) ?>" data-main>
        <?php if (count($photos) > 1): ?>
          <button type="button" class="rd-nav prev" data-prev aria-label="Previous photo"><?= $arrow('M15 6l-6 6 6 6') ?></button>
          <button type="button" class="rd-nav next" data-next aria-label="Next photo"><?= $arrow('M9 6l6 6-6 6') ?></button>
          <span class="rd-count" data-count>1 / <?= count($photos) ?></span>
          <button type="button" class="rd-all" data-showall><?= icon('photos', 16) ?> Show all <?= count($photos) ?> photos</button>
        <?php endif; ?>
      </div>
      <?php foreach ($photos as $i => $p): ?>
        <a href="<?= h($p['url']) ?>" data-lightbox="room" data-room-photo hidden><img src="<?= h($p['url']) ?>" alt="<?= h($title) ?>, photo <?= $i + 1 ?> of <?= count($photos) ?>"></a>
      <?php endforeach; ?>
      <?php if (count($photos) > 1): ?>
      <div class="rd-thumbs">
        <?php foreach ($photos as $i => $p): ?>
          <button type="button" class="rd-thumb<?= $i === 0 ? ' is-on' : '' ?>" data-thumb="<?= $i ?>" data-src="<?= h($p['url']) ?>" aria-label="Show photo <?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>>
            <img src="<?= h($p['url']) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="rd-main no-photo" role="img" aria-label="No photo yet"></div>
    <?php endif; ?>
  </section>

  <aside class="rd-aside">
    <section class="panel rd-book" id="book" aria-label="Book <?= h($title) ?>">
      <p class="rd-price"><strong><?= h(money($rate)) ?></strong> <small><?= h($unit) ?></small></p>
      <p class="muted rd-sleeps">Day use · Up to <?= $cap ?> guests</p>

      <form method="get" action="checkout.php" class="rd-form" data-room-form data-cap="<?= $cap ?>" data-live="1" data-self="<?= h(ru('room.php')) ?>">
        <?= rhidden() ?>
        <input type="hidden" name="cottage" value="<?= $cottageId ?>" data-self-only>
        <input type="hidden" name="scope" value="Cottage">
        <input type="hidden" name="target" value="<?= $cottageId ?>">
        <input type="hidden" name="check_out" value="<?= h($out) ?>">
        <label class="rd-wide"><span>Visit date</span>
          <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
        </label>
        <label class="rd-wide"><span>Guests</span>
          <input type="number" name="adults" value="<?= $guests ?>" min="1" max="<?= $cap ?>" step="1" required>
        </label>
        <input type="hidden" name="children" value="0">

        <?php if ($problems): ?>
          <div class="notice error rd-wide" role="alert"><?= h($problems[0]) ?></div>
        <?php elseif ($ok): ?>
          <div class="rp-result rd-wide" role="status">
            <p class="rp-result-line"><strong><?= h($free <= 2 ? 'Only ' . plural($free, 'cottage') . ' left' : 'Available') ?></strong> on <?= h(fdate($in)) ?>.</p>
            <p class="muted">1 cottage · Day use · <?= h(plural($guests, 'guest')) ?><?= $ent['total'] > 0.004 ? ' (+ ' . h(money($ent['total'])) . ' entrance)' : '' ?></p>
            <p class="rp-total-line">Total <strong class="rp-total"><?= h(money($totalEst)) ?></strong></p>
          </div>
        <?php else: ?>
          <div class="notice error rd-wide" role="alert">
            Sorry, <?= h($title) ?> is fully booked on <?= h(fdate($in)) ?>. Try another date or look at other cottages below.
          </div>
        <?php endif; ?>

        <div class="rd-actions rd-wide">
          <button class="btn primary" type="submit"<?= $ok ? '' : ' disabled' ?>>Book cottage</button>
          <?= str_replace('<button class="btn"', '<button class="btn"' . ($ok ? '' : ' disabled'), trip_add_button()) ?>
        </div>
      </form>

      <?php
        $rPayOn = !empty($config['online_payment_enabled']);
        $rDpPct = $rPayOn ? (int)($config['downpayment_percent'] ?? 50) : 0;
      ?>
      <p class="muted rd-fine"><?= $rDpPct >= 100
        ? '100% GCash/Maya payment required.'
        : ($rDpPct > 0
            ? $rDpPct . '% GCash/Maya downpayment to confirm · balance at resort.'
            : 'Pay at the resort' . ($rPayOn ? ' or via GCash/Maya.' : '.')) ?></p>
    </section>
  </aside>

  <section class="rd-details">
    <h2>About this cottage</h2>
    <?php foreach ($paras as $para): ?><p><?= h($para) ?></p><?php endforeach; ?>
    <dl class="facts rd-facts">
      <dt>Capacity</dt><dd>Up to <?= $cap ?> guests</dd>
      <dt>Rate</dt><dd><?= h(money($rate)) ?> <?= h($unit) ?></dd>
      <?php if ($ent['total'] > 0.004): ?>
        <dt>Entrance fee</dt><dd><?= h(money($ent['adult_rate'])) ?> per guest</dd>
      <?php endif; ?>
      <dt>Type</dt><dd>Single cottage · Day use</dd>
    </dl>

    <?php if ($others): ?>
    <div class="rp-others">
      <span class="muted">Other cottages</span>
      <?php foreach ($others as $o): ?>
        <a href="<?= h(ru('room.php?cottage=' . (int)$o['price_item_id'])) ?>"><?= h($o['item_name']) ?> <small><?= h(money($o['price'])) ?></small></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php
    site_end();
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ? AND archived_at IS NULL');
$stmt->execute([$id, resort_id()]);
$type = $stmt->fetch();
if (!$type) {
    redirect(ru('rooms.php'));
}

$cap    = (int)$type['max_occupancy'];
$noun   = stripos($type['type_name'], 'cottage') !== false ? 'cottage' : 'room';
$photos = room_photos($type['type_name']);
if (!$photos) {
    // No photos uploaded for this type yet: show the resort's main picture instead (or a colour block).
    $hero = resort_hero();
    if ($hero !== '') {
        $photos = [['url' => $hero, 'fit' => 'cover', 'portrait' => false]];
    }
}

$text  = trim((string)($type['details'] ?? '')) !== '' ? (string)$type['details'] : (string)$type['description'];
$paras = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $text) ?: [])));

// Looking at the room is open to everyone. Availability is shown for guests who are logged in; a visitor
// who presses Book now or Add to trip is asked to log in or create an account first (checkout does that).
$in       = isset($_GET['check_in'])  ? (string)$_GET['check_in']  : add_days(today(), 1);
$out      = isset($_GET['check_out']) ? (string)$_GET['check_out'] : add_days(today(), 3);
$adults   = max(1, (int)($_GET['adults'] ?? min(2, $cap)));
$children = max(0, (int)($_GET['children'] ?? 0));
$guests   = $adults + $children;
$need     = rooms_needed($guests, $cap);

$problems = validate_stay($in, $out, $adults, $children);
$free = $n = 0;
if (!$problems) {
    $n = nights($in, $out);
    $free = count(available_rooms($pdo, $in, $out, $id));
}
$maxPick = min($free, (int)$config['max_rooms_per_booking']);
$ok = !$problems && ($free >= $need && $need <= $maxPick);
if (!$problems && $need > (int)$config['max_rooms_per_booking']) {
    $ok = false;
}

$others = $pdo->prepare('SELECT room_type_id, type_name, base_rate FROM room_type WHERE room_type_id <> ? AND resort_id = ? AND archived_at IS NULL ORDER BY base_rate');
$others->execute([$id, resort_id()]);
$others = $others->fetchAll();

site_start($type['type_name'], 'rooms', 'room-detail');
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back" href="<?= h(ru('rooms.php')) ?>">&larr; Back to book</a>
    <h1><?= h($type['type_name']) ?></h1>
  </div>
</div>

<div class="rd">
  <section class="rd-gallery" aria-label="Photos of the <?= h($type['type_name']) ?>" data-gallery>
    <?php if ($photos): ?>
      <div class="rd-main">
        <img src="<?= h($photos[0]['url']) ?>" alt="<?= h($type['type_name']) ?>, photo 1 of <?= count($photos) ?>" data-main>
        <?php if (count($photos) > 1): ?>
          <button type="button" class="rd-nav prev" data-prev aria-label="Previous photo"><?= $arrow('M15 6l-6 6 6 6') ?></button>
          <button type="button" class="rd-nav next" data-next aria-label="Next photo"><?= $arrow('M9 6l6 6-6 6') ?></button>
          <span class="rd-count" data-count>1 / <?= count($photos) ?></span>
          <button type="button" class="rd-all" data-showall><?= icon('photos', 16) ?> Show all <?= count($photos) ?> photos</button>
        <?php endif; ?>
      </div>
      <?php foreach ($photos as $i => $p): ?>
        <a href="<?= h($p['url']) ?>" data-lightbox="room" data-room-photo hidden><img src="<?= h($p['url']) ?>" alt="<?= h($type['type_name']) ?>, photo <?= $i + 1 ?> of <?= count($photos) ?>"></a>
      <?php endforeach; ?>
      <?php if (count($photos) > 1): ?>
      <div class="rd-thumbs">
        <?php foreach ($photos as $i => $p): ?>
          <button type="button" class="rd-thumb<?= $i === 0 ? ' is-on' : '' ?>" data-thumb="<?= $i ?>" data-src="<?= h($p['url']) ?>" aria-label="Show photo <?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>>
            <img src="<?= h($p['url']) ?>" alt="" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="rd-main no-photo" role="img" aria-label="No photo yet"></div>
    <?php endif; ?>
  </section>

  <aside class="rd-aside">
    <section class="panel rd-book" id="book" aria-label="Book <?= h($type['type_name']) ?>">
      <p class="rd-price"><strong><?= h(money($type['base_rate'])) ?></strong> <small>per <?= $noun ?> per night</small></p>
      <p class="muted rd-sleeps">Sleeps up to <?= $cap ?> per <?= $noun ?></p>

      <form method="get" action="checkout.php" class="rd-form" data-stay data-room-form data-cap="<?= $cap ?>" data-live="1" data-self="<?= h(ru('room.php')) ?>">
        <?= rhidden() ?>
        <input type="hidden" name="id" value="<?= $id ?>" data-self-only>
        <input type="hidden" name="type" value="<?= $id ?>">
        <input type="hidden" name="rooms" value="<?= $need ?>" data-rooms>
        <label><span>Check-in</span>
          <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
        </label>
        <label><span>Check-out <em class="nights" data-nights></em></span>
          <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>" required>
        </label>
        <label class="rd-wide"><span>Guests</span>
          <input type="number" name="adults" value="<?= $guests ?>" min="1" max="20" step="1" required>
        </label>
        <input type="hidden" name="children" value="0">

        <?php if ($problems): ?>
          <div class="notice error rd-wide" role="alert"><?= h($problems[0]) ?></div>
        <?php elseif ($ok): ?>
          <div class="rp-result rd-wide" role="status">
            <p class="rp-result-line"><strong><?= h($free <= 3 ? 'Only ' . plural($free, $noun) . ' left' : 'Available') ?></strong> for <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>.</p>
            <p class="muted"><?= h(plural($need, $noun)) ?> for <?= h(plural($n, 'night')) ?> · <?= h(plural($guests, 'guest')) ?></p>
            <p class="rp-total-line">Total <strong class="rp-total"><?= h(money((float)$type['base_rate'] * $n * $need)) ?></strong></p>
          </div>
        <?php else: ?>
          <div class="notice error rd-wide" role="alert">
            <?php if ($need > (int)$config['max_rooms_per_booking']): ?>
              Your group of <?= $guests ?> would need <?= h(plural($need, $noun)) ?>, more than the <?= (int)$config['max_rooms_per_booking'] ?> we take in one online booking. Try booking the whole place, or contact us.
            <?php elseif ($free === 0): ?>
              Sorry, the <?= h($type['type_name']) ?> is fully booked for those dates. Try other dates, or look at another stay below.
            <?php else: ?>
              Your group of <?= $guests ?> needs <?= h(plural($need, $noun)) ?>, but only <?= h(plural($free, $noun)) ?> <?= $free === 1 ? 'is' : 'are' ?> free for those dates. Try other dates, or look at another stay below.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="rd-actions rd-wide">
          <button class="btn primary" type="submit"<?= $ok ? '' : ' disabled' ?>>Book now</button>
          <?= str_replace('<button class="btn"', '<button class="btn"' . ($ok ? '' : ' disabled'), trip_add_button()) ?>
        </div>
      </form>

      <?php
        $rPayOn = !empty($config['online_payment_enabled']);
        $rDpPct = $rPayOn ? (int)($config['downpayment_percent'] ?? 50) : 0;
      ?>
      <p class="muted rd-fine"><?= $rDpPct >= 100
        ? '100% GCash/Maya payment required.'
        : ($rDpPct > 0
            ? $rDpPct . '% GCash/Maya downpayment to confirm · balance at resort.'
            : 'Pay at the resort' . ($rPayOn ? ' or via GCash/Maya.' : '.')) ?></p>
    </section>
  </aside>

  <section class="rd-details">
    <h2>About this <?= $noun ?></h2>
    <?php foreach ($paras as $para): ?><p><?= h($para) ?></p><?php endforeach; ?>
    <dl class="facts rd-facts">
      <dt>Sleeps</dt><dd>Up to <?= $cap ?> guests per <?= $noun ?></dd>
      <dt>Rate</dt><dd><?= h(money($type['base_rate'])) ?> per night</dd>
      <dt>Check-in</dt><dd>From <?= h($config['checkin_time']) ?></dd>
      <dt>Check-out</dt><dd>By <?= h($config['checkout_time']) ?></dd>
    </dl>

    <div class="rp-others">
      <span class="muted">Other stays</span>
      <?php foreach ($others as $o): ?>
        <a href="<?= h(ru('room.php?id=' . (int)$o['room_type_id'])) ?>"><?= h($o['type_name']) ?> <small><?= h(money($o['base_rate'])) ?></small></a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php site_end();
