<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_resort($pdo);

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;
$q        = trim((string)($_GET['q'] ?? ''));

$RID       = resort_id();
$exclusive = exclusive_options($pdo, $in, $out);
$types     = $pdo->query("SELECT * FROM room_type WHERE resort_id = $RID AND archived_at IS NULL ORDER BY base_rate")->fetchAll();
$cottages  = cottage_availability($pdo, $in);

site_start('Book', 'rooms');
?>
<div class="page-head"><h1>Book</h1></div>
<div class="rooms-name-search" data-stay-filter-wrap>
  <span class="rns-ico" aria-hidden="true"><?= icon('search', 18) ?></span>
  <input type="search" id="rooms-name-filter" value="<?= h($q) ?>" placeholder="Search by resort, building, pool, room, or cottage name…" aria-label="Search bookings by name" maxlength="80" autocomplete="off" data-stay-filter>
  <button type="button" class="btn sm" data-stay-filter-clear<?= $q === '' ? ' hidden' : '' ?>>Clear</button>
</div>
<?php require __DIR__ . '/includes/search_form.php'; ?>

<div data-stay-filter-scope>
  <?= exclusive_cards($exclusive, null, 2) ?>

  <?php if ($types): ?>
  <section class="block" data-filter-section>
    <div class="block-head"><h2>Overnight rooms &amp; stays</h2></div>
    <?= capped_list(array_map('room_card', $types), 2) ?>
  </section>
  <?php endif; ?>

  <?php if ($cottages): ?>
  <section class="block" data-filter-section>
    <div class="block-head"><h2>Day-use cottages</h2></div>
    <?= capped_list(array_map(fn($c) => cottage_card($c, $in, $adults, $children), $cottages), 2) ?>
  </section>
  <?php endif; ?>

  <p class="panel empty" data-stay-filter-empty hidden>No stays match your search.</p>
</div>
<?php site_end();
