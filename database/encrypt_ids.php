<?php
// One-off, command line only: encrypts guest ID numbers that were saved as plain text.  php database/encrypt_ids.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/secure_id.php';
$n = 0;
foreach ($pdo->query("SELECT guest_id, id_number FROM guest WHERE id_number IS NOT NULL AND id_number <> '' AND id_number NOT LIKE 'enc1:%'")->fetchAll() as $g) {
    $pdo->prepare('UPDATE guest SET id_number = ? WHERE guest_id = ?')->execute([id_encrypt($g['id_number']), $g['guest_id']]);
    $n++;
}
echo "Encrypted $n ID number(s).\n";
