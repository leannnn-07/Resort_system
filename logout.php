<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/accounts.php';
optional_resort($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    log_out_guest();
    flash('You are logged out.');
}
redirect($resort ? ru('landing.php') : 'index.php');
