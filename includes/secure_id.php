<?php
declare(strict_types=1);

/**
 * Guest ID numbers: kept encrypted in the database (AES-256-GCM), shown masked, readable only by resort admins.
 *
 * The key comes from config['id_key'] (64 hex characters) or, if that is empty, from storage/id.key, which is
 * created on first use. Back that file up: without it the stored ID numbers cannot be read again.
 * Values saved before this existed are plain text; they still display, and are encrypted the next time they are saved
 * (or all at once with: php database/encrypt_ids.php).
 */

const ID_TYPES = [
    'Passport', 'Driver’s licence', 'National ID (PhilSys)', 'UMID', 'Postal ID', 'Voter’s ID',
    'Senior citizen / PWD ID', 'School or company ID', 'Other', 'None presented',
];
const ID_NONE = 'None presented';
const ID_PREFIX = 'enc1:';

function id_key(): string
{
    static $key = null;
    if ($key !== null) { return $key; }
    $hex = (string)($GLOBALS['config']['id_key'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/i', $hex)) { return $key = hex2bin($hex); }
    $file = __DIR__ . '/../storage/id.key';
    if (!is_file($file)) {
        file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    $hex = trim((string)file_get_contents($file));
    if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) { throw new RuntimeException('The ID encryption key is damaged.'); }
    return $key = hex2bin($hex);
}

function id_encrypt(?string $plain): ?string
{
    if ($plain === null || $plain === '') { return null; }
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', id_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) { throw new RuntimeException('Could not protect the ID number.'); }
    return ID_PREFIX . base64_encode($iv . $tag . $ct);
}

function id_decrypt(?string $stored): string
{
    if ($stored === null || $stored === '') { return ''; }
    if (!str_starts_with($stored, ID_PREFIX)) { return $stored; }   // older, unencrypted value
    $raw = base64_decode(substr($stored, strlen(ID_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) { return ''; }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', id_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** What a viewer may see: resort admins get the last 4 characters, front desk only learns that one is on file. */
function id_display(?string $stored, array $staff): string
{
    $plain = id_decrypt($stored);
    if ($plain === '') { return ''; }
    if (!staff_can($staff, 'admin')) { return 'On file'; }
    return str_repeat('•', 4) . mb_substr($plain, -4);
}

/** Checks the ID type/number pair a form posted and returns the columns to save. $number is plain text. */
function id_prepare(string $type, string $number): array
{
    if ($type !== '' && !in_array($type, ID_TYPES, true)) { throw new RuntimeException('ID type isn’t a valid choice.'); }
    if ($type === ID_NONE) { $number = ''; }                      // nothing to record when no ID was shown
    if ($number !== '' && $type === '') { throw new RuntimeException('Choose the ID type that goes with that number.'); }
    return ['id_type' => $type === '' ? null : $type, 'id_number' => id_encrypt($number)];
}
