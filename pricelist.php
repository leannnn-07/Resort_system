<?php
require __DIR__ . '/includes/layout.php';
require_resort($pdo);

$items = $pdo->query('SELECT * FROM price_item WHERE resort_id = ' . resort_id() . ' AND archived_at IS NULL ORDER BY category, sort_order, item_name')->fetchAll();
$groups = [];
foreach ($items as $it) {
    $groups[$it['category']][] = $it;
}
// Put entrance fees first, then cottages, then anything else staff add.
uksort($groups, function (string $a, string $b): int {
    $order = ['Entrance fee' => 0, 'Cottage (day use)' => 1];
    return ($order[$a] ?? 9) <=> ($order[$b] ?? 9) ?: strcmp($a, $b);
});

$stays = $pdo->query('SELECT room_type_id, type_name, max_occupancy, base_rate FROM room_type WHERE resort_id = ' . resort_id() . ' AND archived_at IS NULL ORDER BY base_rate')->fetchAll();
$cottage = null;
foreach ($stays as $t) {
    if (stripos($t['type_name'], 'cottage') !== false) { $cottage = $t; break; }
}

site_start('Price list', 'pricelist');
?>
<div class="page-head"><h1>Price list</h1></div>
<p class="muted lede">Entrance fees and cottage rates for a day at the resort, and our nightly rates if you want to stay over. Senior citizens, PWDs, and special promo offers receive their discount directly off the original price—verified and approved over the counter upon arrival.</p>

<div class="price-grid">
  <?php foreach ($groups as $name => $rows): ?>
    <section class="panel price-panel">
      <h2><?= h($name) ?></h2>
      <ul class="price-rows">
        <?php foreach ($rows as $r): ?>
          <li>
            <span class="pr-name"><?= h($r['item_name']) ?><?php if ($r['note']): ?><small class="muted"><?= h($r['note']) ?></small><?php endif; ?><?php if ($name === 'Cottage (day use)'): ?><small class="muted">Up to <?= (int)($r['max_guests'] ?? 15) ?> guests</small><?php endif; ?></span>
            <span class="pr-amt">
              <?php if ((float)$r['price'] === 0.0): ?><b>Free</b><?php else: ?><b><?= h(money($r['price'])) ?></b><small class="muted"><?= h($r['unit']) ?></small><?php endif; ?>
              <?php if ($name === 'Cottage (day use)'): ?>
                <a class="btn sm primary" style="margin-top:6px" href="<?= h(ru('checkout.php?' . http_build_query(['scope' => 'Cottage', 'target' => (int)$r['price_item_id'], 'check_in' => add_days(today(), 1), 'adults' => 2, 'children' => 0]))) ?>">Book cottage</a>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>

  <section class="panel price-panel">
    <h2>Overnight stays</h2>
    <ul class="price-rows">
      <?php foreach ($stays as $t): ?>
        <li>
          <span class="pr-name"><a href="<?= h(ru('room.php?id=' . (int)$t['room_type_id'])) ?>"><?= h($t['type_name']) ?></a><small class="muted">Sleeps up to <?= (int)$t['max_occupancy'] ?></small></span>
          <span class="pr-amt"><b><?= h(money($t['base_rate'])) ?></b><small class="muted">per night</small></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<section class="panel price-cta">
  <div>
    <h2>Want a cottage for your stay?</h2>
    <p class="muted">Pick a room or cottage to see photos and details, then check dates and book.</p>
  </div>
  <div class="actions">
    <?php if ($cottage): ?><a class="btn primary" href="<?= h(ru('room.php?id=' . (int)$cottage['room_type_id'])) ?>">View <?= h($cottage['type_name']) ?></a><?php endif; ?>
    <a class="btn" href="<?= h(ru('rooms.php')) ?>">Book</a>
  </div>
</section>
<?php site_end();
