<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_resort($pdo);

$in       = (string)($_GET['check_in'] ?? '');
$out      = (string)($_GET['check_out'] ?? '');
$adults   = max(1, (int)($_GET['adults'] ?? 2));
$children = max(0, (int)($_GET['children'] ?? 0));
$guests   = $adults + $children;

$problems = validate_stay($in, $out, $adults, $children);
$types    = [];
$n        = 0;
if (!$problems) {
    $n     = nights($in, $out);
    $types = room_type_availability($pdo, $in, $out);
}

site_start('Available rooms', 'rooms');
?>
<div class="page-head">
  <h1>Available rooms</h1>
</div>
<?= booking_steps(2) ?>

<?php require __DIR__ . '/includes/search_form.php'; ?>

<?php if ($problems): ?>
  <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
<?php else: ?>
  <p class="stay-line">
    <?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>,
    <?= h(plural($n, 'night')) ?>,
    <?= h(plural($adults, 'adult')) ?><?= $children > 0 ? ', ' . h(plural($children, 'child', 'children')) : '' ?>
  </p>

  <?php
  $bookable = 0;
  foreach ($types as $t) {
      $need = rooms_needed($guests, (int)$t['max_occupancy']);
      if ((int)$t['free_rooms'] >= $need && $need <= $config['max_rooms_per_booking']) {
          $bookable++;
      }
  }
  ?>
  <?php if ($bookable === 0): ?>
    <div class="notice error" role="alert">
      Nothing is available for those dates and that group size. Try other dates, or contact us at <?= h($config['contact_email']) ?>.
    </div>
  <?php endif; ?>

  <p class="muted">Travelling with a big group? <a href="<?= h(ru('exclusive.php?' . http_build_query(['check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children]))) ?>">Book a whole pool, building or the entire resort</a> for these dates.</p>

  <div class="type-list">
    <?php foreach ($types as $t):
        $free    = (int)$t['free_rooms'];
        $cap     = (int)$t['max_occupancy'];
        $need    = rooms_needed($guests, $cap);
        $maxPick = min($free, (int)$config['max_rooms_per_booking']);
        $ok      = $free >= $need && $need <= $maxPick;
        ?>
      <?php $ph = room_photos($t['type_name']); $thumb = $ph ? $ph[0]['url'] : ''; ?>
      <article class="type-row has-photo<?= $ok ? '' : ' is-off' ?>">
        <a class="tr-photo<?= $ph ? '' : ' no-photo' ?>" href="<?= h(ru('room.php?id=' . (int)$t['room_type_id'])) ?>"<?= $ph ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?> aria-label="View the <?= h($t['type_name']) ?>"></a>
        <div>
          <h2><a href="<?= h(ru('room.php?id=' . (int)$t['room_type_id'])) ?>"><?= h($t['type_name']) ?></a></h2>
          <p><?= h($t['description']) ?></p>
          <p class="muted">
            Sleeps up to <?= $cap ?> per room.
            <?php if ($free === 0): ?>
              Sold out for these dates.
            <?php elseif ($need > (int)$config['max_rooms_per_booking']): ?>
              Your group of <?= $guests ?> would need <?= h(plural($need, 'room')) ?>, more than the <?= (int)$config['max_rooms_per_booking'] ?> we take in one online booking. Try booking the whole place, or contact us.
            <?php elseif (!$ok): ?>
              Your group of <?= $guests ?> needs <?= h(plural($need, 'room')) ?>, but only <?= h(plural($free, 'room')) ?> <?= $free === 1 ? 'is' : 'are' ?> free.
            <?php else: ?>
              <?= $free <= 3 ? 'Only ' . h(plural($free, 'room')) . ' left.' : 'Rooms available.' ?>
            <?php endif; ?>
          </p>
        </div>

        <div class="type-side">
          <p class="price"><?= h(money($t['base_rate'])) ?> <small>per room per night</small></p>
          <?php if ($ok): ?>
            <p class="muted"><?= h(plural($need, 'room')) ?> for <?= h(plural($n, 'night')) ?>: <strong><?= h(money((float)$t['base_rate'] * $n * $need)) ?></strong></p>
            <form method="get" action="checkout.php" class="reserve">
              <?= rhidden() ?>
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
              <button class="btn primary" type="submit">Reserve</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php site_end();
