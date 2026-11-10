<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require __DIR__ . '/includes/notify.php';
require __DIR__ . '/includes/accounts.php';
require_resort($pdo);

$scope    = (string)($_POST['scope'] ?? $_GET['scope'] ?? 'Rooms');
$scope    = isset(BOOKING_SCOPES[$scope]) ? $scope : 'Rooms';
$isEx     = $scope !== 'Rooms';   // a whole resort, building or pool
$targetId = (int)($_POST['target'] ?? $_GET['target'] ?? 0);
$typeId   = (int)($_POST['type'] ?? $_GET['type'] ?? 0);
$roomsReq = (int)($_POST['rooms'] ?? $_GET['rooms'] ?? 1);
$in       = (string)($_POST['check_in'] ?? $_GET['check_in'] ?? '');
$out      = (string)($_POST['check_out'] ?? $_GET['check_out'] ?? '');
$adults   = (int)($_POST['adults'] ?? $_GET['adults'] ?? 0);
$children = (int)($_POST['children'] ?? $_GET['children'] ?? 0);
$guests   = $adults + $children;

function back_to_search(string $message, string $in, string $out, int $adults, int $children): never
{
    global $isEx;
    flash($message, 'error');
    redirect(ru($isEx ? 'exclusive.php' : 'search.php') . '&' . http_build_query([
        'check_in' => $in, 'check_out' => $out, 'adults' => max(1, $adults), 'children' => max(0, $children),
    ]));
}

// ---------- Validate the selection ----------
$problems = validate_stay($in, $out, $adults, $children, $isEx ? 5000 : null);
if ($problems) {
    back_to_search($problems[0], $in, $out, $adults, $children);
}

$type = null;
$target = null;
if ($isEx) {
    $target = exclusive_target($pdo, $scope, $targetId, $in, $out);
    if (!$target || !$target['free']) {
        back_to_search($target ? ($target['why'] ?: 'That is no longer available for your dates.') : 'Choose what you want to book.', $in, $out, $adults, $children);
    }
    $problems = validate_stay($in, $out, $adults, $children, (int)$target['capacity']);
    if ($problems) {
        back_to_search($problems[0], $in, $out, $adults, $children);
    }
    $roomsReq  = 1;
    $n         = nights($in, $out);
    $roomTotal = $target['rate'] * $n;
} else {
    $stmt = $pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ? AND resort_id = ?');
    $stmt->execute([$typeId, resort_id()]);
    $type = $stmt->fetch();
    if (!$type) {
        back_to_search('Choose a room to reserve.', $in, $out, $adults, $children);
    }

    $need = rooms_needed($guests, (int)$type['max_occupancy']);
    $free = count(available_rooms($pdo, $in, $out, $typeId));
    $maxPick = min($free, (int)$config['max_rooms_per_booking']);
    if ($free === 0 || $need > $maxPick) {
        back_to_search('That room type is no longer available for your dates.', $in, $out, $adults, $children);
    }
    $roomsReq = min(max($roomsReq, $need), $maxPick);

    $n         = nights($in, $out);
    $roomTotal = (float)$type['base_rate'] * $n * $roomsReq;
}
$services  = $pdo->query('SELECT * FROM service WHERE resort_id = ' . resort_id() . ' ORDER BY category, service_name')->fetchAll();
$hid = ['type' => $typeId, 'rooms' => $roomsReq, 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
        'scope' => $scope, 'target' => $targetId];

$form = [
    'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'requests' => '',
];
$svcOn  = [];
$svcQty = [];
$error  = '';

// Signed-in guests: fill in their details, and their email is already verified.
$acct = current_guest($pdo);
if ($acct) {
    $form['first_name'] = $acct['first_name'];
    $form['last_name']  = $acct['last_name'];
    $form['email']      = $acct['email'];
    $form['phone']      = (string)($acct['phone'] ?? '');
    $form['address']    = (string)($acct['address'] ?? '');
}

// ---------- Email verification state ----------
$verifyOn = !empty($config['verify_email']);
$sig      = implode('|', [resort_id(), $scope, $targetId, $typeId, $roomsReq, $in, $out, $adults, $children]);
$pending  = $_SESSION['pending_booking'] ?? null;
if ($pending && ($pending['sig'] ?? '') !== $sig) {
    unset($_SESSION['pending_booking']);   // the guest changed their selection: start over
    $pending = null;
}
$notice = '';
$compValues = [];
$bookerType = 'Adult';

/** Create the booking, email the guest, and go to the confirmation page. */
function finish_booking(PDO $pdo, array $data): never
{
    // A double click or a re-sent form must not create the same booking twice: reuse the one just made in this session.
    $fp = hash('sha256', json_encode([resort_id(), $data['scope'], $data['target_id'], $data['type_id'], $data['rooms'] ?? 1, $data['check_in'],
                                      $data['check_out'], $data['adults'], $data['children'], $data['email'], $data['first_name'], $data['last_name']]));
    $last = $_SESSION['last_booking'] ?? null;
    if ($last && $last['fp'] === $fp && time() - $last['at'] < 300) {
        $same = $pdo->prepare("SELECT 1 FROM reservation WHERE booking_ref = ? AND status <> 'Cancelled'");
        $same->execute([$last['ref']]);
        if ($same->fetchColumn()) {
            $_SESSION['grants'][$last['ref']] = true;
            unset($_SESSION['pending_booking']);
            redirect('booking.php?ref=' . urlencode($last['ref']));
        }
    }
    $result = create_online_booking($pdo, $data);
    $_SESSION['last_booking'] = ['fp' => $fp, 'ref' => $result['ref'], 'at' => time()];
    unset($_SESSION['pending_booking']);
    $_SESSION['grants'][$result['ref']] = true;
    $_SESSION['just_booked'] = $result['ref'];
    send_booking_email($pdo, $result['id'], 'received');
    flash('Thank you. Your booking request is in.');
    redirect('booking.php?ref=' . urlencode($result['ref']));
}

/** Email a fresh code and store its hash in the session. Throws RuntimeException on failure. */
function start_verification(PDO $pdo, array $data, string $sig): void
{
    if (throttle_blocked($pdo, 'verify_send', 5)) {
        throw new RuntimeException('Too many codes requested. Please wait a few minutes and try again.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    if (!send_verification_email($data['email'], $data['first_name'], $code)) {
        throw new RuntimeException('We couldn’t send an email to that address. Check it and try again, or contact us.');
    }
    throttle_fail($pdo, 'verify_send');   // counts every send
    $_SESSION['pending_booking'] = [
        'sig' => $sig, 'data' => $data, 'salt' => $salt, 'hash' => hash('sha256', $salt . $code),
        'expires' => time() + 600, 'sent_at' => time(), 'tries' => 0,
    ];
}

// ---------- Submit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'details');

    try {
        if ($action === 'details') {
            foreach ($form as $k => $_) {
                $form[$k] = trim((string)($_POST[$k] ?? ''));
            }
            if ($acct) {
                $form['email'] = $acct['email'];   // an account's email is fixed and already verified
            }
            $svcOn  = (array)($_POST['svc_on'] ?? []);
            $svcQty = (array)($_POST['svc_qty'] ?? []);
            $cf = (array)($_POST['comp_first'] ?? []);
            $cl = (array)($_POST['comp_last'] ?? []);
            $ca = (array)($_POST['comp_age'] ?? []);
            foreach ($isEx ? [] : companion_slots($adults, $children) as $i => $_) {
                $compValues[$i] = ['type' => (string)(($_POST['comp_type'] ?? [])[$i] ?? ''), 'first_name' => trim((string)($cf[$i] ?? '')), 'last_name' => trim((string)($cl[$i] ?? '')), 'age' => trim((string)($ca[$i] ?? ''))];
            }

            if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 60 || $form['last_name'] === '' || mb_strlen($form['last_name']) > 60) {
                throw new RuntimeException('Enter your first and last name.');
            }
            if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 120) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $form['phone'])) {
                throw new RuntimeException('Enter a phone number we can reach you on (digits, spaces, + and - only).');
            }
            if (mb_strlen($form['address']) > 200) {
                throw new RuntimeException('Keep the address under 200 characters.');
            }
            if (mb_strlen($form['requests']) > 500) {
                throw new RuntimeException('Special requests can be up to 500 characters.');
            }
            if (empty($_POST['terms'])) {
                throw new RuntimeException('Please accept the booking terms to continue.');
            }

            $chosen = [];
            foreach ($services as $s) {
                $sid = (int)$s['service_id'];
                if (!empty($svcOn[$sid])) {
                    $q = (int)($svcQty[$sid] ?? 1);
                    if ($q < 1 || $q > 20) {
                        throw new RuntimeException('Service quantities must be between 1 and 20.');
                    }
                    $chosen[$sid] = $q;
                }
            }

            $bookerType = clean_booker_type($_POST['booker_type'] ?? 'Adult');
            $companions = $isEx ? [] : parse_companions($_POST, $adults, $children, true);

            $data = [
                'scope' => $scope, 'target_id' => $targetId, 'type_id' => $typeId, 'rooms' => $roomsReq,
                'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
                'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                'email' => strtolower($form['email']), 'phone' => $form['phone'], 'address' => $form['address'],
                'requests' => $form['requests'], 'services' => $chosen, 'companions' => $companions, 'booker_type' => $bookerType,
            ];

            if ($verifyOn && empty($_SESSION['verified_emails'][$data['email']])) {
                start_verification($pdo, $data, $sig);
                $pending = $_SESSION['pending_booking'];
            } else {
                finish_booking($pdo, $data);
            }
        } elseif (!$pending && in_array($action, ['verify', 'resend', 'edit'], true)) {
            $last = $_SESSION['last_booking'] ?? null;
            if ($last && time() - $last['at'] < 300) {
                $_SESSION['grants'][$last['ref']] = true;
                redirect('booking.php?ref=' . urlencode($last['ref']));   // the code was already used and the booking exists
            }
            throw new RuntimeException('That verification has expired. Please enter your details again.');
        } elseif ($pending && $action === 'verify') {
            $entered = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
            if (time() > $pending['expires']) {
                throw new RuntimeException('That code has expired. Send a new one.');
            }
            if ($pending['tries'] >= 5 || throttle_blocked($pdo, 'verify_fail', 10)) {
                throw new RuntimeException('Too many wrong codes. Send a new code and try again.');
            }
            if (!hash_equals($pending['hash'], hash('sha256', $pending['salt'] . $entered))) {
                $_SESSION['pending_booking']['tries']++;
                throttle_fail($pdo, 'verify_fail');
                $left = 5 - $_SESSION['pending_booking']['tries'];
                $pending = $_SESSION['pending_booking'];
                throw new RuntimeException('That code isn’t right.' . ($left > 0 ? ' ' . plural($left, 'try', 'tries') . ' left.' : ' Send a new code.'));
            }
            throttle_clear($pdo, 'verify_fail');
            $_SESSION['verified_emails'][$pending['data']['email']] = true;
            try {
                finish_booking($pdo, $pending['data']);
            } catch (RuntimeException $e) {
                // Email is verified but the booking failed (for example the room was just taken): back to the form.
                $d = $pending['data'];
                $form = ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => $d['email'],
                         'phone' => $d['phone'], 'address' => $d['address'], 'requests' => $d['requests']];
                $compValues = companion_values($d['companions'] ?? []);
                $bookerType = $d['booker_type'] ?? 'Adult';
                foreach ($d['services'] ?? [] as $sid => $q) {
                    $svcOn[$sid] = 1;
                    $svcQty[$sid] = $q;
                }
                unset($_SESSION['pending_booking']);
                $pending = null;
                throw $e;
            }
        } elseif ($pending && $action === 'resend') {
            if (time() - $pending['sent_at'] < 60) {
                throw new RuntimeException('Please wait a minute before asking for another code.');
            }
            start_verification($pdo, $pending['data'], $sig);
            $pending = $_SESSION['pending_booking'];
            $notice  = 'We sent you a new code.';
        } elseif ($pending && $action === 'edit') {
            $d = $pending['data'];
            $form = ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'email' => $d['email'],
                     'phone' => $d['phone'], 'address' => $d['address'], 'requests' => $d['requests']];
            $compValues = companion_values($d['companions'] ?? []);
            $bookerType = $d['booker_type'] ?? 'Adult';
            foreach ($d['services'] as $sid => $q) {
                $svcOn[$sid] = 1;
                $svcQty[$sid] = $q;
            }
            unset($_SESSION['pending_booking']);
            $pending = null;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$step = $pending ? 'verify' : 'details';
$addonSum = 0.0;
if ($pending) {
    foreach ($services as $s) {
        $q = (int)($pending['data']['services'][(int)$s['service_id']] ?? 0);
        $addonSum += (float)$s['unit_price'] * $q;
    }
}

site_start('Your details', 'rooms');
?>
<div class="page-head">
  <h1>Your details</h1>
  <a class="btn" href="<?= h(ru($isEx ? 'exclusive.php' : 'search.php') . '&' . http_build_query(['check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children])) ?>"><?= $isEx ? 'Back' : 'Back to rooms' ?></a>
</div>
<?= booking_steps(3) ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

<div class="checkout">
  <?php if ($step === 'verify'): $pe = $pending['data']['email']; ?>
  <section class="panel stack" id="verify-panel">
    <h2>Check your email</h2>
    <p>We sent a 6-digit code to <strong><?= h($pe) ?></strong>. Enter it to finish your booking request. It works for 10 minutes.</p>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was actually sent. Open <code>storage/mail.log</code> to read the code. Set up SMTP in <code>config.php</code> to send real emails.</div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><?= rhidden() ?>
      <?php foreach ($hid as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="action" value="verify">
      <label><span>Verification code</span>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus>
      </label>
      <div class="actions"><button class="btn primary" type="submit">Verify and request booking</button></div>
    </form>
    <div class="actions">
      <form method="post">
        <?= csrf_field() ?><?= rhidden() ?>
        <?php foreach ($hid as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="action" value="resend">
        <button class="btn" type="submit">Send a new code</button>
      </form>
      <form method="post">
        <?= csrf_field() ?><?= rhidden() ?>
        <?php foreach ($hid as $k => $v): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="action" value="edit">
        <button class="btn" type="submit">Use a different email</button>
      </form>
    </div>
  </section>
  <?php else: ?>
  <?php if (!$acct): $back = urlencode('checkout.php?' . ($_SERVER['QUERY_STRING'] ?? '')); ?>
  <p class="notice" role="note" style="margin-bottom:12px">Have an account? <a href="<?= h(ru('login.php?next=' . $back)) ?>">Log in</a> to fill in your details, or <a href="<?= h(ru('register.php?next=' . $back)) ?>">create one</a>. You can also continue as a guest.</p>
  <?php endif; ?>
  <form method="post" class="panel" id="checkout-form">
    <?= csrf_field() ?><?= rhidden() ?>
    <input type="hidden" name="action" value="details">
    <?php foreach ($hid as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>

    <h2>Who is staying</h2>
    <div class="grid-form">
      <label><span>First name</span><input name="first_name" value="<?= h($form['first_name']) ?>" maxlength="60" autocomplete="given-name" required></label>
      <label><span>Last name</span><input name="last_name" value="<?= h($form['last_name']) ?>" maxlength="60" autocomplete="family-name" required></label>
      <label><span>Email<?= $acct ? ' (your account)' : '' ?></span><input type="email" name="email" value="<?= h($form['email']) ?>" maxlength="120" autocomplete="email" required<?= $acct ? ' readonly' : '' ?>></label>
      <label><span>Phone</span><input type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="30" autocomplete="tel" required></label>
      <?= booker_type_field($bookerType) ?>
      <label class="wide"><span>City or country (optional)</span><input name="address" value="<?= h($form['address']) ?>" maxlength="200" autocomplete="address-level2"></label>
    </div>

    <?php if ($isEx): ?>
      <p class="notice" role="note">This is a <?= h(strtolower(BOOKING_SCOPES[$scope])) ?> booking for up to <?= (int)$target['capacity'] ?> guests, <?= h(plural($adults + $children, 'guest')) ?> in your group. You don’t need to list everyone’s names now.</p>
    <?php else: ?>
    <?= companion_fields($adults, $children, $compValues, true) ?>
    <?php endif; ?>

    <h2>Add to your stay <small class="muted">(optional)</small></h2>
    <div class="addon-pick">
      <?php foreach ($services as $s): $sid = (int)$s['service_id']; ?>
        <div class="addon-row">
          <label class="check">
            <input type="checkbox" name="svc_on[<?= $sid ?>]" value="1" data-price="<?= h($s['unit_price']) ?>" <?= !empty($svcOn[$sid]) ? 'checked' : '' ?>>
            <span><?= h($s['service_name']) ?> <small class="muted"><?= h(money($s['unit_price'])) ?> each</small></span>
          </label>
          <input type="number" name="svc_qty[<?= $sid ?>]" value="<?= (int)($svcQty[$sid] ?? 1) ?>" min="1" max="20" aria-label="Quantity for <?= h($s['service_name']) ?>">
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted">Add-ons are scheduled for your check-in day. Our team will arrange exact times with you.</p>

    <h2>Anything we should know</h2>
    <label><span>Special requests (optional)</span>
      <textarea name="requests" rows="3" maxlength="500" placeholder="Late arrival, celebration, dietary needs…"><?= h($form['requests']) ?></textarea>
    </label>

    <label class="check terms">
      <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?> required>
      <span>I understand this is a booking request. The resort confirms it and I pay at the resort. Free cancellation online up to <?= h(plural((int)$config['free_cancel_days'], 'day')) ?> before arrival.</span>
    </label>

    <div class="actions"><button class="btn primary" type="submit"><?= $verifyOn ? 'Continue' : 'Request booking' ?></button></div>
  </form>
  <?php endif; ?>

  <aside class="panel summary-card" aria-label="Booking summary">
    <?php $sp = $type ? room_photos($type['type_name']) : []; ?>
    <?php if ($sp): ?><div class="sc-photo" style="background-image:url('<?= h($sp[0]['url']) ?>')"></div><?php endif; ?>
    <h2>Your stay</h2>
    <dl class="facts">
      <dt>Resort</dt><dd><?= h($config['resort']) ?></dd>
      <?php if ($isEx): ?><dt>Booking</dt><dd><?= h(scope_label(['booking_scope' => $scope, 'building_name' => $target['name'], 'pool_name' => $target['name']])) ?></dd>
      <?php else: ?><dt>Room</dt><dd><?= h($type['type_name']) ?> × <?= $roomsReq ?></dd><?php endif; ?>
      <dt>Check-in</dt><dd><?= h(fdate($in)) ?><br><span class="muted">from <?= h($config['checkin_time']) ?></span></dd>
      <dt>Check-out</dt><dd><?= h(fdate($out)) ?><br><span class="muted">by <?= h($config['checkout_time']) ?></span></dd>
      <dt>Nights</dt><dd><?= $n ?></dd>
      <dt>Guests</dt><dd><?= h(plural($adults, 'adult')) ?><?= $children > 0 ? ', ' . h(plural($children, 'child', 'children')) : '' ?></dd>
    </dl>
    <dl class="facts totals">
      <dt><?= $isEx ? 'Price' : 'Rooms' ?></dt><dd class="num"><?= h(money($roomTotal)) ?></dd>
      <dt>Add-ons</dt><dd class="num" id="addon-total"><?= h(money($addonSum)) ?></dd>
      <dt>Total</dt><dd class="num strong" id="grand-total"><?= h(money($roomTotal + $addonSum)) ?></dd>
    </dl>
    <p class="muted">Pay at the resort. No payment is taken now.</p>
  </aside>
</div>

<script>
(function () {
  var cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  var roomTotal = <?= json_encode($roomTotal) ?>;
  var rows = document.querySelectorAll('.addon-row');
  if (!rows.length) return;   // verification step: totals are already filled in
  var addonEl = document.getElementById('addon-total');
  var grandEl = document.getElementById('grand-total');
  function fmt(v) { return cur + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function update() {
    var sum = 0;
    rows.forEach(function (row) {
      var box = row.querySelector('input[type="checkbox"]');
      var qty = row.querySelector('input[type="number"]');
      if (box.checked) sum += (+box.dataset.price) * Math.max(1, Math.min(20, +qty.value || 1));
    });
    addonEl.textContent = fmt(sum);
    grandEl.textContent = fmt(roomTotal + sum);
  }
  rows.forEach(function (row) {
    row.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', update); i.addEventListener('change', update); });
  });
  update();
})();
</script>
<?php site_end();
