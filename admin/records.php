<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/payments.php';
$staffMember = require_login($pdo);

$RID    = resort_id();
$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$method = (string)($_GET['method'] ?? '');
$period = (string)($_GET['period'] ?? '') === 'month' ? 'month' : '';

$allowedStatuses = ['Pending', 'Paid', 'Voided', 'Refunded'];
$allowedMethods  = ['GCash', 'Maya', 'Cash', 'Card', 'Bank transfer'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}
if (!in_array($method, $allowedMethods, true)) {
    $method = '';
}

// ---------- Summary numbers for this resort ----------
$sumStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE p.payment_status WHEN 'Paid' THEN p.amount WHEN 'Refunded' THEN -p.amount ELSE 0 END), 0) AS verified_total,
        COALESCE(SUM(CASE WHEN p.payment_status = 'Paid' AND p.payment_date >= ? THEN p.amount
                          WHEN p.payment_status = 'Refunded' AND p.payment_date >= ? THEN -p.amount ELSE 0 END), 0) AS month_total,
        SUM(CASE WHEN p.payment_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        COALESCE(SUM(CASE WHEN p.payment_status = 'Pending' THEN p.amount ELSE 0 END), 0) AS pending_total,
        SUM(CASE WHEN p.payment_status = 'Voided' THEN 1 ELSE 0 END) AS voided_count
       FROM payment p
       JOIN reservation v ON v.reservation_id = p.reservation_id
      WHERE v.resort_id = ?"
);
$monthStart = date('Y-m-01');
$sumStmt->execute([$monthStart, $monthStart, $RID]);
$stats = $sumStmt->fetch() ?: [];

// ---------- Payment records query ----------
$where  = ['v.resort_id = ?'];
$params = [$RID];
if ($status !== '') {
    $where[]  = 'p.payment_status = ?';
    $params[] = $status;
}
if ($method !== '') {
    $where[]  = 'p.method = ?';
    $params[] = $method;
}
if ($period === 'month') {
    $where[]  = 'p.payment_date >= ?';
    $params[] = $monthStart;
}
if ($q !== '') {
    $like     = '%' . $q . '%';
    $where[]  = "(v.booking_ref = ? OR CONCAT(g.first_name, ' ', g.last_name) LIKE ? OR COALESCE(p.reference_no, '') LIKE ? OR COALESCE(p.sender_name, '') LIKE ?)";
    $params[] = strtoupper($q);
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "SELECT p.*, v.booking_ref, v.status AS booking_status,
               CONCAT(g.first_name, ' ', g.last_name) AS guest,
               COALESCE(p.approved_by_name, sv.full_name, sr.full_name) AS approver_name,
               COALESCE(p.approved_by_email, sv.email, sr.email) AS approver_email
          FROM payment p
          JOIN reservation v ON v.reservation_id = p.reservation_id
          JOIN guest g       ON g.guest_id = v.guest_id
          LEFT JOIN staff sv ON sv.staff_id = p.verified_by_staff_id
          LEFT JOIN staff sr ON sr.staff_id = p.recorded_by_staff_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY (p.payment_status = 'Pending') DESC, p.payment_id DESC
         LIMIT 250";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

admin_start('Payment records', 'records');
?>
<?php admin_head(
    'Payment records',
    ($q !== '' || $status !== '' || $method !== '')
        ? plural(count($rows), 'record') . ' shown'
        : ''
); ?>

<div class="metric-strip is-grid" style="margin-bottom:14px" aria-label="Payment totals">
  <a class="metric" href="records.php?status=Paid"><b><?= h(money((float)($stats['verified_total'] ?? 0))) ?></b><span><i class="dot lime"></i>Verified total</span></a>
  <a class="metric" href="records.php?status=Paid&amp;period=month"><b><?= h(money((float)($stats['month_total'] ?? 0))) ?></b><span><i class="dot pink"></i>This month</span></a>
  <a class="metric" href="records.php?status=Pending"><b><?= (int)($stats['pending_count'] ?? 0) ?> <small style="font-size:13px;font-weight:500">(<?= h(money((float)($stats['pending_total'] ?? 0))) ?>)</small></b><span><i class="dot gold"></i>Pending verification</span></a>
  <a class="metric" href="records.php?status=Voided"><b><?= (int)($stats['voided_count'] ?? 0) ?></b><span><i class="dot orange"></i>Rejected / voided</span></a>
</div>

<form method="get" class="filters">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search booking ref, guest, or GCash/Maya ref no." aria-label="Search payment records">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach ($allowedStatuses as $s): ?>
      <option value="<?= h($s) ?>"<?= $s === $status ? ' selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="method" aria-label="Method">
    <option value="">All methods</option>
    <?php foreach ($allowedMethods as $m): ?>
      <option value="<?= h($m) ?>"<?= $m === $method ? ' selected' : '' ?>><?= h($m) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== '' || $status !== '' || $method !== '' || $period !== ''): ?><a class="btn" href="records.php">Clear</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No payment records found.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Booking</th>
          <th>Guest</th>
          <th>Method</th>
          <th>Reference &amp; screenshot</th>
          <th>Approved by</th>
          <th>Status</th>
          <th class="right">Amount</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p):
          $proofUrl = payment_proof_url($p['proof_image'] ?? null, '../');
          $isPend   = $p['payment_status'] === 'Pending';
      ?>
        <tr>
          <td class="nowrap"><?= h(date('M j, Y', strtotime($p['payment_date']))) ?></td>
          <td class="strong nowrap">
            <a href="reservation_view.php?id=<?= (int)$p['reservation_id'] ?>#payments"><?= h($p['booking_ref']) ?></a>
          </td>
          <td><?= h($p['guest']) ?></td>
          <td class="nowrap"><?= h($p['method']) ?></td>
          <td>
            <?php if (!empty($p['reference_no'])): ?>
              <code class="ref-code"><?= h($p['reference_no']) ?></code>
              <?php if (!empty($p['sender_name'])): ?><br><small class="muted"><?= h($p['sender_name']) ?></small><?php endif; ?>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
            <?php if ($proofUrl !== ''): ?>
              <br><a class="proof-link" href="<?= h($proofUrl) ?>" data-proof-title="<?= h($p['booking_ref'] . ' · ' . $p['method'] . (!empty($p['reference_no']) ? ' · Ref ' . $p['reference_no'] : '') . ' · ' . money($p['amount'])) ?>"><img class="proof-thumb" src="<?= h($proofUrl) ?>" alt="Payment screenshot"><span>Screenshot</span></a>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $aName  = trim((string)($p['approver_name'] ?? ''));
              $aEmail = trim((string)($p['approver_email'] ?? ''));
              $aWhen  = !empty($p['verified_at']) ? date('M j, Y · g:i A', strtotime($p['verified_at'])) : '';
            ?>
            <?php if ($p['payment_status'] === 'Paid' && $aName !== ''): ?>
              <span><?= h($aName) ?></span>
              <?php if ($aEmail !== ''): ?><br><small class="muted"><?= h($aEmail) ?></small><?php endif; ?>
              <?php if ($aWhen !== ''): ?><br><small class="muted"><?= h($aWhen) ?></small><?php endif; ?>
            <?php elseif ($p['payment_status'] === 'Voided' && !empty($p['verification_note'])): ?>
              <small class="muted"><?= h($p['verification_note']) ?><?= $aName !== '' ? ' · ' . h($aName) : '' ?><?= $aEmail !== '' ? ' (' . h($aEmail) . ')' : '' ?></small>
              <?php if ($aWhen !== ''): ?><br><small class="muted"><?= h($aWhen) ?></small><?php endif; ?>
            <?php elseif ($isPend): ?>
              <small class="muted">Awaiting staff</small>
            <?php elseif ($aName !== ''): ?>
              <span><?= h($aName) ?></span>
              <?php if ($aEmail !== ''): ?><br><small class="muted"><?= h($aEmail) ?></small><?php endif; ?>
              <?php if ($aWhen !== ''): ?><br><small class="muted"><?= h($aWhen) ?></small><?php endif; ?>
            <?php else: ?>
              <span class="muted">–</span>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= badge($p['payment_status']) ?></td>
          <td class="num<?= $p['payment_status'] === 'Voided' ? ' voided-amt' : '' ?>"><?= $p['payment_status'] === 'Refunded' ? '−' : '' ?><?= h(money($p['amount'])) ?></td>
          <td class="row-actions">
            <div class="act-group">
              <a class="btn sm<?= $isPend ? ' primary' : '' ?>" href="reservation_view.php?id=<?= (int)$p['reservation_id'] ?>#payments"><?= $isPend ? 'Verify' : 'Open' ?></a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_end();
