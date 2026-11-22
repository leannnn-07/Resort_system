<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/payments.php';
require_resort($pdo);
require_guest_to_book($pdo);   // "Book a stay" needs an account

$payRules = resort_payment_rules();
$dpPct    = $payRules['downpayment_percent'];

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;

$RID      = resort_id();
$cottages = cottage_availability($pdo, $in);
$types    = $pdo->query("SELECT * FROM room_type WHERE resort_id = $RID AND archived_at IS NULL ORDER BY base_rate")->fetchAll();
$services = $pdo->query("SELECT * FROM service WHERE resort_id = $RID AND archived_at IS NULL ORDER BY category, service_name")->fetchAll();
$exclusive = exclusive_options($pdo, $in, $out);

site_start('Book a cottage');
?>
<div class="page-head"><h1>Book a cottage or stay</h1></div>
<p class="muted lede"><?= $dpPct >= 100 ? '100% GCash/Maya payment required.' : ($dpPct > 0 ? $dpPct . '% GCash/Maya downpayment to confirm.' : 'Pay at the resort or via GCash/Maya.') ?></p>
<?php require __DIR__ . '/includes/search_form.php'; ?>

<?= exclusive_cards($exclusive, null, 2) ?>

<?php if ($types): ?>
<section class="block">
  <div class="block-head">
    <h2>Overnight rooms &amp; stays</h2>
    <a href="<?= h(ru('rooms.php')) ?>">See all</a>
  </div>
  <?= capped_list(array_map('room_card', $types), 2) ?>
</section>
<?php endif; ?>

<?php if ($cottages): ?>
<section class="block" id="cottages">
  <div class="block-head">
    <h2>Day-use cottages</h2>
    <a href="<?= h(ru('pricelist.php')) ?>">Price list</a>
  </div>
  <?= capped_list(array_map(fn($c) => cottage_card($c, $in, $adults, $children), $cottages), 2) ?>
</section>
<?php endif; ?>

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
