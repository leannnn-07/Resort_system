<?php
declare(strict_types=1);

// The resort's own photo gallery (the "See photos" page).
// Only resort admins and the super admin can add, caption, reorder or remove these photos.
// Files live in uploads/gallery/<resort id>/ with random names. The upload checks are the same safe ones review photos use.
require_once __DIR__ . '/reviews.php';

const GALLERY_MAX_PHOTOS = 60;     // per resort
const GALLERY_MAX_AT_ONCE = 20;    // per upload

/** True when the resort_photo table exists, so a site that has not run upgrade_resort_photos.sql keeps working. */
function gallery_available(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $pdo->query('SELECT 1 FROM resort_photo LIMIT 1');
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

function gallery_dir(int $rid): string
{
    return dirname(__DIR__) . '/uploads/gallery/' . $rid;
}

/** Web address of a photo, relative to the site folder ('../' on staff pages). Every upload gets a new random name, so a browser never shows an old copy. */
function gallery_url(array $p, bool $thumb = false, string $prefix = ''): string
{
    $file = $thumb && !empty($p['thumb_name']) ? $p['thumb_name'] : $p['file_name'];
    return $prefix . 'uploads/gallery/' . (int)$p['resort_id'] . '/' . rawurlencode((string)$file);
}

/** The resort's photos in the order they should be shown. */
function gallery_photos(PDO $pdo, int $rid): array
{
    if (!gallery_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM resort_photo WHERE resort_id = ? ORDER BY sort_order, photo_id');
    $stmt->execute([$rid]);
    return $stmt->fetchAll();
}

/** Check the photos sent with the upload form. $already = how many the resort has. Returns the files to store; throws RuntimeException with a readable message. */
function gallery_check_uploads(array $files, int $already): array
{
    $lim = review_media_limits();
    $list = review_files($files, 'photos');
    if (!$list) {
        throw new RuntimeException('Choose at least one photo.');
    }
    if (count($list) > GALLERY_MAX_AT_ONCE) {
        throw new RuntimeException('Add up to ' . GALLERY_MAX_AT_ONCE . ' photos at a time.');
    }
    if ($already + count($list) > GALLERY_MAX_PHOTOS) {
        throw new RuntimeException('A resort can have up to ' . GALLERY_MAX_PHOTOS . ' photos. Remove some first.');
    }
    $ok = [];
    foreach ($list as $f) {
        $name = mb_strimwidth($f['name'] !== '' ? $f['name'] : 'A file', 0, 40, '...');
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($name . ' is too big. Photos can be up to ' . review_size_label($lim['photo_bytes']) . '.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException($name . ' could not be uploaded. Please try again.');
        }
        $type = review_sniff($f['tmp_name']);
        if (!$type || $type[0] !== 'photo') {
            throw new RuntimeException($name . ' is not a JPG, PNG or WebP photo.');
        }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException($name . ' is not a valid picture.');
        }
        if ($f['size'] > $lim['photo_bytes']) {
            throw new RuntimeException($name . ' is over the ' . review_size_label($lim['photo_bytes']) . ' limit.');
        }
        $ok[] = ['ext' => $type[1], 'tmp' => $f['tmp_name'], 'size' => $f['size']];
    }
    return $ok;
}

/** Move the checked photos into uploads/gallery/<id>/ and add them at the end of the gallery. */
function gallery_store(PDO $pdo, int $rid, array $items): void
{
    $fail = 'The photos could not be saved: the uploads folder is not writable.';
    $dir = gallery_dir($rid);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException($fail);
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM resort_photo WHERE resort_id = ?');
    $max->execute([$rid]);
    $order = (int)$max->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO resort_photo (resort_id, file_name, thumb_name, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($items as $it) {
        $base = bin2hex(random_bytes(8));
        $file = $base . '.' . $it['ext'];
        if (!move_uploaded_file($it['tmp'], $dir . '/' . $file)) {
            throw new RuntimeException($fail);
        }
        $thumb = review_make_thumb($dir . '/' . $file, $dir . '/t_' . $base . '.jpg', $it['ext']) ? 't_' . $base . '.jpg' : null;
        $ins->execute([$rid, $file, $thumb, ++$order]);
    }
}

/** Delete one photo (only if it belongs to this resort). */
function gallery_remove(PDO $pdo, int $rid, int $photoId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM resort_photo WHERE photo_id = ? AND resort_id = ?');
    $stmt->execute([$photoId, $rid]);
    $p = $stmt->fetch();
    if (!$p) {
        return false;
    }
    $dir = gallery_dir($rid);
    @unlink($dir . '/' . basename((string)$p['file_name']));
    if (!empty($p['thumb_name'])) {
        @unlink($dir . '/' . basename((string)$p['thumb_name']));
    }
    $pdo->prepare('DELETE FROM resort_photo WHERE photo_id = ?')->execute([$photoId]);
    @rmdir($dir);   // only succeeds when the folder is now empty
    return true;
}

function gallery_set_caption(PDO $pdo, int $rid, int $photoId, string $caption): void
{
    $caption = mb_substr(trim($caption), 0, 120);
    $pdo->prepare('UPDATE resort_photo SET caption = ? WHERE photo_id = ? AND resort_id = ?')->execute([$caption === '' ? null : $caption, $photoId, $rid]);
}

/** Move a photo one place earlier ('up') or later ('down'). */
function gallery_move(PDO $pdo, int $rid, int $photoId, string $direction): void
{
    $stmt = $pdo->prepare('SELECT photo_id FROM resort_photo WHERE resort_id = ? ORDER BY sort_order, photo_id');
    $stmt->execute([$rid]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search($photoId, $ids, true);
    if ($i === false) {
        return;
    }
    $j = $direction === 'up' ? $i - 1 : $i + 1;
    if (!isset($ids[$j])) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $upd = $pdo->prepare('UPDATE resort_photo SET sort_order = ? WHERE photo_id = ? AND resort_id = ?');
    $pdo->beginTransaction();
    foreach ($ids as $n => $id) {
        $upd->execute([$n + 1, $id, $rid]);
    }
    $pdo->commit();
}
