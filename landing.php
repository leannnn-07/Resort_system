<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/reviews.php';
require_once __DIR__ . '/includes/gallery.php';
require_resort($pdo);

$in       = add_days(today(), 1);
$out      = add_days(today(), 3);
$adults   = 2;
$children = 0;

$RID       = resort_id();
$exclusive = exclusive_options($pdo, $in, $out);
$types     = $pdo->query("SELECT * FROM room_type WHERE resort_id = $RID AND archived_at IS NULL ORDER BY base_rate")->fetchAll();
$prices    = $pdo->query("SELECT * FROM price_item WHERE resort_id = $RID AND archived_at IS NULL ORDER BY category, sort_order, item_name")->fetchAll();
$cottages  = cottage_availability($pdo, $in);
$entrance  = array_values(array_filter($prices, fn($p) => $p['category'] === 'Entrance fee'));
$fromRate = $types ? (float)$types[0]['base_rate'] : 0;

$strip    = array_slice(gallery_photos($pdo, $RID), 0, 4);
$rStats   = review_stats($pdo, $RID);
$rLatest  = $pdo->query("SELECT * FROM review WHERE resort_id = $RID AND status = 'Published' ORDER BY (comment IS NULL OR comment = ''), created_at DESC, review_id DESC LIMIT 3")->fetchAll();

site_start('Welcome', '', 'landing bleed');
?>
<section class="landing-hero">
  <div class="lh-copy">
    <h1><?= h($config['resort']) ?></h1>
    <p class="hero-rating"><?= rating_line($rStats, ru('reviews.php')) ?></p>
    <p class="lead"><?= h($config['tagline']) ?></p>
    <?php if (!empty($resort['location'])): ?>
    <p class="lh-address">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      <span><?= h($resort['location']) ?></span>
    </p>
    <?php endif; ?>
    <?php if ($strip): ?>
    <div class="lh-strip">
      <?php foreach ($strip as $ph): ?>
        <a href="<?= h(gallery_url($ph)) ?>" data-lightbox="home"><img src="<?= h(gallery_url($ph, true)) ?>" alt="<?= h((string)($ph['caption'] ?: 'Photo of ' . $config['resort'])) ?>" loading="lazy" decoding="async"></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="lh-actions"><a class="btn" href="<?= h(ru('photos.php')) ?>">See all photos</a></p>
  </div>

  <?php require __DIR__ . '/includes/search_form.php'; ?>
</section>

<?= exclusive_cards($exclusive, null, 2) ?>

<?php if ($types): ?>
<section class="block">
  <div class="block-head">
    <h2>Overnight rooms &amp; stays</h2>
  </div>
  <?= capped_list(array_map('room_card', $types), 2) ?>
</section>
<?php endif; ?>

<?php if ($cottages): ?>
<section class="block" id="cottages">
  <div class="block-head">
    <h2>Day-use cottages</h2>
  </div>
  <?= capped_list(array_map(fn($c) => cottage_card($c, $in, $adults, $children), $cottages), 2) ?>
</section>
<?php endif; ?>

<?php if ($entrance): ?>
<section class="block">
  <div class="block-head">
    <h2>Entrance fees</h2>
    <a href="<?= h(ru('pricelist.php')) ?>">See price list</a>
  </div>
  <div class="panel">
    <ul class="price-rows">
      <?php foreach ($entrance as $r): ?>
        <li>
          <span class="pr-name"><?= h($r['item_name']) ?><?php if (!empty($r['note'])): ?><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></span>
          <span class="pr-amt"><?php if ((float)$r['price'] === 0.0): ?><b>Free</b><?php else: ?><b><?= h(money($r['price'])) ?></b><small class="muted"><?= h($r['unit']) ?></small><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($rLatest): ?>
<section class="block" id="reviews">
  <div class="block-head"><h2>What guests say</h2><a href="<?= h(ru('reviews.php')) ?>">See all <?= h(plural($rStats['count'], 'review')) ?></a></div>
  <div class="review-list review-grid"><?php $rMedia = review_media_map($pdo, $rLatest); foreach ($rLatest as $r) { echo review_card($r, $rMedia[(int)$r['review_id']] ?? []); } ?></div>
</section>
<?php endif; ?>

<?php site_end();
