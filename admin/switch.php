<?php
// Staff / Resort Admin / Super Admin -> User account (automatic for all active staff & admin accounts).
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/accounts.php';

$staffMember = require_login($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $link = ensure_staff_user_link($pdo, (int)$staffMember['staff_id']);
    if ($link) {
        session_regenerate_id(true);
        unset($_SESSION['staff_id'], $_SESSION['default_password']);   // one account at a time
        log_in_guest($pdo, (int)$link['guest_id']);
        flash('You are now using your user account.');
        redirect('../account.php');
    }
    flash('Could not switch to a user account for this profile.', 'error');
}
redirect('index.php');
