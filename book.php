<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_resort($pdo);

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;

$RID      = resort_id();
$types    = $pdo->query("SELECT * FROM room_type WHERE resort_id = $RID ORDER BY base_rate")->fetchAll();
$services = $pdo->query("SELECT * FROM service WHERE resort_id = $RID ORDER BY category, service_name")->fetchAll();
$exclusive = exclusive_options($pdo, $in, $out);

site_start('Book your stay');
?>
<div class="page-head"><h1>Book your stay</h1></div>
<?= booking_steps(1) ?>
<p class="muted lede">Choose your dates and who is coming. We show only the rooms and cottages that are really free. Free to search, and you pay at the resort.</p>
<?php require __DIR__ . '/includes/search_form.php'; ?>

<section class="block">
  <div class="block-head">
    <h2>Rooms and cottages</h2>
    <a href="<?= h(ru('rooms.php')) ?>">See all</a>
  </div>
  <div class="room-cards">
    <?php foreach ($types as $t): ?>
      <?= room_card($t) ?>
    <?php endforeach; ?>
  </div>
</section>

<?= exclusive_cards($exclusive, null) ?>

<section class="block">
  <div class="block-head"><h2>Add to your stay</h2></div>
  <p class="muted">Choose these while booking, or ask our team after you arrive.</p>
  <ul class="addons">
    <?php foreach ($services as $s): ?>
      <li><span><?= h($s['service_name']) ?> <small class="muted"><?= h($s['category']) ?></small></span><b><?= h(money($s['unit_price'])) ?></b></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="block info-row">
  <div><h3>Check-in</h3><p>From <?= h($config['checkin_time']) ?></p></div>
  <div><h3>Check-out</h3><p>By <?= h($config['checkout_time']) ?></p></div>
  <div><h3>Cancellation</h3><p>Free online up to <?= plural((int)$config['free_cancel_days'], 'day') ?> before arrival.</p></div>
</section>
<?php site_end();
