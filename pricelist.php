<?php
require __DIR__ . '/includes/layout.php';
require_resort($pdo);

$items = $pdo->query('SELECT * FROM price_item WHERE resort_id = ' . resort_id() . ' ORDER BY category, sort_order, item_name')->fetchAll();
$groups = [];
foreach ($items as $it) {
    $groups[$it['category']][] = $it;
}
// Put entrance fees first, then cottages, then anything else staff add.
uksort($groups, function (string $a, string $b): int {
    $order = ['Entrance fee' => 0, 'Cottage (day use)' => 1];
    return ($order[$a] ?? 9) <=> ($order[$b] ?? 9) ?: strcmp($a, $b);
});

$stays = $pdo->query('SELECT room_type_id, type_name, max_occupancy, base_rate FROM room_type WHERE resort_id = ' . resort_id() . ' ORDER BY base_rate')->fetchAll();
$cottage = null;
foreach ($stays as $t) {
    if (stripos($t['type_name'], 'cottage') !== false) { $cottage = $t; break; }
}

site_start('Price list', 'pricelist');
?>
<div class="page-head"><h1>Price list</h1></div>
<p class="muted lede">Entrance fees and cottage rates for a day at the resort, and our nightly rates if you want to stay over.</p>

<div class="price-grid">
  <?php foreach ($groups as $name => $rows): ?>
    <section class="panel price-panel">
      <h2><?= h($name) ?></h2>
      <ul class="price-rows">
        <?php foreach ($rows as $r): ?>
          <li>
            <span class="pr-name"><?= h($r['item_name']) ?><?php if ($r['note']): ?><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></span>
            <span class="pr-amt">
              <?php if ((float)$r['price'] === 0.0): ?><b>Free</b><?php else: ?><b><?= h(money($r['price'])) ?></b><small class="muted"><?= h($r['unit']) ?></small><?php endif; ?>
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
    <p class="muted">Open any room or cottage to see its photos and full description, then scroll to check dates and book it.</p>
  </div>
  <div class="actions">
    <?php if ($cottage): ?><a class="btn primary" href="<?= h(ru('room.php?id=' . (int)$cottage['room_type_id'])) ?>">View <?= h($cottage['type_name']) ?></a><?php endif; ?>
    <a class="btn" href="<?= h(ru('rooms.php')) ?>">All rooms and cottages</a>
  </div>
</section>
<?php site_end();
