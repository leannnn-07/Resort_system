<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/gallery.php';
require_resort($pdo);

$photos = gallery_photos($pdo, resort_id());

site_start('Photos', 'photos');
?>
<div class="page-head"><h1>Photos</h1></div>
<p class="muted lede">A look around <?= h($config['resort']) ?>.</p>

<?php if (!$photos): ?>
  <p class="empty">No photos yet. Please check back soon.</p>
<?php else: ?>
  <div class="gallery-grid">
    <?php foreach ($photos as $p): ?>
      <a class="gal-item" href="<?= h(gallery_url($p)) ?>" data-lightbox="gallery">
        <img src="<?= h(gallery_url($p, true)) ?>" alt="<?= h((string)($p['caption'] ?: 'Photo of ' . $config['resort'])) ?>" loading="lazy" decoding="async">
        <?php if (!empty($p['caption'])): ?><span><?= h($p['caption']) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="gal-back"><a class="btn" href="<?= h(ru('landing.php')) ?>">Back to <?= h($config['resort']) ?></a> <a class="btn primary" href="<?= h(ru('book.php#book')) ?>">Book a stay</a></p>
<?php site_end();
