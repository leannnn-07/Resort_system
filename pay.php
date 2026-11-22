<?php
/**
 * Legacy shortcut: redirects to Step 2 (payment.php?ref=...).
 */
require_once __DIR__ . '/includes/bootstrap.php';

$ref = strtoupper(trim((string)($_POST['ref'] ?? $_GET['ref'] ?? '')));
$ref = preg_match('/^[A-Z0-9]{6,10}$/', $ref) ? $ref : '';
redirect($ref !== '' ? 'payment.php?ref=' . urlencode($ref) : 'booking.php');
