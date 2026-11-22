<?php
// Guest ratings, comments, photos and videos. Only guests with a finished stay can review, one review per stay.

/** Average rating, count and a 1..5 breakdown of the published reviews of one resort. */
function review_stats(PDO $pdo, int $resortId): array
{
    $stmt = $pdo->prepare("SELECT rating, COUNT(*) FROM review WHERE resort_id = ? AND status = 'Published' GROUP BY rating");
    $stmt->execute([$resortId]);
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $rating => $n) {
        $dist[(int)$rating] = (int)$n;
    }
    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $r => $n) {
        $sum += $r * $n;
    }
    return ['count' => $count, 'avg' => $count ? $sum / $count : 0.0, 'dist' => $dist];
}

/** [resort_id => ['count', 'avg']] for every resort at once (resort list and platform overview). */
function review_stats_all(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT resort_id, COUNT(*) AS c, AVG(rating) AS a FROM review WHERE status = 'Published' GROUP BY resort_id") as $r) {
        $out[(int)$r['resort_id']] = ['count' => (int)$r['c'], 'avg' => (float)$r['a']];
    }
    return $out;
}

/** Star icons for a rating (partial stars are shown, e.g. 4.3). */
function stars(float $avg): string
{
    $pct = max(0, min(100, round($avg / 5 * 100)));
    return '<span class="stars" style="--pct:' . $pct . '%" role="img" aria-label="' . h(number_format($avg, 1)) . ' out of 5 stars"></span>';
}

/** "4.3 ★★★★☆ (12 reviews)" for a resort card or heading. */
function rating_line(array $st, string $href = ''): string
{
    if (!$st || $st['count'] < 1) {
        return '<span class="rating-line muted">No reviews yet</span>';
    }
    $text = '<strong>' . h(number_format($st['avg'], 1)) . '</strong> ' . stars($st['avg']) . ' <span class="muted">(' . h(plural($st['count'], 'review')) . ')</span>';
    return '<span class="rating-line">' . ($href !== '' ? '<a href="' . h($href) . '">' . $text . '</a>' : $text) . '</span>';
}

/** "Maria S." from a guest's name, so a full surname is never published. */
function review_author(string $first, string $last): string
{
    $initial = mb_strtoupper(mb_substr(trim($last), 0, 1));
    return trim(mb_substr(trim($first), 0, 40) . ($initial !== '' ? ' ' . $initial . '.' : ''));
}

/** One published review as a card. */
function review_card(array $r, array $media = []): string
{
    $html = '<article class="review"><header><div>' . stars((float)$r['rating'])
          . ($r['title'] ? ' <strong class="rv-title">' . h($r['title']) . '</strong>' : '') . '</div>'
          . '<p class="muted rv-meta">' . h($r['author']) . ' · ' . h(fdate(substr((string)$r['created_at'], 0, 10))) . ($r['reservation_id'] ? ' · Verified stay' : '') . '</p></header>';
    if (trim((string)$r['comment']) !== '') {
        $html .= '<p class="rv-text">' . nl2br(h($r['comment'])) . '</p>';
    }
    $html .= review_media_html($media, (string)$r['author']);
    if (trim((string)$r['reply']) !== '') {
        $html .= '<div class="rv-reply"><strong>Reply from the resort</strong><p>' . nl2br(h($r['reply'])) . '</p></div>';
    }
    return $html . '</article>';
}

/** Check a posted rating form. Returns [rating, title|null, comment|null]; throws RuntimeException with a guest-friendly message. */
function parse_review(array $post): array
{
    $rating  = (string)($post['rating'] ?? '');
    $title   = trim((string)($post['title'] ?? ''));
    $comment = trim((string)($post['comment'] ?? ''));
    if (!ctype_digit($rating) || (int)$rating < 1 || (int)$rating > 5) {
        throw new RuntimeException('Choose a rating from 1 to 5 stars.');
    }
    if (mb_strlen($title) > 100) {
        throw new RuntimeException('Keep the headline under 100 characters.');
    }
    if (mb_strlen($comment) > 1000) {
        throw new RuntimeException('Keep your comment under 1000 characters.');
    }
    return [(int)$rating, $title === '' ? null : $title, $comment === '' ? null : $comment];
}

/** The review a stay already has, if any. */
function review_for(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM review WHERE reservation_id = ?');
    $stmt->execute([$reservationId]);
    return $stmt->fetch() ?: null;
}

/** Save (or update) the review of a finished stay, with any photos and videos that came with it. $files is $_FILES. */
function save_review(PDO $pdo, array $res, array $post, array $files = []): void
{
    if ($res['status'] !== 'Checked-out') {
        throw new RuntimeException('You can review a stay after you have checked out.');
    }
    [$rating, $title, $comment] = parse_review($post);

    // Check every file first, so a bad file means nothing at all is saved.
    $existing = review_for($pdo, (int)$res['reservation_id']);
    $mine = $existing ? review_media_for($pdo, (int)$existing['review_id']) : [];
    $drop = array_map('intval', (array)($post['remove_media'] ?? []));
    $have = ['photo' => 0, 'video' => 0];
    foreach ($mine as $m) {
        if (!in_array((int)$m['media_id'], $drop, true)) {
            $have[$m['kind']]++;
        }
    }
    $incoming = review_check_uploads($files, $have);
    if ($incoming && !review_media_available($pdo)) {
        throw new RuntimeException('Photos and videos are not switched on for this site yet. Your review was not saved; send it without them, or ask the resort to run database/upgrade_review_media.sql.');
    }

    $author = review_author((string)$res['first_name'], (string)$res['last_name']);
    $pdo->prepare(
        'INSERT INTO review (resort_id, reservation_id, guest_id, author, rating, title, comment) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), title = VALUES(title), comment = VALUES(comment), updated_at = NOW()'
    )->execute([$res['resort_id'], $res['reservation_id'], $res['guest_id'], $author, $rating, $title, $comment]);

    $saved = review_for($pdo, (int)$res['reservation_id']);
    if ($saved) {
        review_media_remove($pdo, (int)$saved['review_id'], $drop);
        review_media_store($pdo, (int)$saved['review_id'], $incoming);
    }
}

/** Radio stars + headline + comment + photos and videos: the form shown on a finished booking. */
function review_form(string $ref, ?array $existing, array $media = [], bool $uploads = true): string
{
    $cur = (int)($existing['rating'] ?? 0);
    $lim = review_media_limits();
    $havePhotos = count(array_filter($media, fn($m) => $m['kind'] === 'photo'));
    $haveVideos = count($media) - $havePhotos;
    $html = '<form method="post" enctype="multipart/form-data" class="stack" id="review-form" data-upload-form'
          . ' data-max-photos="' . $lim['photos'] . '" data-max-videos="' . $lim['videos'] . '"'
          . ' data-photo-bytes="' . $lim['photo_bytes'] . '" data-video-bytes="' . $lim['video_bytes'] . '"'
          . ' data-have-photos="' . $havePhotos . '" data-have-videos="' . $haveVideos . '">' . csrf_field()
          . '<input type="hidden" name="ref" value="' . h($ref) . '"><input type="hidden" name="action" value="review">'
          . '<fieldset class="rate-pick"><legend>Your rating</legend><div class="rate-row">';
    for ($i = 5; $i >= 1; $i--) {
        $html .= '<input type="radio" name="rating" id="rate' . $i . '" value="' . $i . '"' . ($cur === $i ? ' checked' : '') . ' required>'
               . '<label for="rate' . $i . '" title="' . $i . ' star' . ($i > 1 ? 's' : '') . '"><span class="sr-only">' . $i . ' star' . ($i > 1 ? 's' : '') . '</span></label>';
    }
    $html .= '</div></fieldset>'
           . '<label><span>Headline</span><input name="title" maxlength="100" value="' . h((string)($existing['title'] ?? '')) . '" placeholder="Optional, e.g. A perfect weekend getaway"></label>'
           . '<label><span>Your comment</span><textarea name="comment" rows="4" maxlength="1000" placeholder="Optional, e.g. What did you enjoy? What could be better?">' . h((string)($existing['comment'] ?? '')) . '</textarea></label>';

    if ($media) {
        $html .= '<fieldset class="rv-current"><legend>Your photos and videos</legend><div class="rv-media rv-media-edit">';
        foreach ($media as $m) {
            $full = h(review_media_url($m));
            $thumb = $m['kind'] === 'video'
                ? '<video class="rv-video" preload="metadata" muted playsinline src="' . $full . '#t=0.1"></video>'
                : '<img src="' . h(review_media_url($m, true)) . '" alt="Your photo" loading="lazy">';
            $html .= '<label class="rv-item">' . $thumb . '<span><input type="checkbox" name="remove_media[]" value="' . (int)$m['media_id'] . '" data-kind="' . h($m['kind']) . '"> Remove</span></label>';
        }
        $html .= '</div></fieldset>';
    }

    if ($uploads) {
        $html .= '<fieldset class="rv-upload"><legend>Add photos or videos (optional)</legend>'
               . '<label><span>Photos</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-kind="photo"></label>'
               . '<label><span>Videos</span><input type="file" name="videos[]" accept="video/mp4,video/webm,video/quicktime" multiple data-kind="video"></label>'
               . '<p class="muted rv-hint">Up to ' . $lim['photos'] . ' photos (JPG, PNG or WebP, ' . h(review_size_label($lim['photo_bytes'])) . ' each) and '
               . $lim['videos'] . ' videos (MP4, WebM or MOV, ' . h(review_size_label($lim['video_bytes'])) . ' each) per review. '
               . 'They are shown with your review on the resort page, so please share only pictures and videos you took yourself.</p>'
               . '<p class="notice error" role="alert" data-upload-msg hidden></p></fieldset>';
    }
    return $html . '<div class="actions"><button class="btn primary" type="submit">' . ($existing ? 'Update my review' : 'Post my review') . '</button></div></form>';
}

// ---------- Photos and videos on a review ----------
// The files live in uploads/reviews/<review id>/ with random names; review_media holds one row per file.
const REVIEW_MAX_PHOTOS = 6;
const REVIEW_MAX_VIDEOS = 2;

/** php.ini sizes such as "40M" as bytes (0 means no limit). */
function ini_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $n = (float)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;   // fall through
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** What a guest may upload. The sizes never go above the server's own php.ini limits, so the form never promises more than the server accepts. */
function review_media_limits(): array
{
    $upload = ini_to_bytes((string)ini_get('upload_max_filesize'));
    $post   = ini_to_bytes((string)ini_get('post_max_size'));
    $photo  = 8 * 1024 * 1024;
    $video  = 50 * 1024 * 1024;
    foreach ([$upload, $post > 0 ? $post - 256 * 1024 : 0] as $cap) {
        if ($cap > 0) {
            $photo = min($photo, $cap);
            $video = min($video, $cap);
        }
    }
    return ['photos' => REVIEW_MAX_PHOTOS, 'videos' => REVIEW_MAX_VIDEOS, 'photo_bytes' => max(0, $photo), 'video_bytes' => max(0, $video), 'post_bytes' => $post];
}

function review_size_label(int $bytes): string
{
    return $bytes >= 1048576 ? (int)floor($bytes / 1048576) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** True when the review_media table exists (so a site that has not run upgrade_review_media.sql keeps working without it). */
function review_media_available(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $pdo->query('SELECT 1 FROM review_media LIMIT 1');
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

function review_media_dir(int $reviewId): string
{
    return dirname(__DIR__) . '/uploads/reviews/' . $reviewId;
}

/** Web address of a file, relative to the site folder. $prefix is '../' on staff pages. */
function review_media_url(array $m, bool $thumb = false, string $prefix = ''): string
{
    $file = $thumb && !empty($m['thumb_name']) ? $m['thumb_name'] : $m['file_name'];
    return $prefix . 'uploads/reviews/' . (int)$m['review_id'] . '/' . rawurlencode((string)$file);
}

/** The photos and videos of one review. */
function review_media_for(PDO $pdo, int $reviewId): array
{
    if (!review_media_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM review_media WHERE review_id = ? ORDER BY media_id');
    $stmt->execute([$reviewId]);
    return $stmt->fetchAll();
}

/** [review_id => [media rows]] for a page of reviews, in one query. */
function review_media_map(PDO $pdo, array $reviews): array
{
    $ids = array_values(array_map(fn($r) => (int)$r['review_id'], $reviews));
    if (!$ids || !review_media_available($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM review_media WHERE review_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY media_id');
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $m) {
        $map[(int)$m['review_id']][] = $m;
    }
    return $map;
}

/** Photos as small pictures that open full size, then videos with a play button. */
function review_media_html(array $media, string $author, string $prefix = ''): string
{
    if (!$media) {
        return '';
    }
    $html = '<div class="rv-media">';
    foreach ($media as $m) {
        if ($m['kind'] === 'photo') {
            $html .= '<a class="rv-photo" href="' . h(review_media_url($m, false, $prefix)) . '" data-lightbox="r' . (int)$m['review_id'] . '">'
                   . '<img src="' . h(review_media_url($m, true, $prefix)) . '" alt="Photo shared by ' . h($author) . '" loading="lazy" decoding="async"></a>';
        }
    }
    foreach ($media as $m) {
        if ($m['kind'] === 'video') {
            $url = h(review_media_url($m, false, $prefix));
            $html .= '<video class="rv-video" controls preload="metadata" playsinline src="' . $url . '"><a href="' . $url . '">Watch the video</a></video>';
        }
    }
    return $html . '</div>';
}

/** Uploaded files of one input as a plain list. Empty slots are skipped. */
function review_files(array $files, string $key): array
{
    $f = $files[$key] ?? null;
    if (!is_array($f) || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        $f = array_map(fn($v) => [$v], $f);   // a single file: make it a list of one
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => (string)$name, 'tmp_name' => (string)($f['tmp_name'][$i] ?? ''), 'error' => $err, 'size' => (int)($f['size'][$i] ?? 0)];
    }
    return $out;
}

/** Say what a file really is from its first bytes (never from its name): ['photo'|'video', extension, mime] or null. */
function review_sniff(string $path): ?array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    $head = (string)fread($fh, 16);
    fclose($fh);
    if (strlen($head) < 12) {
        return null;
    }
    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return ['photo', 'jpg', 'image/jpeg'];
    }
    if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
        return ['photo', 'png', 'image/png'];
    }
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return ['photo', 'webp', 'image/webp'];
    }
    if (substr($head, 4, 4) === 'ftyp') {
        $brand = substr($head, 8, 4);
        if (preg_match('/^(hei|mif|msf|avif|avis|crx)/', $brand)) {
            return null;   // HEIC / AVIF pictures: most browsers cannot show them
        }
        return $brand === 'qt  ' ? ['video', 'mov', 'video/quicktime'] : ['video', 'mp4', 'video/mp4'];
    }
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
        return ['video', 'webm', 'video/webm'];
    }
    return null;
}

/**
 * Check the photos and videos sent with a review form. $have = how many of each the review keeps from before.
 * Returns the files to store; throws RuntimeException (a message the guest can read) if anything is wrong.
 */
function review_check_uploads(array $files, array $have): array
{
    $lim = review_media_limits();
    $all = array_merge(review_files($files, 'photos'), review_files($files, 'videos'));
    $ok = [];
    $count = ['photo' => 0, 'video' => 0];
    foreach ($all as $f) {
        $name = mb_strimwidth($f['name'] !== '' ? $f['name'] : 'A file', 0, 40, '...');
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($name . ' is too big for this website to accept. Photos can be up to ' . review_size_label($lim['photo_bytes']) . ' and videos up to ' . review_size_label($lim['video_bytes']) . '.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException($name . ' could not be uploaded. Please try again.');
        }
        $type = review_sniff($f['tmp_name']);
        if (!$type) {
            throw new RuntimeException($name . ' is not a photo or video we can use. Photos: JPG, PNG or WebP. Videos: MP4, WebM or MOV.');
        }
        [$kind, $ext, $mime] = $type;
        if ($kind === 'photo' && (!($info = @getimagesize($f['tmp_name'])) || $info[0] < 1 || $info[1] < 1)) {
            throw new RuntimeException($name . ' is not a valid picture.');
        }
        $max = $kind === 'photo' ? $lim['photo_bytes'] : $lim['video_bytes'];
        if ($f['size'] > $max) {
            throw new RuntimeException($name . ' is over the ' . review_size_label($max) . ' limit for a ' . $kind . '.');
        }
        $count[$kind]++;
        $ok[] = ['kind' => $kind, 'ext' => $ext, 'mime' => $mime, 'tmp' => $f['tmp_name'], 'size' => $f['size']];
    }
    if ($count['photo'] + (int)($have['photo'] ?? 0) > $lim['photos']) {
        throw new RuntimeException('A review can have up to ' . $lim['photos'] . ' photos. Remove some or choose fewer.');
    }
    if ($count['video'] + (int)($have['video'] ?? 0) > $lim['videos']) {
        throw new RuntimeException('A review can have up to ' . $lim['videos'] . ' videos. Remove one or choose fewer.');
    }
    return $ok;
}

/** A small JPEG preview of a photo (max 480 px, turned upright) so review lists load fast. False if the server cannot make one. */
function review_make_thumb(string $src, string $dst, string $ext): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
        return false;
    }
    $img = match ($ext) {
        'jpg'   => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($src) : false,
        'png'   => function_exists('imagecreatefrompng') ? @imagecreatefrompng($src) : false,
        'webp'  => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default => false,
    };
    if (!$img) {
        return false;
    }
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle !== 0 && ($rot = imagerotate($img, $angle, 0))) {
            $img = $rot;
        }
    }
    $w = imagesx($img);
    $hgt = imagesy($img);
    $scale = min(1, 480 / max($w, $hgt));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($hgt * $scale));
    $out = imagecreatetruecolor($tw, $th);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // see-through PNGs get a white background
    imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $hgt);
    return (bool)@imagejpeg($out, $dst, 82);
}

/** Move the checked files into uploads/reviews/<id>/ and record them. */
function review_media_store(PDO $pdo, int $reviewId, array $items): void
{
    if (!$items) {
        return;
    }
    $fail = 'Your review was saved, but the photos and videos could not be stored: the uploads folder is not writable.';
    $dir = review_media_dir($reviewId);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException($fail);
    }
    $ins = $pdo->prepare('INSERT INTO review_media (review_id, kind, file_name, thumb_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($items as $it) {
        $base = bin2hex(random_bytes(8));
        $file = $base . '.' . $it['ext'];
        if (!move_uploaded_file($it['tmp'], $dir . '/' . $file)) {
            throw new RuntimeException($fail);
        }
        $thumb = null;
        if ($it['kind'] === 'photo' && review_make_thumb($dir . '/' . $file, $dir . '/t_' . $base . '.jpg', $it['ext'])) {
            $thumb = 't_' . $base . '.jpg';
        }
        $ins->execute([$reviewId, $it['kind'], $file, $thumb, $it['mime'], $it['size']]);
    }
}

/** Delete some of a review's files (only ones that belong to that review). Returns how many were removed. */
function review_media_remove(PDO $pdo, int $reviewId, array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !review_media_available($pdo)) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT * FROM review_media WHERE review_id = ? AND media_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute(array_merge([$reviewId], $ids));
    $rows = $stmt->fetchAll();
    $dir = review_media_dir($reviewId);
    foreach ($rows as $m) {
        @unlink($dir . '/' . basename((string)$m['file_name']));
        if (!empty($m['thumb_name'])) {
            @unlink($dir . '/' . basename((string)$m['thumb_name']));
        }
        $pdo->prepare('DELETE FROM review_media WHERE media_id = ?')->execute([(int)$m['media_id']]);
    }
    if ($rows) {
        @rmdir($dir);   // only succeeds when the folder is now empty
    }
    return count($rows);
}
