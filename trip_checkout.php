<?php
/**
 * Book the whole trip in one go: the guest's details once, each stay's other guests and add-ons,
 * one email code (guests without an account), one confirmation. Every stay becomes its own booking at its own resort,
 * all saved in one transaction.
 */
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/trips.php';
require __DIR__ . '/includes/accounts.php';
require_guest_to_book($pdo);   // booking a trip needs an account

$rows = trip_resolve_all($pdo);

if (!$rows) {
    $last = $_SESSION['last_trip'] ?? null;
    if ($last && time() - $last['at'] < 300) {
        redirect('trip_booked.php?code=' . urlencode($last['code']));   // booked a moment ago (double click or re-sent form)
    }
    flash('Your trip is empty. Add a stay first.', 'error');
    redirect('resorts.php');
}
foreach ($rows as $r) {
    if (!$r['ok']) {
        flash('Remove the stays that aren’t available any more, then continue.', 'error');
        redirect('trip.php');
    }
}

/** A line that names a stay, for error messages. */
function trip_stay_name(array $row): string
{
    return $row['resort']['name'] . ', ' . $row['label'];
}

/** Add-ons of every resort in the trip. */
$services = [];
foreach ($rows as $r) {
    if (!isset($services[$r['slug']])) {
        $st = $pdo->prepare('SELECT * FROM service WHERE resort_id = ? AND archived_at IS NULL ORDER BY category, service_name');
        $st->execute([(int)$r['resort']['resort_id']]);
        $services[$r['slug']] = $st->fetchAll();
    }
}

$form       = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'address' => ''];
$bookerType = 'Adult';
$discountChoice = 'none';
$compValues = $svcOn = $svcQty = $reqs = [];   // each keyed by stay id
$error      = '';
$notice     = '';

$acct = current_guest($pdo);
if ($acct) {
    $form['first_name']  = $acct['first_name'];
    $form['middle_name'] = (string)($acct['middle_name'] ?? '');
    $form['last_name']   = $acct['last_name'];
    $form['email']       = $acct['email'];
    $form['phone']       = (string)($acct['phone'] ?? '');
    $form['address']     = (string)($acct['address'] ?? '');
}

// ---------- Email verification state ----------
$verifyOn = !empty($config['verify_email']);
$sig      = implode('|', array_column($rows, 'id'));
$pending  = $_SESSION['pending_trip'] ?? null;
if ($pending && ($pending['sig'] ?? '') !== $sig) {
    unset($_SESSION['pending_trip']);   // the trip changed: start over
    $pending = null;
}

/** Refill the form from details that were saved while waiting for the code. */
function trip_form_from_data(array $d): array
{
    $g = $d['guest'];
    $form = ['first_name' => $g['first_name'], 'middle_name' => (string)($g['middle_name'] ?? ''), 'last_name' => $g['last_name'], 'email' => $g['email'], 'phone' => $g['phone'], 'address' => $g['address']];
    $comp = $on = $qty = $req = [];
    foreach ($d['extras'] as $id => $x) {
        $comp[$id] = companion_values($x['companions']);
        foreach ($x['services'] as $sid => $q) {
            $on[$id][$sid]  = 1;
            $qty[$id][$sid] = $q;
        }
        $req[$id] = $x['requests'];
    }
    return [$form, $g['booker_type'], $comp, $on, $qty, $req];
}

/** Book everything, email the guest once, and go to payment (if one resort needs payment) or the trip summary. */
function trip_finish(PDO $pdo, array $rows, array $data): never
{
    $made = create_trip_booking($pdo, $rows, $data['guest'], $data['extras']);
    $refs = array_column($made['bookings'], 'ref');
    foreach ($refs as $ref) {
        $_SESSION['grants'][$ref] = true;
    }
    $_SESSION['trip_done'][$made['code']] = $refs;
    $_SESSION['last_trip']        = ['code' => $made['code'], 'at' => time()];
    $_SESSION['just_booked_trip'] = $made['code'];
    unset($_SESSION['pending_trip']);
    trip_clear();
    send_trip_email($pdo, $made['code'], array_column($made['bookings'], 'id'));

    // If every stay needing downpayment belongs to a single resort, go straight to one combined payment page.
    $payResortIds = [];
    foreach ($made['bookings'] as $idx => $b) {
        $rRow = $rows[$idx]['resort'] ?? null;
        if ($rRow && (int)($rRow['online_payment_enabled'] ?? 1) === 1 && (int)($rRow['downpayment_percent'] ?? 50) > 0) {
            $payResortIds[(int)$rRow['resort_id']] = true;
        }
    }
    if (count($payResortIds) === 1) {
        $onlyRid = (int)array_key_first($payResortIds);
        flash('Trip saved. Complete your GCash or Maya payment once to confirm all stays at this resort.', 'ok');
        redirect('payment.php?trip=' . urlencode($made['code']) . '&resort=' . $onlyRid);
    }

    flash('Thank you. Your booking requests are in.');
    redirect('trip_booked.php?code=' . urlencode($made['code']));
}

/** Email a fresh code and keep its hash in the session. */
function trip_start_verification(PDO $pdo, array $data, string $sig): void
{
    if (throttle_blocked($pdo, 'verify_send', 5)) {
        throw new RuntimeException('Too many codes requested. Please wait a few minutes and try again.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    if (!send_verification_email($data['guest']['email'], $data['guest']['first_name'], $code)) {
        throw new RuntimeException('We couldn’t send an email to that address. Check it and try again, or contact us.');
    }
    throttle_fail($pdo, 'verify_send');
    $_SESSION['pending_trip'] = [
        'sig' => $sig, 'data' => $data, 'salt' => $salt, 'hash' => hash('sha256', $salt . $code),
        'expires' => time() + 600, 'sent_at' => time(), 'tries' => 0,
    ];
}

$str = fn($v): string => is_scalar($v) ? trim((string)$v) : '';

// ---------- Submit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'details');

    try {
        if ($action === 'details') {
            foreach ($form as $k => $_) {
                $form[$k] = $str($_POST[$k] ?? '');
            }
            $form['address'] = (string)($acct['address'] ?? '');
            if ($acct) {
                $form['email'] = $acct['email'];   // an account's email is fixed and already verified
            }
            $bookerType = clean_booker_type($_POST['booker_type'] ?? 'Adult');

            // What was typed, kept so the form can be shown again after a mistake
            $stayPost = (array)($_POST['stay'] ?? []);
            foreach ($rows as $row) {
                $id = $row['id'];
                $sp = (array)($stayPost[$id] ?? []);
                $svcOn[$id]  = (array)($sp['svc_on'] ?? []);
                $svcQty[$id] = (array)($sp['svc_qty'] ?? []);
                $reqs[$id]   = $str($sp['requests'] ?? '');
                $ct = array_values((array)($sp['comp_type'] ?? []));
                $cf = array_values((array)($sp['comp_first'] ?? []));
                $cm = array_values((array)($sp['comp_middle'] ?? []));
                $cl = array_values((array)($sp['comp_last'] ?? []));
                $postedComps = isset($sp['comp_present']) ? max(count($ct), count($cf), count($cl)) : count(companion_slots($row['adults'], $row['children']));
                $compValues[$id] = [];
                for ($i = 0; $i < $postedComps; $i++) {
                    $compValues[$id][$i] = [
                        'type'        => $str($ct[$i] ?? 'Adult'),
                        'first_name'  => $str($cf[$i] ?? ''),
                        'middle_name' => $str($cm[$i] ?? ''),
                        'last_name'   => $str($cl[$i] ?? ''),
                    ];
                }
            }

            if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 60 || $form['last_name'] === '' || mb_strlen($form['last_name']) > 60 || mb_strlen($form['middle_name']) > 60) {
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
            if (empty($_POST['terms'])) {
                throw new RuntimeException('Please accept the booking terms to continue.');
            }

            $discountChoice = $str($_POST['discount_choice'] ?? 'none');
            $claimedSeniors = max(1, (int)($_POST['claimed_senior_count'] ?? 1));

            $extras = [];
            foreach ($rows as $row) {
                $id = $row['id'];
                $sp = (array)($stayPost[$id] ?? []);
                try {
                    if (mb_strlen($reqs[$id]) > 500) {
                        throw new RuntimeException('Special requests can be up to 500 characters.');
                    }
                    $chosen = [];
                    foreach ($services[$row['slug']] as $s) {
                        $sid = (int)$s['service_id'];
                        if (!empty($svcOn[$id][$sid])) {
                            $q = (int)($svcQty[$id][$sid] ?? 1);
                            if ($q < 1 || $q > 20) {
                                throw new RuntimeException('Service quantities must be between 1 and 20.');
                            }
                            $chosen[$sid] = $q;
                        }
                    }
                    $maxStayCap = max(1, (int)$row['capacity']);
                    $comp = parse_companions($sp, $row['adults'], $row['children'], true, $maxStayCap);
                    [$numA, $numC] = party_counts($bookerType, $comp, $row['adults'], $row['children'], true);
                    $stayGuests = max(1, $numA + $numC);
                    $stayDisc = parse_discount_application(
                        $pdo,
                        (int)$row['resort']['resort_id'],
                        (string)$row['check_in'],
                        $discountChoice,
                        $stayGuests,
                        min($stayGuests, $claimedSeniors)
                    );
                } catch (RuntimeException $e) {
                    throw new RuntimeException(trip_stay_name($row) . ': ' . $e->getMessage());
                }
                $extras[$id] = ['companions' => $comp, 'adults' => $numA, 'children' => $numC, 'services' => $chosen, 'requests' => $reqs[$id], 'discount' => $stayDisc];
            }

            $data = [
                'guest' => [
                    'first_name' => $form['first_name'], 'middle_name' => $form['middle_name'], 'last_name' => $form['last_name'], 'email' => strtolower($form['email']),
                    'phone' => $form['phone'], 'address' => $form['address'], 'booker_type' => $bookerType,
                ],
                'extras' => $extras,
            ];

            if ($verifyOn && empty($_SESSION['verified_emails'][$data['guest']['email']])) {
                trip_start_verification($pdo, $data, $sig);
                $pending = $_SESSION['pending_trip'];
            } else {
                trip_finish($pdo, $rows, $data);
            }
        } elseif (!$pending && in_array($action, ['verify', 'resend', 'edit'], true)) {
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
                $_SESSION['pending_trip']['tries']++;
                throttle_fail($pdo, 'verify_fail');
                $left = 5 - $_SESSION['pending_trip']['tries'];
                $pending = $_SESSION['pending_trip'];
                throw new RuntimeException('That code isn’t right.' . ($left > 0 ? ' ' . plural($left, 'try', 'tries') . ' left.' : ' Send a new code.'));
            }
            throttle_clear($pdo, 'verify_fail');
            $_SESSION['verified_emails'][$pending['data']['guest']['email']] = true;
            try {
                trip_finish($pdo, $rows, $pending['data']);
            } catch (RuntimeException $e) {
                // The email is verified but a stay failed (for example it was just taken): back to the form, nothing was booked.
                [$form, $bookerType, $compValues, $svcOn, $svcQty, $reqs] = trip_form_from_data($pending['data']);
                unset($_SESSION['pending_trip']);
                $pending = null;
                throw $e;
            }
        } elseif ($pending && $action === 'resend') {
            if (time() - $pending['sent_at'] < 60) {
                throw new RuntimeException('Please wait a minute before asking for another code.');
            }
            trip_start_verification($pdo, $pending['data'], $sig);
            $pending = $_SESSION['pending_trip'];
            $notice  = 'We sent you a new code.';
        } elseif ($pending && $action === 'edit') {
            [$form, $bookerType, $compValues, $svcOn, $svcQty, $reqs] = trip_form_from_data($pending['data']);
            unset($_SESSION['pending_trip']);
            $pending = null;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$step     = $pending ? 'verify' : 'details';
$roomSum  = array_sum(array_column($rows, 'total'));
$addonSum = 0.0;
if ($pending) {
    foreach ($rows as $row) {
        foreach ($services[$row['slug']] as $s) {
            $addonSum += (float)$s['unit_price'] * (int)($pending['data']['extras'][$row['id']]['services'][(int)$s['service_id']] ?? 0);
        }
    }
}
$back = urlencode('trip_checkout.php');

site_start('Book your trip', 'trip', 'no-nav');
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back" href="trip.php">&larr; Back to trip</a>
    <h1>Book your trip</h1>
  </div>
</div>
<?= booking_steps(1) ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>

<div class="checkout">
  <?php if ($step === 'verify'): ?>
  <section class="panel stack" id="verify-panel">
    <h2>Check your email</h2>
    <p>We sent a 6-digit code to <strong><?= h($pending['data']['guest']['email']) ?></strong>. Enter it to finish booking your trip. It works for 10 minutes.</p>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (($config['mail']['driver'] ?? 'log') !== 'smtp'): ?>
      <div class="notice" role="status">Test mode: no email was actually sent. Open <code>storage/mail.log</code> to read the code. Set up SMTP in <code>config.php</code> to send real emails.</div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label><span>Verification code</span>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" required autofocus>
      </label>
      <div class="actions"><button class="btn primary" type="submit" data-confirm="Send booking requests for every stay in your trip now?" data-confirm-title="Book trip" data-confirm-ok="Yes, book trip">Verify and book trip</button></div>
    </form>
    <div class="actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn" type="submit">Send a new code</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><button class="btn" type="submit">Use a different email</button></form>
    </div>
  </section>
  <?php else: ?>
  <div class="trip-form-col">
    <?php if (!$acct): ?>
    <p class="notice" role="note" style="margin-bottom:12px">Have an account? <a href="<?= h('login.php?next=' . $back) ?>">Log in</a> to fill in your details, or <a href="<?= h('register.php?next=' . $back) ?>">create one</a>. You can also continue as a guest.</p>
    <?php endif; ?>
    <form method="post" class="panel pay-form ck-form" id="checkout-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">

      <div class="pay-section">
        <div class="pay-sec-head">
          <span class="pay-sec-num">1</span>
          <div>
            <h2 class="pay-sec-title">Guest details</h2>
            <p class="pay-sec-sub">Format: Last name, First name. Applied to all stays in this trip.</p>
          </div>
        </div>
        <div class="grid-form three-col-mix">
          <label><span>Last name</span><input placeholder="e.g. Santos" name="last_name" value="<?= h($form['last_name']) ?>" maxlength="60" autocomplete="family-name" required></label>
          <label><span>First name</span><input placeholder="e.g. Maria" name="first_name" value="<?= h($form['first_name']) ?>" maxlength="60" autocomplete="given-name" required></label>
          <label><span>Middle name <small class="muted">(optional)</small></span><input placeholder="e.g. Cruz" name="middle_name" value="<?= h($form['middle_name']) ?>" maxlength="60" autocomplete="additional-name"></label>
        </div>
        <div class="grid-form three-col-mix" style="margin-top:12px">
          <label><span>Email</span><input placeholder="e.g. maria@example.com" type="email" name="email" value="<?= h($form['email']) ?>" maxlength="120" autocomplete="email" required<?= $acct ? ' readonly' : '' ?>></label>
          <label><span>Phone</span><input placeholder="e.g. 0917 123 4567" type="tel" name="phone" value="<?= h($form['phone']) ?>" maxlength="30" autocomplete="tel" required></label>
          <?= booker_type_field($bookerType) ?>
        </div>
      </div>

      <div class="pay-section">
        <div class="pay-sec-head">
          <span class="pay-sec-num">2</span>
          <div>
            <h2 class="pay-sec-title">Stays &amp; optional add-ons</h2>
            <p class="pay-sec-sub">Review each stay, enter other guests’ full names, and add extras if needed.</p>
          </div>
        </div>
      <?php foreach ($rows as $k => $row):
          $id  = $row['id'];
          $ns  = 'stay[' . $id . ']';
          $has = !empty($reqs[$id]) || !empty(array_filter($svcOn[$id] ?? []));
          ?>
      <fieldset class="trip-form-stay">
        <legend>Stay <?= $k + 1 ?> · <?= h($row['resort']['name']) ?></legend>
        <p class="tfs-line"><strong><?= h($row['label']) ?></strong>
          <span class="muted"><?= $row['scope'] === 'Cottage' ? h(fdate($row['check_in'])) . ' · Day use' : h(fdate($row['check_in'])) . ' to ' . h(fdate($row['check_out'])) ?> · <?= h(plural($row['adults'] + $row['children'], 'guest')) ?></span></p>
        <?php if (!empty($row['resort']['location'])): ?><p class="muted" style="margin:0 0 8px;font-size:12.5px"><?= h($row['resort']['location']) ?></p><?php endif; ?>

        <?php if ($row['scope'] !== 'Rooms'): ?>
          <p class="ck-stay-note">Up to <?= (int)$row['capacity'] ?> guests (<?= h(plural($row['adults'] + $row['children'], 'guest')) ?> arriving).</p>
        <?php else: ?>
          <p class="ck-stay-note">Sleeps up to <?= (int)$row['capacity'] ?> guests.</p>
        <?php endif; ?>
        <?= companion_fields($row['adults'], $row['children'], $compValues[$id] ?? [], true, $ns, (int)$row['capacity']) ?>

        <details class="tfs-more"<?= $has ? ' open' : '' ?>>
          <summary>Add-ons and requests <small class="muted">(optional)</small></summary>
          <?php if ($services[$row['slug']]): ?>
          <div class="addon-pick">
            <?php foreach ($services[$row['slug']] as $s): $sid = (int)$s['service_id']; ?>
              <div class="addon-row">
                <label class="check">
                  <input type="checkbox" name="<?= h($ns) ?>[svc_on][<?= $sid ?>]" value="1" data-price="<?= h($s['unit_price']) ?>" <?= !empty($svcOn[$id][$sid]) ? 'checked' : '' ?>>
                  <span><?= h($s['service_name']) ?> <small class="muted"><?= h(money($s['unit_price'])) ?> each</small></span>
                </label>
                <input type="number" name="<?= h($ns) ?>[svc_qty][<?= $sid ?>]" value="<?= (int)($svcQty[$id][$sid] ?? 1) ?>" min="1" max="20" aria-label="Quantity for <?= h($s['service_name']) ?>">
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <label><span>Special requests</span>
            <textarea name="<?= h($ns) ?>[requests]" rows="2" maxlength="500" placeholder="Optional, e.g. Late arrival, celebration, dietary needs"><?= h($reqs[$id] ?? '') ?></textarea>
          </label>
        </details>
      </fieldset>
      <?php endforeach; ?>
      </div>

      <div class="pay-section">
        <div class="pay-sec-head">
          <span class="pay-sec-num">3</span>
          <div>
            <h2 class="pay-sec-title">Over-the-Counter Discounts <small class="muted">(optional)</small></h2>
            <p class="pay-sec-sub">Initial online downpayment uses the regular rate. No ID photo upload is needed—Senior Citizen (RA 9994) and PWD discounts are verified and approved over the counter by staff, admin, or superadmin.</p>
          </div>
        </div>
        <label><span>Discount / claim type</span>
          <select name="discount_choice" id="discount-choice">
            <option value="none" data-is-statutory="0"<?= $discountChoice === 'none' ? ' selected' : '' ?>>No discount (regular rate)</option>
            <option value="Senior" data-is-statutory="1"<?= $discountChoice === 'Senior' ? ' selected' : '' ?>>Senior Citizen Discount — RA 9994 (20% off + 12% VAT-exempt pro-rated share · verified over the counter)</option>
            <option value="PWD" data-is-statutory="1"<?= $discountChoice === 'PWD' ? ' selected' : '' ?>>PWD Discount (20% off + 12% VAT-exempt pro-rated share · verified over the counter)</option>
          </select>
        </label>
        <div id="discount-id-wrap" style="margin-top:12px"<?= in_array($discountChoice, ['Senior', 'PWD'], true) ? '' : ' hidden' ?>>
          <label><span>Number of Senior / PWD occupants</span>
            <input type="number" name="claimed_senior_count" value="1" min="1" max="50" step="1">
          </label>
          <p class="muted" style="margin:6px 0 0;font-size:13px">No ID upload needed online. Staff, admin, or superadmin will verify and approve the discount over the counter.</p>
        </div>
      </div>

      <div class="ck-terms-box">
        <label class="check terms" style="margin:0">
          <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?> required>
          <span>I accept each resort’s booking, downpayment, and cancellation rules.</span>
        </label>
      </div>

      <div class="actions pay-submit-row">
        <button class="btn primary" type="submit"<?= $verifyOn && !$acct ? '' : ' data-confirm="Create the bookings for every stay in your trip now?" data-confirm-title="Book trip" data-confirm-ok="Yes, book trip"' ?>><?= $verifyOn && !$acct ? 'Continue' : 'Book trip' ?></button>
        <span class="ck-trust-note"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg> Combined trip checkout</span>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <aside class="panel summary-card is-fintech" aria-label="Trip summary">
    <div class="sum-head">
      <span class="sum-eyebrow"><?= h(plural(count($rows), 'stay')) ?> in your trip</span>
      <h2>Trip summary</h2>
    </div>
    <dl class="facts totals trip-sum">
      <?php $estDp = 0.0; foreach ($rows as $row):
          $rPayOn = (int)($row['resort']['online_payment_enabled'] ?? 1) === 1;
          $rPct   = $rPayOn ? max(0, min(100, (int)($row['resort']['downpayment_percent'] ?? 50))) : 0;
          $estDp += $rPct > 0 ? round($row['total'] * $rPct / 100, 2) : 0.0;
      ?>
        <dt><?= h($row['resort']['name']) ?><br><span class="muted"><?= h($row['label']) ?><?= $rPct > 0 ? ' · ' . $rPct . '% downpayment' : '' ?></span></dt><dd class="num"><?= h(money($row['total'])) ?></dd>
      <?php endforeach; ?>
      <dt>Add-ons</dt><dd class="num" id="addon-total"><?= h(money($addonSum)) ?></dd>
      <dt>Total</dt><dd class="num strong" id="grand-total"><?= h(money($roomSum + $addonSum)) ?></dd>
    </dl>
    <?php if ($estDp > 0.004): ?>
    <div class="sum-highlight">
      <div class="sum-hi-row">
        <span>Est. downpayment</span>
        <strong><?= h(money($estDp)) ?>+</strong>
      </div>
    </div>
    <?php endif; ?>
    <p class="muted sum-foot-note"><?= $estDp > 0.004 ? 'Next step: Pay downpayment via GCash or Maya.' : 'Pay at each resort.' ?></p>
  </aside>
</div>

<script>
(function () {
  var cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  var base = <?= json_encode((float)$roomSum) ?>;
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
    grandEl.textContent = fmt(base + sum);
  }
  rows.forEach(function (row) {
    row.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', update); i.addEventListener('change', update); });
  });
  update();
})();
(function () {
  var discSel = document.getElementById('discount-choice');
  var discWrap = document.getElementById('discount-id-wrap');
  if (!discSel) return;
  function syncDisc() {
    var opt = discSel.selectedOptions && discSel.selectedOptions[0];
    var isStat = opt && opt.getAttribute('data-is-statutory') === '1';
    if (discWrap) discWrap.hidden = !isStat;
  }
  discSel.addEventListener('change', syncDisc);
  syncDisc();
})();
</script>
<?php site_end();
