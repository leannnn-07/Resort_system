<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require __DIR__ . '/includes/reviews.php';
require_resort($pdo);

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;

$RID      = resort_id();
$types    = $pdo->query("SELECT * FROM room_type WHERE resort_id = $RID ORDER BY base_rate")->fetchAll();
$prices   = $pdo->query("SELECT * FROM price_item WHERE resort_id = $RID ORDER BY category, sort_order, item_name")->fetchAll();
$fromRate = $types ? (float)$types[0]['base_rate'] : 0;

$rStats   = review_stats($pdo, $RID);
$rLatest  = $pdo->query("SELECT * FROM review WHERE resort_id = $RID AND status = 'Published' ORDER BY (comment IS NULL OR comment = ''), created_at DESC, review_id DESC LIMIT 3")->fetchAll();

$rates = [];
foreach ($prices as $p) {
    $rates[$p['category']][] = $p;
}

site_start('Welcome', '', 'landing bleed');
?>
<section class="landing-hero">
  <div class="lh-copy">
    <h1><?= h($config['resort']) ?></h1>
    <p class="hero-rating"><?= rating_line($rStats, ru('reviews.php')) ?></p>
    <p class="lead"><?= h($config['tagline']) ?></p>
  </div>

  <?php require __DIR__ . '/includes/search_form.php'; ?>
</section>

<?php if ($rLatest): ?>
<section class="block" id="reviews">
  <div class="block-head"><h2>What guests say</h2><a href="<?= h(ru('reviews.php')) ?>">Read all <?= h(plural($rStats['count'], 'review')) ?></a></div>
  <div class="review-list review-grid"><?php foreach ($rLatest as $r) { echo review_card($r); } ?></div>
</section>
<?php endif; ?>

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

<?php if ($rates): ?>
<section class="block">
  <div class="block-head">
    <h2>Entrance and cottage rates</h2>
    <a href="<?= h(ru('pricelist.php')) ?>">Full price list</a>
  </div>
  <div class="rate-cols">
    <?php foreach ($rates as $cat => $rows): ?>
      <div class="panel">
        <h2><?= h($cat) ?></h2>
        <ul class="price-rows">
          <?php foreach ($rows as $r): ?>
            <li>
              <span class="pr-name"><?= h($r['item_name']) ?></span>
              <span class="pr-amt"><?php if ((float)$r['price'] === 0.0): ?><b>Free</b><?php else: ?><b><?= h(money($r['price'])) ?></b><small class="muted"><?= h($r['unit']) ?></small><?php endif; ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php site_end();
