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
        'name' => [true, 80], 'tagline' => [false, 160], 'description' => [false, 1000],
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
    // Complete resort address in inverted pyramid format: City, Barangay, Street/Drive, House/Lot/Bldg. no.
    if (array_key_exists('loc_city', $post) || array_key_exists('loc_barangay', $post) || array_key_exists('loc_street', $post)) {
        $lc = trim((string)($post['loc_city'] ?? ''));
        $lb = trim((string)($post['loc_barangay'] ?? ''));
        $ls = trim((string)($post['loc_street'] ?? ''));
        if ($lc === '' || $lb === '' || $ls === '') {
            throw new RuntimeException('Provide the resort’s complete address: City, Barangay, and Street / Drive are required.');
        }
        $loc = address_compose($post, 'loc_', 'location');
    } else {
        $loc = trim((string)($post['location'] ?? ''));
        if ($loc === '') {
            throw new RuntimeException('Provide the resort’s complete address.');
        }
    }
    if (mb_strlen($loc) > 220) {
        throw new RuntimeException('Keep the resort address under 220 characters.');
    }
    $d['location'] = $loc;
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
    $d['allow_cancellation'] = (string)($post['allow_cancellation'] ?? '1') === '0' ? 0 : 1;
    $d['online_payment_enabled'] = (string)($post['online_payment_enabled'] ?? '1') === '0' ? 0 : 1;
    $dp = trim((string)($post['downpayment_percent'] ?? '50'));
    if (!ctype_digit($dp) || (int)$dp < 0 || (int)$dp > 100) {
        throw new RuntimeException('Required downpayment percentage must be a whole number from 0 to 100.');
    }
    $d['downpayment_percent'] = (int)$dp;
    foreach (['gcash_name' => 100, 'gcash_number' => 30, 'maya_name' => 100, 'maya_number' => 30] as $pk => $pmax) {
        $pv = trim((string)($post[$pk] ?? ''));
        if (mb_strlen($pv) > $pmax) {
            throw new RuntimeException('Keep the ' . str_replace('_', ' ', $pk) . ' under ' . $pmax . ' characters.');
        }
        $d[$pk] = $pv === '' ? null : $pv;
    }
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
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('That photo is too big. Use a picture under 8 MB.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The photo could not be uploaded. Please try again.');
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        throw new RuntimeException('Use a JPG, PNG or WebP picture.');
    }
    $dir = __DIR__ . '/../assets/resorts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not save the photo: the assets/resorts folder is not writable.');
    }
    $name = $slug . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the photo: the assets/resorts folder is not writable.');
    }
    // Saved first, so a failed upload never leaves the resort without a photo. Now remove an older one with another file type.
    foreach (glob($dir . '/' . $slug . '.*') ?: [] as $old) {
        if (basename($old) !== $name) {
            @unlink($old);
        }
    }
    clearstatcache();
    return 'resorts/' . $name;
}

/** The fields shared by "add a resort" and "resort settings". */
function resort_form_fields(array $r = []): void
{
    $v = fn(string $k, string $def = '') => h($r[$k] ?? $def);
    $locParts = address_parse((string)($r['location'] ?? ''));
    ?>
    <label class="wide"><span>Resort name</span><input placeholder="e.g. Hannah’s Nature Camp" name="name" value="<?= $v('name') ?>" maxlength="80" required></label>
    <fieldset class="cancel-terms wide">
      <legend>Complete resort address <small class="muted">(City, Barangay, Street / Drive, House / Lot no.)</small></legend>
      <?= address_fields_html($locParts, 'loc_', true) ?>
    </fieldset>
    <label class="wide"><span>Tagline</span><input placeholder="e.g. Rest by the lake" name="tagline" value="<?= $v('tagline') ?>" maxlength="160"></label>
    <label class="wide"><span>About the resort</span><textarea name="description" rows="3" maxlength="1000"><?= $v('description') ?></textarea></label>
    <label><span>Contact email</span><input placeholder="e.g. hello@yourresort.com" type="email" name="contact_email" value="<?= $v('contact_email') ?>" maxlength="120"></label>
    <label><span>Contact phone</span><input placeholder="e.g. 0917 123 4567" name="contact_phone" value="<?= $v('contact_phone') ?>" maxlength="40"></label>
    <label><span>Check-in time</span><input placeholder="e.g. 2:00 PM" name="checkin_time" value="<?= $v('checkin_time', '2:00 PM') ?>" maxlength="20" required></label>
    <label><span>Check-out time</span><input placeholder="e.g. 12:00 NN" name="checkout_time" value="<?= $v('checkout_time', '12:00 NN') ?>" maxlength="20" required></label>
    <?php resort_cancel_fields($r, $v); ?>
    <?php resort_payment_fields($r, $v); ?>
    <label><span>Price to book the whole resort (per night)</span><input type="number" name="exclusive_rate" value="<?= $v('exclusive_rate') ?>" min="0" step="0.01" placeholder="Blank = total of all room rates"></label>
    <label><span>Colour theme</span>
      <select name="theme">
        <?php foreach (RESORT_THEMES as $k => $_): ?><option value="<?= h($k) ?>"<?= ($r['theme'] ?? 'ocean') === $k ? ' selected' : '' ?>><?= h(ucfirst($k)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span>Cover photo (JPG, PNG or WebP, under 8 MB)</span><input type="file" name="hero" accept="image/jpeg,image/png,image/webp"></label>
    <?php $coverFile = (string)($r['hero_image'] ?? ''); if ($coverFile !== '' && is_file(__DIR__ . '/../assets/' . $coverFile)): ?>
      <p class="wide wiz-cover"><img src="../assets/<?= h($coverFile) ?>?v=<?= (int)filemtime(__DIR__ . '/../assets/' . $coverFile) ?>" alt="Current cover photo"><span class="muted">Current cover photo. Choose a new file to replace it.</span></p>
    <?php endif; ?>
    <label class="check wide"><input type="checkbox" name="is_active" value="1"<?= ($r['is_active'] ?? 1) ? ' checked' : '' ?>><span>Open for bookings and listed on the resort directory</span></label>
    <?php
}


/** The two cancellation terms: a switch, and how many days before check-in the last online cancellation is. */
function resort_cancel_fields(array $r, callable $v): void
{
    $on = (int)($r['allow_cancellation'] ?? 1) === 1;
    ?>
    <fieldset class="cancel-terms wide">
      <legend>Guest cancellation</legend>
      <input type="hidden" name="allow_cancellation" value="0">
      <label class="check"><input type="checkbox" name="allow_cancellation" value="1" data-cancel-switch<?= $on ? ' checked' : '' ?>><span>Let guests cancel their booking online</span></label>
      <label class="cancel-days"><span>Last day to cancel: how many days before check-in</span>
        <input type="number" name="free_cancel_days" value="<?= $v('free_cancel_days', '2') ?>" min="0" max="60" step="1" placeholder="e.g. 2">
      </label>
    </fieldset>
    <?php
}

/** GCash & Maya downpayment settings (per resort). */
function resort_payment_fields(array $r, callable $v): void
{
    $payOn = (int)($r['online_payment_enabled'] ?? 1) === 1;
    ?>
    <fieldset class="cancel-terms wide">
      <legend>GCash &amp; Maya payments</legend>
      <input type="hidden" name="online_payment_enabled" value="0">
      <label class="check"><input type="checkbox" name="online_payment_enabled" value="1" data-pay-switch<?= $payOn ? ' checked' : '' ?>><span>Accept GCash &amp; Maya downpayments</span></label>
      <label class="cancel-days"><span>Required downpayment (%)</span>
        <input type="number" name="downpayment_percent" value="<?= $v('downpayment_percent', '50') ?>" min="0" max="100" step="1" placeholder="e.g. 50 (0 = none, 100 = full)">
      </label>
      <div class="grid-form">
        <label><span>GCash account name</span><input name="gcash_name" value="<?= $v('gcash_name') ?>" maxlength="100" placeholder="Account holder / Resort name"></label>
        <label><span>GCash number</span><input name="gcash_number" value="<?= $v('gcash_number') ?>" maxlength="30" placeholder="0917 123 4567"></label>
        <label><span>Maya account name</span><input name="maya_name" value="<?= $v('maya_name') ?>" maxlength="100" placeholder="Account holder / Resort name"></label>
        <label><span>Maya number</span><input name="maya_number" value="<?= $v('maya_number') ?>" maxlength="30" placeholder="0918 765 4321"></label>
      </div>
    </fieldset>
    <?php
}
