<?php
/**
 * Step 2 of booking: GCash / Maya payment submission (reference number + required payment screenshot).
 * Supports both:
 *  - Single booking: payment.php?ref=<booking_ref>
 *  - Combined same-resort trip payment: payment.php?trip=<trip_code>&resort=<resort_id>
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/accounts.php';

$tripCode = strtoupper(trim((string)($_POST['trip'] ?? $_GET['trip'] ?? '')));
$tripCode = preg_match('/^[A-Z0-9]{6,10}$/', $tripCode) ? $tripCode : '';
$tripRid  = (int)($_POST['resort'] ?? $_GET['resort'] ?? 0);

$ref = strtoupper(trim((string)($_POST['ref'] ?? $_GET['ref'] ?? '')));
$ref = preg_match('/^[A-Z0-9]{6,10}$/', $ref) ? $ref : '';

$isTripPay = $tripCode !== '' && $tripRid > 0;
if (!$isTripPay && $ref === '') {
    redirect('booking.php');
}

$acct = current_guest($pdo);
$resList = [];

if ($isTripPay) {
    $stmt = $pdo->prepare(
        "SELECT v.*, g.first_name, g.last_name, g.email, g.phone,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
                (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
           FROM reservation v JOIN guest g ON g.guest_id = v.guest_id
          WHERE v.trip_code = ? AND v.resort_id = ?
          ORDER BY v.reservation_id"
    );
    $stmt->execute([$tripCode, $tripRid]);
    $allTripRes = $stmt->fetchAll();

    $allowed = false;
    foreach ($allTripRes as $r) {
        if (!empty($_SESSION['grants'][$r['booking_ref']]) || ($acct && (int)$acct['guest_id'] === (int)$r['guest_id'])) {
            $allowed = true;
            break;
        }
    }
    if (!$allTripRes || !$allowed) {
        flash('Please look up your trip first.', 'error');
        redirect('booking.php?ref=' . urlencode($tripCode));
    }
    foreach ($allTripRes as $r) {
        $_SESSION['grants'][$r['booking_ref']] = true;
    }
    $resList = $allTripRes;
} else {
    $stmt = $pdo->prepare(
        "SELECT v.*, g.first_name, g.last_name, g.email, g.phone,
                (SELECT b.name FROM building b WHERE b.building_id = v.building_id) AS building_name,
                (SELECT p.name FROM reservation_pool rp JOIN pool p ON p.pool_id = rp.pool_id WHERE rp.reservation_id = v.reservation_id LIMIT 1) AS pool_name,
                (SELECT rc.cottage_name FROM reservation_cottage rc WHERE rc.reservation_id = v.reservation_id LIMIT 1) AS cottage_name
           FROM reservation v JOIN guest g ON g.guest_id = v.guest_id
          WHERE v.booking_ref = ?"
    );
    $stmt->execute([$ref]);
    $single = $stmt->fetch();
    $allowed = $single && (!empty($_SESSION['grants'][$ref]) || ($acct && (int)$acct['guest_id'] === (int)$single['guest_id']));
    if (!$allowed) {
        flash('Please look up your booking with your reference and email first.', 'error');
        redirect('booking.php?ref=' . urlencode($ref));
    }
    $_SESSION['grants'][$ref] = true;
    $resList = [$single];
}

$firstRes = $resList[0];
$rr = resort_by_id($pdo, (int)$firstRes['resort_id']);
if ($rr) {
    resort_apply($rr);
}
$backUrl = $isTripPay ? ('trip_booked.php?code=' . urlencode($tripCode)) : ('booking.php?ref=' . urlencode($ref));
$selfUrl = $isTripPay ? ('payment.php?trip=' . urlencode($tripCode) . '&resort=' . $tripRid) : ('payment.php?ref=' . urlencode($ref));

if (!$rr || (int)($rr['online_payment_enabled'] ?? 1) !== 1) {
    flash('This resort does not accept GCash or Maya payments online right now.', 'error');
    redirect($backUrl);
}

// Compute combined payment summary across all payable stays in this payment.
$payableStays    = [];
$combinedTotal   = 0.0;
$combinedReqDp   = 0.0;
$combinedPaid    = 0.0;
$unclaimedBal    = 0.0;
$dpAmt           = 0.0;
$anyAwaiting     = false;

$lineStmt = $pdo->prepare(
    'SELECT t.type_name, COUNT(*) AS qty
       FROM reservation_room rr
       JOIN room r ON r.room_id = rr.room_id
       JOIN room_type t ON t.room_type_id = r.room_type_id
      WHERE rr.reservation_id = ? GROUP BY t.type_name'
);

foreach ($resList as $r) {
    $rid = (int)$r['reservation_id'];
    $sum = reservation_payment_summary($pdo, $r);
    if ($sum['awaiting_verification']) {
        $anyAwaiting = true;
    }
    if (in_array($r['status'], ['Cancelled', 'Checked-out'], true)) {
        continue;
    }
    $sUnclaimed = round(max(0.0, $sum['balance'] - $sum['pending']), 2);
    if ($sUnclaimed <= 0.004) {
        continue;
    }
    if ($r['booking_scope'] === 'Rooms') {
        $lineStmt->execute([$rid]);
        $r['what'] = implode(', ', array_map(fn($l) => $l['type_name'] . ' × ' . (int)$l['qty'], $lineStmt->fetchAll()));
    } else {
        $r['what'] = scope_label($r);
    }
    $sDpRem = $sum['downpayment_remaining'] > 0.004 ? min($sUnclaimed, $sum['downpayment_remaining']) : 0.0;
    $r['_sum']       = $sum;
    $r['_unclaimed'] = $sUnclaimed;
    $r['_dp_share']  = $sDpRem;
    $payableStays[]  = $r;

    $combinedTotal = round($combinedTotal + (float)$r['total_amount'], 2);
    $combinedReqDp = round($combinedReqDp + $sum['required_downpayment'], 2);
    $combinedPaid  = round($combinedPaid + $sum['paid'], 2);
    $unclaimedBal  = round($unclaimedBal + $sUnclaimed, 2);
    $dpAmt         = round($dpAmt + $sDpRem, 2);
}

if (!$payableStays || $unclaimedBal <= 0.004) {
    if ($anyAwaiting) {
        flash('Your payment is already submitted and awaiting staff verification.');
    }
    redirect($backUrl);
}
if ($dpAmt <= 0.004) {
    $dpAmt = $unclaimedBal;
}

// Catch oversized uploads where PHP empties $_POST and $_FILES.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('That screenshot is too big to upload. Choose a JPG, PNG or WebP image under 8 MB and try again.', 'error');
    redirect($selfUrl);
}

$enabledMethods = payment_enabled_methods();
$dpPct          = (int)($rr['downpayment_percent'] ?? 50);
$hasDpChoice    = $dpAmt > 0.004 && $unclaimedBal > $dpAmt + 0.004;

$payPlan   = $hasDpChoice ? 'downpayment' : 'full';
$payMethod = $enabledMethods[0] ?? 'gcash';
$refNo     = '';
$sender    = trim($firstRes['first_name'] . ' ' . $firstRes['last_name']);
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rawPlan   = (string)($_POST['pay_plan'] ?? $payPlan);
    $payPlan   = ($hasDpChoice && $rawPlan === 'downpayment') ? 'downpayment' : 'full';
    $rawMethod = strtolower(trim((string)($_POST['pay_method'] ?? $payMethod)));
    $payMethod = in_array($rawMethod, $enabledMethods, true) ? $rawMethod : ($enabledMethods[0] ?? 'gcash');
    $refNo     = trim((string)($_POST['reference_no'] ?? ''));
    $sender    = trim((string)($_POST['sender_name'] ?? ''));

    $savedProof = '';
    try {
        $cleanRef = clean_payment_reference($refNo);
        if (mb_strlen($sender) > 100) {
            throw new RuntimeException('Keep the sender name or mobile number under 100 characters.');
        }
        $compactRef = str_replace([' ', '-'], '', $cleanRef);
        $dup = $pdo->prepare(
            "SELECT 1 FROM payment
              WHERE method = ?
                AND REPLACE(REPLACE(UPPER(COALESCE(reference_no, '')), ' ', ''), '-', '') = ?
                AND payment_status IN ('Pending', 'Paid')
              LIMIT 1"
        );
        $dup->execute([payment_method_label($payMethod), $compactRef]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('That ' . payment_method_label($payMethod) . ' reference number has already been submitted. Check your receipt.');
        }

        $firstId    = (int)$payableStays[0]['reservation_id'];
        $savedProof = payment_save_proof($_FILES, $firstId, 'proof_image');
        $amount     = $payPlan === 'downpayment' ? $dpAmt : $unclaimedBal;

        if (count($payableStays) === 1 && !$isTripPay) {
            submit_guest_payment($pdo, $firstId, $payMethod, $amount, $cleanRef, $sender, $savedProof);
            $_SESSION['just_paid_submitted'] = $ref;
            flash('Thank you! Your ' . payment_method_label($payMethod) . ' payment of ' . money($amount) . ' (Ref: ' . $cleanRef . ') and screenshot were submitted for staff verification.', 'ok');
            redirect('booking.php?ref=' . urlencode($ref));
        } else {
            $targets = [];
            foreach ($payableStays as $ps) {
                $share = $payPlan === 'downpayment' ? (float)$ps['_dp_share'] : (float)$ps['_unclaimed'];
                if ($share > 0.004) {
                    $targets[] = ['reservation' => $ps, 'amount' => $share];
                }
            }
            submit_trip_resort_payment($pdo, $targets, $payMethod, $cleanRef, $sender, $savedProof, $tripCode);
            if ($isTripPay) {
                $_SESSION['just_paid_trip'] = $tripCode;
                flash('Thank you! Your combined ' . payment_method_label($payMethod) . ' payment of ' . money($amount) . ' (Ref: ' . $cleanRef . ') for ' . $config['resort'] . ' was submitted for staff verification.', 'ok');
                redirect('trip_booked.php?code=' . urlencode($tripCode));
            } else {
                $_SESSION['just_paid_submitted'] = $ref;
                flash('Thank you! Your ' . payment_method_label($payMethod) . ' payment of ' . money($amount) . ' (Ref: ' . $cleanRef . ') and screenshot were submitted for staff verification.', 'ok');
                redirect('booking.php?ref=' . urlencode($ref));
            }
        }
    } catch (RuntimeException $e) {
        if ($savedProof !== '') {
            @unlink(__DIR__ . '/uploads/' . $savedProof);
        }
        $error = $e->getMessage();
    }
}

$payIds = array_map(fn($r) => (int)$r['reservation_id'], $payableStays);
$rejStmt = $pdo->prepare(
    "SELECT method, reference_no, verification_note FROM payment
      WHERE reservation_id IN (" . implode(',', array_fill(0, count($payIds), '?')) . ")
        AND payment_status = 'Voided' AND source = 'Guest' AND verification_note IS NOT NULL
      ORDER BY payment_id DESC LIMIT 1"
);
$rejStmt->execute($payIds);
$lastRejected = $rejStmt->fetch() ?: null;

$payingNowAmt = $payPlan === 'downpayment' ? $dpAmt : $unclaimedBal;
$headingLabel = $isTripPay
    ? ('Payment for ' . $config['resort'])
    : ('Payment for ' . $ref);

// Allow going back to Step 1 (Your details) whenever this is an unpaid Pending single booking.
$canEditStep1 = !$isTripPay
    && count($payableStays) === 1
    && ($payableStays[0]['status'] ?? '') === 'Pending'
    && $combinedPaid <= 0.004
    && !$anyAwaiting;
$step1Url = $canEditStep1
    ? ('checkout.php?r=' . rawurlencode((string)$rr['slug']) . '&edit=' . rawurlencode($ref))
    : '';
$topBackHref  = $canEditStep1 ? $step1Url : $backUrl;
$topBackLabel = $canEditStep1 ? 'Back to your details' : ($isTripPay ? 'Back to trip' : 'Back to booking');

site_start($headingLabel, $isTripPay ? 'trip' : 'booking', 'no-nav');
?>
<div class="page-head has-back">
  <div class="ph-left">
    <a class="btn sm page-back" href="<?= h($topBackHref) ?>">&larr; <?= h($topBackLabel) ?></a>
    <h1><?= $isTripPay ? 'Payment for ' . h($config['resort']) : 'Payment for <span class="ref">' . h($ref) . '</span>' ?></h1>
  </div>
</div>
<?= booking_steps(2, $canEditStep1 ? [1 => $step1Url] : []) ?>

<?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($lastRejected): ?>
  <div class="notice error" role="alert">
    Previous <?= h($lastRejected['method']) ?> payment rejected: <strong><?= h($lastRejected['verification_note']) ?></strong>. Submit a clear screenshot and reference number below.
  </div>
<?php endif; ?>

<div class="checkout">
  <form method="post" enctype="multipart/form-data" class="panel pay-form" id="payment-form">
    <?= csrf_field() ?>
    <?php if ($isTripPay): ?>
      <input type="hidden" name="trip" value="<?= h($tripCode) ?>">
      <input type="hidden" name="resort" value="<?= $tripRid ?>">
    <?php else: ?>
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
    <?php endif; ?>

    <?php $secNum = 1; ?>
    <?php if ($hasDpChoice): ?>
    <div class="pay-section">
      <div class="pay-sec-head">
        <span class="pay-sec-num"><?= $secNum++ ?></span>
        <div>
          <h2 class="pay-sec-title">Amount to pay</h2>
          <p class="pay-sec-sub">Reserve with a downpayment or settle the full balance now.</p>
        </div>
      </div>
      <div class="pay-plan" role="radiogroup" aria-label="Amount to pay">
        <label class="pay-opt">
          <input type="radio" name="pay_plan" value="downpayment"<?= $payPlan === 'downpayment' ? ' checked' : '' ?>>
          <span class="pay-opt-body">
            <span class="pay-opt-top">
              <span class="pay-opt-amt"><?= h(money($dpAmt)) ?></span>
              <span class="pay-opt-ring" aria-hidden="true"></span>
            </span>
            <strong><?= $dpPct > 0 && $dpPct < 100 ? $dpPct . '% Downpayment' : 'Downpayment' ?></strong>
            <small><?= h(money(max(0, $unclaimedBal - $dpAmt))) ?> balance at resort</small>
          </span>
        </label>
        <label class="pay-opt">
          <input type="radio" name="pay_plan" value="full"<?= $payPlan === 'full' ? ' checked' : '' ?>>
          <span class="pay-opt-body">
            <span class="pay-opt-top">
              <span class="pay-opt-amt"><?= h(money($unclaimedBal)) ?></span>
              <span class="pay-opt-ring" aria-hidden="true"></span>
            </span>
            <strong>Full payment</strong>
            <small>₱0.00 balance · ready for full approval</small>
          </span>
        </label>
      </div>
    </div>
    <?php else: ?>
      <input type="hidden" name="pay_plan" value="full">
    <?php endif; ?>

    <div class="pay-section">
      <div class="pay-sec-head">
        <span class="pay-sec-num"><?= $secNum++ ?></span>
        <div>
          <h2 class="pay-sec-title">Payment method</h2>
          <p class="pay-sec-sub">Transfer directly to the resort’s verified GCash or Maya account.</p>
        </div>
      </div>
      <div class="pay-transfer-box">
        <?= payment_method_picker($payMethod, 'pay_method') ?>
        <?= payment_accounts_box($rr, true, $payMethod) ?>
      </div>
    </div>

    <div class="pay-section">
      <div class="pay-sec-head">
        <span class="pay-sec-num"><?= $secNum++ ?></span>
        <div>
          <h2 class="pay-sec-title">Proof of payment</h2>
          <p class="pay-sec-sub">Enter the reference number and upload a clear receipt screenshot.</p>
        </div>
      </div>
      <div class="grid-form two-col">
        <label><span>Reference number</span>
          <input name="reference_no" value="<?= h($refNo) ?>" maxlength="60" placeholder="e.g. 1029 3847 56123" required>
        </label>
        <label><span>Sender <small class="muted">(Lastname, Firstname or mobile)</small></span>
          <input name="sender_name" value="<?= h($sender) ?>" maxlength="100" placeholder="e.g. Santos, Maria or 0917…">
        </label>
      </div>

      <label class="proof-dropzone" for="proof-input">
        <input type="file" name="proof_image" id="proof-input" accept="image/jpeg,image/png,image/webp" required>
        <span class="proof-dz-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </span>
        <span class="proof-dz-copy">
          <strong id="proof-dz-title">Upload payment screenshot</strong>
          <small id="proof-dz-sub">JPG, PNG or WebP · max 8 MB</small>
        </span>
      </label>

      <div id="proof-preview-wrap" class="proof-preview" hidden>
        <img id="proof-preview-img" src="" alt="Payment screenshot preview">
        <small class="muted">Make sure the reference number and amount are readable.</small>
      </div>
    </div>

    <div class="actions pay-submit-row">
      <button class="btn primary" type="submit" id="pay-submit-btn">Submit payment · <span id="pay-btn-amt"><?= h(money($payingNowAmt)) ?></span></button>
      <a class="btn" href="<?= h($backUrl) ?>">Pay later</a>
    </div>
  </form>

  <aside class="panel summary-card is-fintech" aria-label="Booking summary">
    <div class="sum-head">
      <span class="sum-eyebrow"><?= count($payableStays) > 1 ? 'Combined resort payment' : 'Payment summary' ?></span>
      <h2><?= h($config['resort']) ?></h2>
      <?php if (!empty($rr['location'])): ?><p class="sum-addr"><?= h($rr['location']) ?></p><?php endif; ?>
    </div>
    <?php if (count($payableStays) > 1): ?>
      <dl class="facts">
        <dt>Trip</dt><dd><strong class="ref"><?= h($tripCode) ?></strong></dd>
        <dt>Stays</dt><dd><?= count($payableStays) ?> stays paid together</dd>
      </dl>
      <dl class="facts totals trip-sum">
        <?php foreach ($payableStays as $ps): ?>
          <dt>
            <strong><?= h($ps['what']) ?></strong><br>
            <small class="muted">
              <?= h($ps['booking_ref']) ?> ·
              <?= $ps['booking_scope'] === 'Cottage'
                  ? h(fdate($ps['check_in_date'])) . ' (Day use)'
                  : h(fdate($ps['check_in_date'])) . ' to ' . h(fdate($ps['check_out_date'])) ?>
            </small>
          </dt>
          <dd class="num"><?= h(money($ps['total_amount'])) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php else: $only = $payableStays[0]; $n = nights($only['check_in_date'], $only['check_out_date']); ?>
      <dl class="facts">
        <dt>Reference</dt><dd><strong class="ref"><?= h($only['booking_ref']) ?></strong></dd>
        <dt>Booked</dt><dd><?= h($only['what']) ?></dd>
        <?php if ($only['booking_scope'] === 'Cottage'): ?>
          <dt>Visit date</dt><dd><?= h(fdate($only['check_in_date'])) ?> <span class="muted">(Day use)</span></dd>
          <dt>Guests</dt><dd><?= h(plural((int)$only['num_guests'], 'guest')) ?></dd>
        <?php else: ?>
          <dt>Check-in</dt><dd><?= h(fdate($only['check_in_date'])) ?><br><span class="muted">from <?= h($config['checkin_time']) ?></span></dd>
          <dt>Check-out</dt><dd><?= h(fdate($only['check_out_date'])) ?><br><span class="muted">by <?= h($config['checkout_time']) ?></span></dd>
          <dt>Nights</dt><dd><?= $n ?></dd>
        <?php endif; ?>
      </dl>
    <?php endif; ?>
    <dl class="facts totals">
      <dt>Total</dt><dd class="num strong"><?= h(money($combinedTotal)) ?></dd>
      <?php if ($combinedReqDp > 0.004): ?>
        <dt>Required downpayment</dt><dd class="num"><?= h(money($combinedReqDp)) ?></dd>
      <?php endif; ?>
      <?php if ($combinedPaid > 0.004): ?>
        <dt>Already paid</dt><dd class="num"><?= h(money($combinedPaid)) ?></dd>
      <?php endif; ?>
    </dl>
    <div class="sum-highlight">
      <div class="sum-hi-row">
        <span>Paying now</span>
        <strong id="sum-pay-now"><?= h(money($payingNowAmt)) ?></strong>
      </div>
      <div class="sum-hi-sub">
        <span>Balance after</span>
        <span id="sum-bal-after"><?= h(money(max(0, round($unclaimedBal - $payingNowAmt, 2)))) ?></span>
      </div>
    </div>
  </aside>
</div>

<script>
(function () {
  var cur = <?= json_encode($config['currency'], JSON_UNESCAPED_UNICODE) ?>;
  var dpAmt = <?= json_encode($dpAmt) ?>;
  var fullAmt = <?= json_encode($unclaimedBal) ?>;
  var radios = document.querySelectorAll('input[name="pay_plan"]');
  var transferEls = document.querySelectorAll('[data-transfer-amt]');
  var btnAmtEl = document.getElementById('pay-btn-amt');
  var sumNowEl = document.getElementById('sum-pay-now');
  var sumBalEl = document.getElementById('sum-bal-after');
  function fmt(v) { return cur + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function syncAmt() {
    var checked = document.querySelector('input[name="pay_plan"]:checked');
    var amt = (checked && checked.value === 'downpayment') ? dpAmt : fullAmt;
    var rem = Math.max(0, Math.round((fullAmt - amt) * 100) / 100);
    transferEls.forEach(function (el) { el.textContent = fmt(amt); });
    if (btnAmtEl) btnAmtEl.textContent = fmt(amt);
    if (sumNowEl) sumNowEl.textContent = fmt(amt);
    if (sumBalEl) sumBalEl.textContent = fmt(rem);
  }
  radios.forEach(function (r) { r.addEventListener('change', syncAmt); });
  syncAmt();

  var methodRadios = document.querySelectorAll('input[name="pay_method"]');
  var acctCards = document.querySelectorAll('.pay-accounts.is-single .pay-acct[data-acct]');
  function syncAcct() {
    var checked = document.querySelector('input[name="pay_method"]:checked');
    if (!checked || !acctCards.length) return;
    acctCards.forEach(function (c) { c.hidden = (c.dataset.acct !== checked.value); });
  }
  methodRadios.forEach(function (r) { r.addEventListener('change', syncAcct); });
  syncAcct();

  var input = document.getElementById('proof-input');
  var wrap = document.getElementById('proof-preview-wrap');
  var img = document.getElementById('proof-preview-img');
  var dzTitle = document.getElementById('proof-dz-title');
  var dzSub = document.getElementById('proof-dz-sub');
  if (input && wrap && img) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file || !file.type.startsWith('image/')) {
        wrap.hidden = true;
        img.src = '';
        if (dzTitle) dzTitle.textContent = 'Upload payment screenshot';
        if (dzSub) dzSub.textContent = 'JPG, PNG or WebP · max 8 MB';
        return;
      }
      if (dzTitle) dzTitle.textContent = file.name;
      if (dzSub) dzSub.textContent = (file.size / 1048576).toFixed(2) + ' MB · Click to change';
      var reader = new FileReader();
      reader.onload = function (ev) {
        img.src = ev.target.result;
        wrap.hidden = false;
      };
      reader.readAsDataURL(file);
    });
  }
})();
</script>
<?php site_end();
