<?php
// User account -> Staff / Resort Admin / Super Admin account (automatic for all active staff & admin accounts).
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

$guest = require_guest($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $link = link_to_staff($pdo, (int)$guest['guest_id']);
    if ($link) {
        log_out_guest();                                  // one account at a time (this also renews the session id)
        $_SESSION['staff_id'] = (int)$link['staff_id'];
        $_SESSION['default_password'] = false;
        $isSuper = $link['resort_id'] === null;
        $isAdmin = $isSuper || ($link['access'] ?? '') === 'Admin';
        flash('You are now using your ' . ($isSuper ? 'super admin' : ($isAdmin ? 'admin' : 'staff')) . ' account.');
        redirect('admin/' . ($isSuper ? 'platform.php' : 'index.php'));
    }
    flash('No active staff or admin account is linked to this email.', 'error');
}
redirect(ru('account.php'));
