<?php
// Add a resort, one small step at a time (super admin only).
// Step 1 creates the resort as a hidden draft; every later step saves straight away, so nothing is lost if you stop.
// The resort only goes live on the last step.
require __DIR__ . '/../includes/resort_admin.php';
require_once __DIR__ . '/../includes/gallery.php';

$staffMember = require_login($pdo, true);   // super admin only
$self = 'resort_new.php';

// [key, title, short label for the progress bar]
$STEPS = [
    1 => ['basics',     'The basics',             'Basics'],
    2 => ['contact',    'Contact and stay rules', 'Contact'],
    3 => ['buildings',  'Buildings or areas',     'Buildings'],
    4 => ['room_types', 'Room types',             'Room types'],
    5 => ['rooms',      'Rooms',                  'Rooms'],
    6 => ['pools',      'Pools',                  'Pools'],
    7 => ['services',   'Services',               'Services'],
    8 => ['pricelist',  'Price list',             'Prices'],
    9 => ['photos',     'Resort photos',          'Photos'],
    10 => ['finish',    'Finish',                 'Finish'],
];

// The steps that are a short list of the same kind of thing. 'int'/'min'/'maxv' limit numbers, 'max' limits text length.
$LISTS = [
    'buildings' => [
        'table' => 'building', 'pk' => 'building_id', 'singular' => 'Building', 'order' => 'name', 'optional' => false,
        'intro' => 'A building (or area) is a group of rooms, such as “Beach Row” or “Main House”. Add at least one, even if the resort is a single building. You can add the rest later.',
        'fields' => [
            ['name' => 'name',        'label' => 'Name',              'type' => 'text', 'required' => true, 'max' => 80],
            ['name' => 'description', 'label' => 'Short description', 'type' => 'text', 'max' => 300],
        ],
        'cols' => [['Name', 'name'], ['Description', 'description']],
    ],
    'room_types' => [
        'table' => 'room_type', 'pk' => 'room_type_id', 'singular' => 'Room type', 'order' => 'base_rate', 'optional' => false,
        'intro' => 'A room type is a kind of room guests can choose, for example “Standard Room” or “Family Cottage”, with its own price. Add the kinds you offer; the next step adds the actual rooms.',
        'fields' => [
            ['name' => 'type_name',     'label' => 'Name',             'type' => 'text',   'required' => true, 'max' => 60],
            ['name' => 'max_occupancy', 'label' => 'Max guests',       'type' => 'number', 'required' => true, 'int' => true, 'min' => 1, 'maxv' => 50, 'default' => 2],
            ['name' => 'base_rate',     'label' => 'Rate per night',   'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
            ['name' => 'description',   'label' => 'Short description', 'type' => 'text',  'max' => 300],
        ],
        'cols' => [['Name', 'type_name'], ['Max guests', 'max_occupancy'], ['Rate per night', 'base_rate', 'money'], ['Description', 'description']],
    ],
    'pools' => [
        'table' => 'pool', 'pk' => 'pool_id', 'singular' => 'Pool', 'order' => 'name', 'optional' => true,
        'intro' => 'Does the resort have a pool? Add it here. If you leave the whole-pool price empty, guests simply can’t book the pool by itself. No pool? Skip this step.',
        'fields' => [
            ['name' => 'name',         'label' => 'Name',                       'type' => 'text',   'required' => true, 'max' => 80],
            ['name' => 'max_guests',   'label' => 'Max guests at once',         'type' => 'number', 'required' => true, 'int' => true, 'min' => 1, 'maxv' => 500, 'default' => 20],
            ['name' => 'rate_per_day', 'label' => 'Whole-pool price per day',   'type' => 'number', 'min' => 0, 'step' => '0.01'],
            ['name' => 'description',  'label' => 'Short description',          'type' => 'text',   'max' => 300],
        ],
        'cols' => [['Name', 'name'], ['Max guests', 'max_guests'], ['Whole pool per day', 'rate_per_day', 'money']],
    ],
    'services' => [
        'table' => 'service', 'pk' => 'service_id', 'singular' => 'Service', 'order' => 'category, service_name', 'optional' => true,
        'intro' => 'Extras guests can add to a booking, such as breakfast, an airport pickup or an island trip. Skip this if you want to add them later.',
        'fields' => [
            ['name' => 'service_name', 'label' => 'Name',       'type' => 'text',   'required' => true, 'max' => 80],
            ['name' => 'category',     'label' => 'Category',   'type' => 'text',   'max' => 40, 'placeholder' => 'Dining, Activity, Transport…'],
            ['name' => 'unit_price',   'label' => 'Unit price', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
        ],
        'cols' => [['Name', 'service_name'], ['Category', 'category'], ['Unit price', 'unit_price', 'money']],
    ],
    'pricelist' => [
        'table' => 'price_item', 'pk' => 'price_item_id', 'singular' => 'Price', 'order' => 'category, sort_order, item_name', 'optional' => true,
        'intro' => 'The public price list: entrance fees and day-use cottages for visitors who aren’t staying overnight. Skip this if the resort has none.',
        'fields' => [
            ['name' => 'category',  'label' => 'Group', 'type' => 'select', 'required' => true, 'options' => ['Entrance fee', 'Cottage (day use)']],
            ['name' => 'item_name', 'label' => 'Name',  'type' => 'text',   'required' => true, 'max' => 80],
            ['name' => 'price',     'label' => 'Price', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
            ['name' => 'unit',      'label' => 'Per',   'type' => 'text',   'required' => true, 'max' => 40, 'default' => 'per person'],
            ['name' => 'note',      'label' => 'Note (shown under the name)', 'type' => 'text', 'max' => 160],
        ],
        'cols' => [['Group', 'category'], ['Name', 'item_name'], ['Price', 'price', 'money'], ['Per', 'unit']],
    ],
];

/** Read and check one small form. Returns column => value. Throws RuntimeException with a readable message. */
function wiz_read(array $fields, array $post): array
{
    $data = [];
    foreach ($fields as $f) {
        $v = trim((string)($post[$f['name']] ?? ''));
        $label = mb_strtolower($f['label']);
        if (!empty($f['required']) && $v === '') {
            throw new RuntimeException($f['label'] . ' is required.');
        }
        if ($v !== '' && isset($f['max']) && mb_strlen($v) > $f['max']) {
            throw new RuntimeException('Keep the ' . $label . ' under ' . $f['max'] . ' characters.');
        }
        if ($v !== '' && $f['type'] === 'number') {
            if (!is_numeric($v) || (!empty($f['int']) && !ctype_digit($v))) {
                throw new RuntimeException('The ' . $label . ' must be ' . (!empty($f['int']) ? 'a whole number.' : 'a number.'));
            }
            if (isset($f['min']) && (float)$v < $f['min']) {
                throw new RuntimeException('The ' . $label . ' can’t be less than ' . $f['min'] . '.');
            }
            if (isset($f['maxv']) && (float)$v > $f['maxv']) {
                throw new RuntimeException('The ' . $label . ' can’t be more than ' . $f['maxv'] . '.');
            }
        }
        if ($v !== '' && $f['type'] === 'select' && !in_array($v, $f['options'], true)) {
            throw new RuntimeException($f['label'] . ' isn’t a valid choice.');
        }
        $data[$f['name']] = $v === '' ? null : $v;
    }
    return $data;
}

/** "101, 102, 105-110, V1" becomes ['101','102','105',...,'110','V1']. Up to 100 at a time. */
function wiz_room_numbers(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        if (preg_match('/^(\d{1,6})-(\d{1,6})$/', $tok, $m)) {
            [$a, $b] = [(int)$m[1], (int)$m[2]];
            if ($b < $a || $b - $a > 99) {
                throw new RuntimeException('“' . $tok . '” isn’t a usable range. Use something like 101-110 (up to 100 rooms).');
            }
            $pad = (strlen($m[1]) > 1 && $m[1][0] === '0') ? strlen($m[1]) : 0;   // 001-010 keeps its leading zeros
            for ($i = $a; $i <= $b; $i++) {
                $out[] = $pad ? str_pad((string)$i, $pad, '0', STR_PAD_LEFT) : (string)$i;
            }
        } elseif (preg_match('/^[A-Za-z0-9]{1,10}$/', $tok)) {
            $out[] = $tok;
        } else {
            throw new RuntimeException('“' . mb_strimwidth($tok, 0, 20, '…') . '” isn’t a valid room number. Use letters and numbers only (up to 10 characters).');
        }
    }
    $out = array_values(array_unique($out));
    if (!$out) {
        throw new RuntimeException('Enter at least one room number.');
    }
    if (count($out) > 100) {
        throw new RuntimeException('Add up to 100 rooms at a time.');
    }
    foreach ($out as $n) {
        if (strlen($n) > 10) {
            throw new RuntimeException('Room numbers can be at most 10 characters.');
        }
    }
    return $out;
}

function wiz_count(PDO $pdo, string $table, int $rid): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE resort_id = ?");   // $table is always one of our own table names
    $stmt->execute([$rid]);
    return (int)$stmt->fetchColumn();
}

/** One input of a small form, filled from $vals (what was just posted) when there is something. */
function wiz_field(array $f, array $vals): void
{
    $name = h($f['name']);
    $val = h($vals[$f['name']] ?? ($f['default'] ?? ''));
    $req = !empty($f['required']) ? ' required' : '';
    echo '<label><span>' . h($f['label']) . '</span>';
    if ($f['type'] === 'select') {
        echo '<select name="' . $name . '"' . $req . '>';
        foreach ($f['options'] as $o) {
            echo '<option value="' . h($o) . '"' . (($vals[$f['name']] ?? '') === $o ? ' selected' : '') . '>' . h($o) . '</option>';
        }
        echo '</select>';
    } elseif ($f['type'] === 'number') {
        echo '<input type="number" name="' . $name . '" value="' . $val . '"' . $req
           . ' min="' . h($f['min'] ?? 0) . '"' . (isset($f['maxv']) ? ' max="' . h($f['maxv']) . '"' : '') . ' step="' . h($f['step'] ?? '1') . '" inputmode="decimal">';
    } else {
        echo '<input name="' . $name . '" value="' . $val . '"' . $req . ' maxlength="' . (int)($f['max'] ?? 120) . '"'
           . (!empty($f['placeholder']) ? ' placeholder="' . h($f['placeholder']) . '"' : '') . '>';
    }
    echo '</label>';
}

// ---------- Which resort are we setting up? ----------
$rid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$res = $rid > 0 ? resort_by_id($pdo, $rid) : null;
if ($rid > 0 && !$res) {
    flash('That resort no longer exists.', 'error');
    redirect('resorts.php');
}
$step = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$step = ($res && isset($STEPS[$step])) ? $step : 1;
$key  = $STEPS[$step][0];
$L    = $LISTS[$key] ?? null;
$form = [];   // what was just posted, to refill a form after a mistake
$to   = fn(int $n): string => $self . '?id=' . $rid . '&step=' . $n;

// A form bigger than the server's post_max_size arrives completely empty: say what happened instead of failing silently.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('Those photos are too big to send at once (the limit is ' . review_size_label(review_media_limits()['post_bytes']) . ' for everything together). Upload fewer photos at a time.', 'error');
    redirect($res ? $to($step) : $self);
}

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($step === 1) {
            $d = resort_read_post($_POST + ['checkin_time' => $res['checkin_time'] ?? '2:00 PM', 'checkout_time' => $res['checkout_time'] ?? '12:00 NN']);
            if (!$res) {
                $slug = unique_slug($pdo, (string)$d['name']);
                $cover = resort_save_cover($slug);
                $pdo->prepare('INSERT INTO resort (slug, name, tagline, description, location, theme, hero_image, is_active, sort_order)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 0, (SELECT COALESCE(MAX(r.sort_order), 0) + 1 FROM resort r))')
                    ->execute([$slug, $d['name'], $d['tagline'], $d['description'], $d['location'], $d['theme'], $cover]);
                $rid = (int)$pdo->lastInsertId();
                flash('“' . $d['name'] . '” is saved as a hidden draft. It only goes live when you finish the last step.');
            } else {
                $cover = resort_save_cover($res['slug']);
                $vals = [$d['name'], $d['tagline'], $d['description'], $d['location'], $d['theme']];
                if ($cover !== null) {
                    $vals[] = $cover;
                }
                $vals[] = $rid;
                $pdo->prepare('UPDATE resort SET name = ?, tagline = ?, description = ?, location = ?, theme = ?' . ($cover !== null ? ', hero_image = ?' : '') . ' WHERE resort_id = ?')->execute($vals);
                flash('Saved.');
            }
            redirect($self . '?id=' . $rid . '&step=2');
        }

        if (!$res) {
            redirect($self);
        }

        if ($step === 2) {
            $d = resort_read_post($_POST + ['name' => $res['name']]);
            $pdo->prepare('UPDATE resort SET contact_email = ?, contact_phone = ?, checkin_time = ?, checkout_time = ?, free_cancel_days = ?, allow_cancellation = ?, online_payment_enabled = ?, downpayment_percent = ?, gcash_name = ?, gcash_number = ?, maya_name = ?, maya_number = ? WHERE resort_id = ?')
                ->execute([$d['contact_email'], $d['contact_phone'], $d['checkin_time'], $d['checkout_time'], $d['free_cancel_days'], $d['allow_cancellation'], $d['online_payment_enabled'], $d['downpayment_percent'], $d['gcash_name'], $d['gcash_number'], $d['maya_name'], $d['maya_number'], $rid]);
            flash('Saved.');
            redirect($to(3));
        }

        if ($L && $action === 'add') {
            $data = wiz_read($L['fields'], $_POST);
            if ($L['table'] === 'price_item') {
                $data['sort_order'] = wiz_count($pdo, 'price_item', $rid) + 1;
            }
            $cols = array_keys($data);
            $pdo->prepare('INSERT INTO ' . $L['table'] . ' (resort_id, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_merge([$rid], array_values($data)));
            flash($L['singular'] . ' added. Add another, or continue when you are done.');
            redirect($to($step));
        }

        if (($L || $key === 'rooms') && $action === 'remove') {
            $table = $L['table'] ?? 'room';
            $pk    = $L['pk'] ?? 'room_id';
            $pdo->prepare("DELETE FROM $table WHERE $pk = ? AND resort_id = ?")->execute([(int)($_POST['item'] ?? 0), $rid]);
            flash('Removed.');
            redirect($to($step));
        }

        if ($key === 'rooms' && $action === 'add') {
            $okB = $pdo->prepare('SELECT COUNT(*) FROM building WHERE building_id = ? AND resort_id = ?');
            $okB->execute([(int)($_POST['building_id'] ?? 0), $rid]);
            $okT = $pdo->prepare('SELECT COUNT(*) FROM room_type WHERE room_type_id = ? AND resort_id = ?');
            $okT->execute([(int)($_POST['room_type_id'] ?? 0), $rid]);
            if (!(int)$okB->fetchColumn() || !(int)$okT->fetchColumn()) {
                throw new RuntimeException('Choose a building and a room type.');
            }
            $floor = trim((string)($_POST['floor'] ?? '1'));
            if (!preg_match('/^-?\d{1,2}$/', $floor)) {
                throw new RuntimeException('The floor must be a whole number.');
            }
            $numbers = wiz_room_numbers((string)($_POST['numbers'] ?? ''));
            $have = $pdo->prepare('SELECT room_number FROM room WHERE resort_id = ?');
            $have->execute([$rid]);
            $taken = array_flip(array_map('mb_strtolower', $have->fetchAll(PDO::FETCH_COLUMN)));
            $new = array_values(array_filter($numbers, fn($n) => !isset($taken[mb_strtolower($n)])));
            $skipped = array_values(array_diff($numbers, $new));
            if (!$new) {
                throw new RuntimeException('Those room numbers already exist in this resort.');
            }
            $ins = $pdo->prepare('INSERT INTO room (resort_id, building_id, room_type_id, room_number, floor, status) VALUES (?, ?, ?, ?, ?, ?)');
            $pdo->beginTransaction();
            foreach ($new as $n) {
                $ins->execute([$rid, (int)$_POST['building_id'], (int)$_POST['room_type_id'], $n, (int)$floor, 'Available']);
            }
            $pdo->commit();
            flash(plural(count($new), 'room') . ' added' . ($skipped ? '. Skipped because they already exist: ' . implode(', ', $skipped) : '.'));
            redirect($to($step));
        }

        if ($key === 'photos') {
            if (!gallery_available($pdo)) {
                throw new RuntimeException('Resort photos are not set up yet. Run database/upgrade_resort_photos.sql once.');
            }
            if ($action === 'upload') {
                $items = gallery_check_uploads($_FILES, wiz_count($pdo, 'resort_photo', $rid));   // every file is checked first, so a bad file means nothing is saved
                gallery_store($pdo, $rid, $items);
                flash(plural(count($items), 'photo') . ' added. Add more, or continue when you are done.');
                redirect($to($step));
            }
            if ($action === 'remove') {
                flash(gallery_remove($pdo, $rid, (int)($_POST['item'] ?? 0)) ? 'Photo removed.' : 'That photo no longer exists.');
                redirect($to($step));
            }
        }

        if ($key === 'finish' && ($action === 'finish' || $action === 'draft')) {
            if ($action === 'draft') {
                flash('Draft saved. Pick it up again any time from All resorts.');
                redirect('resorts.php');
            }
            $mName = trim((string)($_POST['mgr_name'] ?? ''));
            $mEmail = strtolower(trim((string)($_POST['mgr_email'] ?? '')));
            $mPass = (string)($_POST['mgr_password'] ?? '');
            $wantsAdmin = $mName !== '' || $mEmail !== '' || $mPass !== '';
            if ($wantsAdmin && ($mName === '' || !filter_var($mEmail, FILTER_VALIDATE_EMAIL) || strlen($mPass) < 8)) {
                throw new RuntimeException('For a resort admin account give a name, a valid email and a password of at least 8 characters.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE resort SET is_active = ? WHERE resort_id = ?')->execute([empty($_POST['is_active']) ? 0 : 1, $rid]);
            if ($wantsAdmin) {
                $pdo->prepare('INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$rid, $mName, 'Resort admin', 'Admin', $mEmail, password_hash($mPass, PASSWORD_DEFAULT)]);
            }
            $pdo->commit();
            $_SESSION['admin_resort'] = $rid;
            flash('“' . $res['name'] . '” is set up' . (empty($_POST['is_active']) ? ' and kept hidden for now. Turn on “Open for bookings” in Settings when you are ready.' : ' and open for bookings.'));
            redirect('index.php?resort=' . $rid);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $form = $_POST;
        $code = (int)($e->errorInfo[1] ?? 0);
        flash($code === 1062
            ? ($key === 'finish' ? 'That email is already used by another staff account.' : 'That one already exists in this resort. Use a different name or number.')
            : ($code === 1451 ? 'That is used by other data (for example rooms), so it can’t be removed yet. Remove those first.' : 'The database rejected that change. Check the values and try again.'), 'error');
    } catch (RuntimeException $e) {
        $form = $_POST;
        flash($e->getMessage(), 'error');
    }
}

// ---------- Show the step ----------
$title = $res ? 'Set up ' . $res['name'] : 'Add a resort';
admin_start($title, 'resorts');
admin_head($title, 'Step ' . $step . ' of ' . count($STEPS) . ': ' . $STEPS[$step][1], '<a class="btn" href="resorts.php">' . ($res ? 'Finish later' : 'Cancel') . '</a>');
?>
<nav class="wiz-steps" aria-label="Setup steps" style="--n:<?= count($STEPS) ?>">
  <div class="wiz-bar" aria-hidden="true"><i style="width:<?= round(($step - 1) / (count($STEPS) - 1) * 100, 3) ?>%"></i></div>
  <ol>
    <?php foreach ($STEPS as $n => [$k, $t, $short]):
        $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo');
        $inner = '<span class="wiz-dot">' . ($state === 'done' ? '✓' : $n) . '</span><span class="wiz-label">' . h($short) . '</span>'; ?>
      <li class="<?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
        <?= ($res && $n !== $step) ? '<a href="' . h($to($n)) . '" title="' . h($t) . '">' . $inner . '</a>' : $inner ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>

<?php
$hid = $res ? '<input type="hidden" name="id" value="' . (int)$rid . '">' : '';
$stepField = '<input type="hidden" name="step" value="' . $step . '">';
$back = $step > 1 ? '<a class="btn" href="' . h($to($step - 1)) . '">← Back</a>' : '';
$next = $step < count($STEPS) ? $STEPS[$step + 1][1] : '';

if ($step === 1):
    $v = fn(string $k, string $def = '') => h($form[$k] ?? $res[$k] ?? $def);
    $locParts = isset($form['loc_city'])
        ? ['city' => $form['loc_city'] ?? '', 'barangay' => $form['loc_barangay'] ?? '', 'street' => $form['loc_street'] ?? '', 'house' => $form['loc_house'] ?? '']
        : address_parse((string)($res['location'] ?? ''));
    $cover = $res['hero_image'] ?? ''; ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Name your resort</h2></div>
  <p class="panel-note">Start with the basics. Everything here can be changed later in Settings. The resort stays hidden from guests until you finish the last step.</p>
  <form method="post" enctype="multipart/form-data" class="grid-form">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?>
    <label class="wide"><span>Resort name</span><input placeholder="e.g. Hannah’s Nature Camp" name="name" value="<?= $v('name') ?>" maxlength="80" required autofocus></label>
    <fieldset class="cancel-terms wide">
      <legend>Complete resort address <small class="muted">(City, Barangay, Street / Drive, House / Lot no.)</small></legend>
      <?= address_fields_html($locParts, 'loc_', true) ?>
    </fieldset>
    <label class="wide"><span>Tagline</span><input placeholder="e.g. Rest by the lake" name="tagline" value="<?= $v('tagline') ?>" maxlength="160"></label>
    <label class="wide"><span>About the resort</span><textarea name="description" rows="3" maxlength="1000"><?= $v('description') ?></textarea></label>
    <label><span>Colour theme</span>
      <select name="theme">
        <?php foreach (RESORT_THEMES as $k => $_): ?><option value="<?= h($k) ?>"<?= ($form['theme'] ?? $res['theme'] ?? 'ocean') === $k ? ' selected' : '' ?>><?= h(ucfirst($k)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><span>Background photo (JPG, PNG or WebP, under 8 MB)</span><input type="file" name="hero" accept="image/jpeg,image/png,image/webp"></label>
    <?php if ($cover !== '' && is_file(__DIR__ . '/../assets/' . $cover)): ?>
      <p class="wide wiz-cover"><img src="../assets/<?= h($cover) ?>?v=<?= (int)filemtime(__DIR__ . '/../assets/' . $cover) ?>" alt="Current background photo"><span class="muted">Current background photo. Choose a new file to replace it.</span></p>
    <?php endif; ?>
    <div class="actions wide wiz-actions"><span></span><button class="btn primary" type="submit"><?= $res ? 'Save and continue' : 'Create and continue' ?> →</button></div>
  </form>
</section>

<?php elseif ($step === 2):
    $v = fn(string $k, string $def = '') => h($form[$k] ?? $res[$k] ?? $def); ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Contact and stay rules</h2></div>
  <p class="panel-note">How guests reach the resort, and the times and cancellation rule shown on their booking. All optional except the times.</p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?>
    <label><span>Contact email</span><input placeholder="e.g. hello@yourresort.com" type="email" name="contact_email" value="<?= $v('contact_email') ?>" maxlength="120"></label>
    <label><span>Contact phone</span><input placeholder="e.g. 0917 123 4567" name="contact_phone" value="<?= $v('contact_phone') ?>" maxlength="40"></label>
    <label><span>Check-in time</span><input placeholder="e.g. 2:00 PM" name="checkin_time" value="<?= $v('checkin_time', '2:00 PM') ?>" maxlength="20" required></label>
    <label><span>Check-out time</span><input placeholder="e.g. 12:00 NN" name="checkout_time" value="<?= $v('checkout_time', '12:00 NN') ?>" maxlength="20" required></label>
    <?php resort_cancel_fields($res ?? [], $v); ?>
    <?php resort_payment_fields($res ?? [], $v); ?>
    <div class="actions wide wiz-actions"><?= $back ?: '<span></span>' ?><button class="btn primary" type="submit">Save and continue →</button></div>
  </form>
</section>

<?php elseif ($L):
    $stmt = $pdo->prepare('SELECT * FROM ' . $L['table'] . ' WHERE resort_id = ? ORDER BY ' . $L['order']);
    $stmt->execute([$rid]);
    $items = $stmt->fetchAll(); ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Add <?= h(mb_strtolower($L['singular'])) ?></h2><?= $L['optional'] ? '<span class="badge">Optional</span>' : '' ?></div>
  <p class="panel-note"><?= h($L['intro']) ?></p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="add">
    <?php foreach ($L['fields'] as $f) { wiz_field($f, $form); } ?>
    <div class="actions wide"><button class="btn primary" type="submit">Add <?= h(mb_strtolower($L['singular'])) ?></button></div>
  </form>
</section>

<section class="panel wiz-panel">
  <div class="panel-head"><h2>Added so far <small class="muted">(<?= count($items) ?>)</small></h2></div>
  <?php if (!$items): ?>
    <p class="muted">Nothing yet.<?= $L['optional'] ? ' You can skip this step.' : '' ?></p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><?php foreach ($L['cols'] as $c): ?><th><?= h($c[0]) ?></th><?php endforeach; ?><th class="right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <?php foreach ($L['cols'] as $c): $cell = $it[$c[1]] ?? null; ?>
            <td><?= $cell === null || $cell === '' ? '<span class="muted">-</span>' : h(($c[2] ?? '') === 'money' ? money($cell) : $cell) ?></td>
          <?php endforeach; ?>
          <td class="row-actions">
            <div class="act-group">
              <form method="post">
                <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="remove"><input type="hidden" name="item" value="<?= (int)$it[$L['pk']] ?>">
                <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove this <?= h(mb_strtolower($L['singular'])) ?>?" data-confirm-title="Remove <?= h(mb_strtolower($L['singular'])) ?>" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<div class="wiz-nav"><?= $back ?><a class="btn primary" href="<?= h($to($step + 1)) ?>"><?= (!$items && $L['optional']) ? 'Skip for now' : 'Next: ' . h($next) ?> →</a></div>

<?php elseif ($key === 'rooms'):
    $buildings = $pdo->prepare('SELECT building_id, name FROM building WHERE resort_id = ? ORDER BY name');
    $buildings->execute([$rid]);
    $buildings = $buildings->fetchAll(PDO::FETCH_KEY_PAIR);
    $types = $pdo->prepare('SELECT room_type_id, type_name FROM room_type WHERE resort_id = ? ORDER BY base_rate, type_name');
    $types->execute([$rid]);
    $types = $types->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $pdo->prepare('SELECT r.room_id, r.room_number, r.floor, b.name AS building, t.type_name FROM room r
                             JOIN building b ON b.building_id = r.building_id JOIN room_type t ON t.room_type_id = r.room_type_id
                            WHERE r.resort_id = ? ORDER BY b.name, r.floor, r.room_number LIMIT 300');
    $stmt->execute([$rid]);
    $rooms = $stmt->fetchAll(); ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Add rooms</h2></div>
  <?php if (!$buildings || !$types): ?>
    <p class="panel-note">Rooms belong to a building and a room type, so add at least one of each first:
      <?php if (!$buildings): ?><a href="<?= h($to(3)) ?>">add a building</a><?php endif; ?><?= (!$buildings && !$types) ? ' and ' : '' ?><?php if (!$types): ?><a href="<?= h($to(4)) ?>">add a room type</a><?php endif; ?>.</p>
  <?php else: ?>
  <p class="panel-note">Add many rooms at once. Type the room numbers separated by commas, and use a dash for a range: <code>101, 102, 105-110</code> adds 101, 102 and 105 to 110.</p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="add">
    <label><span>Building</span>
      <select name="building_id" required><?php foreach ($buildings as $bid => $bn): ?><option value="<?= (int)$bid ?>"<?= (int)($form['building_id'] ?? 0) === (int)$bid ? ' selected' : '' ?>><?= h($bn) ?></option><?php endforeach; ?></select></label>
    <label><span>Room type</span>
      <select name="room_type_id" required><?php foreach ($types as $tid => $tn): ?><option value="<?= (int)$tid ?>"<?= (int)($form['room_type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select></label>
    <label><span>Floor</span><input type="number" name="floor" value="<?= h($form['floor'] ?? '1') ?>" min="-9" max="99" step="1" required></label>
    <label class="wide"><span>Room numbers</span><input name="numbers" value="<?= h($form['numbers'] ?? '') ?>" placeholder="101, 102, 105-110" required></label>
    <div class="actions wide"><button class="btn primary" type="submit">Add rooms</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel wiz-panel">
  <div class="panel-head"><h2>Added so far <small class="muted">(<?= count($rooms) ?>)</small></h2></div>
  <?php if (!$rooms): ?>
    <p class="muted">No rooms yet. Guests can’t book until the resort has at least one room, but you can also add them later under Rooms.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Room</th><th>Type</th><th>Building</th><th class="num">Floor</th><th class="right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $rm): ?>
        <tr>
          <td class="nowrap"><strong><?= h($rm['room_number']) ?></strong></td><td><?= h($rm['type_name']) ?></td><td><?= h($rm['building']) ?></td><td class="num"><?= (int)$rm['floor'] ?></td>
          <td class="row-actions">
            <div class="act-group">
              <form method="post">
                <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="remove"><input type="hidden" name="item" value="<?= (int)$rm['room_id'] ?>">
                <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove room <?= h($rm['room_number']) ?>?" data-confirm-title="Remove room" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<div class="wiz-nav"><?= $back ?><a class="btn primary" href="<?= h($to($step + 1)) ?>"><?= $rooms ? 'Next: ' . h($next) : 'Skip for now' ?> →</a></div>

<?php elseif ($key === 'photos'):
    $galOn = gallery_available($pdo);
    $gp = gallery_photos($pdo, $rid);
    $lim = review_media_limits(); ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Add photos</h2><span class="badge">Optional</span></div>
  <p class="panel-note">These show on the resort's See photos page. JPG, PNG or WebP, up to <?= h(review_size_label($lim['photo_bytes'])) ?> each, <?= GALLERY_MAX_AT_ONCE ?> at a time. You can add captions and change the order later under Resort photos.</p>
  <?php if (!$galOn): ?>
    <p class="notice error" role="status">Resort photos are not set up yet. Run <code>database/upgrade_resort_photos.sql</code> once, then come back to this step.</p>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="upload">
    <label><span>Photos</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
    <div class="actions"><button class="btn primary" type="submit">Upload photos</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel wiz-panel">
  <div class="panel-head"><h2>Added so far <small class="muted">(<?= count($gp) ?>)</small></h2></div>
  <?php if (!$gp): ?>
    <p class="muted">No photos yet. You can skip this step and add them later.</p>
  <?php else: ?>
  <div class="gal-admin">
    <?php foreach ($gp as $i => $p): ?>
      <figure class="gal-card">
        <a href="<?= h(gallery_url($p, false, '../')) ?>" target="_blank" rel="noopener"><img src="<?= h(gallery_url($p, true, '../')) ?>" alt="Photo <?= $i + 1 ?>" loading="lazy"></a>
        <form method="post">
          <?= csrf_field() ?><?= $hid ?><?= $stepField ?><input type="hidden" name="action" value="remove"><input type="hidden" name="item" value="<?= (int)$p['photo_id'] ?>">
          <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove this photo?" data-confirm-title="Remove photo" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove</button>
        </form>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<div class="wiz-nav"><?= $back ?><a class="btn primary" href="<?= h($to($step + 1)) ?>"><?= $gp ? 'Next: ' . h($next) : 'Skip for now' ?> →</a></div>

<?php else:   // finish
    $counts = [
        ['Buildings', wiz_count($pdo, 'building', $rid), 3], ['Room types', wiz_count($pdo, 'room_type', $rid), 4], ['Rooms', wiz_count($pdo, 'room', $rid), 5],
        ['Pools', wiz_count($pdo, 'pool', $rid), 6], ['Services', wiz_count($pdo, 'service', $rid), 7], ['Price list items', wiz_count($pdo, 'price_item', $rid), 8],
        ['Resort photos', gallery_available($pdo) ? wiz_count($pdo, 'resort_photo', $rid) : 0, 9],
    ];
    $roomCount = $counts[2][1];
    $openDefault = $form ? !empty($form['is_active']) : $roomCount > 0; ?>
<section class="panel wiz-panel">
  <div class="panel-head"><h2>Review</h2></div>
  <p class="panel-note"><strong><?= h($res['name']) ?></strong><?= $res['location'] ? ' · ' . h($res['location']) : '' ?>. Click a line to go back and change it.</p>
  <ul class="wiz-summary">
    <li><a href="<?= h($to(1)) ?>">Basics</a><span><?= !empty($res['hero_image']) ? 'Background photo added' : 'No background photo yet (guests see a colour backdrop)' ?></span></li>
    <li><a href="<?= h($to(2)) ?>">Contact</a><span><?= ($res['contact_email'] || $res['contact_phone']) ? h(trim(($res['contact_email'] ?? '') . ' ' . ($res['contact_phone'] ?? ''))) : 'No contact details yet' ?></span></li>
    <?php foreach ($counts as [$label, $n, $go]): ?>
      <li><a href="<?= h($to($go)) ?>"><?= h($label) ?></a><span><?= $n ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($roomCount === 0): ?><p class="notice error" role="status">This resort has no rooms yet, so guests can’t book it. You can still finish and add rooms later under Rooms.</p><?php endif; ?>
</section>

<section class="panel wiz-panel">
  <div class="panel-head"><h2>Go live</h2></div>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><?= $hid ?><?= $stepField ?>
    <label class="check wide"><input type="checkbox" name="is_active" value="1"<?= $openDefault ? ' checked' : '' ?>><span>Open for bookings and show this resort on the resort directory</span></label>
    <h3 class="wide form-section">First resort admin account <small>(optional)</small></h3>
    <label><span>Name</span><input name="mgr_name" placeholder="e.g. Dan Flores" maxlength="80" value="<?= h($form['mgr_name'] ?? '') ?>"></label>
    <label><span>Email (for logging in)</span><input type="email" name="mgr_email" placeholder="e.g. manager@yourresort.com" maxlength="120" value="<?= h($form['mgr_email'] ?? '') ?>"></label>
    <label><span>Password</span><input type="password" name="mgr_password" autocomplete="new-password" minlength="8" placeholder="Min. 8 characters"></label>
    <div class="actions wide wiz-actions">
      <?= $back ?>
      <span class="wiz-end">
        <button class="btn" type="submit" name="action" value="draft">Save as draft and exit</button>
        <button class="btn primary" type="submit" name="action" value="finish" data-confirm="Finish setting up this resort? You can still change everything afterwards." data-confirm-title="Finish setup" data-confirm-ok="Yes, finish">Finish setup</button>
      </span>
    </div>
  </form>
</section>
<?php endif; ?>
<?php admin_end();
