<?php
require __DIR__ . '/../includes/layout.php';
$staffMember = require_login($pdo, false, 'admin');
$self = 'photos.php';
$slug = $resort['slug'];
$dir  = __DIR__ . '/../assets/rooms/' . $slug;

$stmt = $pdo->prepare('SELECT room_type_id, type_name FROM room_type WHERE resort_id = ? AND archived_at IS NULL ORDER BY base_rate, type_name');
$stmt->execute([resort_id()]);
$types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $tid = (int)($_POST['room_type_id'] ?? 0);
        if (!isset($types[$tid])) {
            throw new RuntimeException('Choose a room type.');
        }
        $base = slugify($types[$tid]);
        if ($base === '') {
            throw new RuntimeException('Give this room type a name with letters or numbers first.');
        }
        if (($_POST['action'] ?? '') === 'delete') {
            $file = basename((string)($_POST['file'] ?? ''));
            if (!preg_match('/^' . preg_quote($base, '/') . '-\d+\.(jpe?g|png|webp)$/i', $file) || !is_file($dir . '/' . $file)) {
                throw new RuntimeException('That photo no longer exists.');
            }
            unlink($dir . '/' . $file);
            flash('Photo removed.');
        } else {
            $f = $_FILES['photo'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a photo to upload.');
            }
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 4 * 1024 * 1024) {
                throw new RuntimeException('The photo must be an image under 4 MB.');
            }
            $info = @getimagesize($f['tmp_name']);
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
            if (!$ext) {
                throw new RuntimeException('Use a JPG, PNG or WebP image.');
            }
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new RuntimeException('Could not save the photo: the assets/rooms folder is not writable.');
            }
            $n = 1;
            foreach (glob($dir . '/' . $base . '-*') ?: [] as $old) {
                if (preg_match('/-(\d+)\.[a-z]+$/i', $old, $m)) { $n = max($n, (int)$m[1] + 1); }
            }
            if ($n > 12) {
                throw new RuntimeException('A room type can have up to 12 photos. Remove one first.');
            }
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $base . '-' . $n . '.' . $ext)) {
                throw new RuntimeException('Could not save the photo: the assets/rooms folder is not writable.');
            }
            flash('Photo added to ' . $types[$tid] . '.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($self);
}

admin_start('Room photos', 'photos');
admin_head('Room photos', '',
    $types ? '<button type="button" class="btn primary" data-form-open="photo-modal">Add photo</button>' : '');
?>
<nav class="seg" aria-label="Photos">
  <a href="gallery.php">Resort photos</a>
  <a href="photos.php" aria-current="page">Room photos</a>
</nav>
<?php if (!$types): ?>
  <p class="empty">Add a room type first, then come back to add its photos.</p>
<?php else: foreach ($types as $tid => $name): $photos = room_photos($name); ?>
<section class="panel">
  <div class="panel-head"><h2><?= h($name) ?> <small class="muted">(<?= count($photos) ?> <?= count($photos) === 1 ? 'photo' : 'photos' ?>)</small></h2>
    <button type="button" class="btn sm" data-form-open="photo-modal" data-fill="<?= h(json_encode(['room_type_id' => (string)$tid])) ?>">Add photo</button></div>
  <?php if ($photos): ?>
  <div class="photo-grid">
    <?php foreach ($photos as $ph): $file = basename(parse_url($ph['url'], PHP_URL_PATH)); ?>
      <figure>
        <img src="../<?= h($ph['url']) ?>" alt="<?= h($name) ?>" loading="lazy">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="room_type_id" value="<?= (int)$tid ?>">
          <input type="hidden" name="file" value="<?= h($file) ?>">
          <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove this photo?" data-confirm-title="Remove photo" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button>
        </form>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="muted">No photos yet.</p><?php endif; ?>
</section>
<?php endforeach; endif; ?>
<?php if ($types): ?>
<div class="modal" id="photo-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="photo-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head"><h2 id="photo-title">Add a room photo</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <label><span>Room type</span>
        <select name="room_type_id" required>
          <?php foreach ($types as $tid => $name): ?><option value="<?= (int)$tid ?>"><?= h($name) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span>Photo</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Add photo</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php admin_end();
