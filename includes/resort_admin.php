<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

/** Turn a resort name into a unique URL slug ("Blue Bay Resort" -> "blue-bay-resort"). */
function unique_slug(PDO $pdo, string $name): string
{
    $base = slugify($name);
    $base = $base !== '' ? substr($base, 0, 50) : 'resort';
    $slug = $base;
    $check = $pdo->prepare('SELECT COUNT(*) FROM resort WHERE slug = ?');
    for ($i = 2; ; $i++) {
        $check->execute([$slug]);
        if ((int)$check->fetchColumn() === 0 && !in_array($slug, ['admin', 'assets', 'includes', 'storage', 'database'], true)) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
}

/** Read and validate the resort form. Returns the column values. Throws RuntimeException. */
function resort_read_post(array $post): array
{
    $d = [];
    $rules = [
        'name' => [true, 80], 'tagline' => [false, 160], 'location' => [false, 120], 'description' => [false, 1000],
        'contact_email' => [false, 120], 'contact_phone' => [false, 40], 'checkin_time' => [true, 20], 'checkout_time' => [true, 20],
    ];
    foreach ($rules as $k => [$required, $max]) {
        $v = trim((string)($post[$k] ?? ''));
        if ($required && $v === '') {
            throw new RuntimeException('Fill in the ' . str_replace('_', ' ', $k) . '.');
        }
        if (mb_strlen($v) > $max) {
            throw new RuntimeException('Keep the ' . str_replace('_', ' ', $k) . ' under ' . $max . ' characters.');
        }
        $d[$k] = $v === '' ? null : $v;
    }
    if ($d['contact_email'] !== null && !filter_var($d['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid contact email.');
    }
    $theme = (string)($post['theme'] ?? 'ocean');
    $d['theme'] = isset(RESORT_THEMES[$theme]) ? $theme : 'ocean';
    $days = (string)($post['free_cancel_days'] ?? '2');
    if (!ctype_digit($days) || (int)$days > 60) {
        throw new RuntimeException('Free cancellation days must be a whole number from 0 to 60.');
    }
    $d['free_cancel_days'] = (int)$days;
    $rate = trim((string)($post['exclusive_rate'] ?? ''));
    if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0)) {
        throw new RuntimeException('The whole-resort price must be a positive number.');
    }
    $d['exclusive_rate'] = $rate === '' ? null : $rate;
    $d['is_active'] = empty($post['is_active']) ? 0 : 1;
    return $d;
}

/** Save an uploaded cover photo for the resort; returns the stored name (relative to /assets) or null when nothing was sent. */
function resort_save_cover(string $slug): ?string
{
    $f = $_FILES['hero'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('The cover photo must be an image under 4 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        throw new RuntimeException('Use a JPG, PNG or WebP image for the cover photo.');
    }
    $dir = __DIR__ . '/../assets/resorts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not save the photo: the assets/resorts folder is not writable.');
    }
    foreach (glob($dir . '/' . $slug . '.*') ?: [] as $old) {
        @unlink($old);
    }
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $slug . '.' . $ext)) {
        throw new RuntimeException('Could not save the photo: the assets/resorts folder is not writable.');
    }
    return 'resorts/' . $slug . '.' . $ext;
}

/** The fields shared by "add a resort" and "resort settings". */
function resort_form_fields(array $r = []): void
{
    $v = fn(string $k, string $def = '') => h($r[$k] ?? $def);
    ?>
    <label><span>Resort name</span><input name="name" value="<?= $v('name') ?>" maxlength="80" required></label>
    <label><span>Location</span><input name="location" value="<?= $v('location') ?>" maxlength="120" placeholder="Town, Province"></label>
    <label class="wide"><span>Tagline (one line under the name)</span><input name="tagline" value="<?= $v('tagline') ?>" maxlength="160"></label>
    <label class="wide"><span>About the resort</span><textarea name="description" rows="3" maxlength="1000"><?= $v('description') ?></textarea></label>
    <label><span>Contact email</span><input type="email" name="contact_email" value="<?= $v('contact_email') ?>" maxlength="120"></label>
    <label><span>Contact phone</span><input name="contact_phone" value="<?= $v('contact_phone') ?>" maxlength="40"></label>
    <label><span>Check-in time</span><input name="checkin_time" value="<?= $v('checkin_time', '2:00 PM') ?>" maxlength="20" required></label>
    <label><span>Check-out time</span><input name="checkout_time" value="<?= $v('checkout_time', '12:00 NN') ?>" maxlength="20" required></label>
    <label><span>Free cancellation (days before check-in)</span><input type="number" name="free_cancel_days" value="<?= $v('free_cancel_days', '2') ?>" min="0" max="60" step="1"></label>
    <label><span>Price to book the whole resort (per night)</span><input type="number" name="exclusive_rate" value="<?= $v('exclusive_rate') ?>" min="0" step="0.01" placeholder="Blank = total of all room rates"></label>
    <label><span>Colour theme</span>
      <select name="theme">
        <?php foreach (RESORT_THEMES as $k => $_): ?><option value="<?= h($k) ?>"<?= ($r['theme'] ?? 'ocean') === $k ? ' selected' : '' ?>><?= h(ucfirst($k)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span>Cover photo (JPG, PNG or WebP, under 4 MB)</span><input type="file" name="hero" accept="image/jpeg,image/png,image/webp"></label>
    <label class="check wide"><input type="checkbox" name="is_active" value="1"<?= ($r['is_active'] ?? 1) ? ' checked' : '' ?>><span>Open for bookings and listed on the resort directory</span></label>
    <?php
}
