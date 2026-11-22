<?php
// Resort photos: the pictures behind the public "See photos" page. Resort admins and the super admin only.
require __DIR__ . '/../includes/resort_admin.php';   // also loads the layout and the background-photo helper
require_once __DIR__ . '/../includes/gallery.php';
$staffMember = require_login($pdo, false, 'admin');
$self = 'gallery.php';
$RID = resort_id();

// A form bigger than the server's post_max_size arrives completely empty: say what happened instead of failing silently.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('Those photos are too big to send at once (the limit is ' . review_size_label(review_media_limits()['post_bytes']) . ' for everything together). Upload fewer photos at a time.', 'error');
    redirect($self);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['photo_id'] ?? 0);
    try {
        // Background photo of the resort (the big picture behind its home page). Works for resort admins and the super admin.
        if ($act === 'cover') {
            $cover = resort_save_cover($resort['slug']);
            if ($cover === null) {
                throw new RuntimeException('Choose a photo first.');
            }
            $pdo->prepare('UPDATE resort SET hero_image = ? WHERE resort_id = ?')->execute([$cover, $RID]);
            flash('Background photo saved.');
            redirect($self);
        }
        if ($act === 'cover_remove') {
            $old = (string)($resort['hero_image'] ?? '');
            $pdo->prepare('UPDATE resort SET hero_image = NULL WHERE resort_id = ?')->execute([$RID]);
            if (preg_match('#^resorts/[a-z0-9_\-]+\.(jpe?g|png|webp)$#i', $old) && is_file(__DIR__ . '/../assets/' . $old)) {
                @unlink(__DIR__ . '/../assets/' . $old);
            }
            flash('Background photo removed. Guests now see the colour backdrop.');
            redirect($self);
        }
        if (!gallery_available($pdo)) {
            throw new RuntimeException('Resort photos are not set up yet. Run database/upgrade_resort_photos.sql once.');
        }
        if ($act === 'upload') {
            $have = count(gallery_photos($pdo, $RID));
            $items = gallery_check_uploads($_FILES, $have);   // checks every file first, so a bad file means nothing is saved
            gallery_store($pdo, $RID, $items);
            flash(plural(count($items), 'photo') . ' added.');
        } elseif ($act === 'caption') {
            gallery_set_caption($pdo, $RID, $pid, (string)($_POST['caption'] ?? ''));
            flash('Caption saved.');
        } elseif ($act === 'move') {
            gallery_move($pdo, $RID, $pid, ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down');
        } elseif ($act === 'remove') {
            flash(gallery_remove($pdo, $RID, $pid) ? 'Photo removed.' : 'That photo no longer exists.', 'ok');
        }
    } catch (PDOException $e) {
        flash('The database rejected that change.', 'error');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($self);
}

$photos = gallery_photos($pdo, $RID);
$lim = review_media_limits();

admin_start('Resort photos', 'gallery');
$coverRel = (string)($resort['hero_image'] ?? '');
$hasCover = $coverRel !== '' && is_file(__DIR__ . '/../assets/' . $coverRel);
admin_head(
    'Resort photos',
    '',
    '<button type="button" class="btn primary" data-form-open="upload-modal">Upload photos</button>'
  . '<button type="button" class="btn" data-form-open="cover-modal">' . ($hasCover ? 'Change' : 'Add') . ' background photo</button>'
  . '<a class="btn" href="../' . h(ru('photos.php')) . '" target="_blank" rel="noopener">View photos page</a>'
);
?>
<nav class="seg" aria-label="Photos">
  <a href="gallery.php" aria-current="page">Resort photos</a>
  <a href="photos.php">Room photos</a>
</nav>
<div class="modal" id="upload-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="upload-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head"><h2 id="upload-title">Upload photos</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <label><span>Photos</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Upload</button></div>
    </form>
  </div>
</div>

<div class="modal" id="cover-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="cover-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head"><h2 id="cover-title">Background photo</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
    <?php if ($hasCover): ?>
      <p style="margin:0 0 14px"><img src="../assets/<?= h($coverRel) ?>?v=<?= (int)filemtime(__DIR__ . '/../assets/' . $coverRel) ?>" alt="Current background photo" style="width:100%;border-radius:12px"></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="cover">
      <label><span><?= $hasCover ? 'Replace with' : 'Choose a photo' ?></span><input type="file" name="hero" accept="image/jpeg,image/png,image/webp" required></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save photo</button></div>
    </form>
    <?php if ($hasCover): ?>
    <form method="post" style="margin-top:12px;text-align:right">
      <?= csrf_field() ?><input type="hidden" name="action" value="cover_remove">
      <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove the background photo?" data-confirm-title="Remove background photo" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove background photo</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>Photos</h2></div>
  <?php if (!$photos): ?>
    <p class="empty">No photos yet. <button type="button" class="link-btn" data-form-open="upload-modal">Upload some</button>.</p>
  <?php else: ?>
  <div class="gal-admin">
    <?php foreach ($photos as $i => $p): ?>
      <figure class="gal-card">
        <a href="<?= h(gallery_url($p, false, '../')) ?>" target="_blank" rel="noopener"><img src="<?= h(gallery_url($p, true, '../')) ?>" alt="<?= h((string)($p['caption'] ?: 'Photo ' . ($i + 1))) ?>" loading="lazy"></a>
        <form method="post" class="gal-caption">
          <?= csrf_field() ?><input type="hidden" name="action" value="caption"><input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>">
          <input name="caption" value="<?= h((string)$p['caption']) ?>" maxlength="120" placeholder="Caption (optional)" aria-label="Caption for photo <?= $i + 1 ?>">
          <button class="btn sm" type="submit">Save</button>
        </form>
        <div class="gal-tools">
          <span class="muted"><?= $i + 1 ?> of <?= count($photos) ?></span>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>"><input type="hidden" name="dir" value="up">
            <button class="btn sm" type="submit" aria-label="Move earlier" title="Move earlier"<?= $i === 0 ? ' disabled' : '' ?>>←</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>"><input type="hidden" name="dir" value="down">
            <button class="btn sm" type="submit" aria-label="Move later" title="Move later"<?= $i === count($photos) - 1 ? ' disabled' : '' ?>>→</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="photo_id" value="<?= (int)$p['photo_id'] ?>">
            <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove this photo?" data-confirm-title="Remove photo" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button></form>
        </div>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php admin_end();
