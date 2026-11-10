<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_resort($pdo);

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ?');
$stmt->execute([$id, resort_id()]);
$type = $stmt->fetch();
if (!$type) {
    redirect(ru('rooms.php'));
}

$cap    = (int)$type['max_occupancy'];
$noun   = stripos($type['type_name'], 'cottage') !== false ? 'cottage' : 'room';
$photos = room_photos($type['type_name']);
if (!$photos) {
    // No photos uploaded for this type yet: show the resort instead.
    $hero = resort_hero();
    $photos = [['url' => $hero, 'fit' => 'cover', 'portrait' => false]];
}

$text  = trim((string)($type['details'] ?? '')) !== '' ? (string)$type['details'] : (string)$type['description'];
$paras = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $text) ?: [])));

$checked  = isset($_GET['check_in']);
$in       = $checked ? (string)($_GET['check_in'] ?? '') : add_days(today(), 1);
$out      = $checked ? (string)($_GET['check_out'] ?? '') : add_days(today(), 3);
$adults   = max(1, (int)($_GET['adults'] ?? min(2, $cap)));
$children = max(0, (int)($_GET['children'] ?? 0));
$guests   = $adults + $children;

$problems = [];
$free = $need = $maxPick = $n = 0;
if ($checked) {
    $problems = validate_stay($in, $out, $adults, $children);
    if (!$problems) {
        $n       = nights($in, $out);
        $free    = count(available_rooms($pdo, $in, $out, $id));
        $need    = rooms_needed($guests, $cap);
        $maxPick = min($free, (int)$config['max_rooms_per_booking']);
    }
}
$ok = $checked && !$problems && $free >= $need && $need <= $maxPick;

$others = $pdo->prepare('SELECT room_type_id, type_name, base_rate FROM room_type WHERE room_type_id <> ? AND resort_id = ? ORDER BY base_rate');
$others->execute([$id, resort_id()]);
$others = $others->fetchAll();

site_start($type['type_name'], 'rooms', 'bleed room-page');
?>
<div class="rp-bg" aria-hidden="true">
  <?php foreach ($photos as $i => $p): ?>
    <div class="rp-slide<?= $i === 0 ? ' is-on' : '' ?><?= $p['portrait'] ? ' is-portrait' : '' ?>">
      <i class="rp-fill" style="background-image:url('<?= h($p['url']) ?>')"></i>
      <i class="rp-pic" style="background-image:url('<?= h($p['url']) ?>');background-size:<?= h($p['fit']) ?>"></i>
    </div>
  <?php endforeach; ?>
</div>

<section class="rp-stage" data-slides="<?= count($photos) ?>">
  <div class="rp-copy">
    <p class="rp-meta"><?= h($type['type_name']) ?> · Sleeps up to <?= $cap ?> · <?= h(money($type['base_rate'])) ?> <small>per night</small></p>
    <h1><?= h($type['type_name']) ?></h1>
    <?php foreach ($paras as $para): ?><p class="rp-text"><?= h($para) ?></p><?php endforeach; ?>
    <a class="rp-hint" href="#book">
      <span>Scroll down to book this <?= $noun ?></span>
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
    </a>
  </div>

  <?php if (count($photos) > 1): ?>
  <div class="rp-controls" role="group" aria-label="Photo slideshow">
    <button type="button" class="rp-btn" data-prev aria-label="Previous photo">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
    </button>
    <div class="rp-dots">
      <?php foreach ($photos as $i => $_): ?>
        <button type="button" class="rp-dot<?= $i === 0 ? ' is-on' : '' ?>" data-dot="<?= $i ?>" aria-label="Photo <?= $i + 1 ?> of <?= count($photos) ?>"></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="rp-btn" data-next aria-label="Next photo">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </button>
    <button type="button" class="rp-btn" data-play aria-label="Pause slideshow" aria-pressed="true">
      <svg class="i-pause" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
      <svg class="i-play" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M8 5l11 7-11 7z"/></svg>
    </button>
  </div>
  <?php endif; ?>
</section>

<section class="rp-book" id="book" aria-label="Book <?= h($type['type_name']) ?>">
  <h2>Book the <?= h($type['type_name']) ?></h2>
  <p class="muted">Pick your dates and group size. We check this <?= $noun ?> only, so what you see is what is free.</p>

  <form method="get" action="room.php#book" class="search-card" data-stay>
    <?= rhidden() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <label><span>Check-in</span>
      <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
    </label>
    <label><span>Check-out <em class="nights" data-nights></em></span>
      <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>" required>
    </label>
    <label><span>Adults</span>
      <input type="number" name="adults" value="<?= $adults ?>" min="1" max="20" step="1" required>
    </label>
    <label><span>Children</span>
      <input type="number" name="children" value="<?= $children ?>" min="0" max="20" step="1">
    </label>
    <button class="btn primary" type="submit">Check this <?= $noun ?></button>
  </form>

  <?php if ($checked && $problems): ?>
    <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
  <?php elseif ($checked && $ok): ?>
    <div class="rp-result">
      <div>
        <p class="rp-result-line"><strong><?= h($free <= 3 ? 'Only ' . plural($free, $noun) . ' left' : 'Available') ?></strong> for <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>.</p>
        <p class="muted"><?= h(plural($need, $noun)) ?> for <?= h(plural($n, 'night')) ?>, <?= h(plural($adults, 'adult')) ?><?= $children > 0 ? ', ' . h(plural($children, 'child', 'children')) : '' ?>:
          <strong class="rp-total"><?= h(money((float)$type['base_rate'] * $n * $need)) ?></strong></p>
      </div>
      <form method="get" action="checkout.php" class="reserve">
        <?= rhidden() ?>
        <input type="hidden" name="type" value="<?= $id ?>">
        <input type="hidden" name="check_in" value="<?= h($in) ?>">
        <input type="hidden" name="check_out" value="<?= h($out) ?>">
        <input type="hidden" name="adults" value="<?= $adults ?>">
        <input type="hidden" name="children" value="<?= $children ?>">
        <?php if ($maxPick > $need): ?>
          <label class="inline"><span><?= h(ucfirst($noun)) ?>s</span>
            <select name="rooms">
              <?php for ($r = $need; $r <= $maxPick; $r++): ?><option value="<?= $r ?>"><?= $r ?></option><?php endfor; ?>
            </select>
          </label>
        <?php else: ?>
          <input type="hidden" name="rooms" value="<?= $need ?>">
        <?php endif; ?>
        <button class="btn primary" type="submit">Reserve</button>
      </form>
    </div>
  <?php elseif ($checked): ?>
    <div class="notice error" role="alert">
      <?php if ($free === 0): ?>
        Sorry, the <?= h($type['type_name']) ?> is fully booked for those dates. Try other dates, or look at another stay below.
      <?php else: ?>
        Your group of <?= $guests ?> needs <?= h(plural($need, $noun)) ?>, but only <?= h(plural($free, $noun)) ?> <?= $free === 1 ? 'is' : 'are' ?> free for those dates. Try other dates, or look at another stay below.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="rp-others">
    <span class="muted">Other stays</span>
    <?php foreach ($others as $o): ?>
      <a href="<?= h(ru('room.php?id=' . (int)$o['room_type_id'])) ?>"><?= h($o['type_name']) ?> <small><?= h(money($o['base_rate'])) ?></small></a>
    <?php endforeach; ?>
  </div>
</section>
<?php site_end();
