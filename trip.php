<?php
/**
 * Your trip: the stays picked so far, grouped by resort, checked live, with one button to book them all together.
 */
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/trips.php';
require_guest_to_book($pdo);   // the trip needs an account

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'remove') {
        flash(trip_remove((string)($_POST['id'] ?? '')) ? 'Removed from your trip.' : 'That stay was already removed.');
    }
    redirect('trip.php');
}

$rows   = trip_resolve_all($pdo);
$allOk  = $rows && !array_filter($rows, fn($r) => !$r['ok']);
$groups = [];
foreach ($rows as $r) {
    $groups[$r['slug']][] = $r;
}
$total = 0.0;
foreach ($rows as $r) {
    $total += $r['ok'] ? $r['total'] : 0;
}

site_start('Your trip', 'trip');
?>
<div class="page-head">
  <h1>Your trip</h1>
  <a class="btn" href="resorts.php#resorts">Add another resort</a>
</div>

<?php if (!$rows): ?>
  <p class="panel empty">Nothing here yet. <a href="resorts.php#resorts">Find a resort</a></p>
<?php else: ?>
<div class="checkout">
  <div class="trip-list">
    <?php foreach ($groups as $slug => $stays): $rs = $stays[0]['resort']; ?>
      <section class="panel trip-group">
        <header class="tg-head">
          <h2><a href="<?= h('landing.php?r=' . rawurlencode($slug)) ?>"><?= h($rs['name'] ?? 'Resort') ?></a></h2>
          <?php if (!empty($rs['location'])): ?><span class="muted"><?= h($rs['location']) ?></span><?php endif; ?>
        </header>
        <?php foreach ($stays as $r):
            $theme = RESORT_THEMES[$rs['theme'] ?? 'ocean'] ?? RESORT_THEMES['ocean'];
            $bg    = $r['photo'] !== '' ? "url('" . h($r['photo']) . "')" : h($theme[1]);
            ?>
          <article class="trip-stay<?= $r['ok'] ? '' : ' is-off' ?>">
            <span class="ts-photo" style="background-image:<?= $bg ?>" aria-hidden="true"></span>
            <div class="ts-body">
              <h3><?= h($r['label'] !== '' ? $r['label'] : 'Stay') ?></h3>
              <p class="muted">
                <?php if ($r['scope'] === 'Cottage'): ?>
                  <?= h(fdate($r['check_in'])) ?> · Day use
                <?php else: ?>
                  <?= h(fdate($r['check_in'])) ?> to <?= h(fdate($r['check_out'])) ?><?= $r['nights'] > 0 ? ' · ' . h(plural($r['nights'], 'night')) : '' ?>
                <?php endif; ?><br>
                <?= h(plural($r['adults'] + $r['children'], 'guest')) ?>
              </p>
              <?php if (!$r['ok']): ?><p class="ex-why"><?= h($r['why']) ?></p><?php endif; ?>
            </div>
            <div class="ts-side">
              <?php if ($r['ok']): ?><p class="price"><?= h(money($r['total'])) ?></p><?php endif; ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="btn danger" type="submit" data-confirm="Are you sure you want to remove this stay from your trip?" data-confirm-title="Remove stay" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <aside class="panel summary-card after-list" aria-label="Trip summary">
    <h2>Your trip</h2>
    <dl class="facts totals trip-sum">
      <dt>Stays</dt><dd class="num"><?= count($rows) ?></dd>
      <dt>Resorts</dt><dd class="num"><?= count($groups) ?></dd>
      <dt>Total</dt><dd class="num strong"><?= h(money($total)) ?></dd>
    </dl>
    <p class="muted">Pay at each resort. No payment is taken now. You choose add-ons on the next page.</p>
    <?php if ($allOk): ?>
      <a class="btn primary" href="trip_checkout.php">Book trip</a>
    <?php else: ?>
      <p class="ex-why">Remove the stays that aren’t available to continue.</p>
    <?php endif; ?>
  </aside>
</div>
<?php endif; ?>
<?php site_end();
