<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/secure_id.php';

/** The values a select field may take: its fixed options, or the rows its query returns for THIS resort. */
function crud_option_values(PDO $pdo, array $f, int $rid): array
{
    if (isset($f['options'])) {
        $keys = array_is_list($f['options']) ? array_values($f['options']) : array_keys($f['options']);
    } else {
        $os = $pdo->prepare($f['options_sql']);
        $os->execute(str_contains($f['options_sql'], ':rid') ? ['rid' => $rid] : []);
        $keys = array_keys($os->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    return array_map('strval', $keys);
}

/** Stops staff accounts being switched off or archived in ways that lock people out. $act: 'toggle' or 'archive'. */
function crud_staff_guard(PDO $pdo, array $me, int $targetId, string $act): void
{
    $st = $pdo->prepare('SELECT staff_id, resort_id, access FROM staff WHERE staff_id = ? AND archived_at IS NULL');
    $st->execute([$targetId]);
    $t = $st->fetch();
    if (!$t) { throw new RuntimeException('That account no longer exists.'); }
    if ($targetId === (int)$me['staff_id']) {
        throw new RuntimeException('You can’t ' . ($act === 'toggle' ? 'turn off login for' : 'archive') . ' the account you’re signed in with.');
    }
    if ($t['resort_id'] === null) {
        $n = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE resort_id IS NULL AND staff_id <> ? AND ' . STAFF_CAN_LOGIN);
        $n->execute([$targetId]);
        if ((int)$n->fetchColumn() === 0) {
            throw new RuntimeException('That is the only active super admin, so it can’t be turned off or archived.');
        }
    }
    if ($act === 'toggle' && !staff_can($me, 'super') && $t['access'] === 'Admin') {
        throw new RuntimeException('Only the super admin can turn login on or off for a resort admin.');
    }
}

/**
 * Renders a list + add/edit form for a simple table.
 * Optional page switches: 'archive' (delete moves a row to the Archive page, where it can be restored),
 * 'login_toggle' (a login on/off switch per row; staff table), 'search' (a search box over the shown columns; on by default).
 *
 * $c keys: title, active, table, pk, order, fields[], columns[], list_sql (optional), empty (optional),
 *         level ('admin' by default, or 'staff'), staff_edit (fields front-desk staff may change; they can't add or delete)
 * field:   name, label, type (text|email|number|textarea|select), required, step, options | options_sql, default
 * column:  label, key, format (optional: money|date|badge|secret)
 * before_save (optional): fn(array $data, array $post, array $staff, int $editId): array, may add or change the columns to write
 * field flag 'secret': never read from the form by the engine, never sent to the browser unless the viewer is a resort admin
 */
function crud_page(PDO $pdo, array $c): void
{
    global $staffMember;
    $staffMember = require_login($pdo, !empty($c['platform_only']), $c['level'] ?? 'admin');
    // Front-desk staff on a page that opens to them but lists staff_edit: they may only change those fields of an existing row.
    $limited = $staffMember['level'] === 'staff' && isset($c['staff_edit']);

    $table = $c['table'];
    $pk    = $c['pk'];
    // Scoped tables hold one row set per resort: every read, update and delete is limited to the current resort.
    $scoped = !empty($c['scoped']);
    $rid    = resort_id();
    $scopeSql = str_replace('{rid}', (string)$rid, $c['scope_sql'] ?? ($scoped ? 'resort_id = {rid}' : ''));
    $and    = $scopeSql !== '' ? " AND ($scopeSql)" : '';
    $self  = basename($_SERVER['SCRIPT_NAME']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $failed = false;
        try {
            $action = $_POST['action'] ?? '';

            if ($limited && ($action === 'delete' || ($action === 'save' && (int)($_POST[$pk] ?? 0) === 0))) {
                throw new RuntimeException('Only resort admins can add or delete these.');
            }
            if ($action === 'toggle_login' && !empty($c['login_toggle'])) {
                $tid = (int)($_POST[$pk] ?? 0);
                if ($table === 'staff') { crud_staff_guard($pdo, $staffMember, $tid, 'toggle'); }
                $pdo->prepare("UPDATE $table SET login_enabled = 1 - login_enabled WHERE $pk = ? AND archived_at IS NULL$and")->execute([$tid]);
                flash('Login access updated.');
            } elseif ($action === 'save') {
                $data = [];
                $editId = (int)($_POST[$pk] ?? 0);
                foreach ($c['fields'] as $f) {
                    if ($limited && !in_array($f['name'], $c['staff_edit'], true)) {
                        continue;
                    }
                    if ($f['type'] === 'password') {
                        $raw = (string)($_POST[$f['name']] ?? '');
                        if ($raw === '') {
                            if ($editId === 0) { $data[$f['name']] = null; }
                            continue; // blank on edit keeps the current password
                        }
                        if (strlen($raw) < 8) {
                            throw new RuntimeException('Password must be at least 8 characters.');
                        }
                        $data[$f['name']] = password_hash($raw, PASSWORD_DEFAULT);
                        continue;
                    }
                    if ($f['type'] === 'image') {
                        $file = $_FILES[$f['name']] ?? null;
                        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                            continue; // blank on edit keeps existing photo
                        }
                        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > 8 * 1024 * 1024) {
                            throw new RuntimeException('That photo is too big. Use a picture under 8 MB.');
                        }
                        if ($file['error'] !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('The photo could not be uploaded. Please try again.');
                        }
                        $info = @getimagesize($file['tmp_name']);
                        $ext  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
                        if (!$ext) {
                            throw new RuntimeException('Use a JPG, PNG or WebP picture.');
                        }
                        $subDir = preg_replace('/[^a-z0-9_\-]/i', '', (string)($f['dir'] ?? $table)) ?: $table;
                        $dirAbs = __DIR__ . '/../assets/' . $subDir;
                        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true)) {
                            throw new RuntimeException('Could not save the photo folder.');
                        }
                        $fname = $rid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (!move_uploaded_file($file['tmp_name'], $dirAbs . '/' . $fname)) {
                            throw new RuntimeException('Could not save the uploaded photo.');
                        }
                        $data[$f['name']] = $subDir . '/' . $fname;
                        continue;
                    }
                    if (!empty($f['secret'])) { continue; }   // saved by the page's before_save hook (encrypted)
                    $value = trim((string)($_POST[$f['name']] ?? ''));
                    if (!empty($f['required']) && $value === '') {
                        throw new RuntimeException($f['label'] . ' is required.');
                    }
                    // A posted choice must be one the form offered: stops someone linking a row to another resort's building, type or resort.
                    if ($f['type'] === 'select' && $value !== '' && !in_array($value, crud_option_values($pdo, $f, $rid), true)) {
                        throw new RuntimeException($f['label'] . ' isn’t a valid choice.');
                    }
                    if ($f['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Enter a valid email address.');
                    }
                    if ($f['type'] === 'number' && $value !== '' && !is_numeric($value)) {
                        throw new RuntimeException($f['label'] . ' must be a number.');
                    }
                    $data[$f['name']] = $value === '' ? null : $value;
                }

                $id = (int)($_POST[$pk] ?? 0);
                if (isset($c['before_save'])) { $data = $c['before_save']($data, $_POST, $staffMember, $id); }
                // Nobody can change their own access or resort (so they can't lock themselves out or promote themselves).
                if (!empty($c['protect_self']) && $id === (int)($_SESSION['staff_id'] ?? 0)) {
                    unset($data['access'], $data['resort_id']);
                }
                if ($table === 'staff' && $id === (int)($_SESSION['staff_id'] ?? 0) && !empty($_POST['password_hash'])) {
                    unset($_SESSION['default_password']);
                }
                if ($id > 0) {
                    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
                    $data[$pk] = $id;
                    $st = $pdo->prepare("UPDATE $table SET $set WHERE $pk = :$pk$and" . (!empty($c['archive']) ? ' AND archived_at IS NULL' : ''));
                    $st->execute($data);
                    flash('Changes saved.');
                } else {
                    if ($scoped) {
                        $data['resort_id'] = $rid;
                    }
                    foreach ($c['insert_extra'] ?? [] as $col => $_) {
                        $data[$col] = $rid;
                    }
                    $cols = implode(', ', array_keys($data));
                    $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
                    $pdo->prepare("INSERT INTO $table ($cols) VALUES ($vals)")->execute($data);
                    flash($c['singular'] . ' added.');
                }
            } elseif ($action === 'delete') {
                if (!empty($c['protect_self']) && (int)($_POST[$pk] ?? 0) === (int)($_SESSION['staff_id'] ?? 0)) {
                    throw new RuntimeException('You can’t delete the account you’re signed in with.');
                }
                if (!empty($c['archive'])) {
                    if ($table === 'staff') { crud_staff_guard($pdo, $staffMember, (int)($_POST[$pk] ?? 0), 'archive'); }
                    $pdo->prepare("UPDATE $table SET archived_at = NOW() WHERE $pk = ? AND archived_at IS NULL$and")->execute([(int)($_POST[$pk] ?? 0)]);
                    flash($c['singular'] . ' moved to Archived. You can restore it anytime.');
                } else {
                    $pdo->prepare("DELETE FROM $table WHERE $pk = ?$and")->execute([(int)($_POST[$pk] ?? 0)]);
                    flash($c['singular'] . ' deleted.');
                }
            }
        } catch (PDOException $e) {   // must come first: PDOException is a kind of RuntimeException
            $code = (int)($e->errorInfo[1] ?? 0);
            $msg = $code === 1062
                ? 'That value is already in use. Enter a different one.'
                : ($code === 1451
                    ? 'This ' . strtolower($c['singular']) . ' is used by bookings or other records, so it can’t be deleted.'
                    : 'The database rejected that change. Check the values and try again.');
            flash($msg, 'error');
            $failed = true;
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
            $failed = true;
        }
        // A failed save reopens the form with what was typed (never the password), instead of losing it.
        if ($failed && ($_POST['action'] ?? '') === 'save') {
            $old = ['id' => (int)($_POST[$pk] ?? 0), 'values' => []];
            foreach ($c['fields'] as $f) {
                if ($f['type'] !== 'password' && empty($f['secret'])) {
                    $old['values'][$f['name']] = (string)($_POST[$f['name']] ?? '');
                }
            }
            $_SESSION['crud_old'][$self] = $old;
        }
        redirect($self);
    }

    // ?edit=ID still works (a bookmark, or no JavaScript): it opens that row's form straight away.
    $edit = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $pk = ?$and" . (!empty($c['archive']) ? ' AND archived_at IS NULL' : ''));
        $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    $old = $_SESSION['crud_old'][$self] ?? null;
    unset($_SESSION['crud_old'][$self]);

    // Archived rows are left out of the list; they live on the Archive page, where they can be restored.
    $archive  = !empty($c['archive']);
    $archSql  = $archive ? ($c['archive_col'] ?? 'archived_at') . ' IS NULL' : '1=1';
    $listSql = $c['list_sql'] ?? ("SELECT * FROM $table WHERE " . ($scopeSql !== '' ? "($scopeSql) AND " : '') . '{archived} ORDER BY ' . $c['order']);
    $listSql = str_replace('{archived}', $archSql, $listSql);
    $stmt = $pdo->prepare($listSql);
    $stmt->execute(str_contains($listSql, ':rid') ? ['rid' => $rid] : []);
    $rows = $stmt->fetchAll();
    if (isset($c['filter_rows']) && is_callable($c['filter_rows'])) {
        $rows = array_values($c['filter_rows']($rows));
    }

    // Search box: matches any column shown in the table (and any extra search_keys), ignoring upper/lower case.
    $q = trim((string)($_GET['q'] ?? ''));
    $totalRows = count($rows);
    if ($q !== '' && !empty($c['search'] ?? true)) {
        $keys = array_merge(
            array_column(array_filter($c['columns'], fn($col) => ($col['format'] ?? '') !== 'secret' && ($col['format'] ?? '') !== 'switch'), 'key'),
            $c['search_keys'] ?? []
        );
        $rows = array_values(array_filter($rows, function ($r) use ($keys, $q) {
            foreach ($keys as $k) {
                if (isset($r[$k]) && mb_stripos((string)$r[$k], $q) !== false) { return true; }
            }
            return false;
        }));
    }

    // The form values of every row, so Edit can fill the pop-up without another request. (The list query may
    // show joined or renamed columns, so this reads the table itself. Passwords are never included.)
    $records = [];
    $rawStmt = $pdo->query("SELECT * FROM $table" . ($scopeSql !== '' ? " WHERE ($scopeSql)" : '') . ($archive ? ($scopeSql !== '' ? ' AND ' : ' WHERE ') . 'archived_at IS NULL' : ''));
    foreach ($rawStmt->fetchAll() as $raw) {
        $rec = [];
        foreach ($c['fields'] as $f) {
            if ($f['type'] === 'password') { continue; }
            // Secret fields (ID numbers) are decrypted for resort admins only; everyone else gets an empty box.
            $rec[$f['name']] = !empty($f['secret'])
                ? (staff_can($staffMember, 'admin') ? id_decrypt($raw[$f['name']] ?? null) : '')
                : (string)($raw[$f['name']] ?? '');
        }
        $records[(int)$raw[$pk]] = $rec;
    }

    // A column is a "number" column (right-aligned, header too) when it holds money or only numbers.
    $isNum = [];
    foreach ($c['columns'] as $ci => $col) {
        $isNum[$ci] = ($col['format'] ?? '') === 'money' || ($rows && !($col['format'] ?? '') && count(array_filter($rows, fn($r) => is_numeric($r[$col['key']] ?? null))) === count($rows));
    }
    $count = count($rows);
    $one   = strtolower($c['singular']);
    $showSearch = !empty($c['search'] ?? true);

    $autoOpen = null;
    if ($old) {
        $autoOpen = $old;
    } elseif ($edit) {
        $autoOpen = ['id' => (int)$edit[$pk], 'values' => $records[(int)$edit[$pk]] ?? []];
    }

    $subText = $c['sub'] ?? ($q !== '' ? plural($count, $one) . ' match' . ($count === 1 ? 'es' : '') : '');
    $headAct = ($c['head_actions'] ?? '') . ($limited ? '' : '<button type="button" class="btn primary" data-form-open="crud-modal">Add ' . h($one) . '</button>');
    admin_start($c['title'], $c['active']);
    admin_head($c['title'], $subText, $headAct);
    if (!empty($c['before_panel'])) {
        echo is_callable($c['before_panel']) ? $c['before_panel']($staffMember) : $c['before_panel'];
    }
    ?>

    <?php if ($showSearch): ?>
      <form method="get" class="filters" role="search">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search <?= h(strtolower($c['title'])) ?>" aria-label="Search <?= h(strtolower($c['title'])) ?>" maxlength="80" autocomplete="off">
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="btn" href="<?= h($self) ?>">Clear</a><?php endif; ?>
      </form>
    <?php endif; ?>

    <section class="panel flush">
      <?php if (!$rows): ?>
        <?php if ($q !== '' && $totalRows > 0): ?>
          <p class="empty">No <?= h(strtolower($c['title'])) ?> match “<?= h($q) ?>”.</p>
        <?php else: ?>
          <p class="empty">No <?= h(strtolower($c['title'])) ?> yet.<?= $limited ? '' : ' <button type="button" class="link-btn" data-form-open="crud-modal">Add the first ' . h($one) . '</button>.' ?></p>
        <?php endif; ?>
      <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <?php foreach ($c['columns'] as $ci => $col): ?><th<?= $isNum[$ci] ? ' class="num"' : '' ?>><?= h($col['label']) ?></th><?php endforeach; ?>
              <th class="right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <?php foreach ($c['columns'] as $ci => $col):
                  $v = $r[$col['key']] ?? '';
                  $fmt = $col['format'] ?? '';
                  $tdClass = $isNum[$ci]
                      ? 'num'
                      : (!empty($col['muted'])
                          ? 'sub'
                          : (in_array($fmt, ['badge', 'yesno', 'date', 'switch', 'image'], true) ? 'nowrap' : ''));
                  ?>
                <td<?= $tdClass !== '' ? ' class="' . $tdClass . '"' : '' ?>>
                  <?php if ($fmt === 'switch'):
                      $isSelf = !empty($c['protect_self']) && (int)$r[$pk] === (int)($_SESSION['staff_id'] ?? 0); ?>
                    <form method="post" class="switch-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_login">
                      <input type="hidden" name="<?= h($pk) ?>" value="<?= (int)$r[$pk] ?>">
                      <button type="submit" class="switch" role="switch" aria-checked="<?= $v ? 'true' : 'false' ?>"
                              aria-label="Login access for <?= h((string)($r['full_name'] ?? $one)) ?>"<?= $isSelf ? ' disabled title="You can’t turn off your own login"' : '' ?>
                              <?= $v ? 'data-confirm="Turn off login for this ' . h($one) . '? They are signed out straight away and can’t log in until you turn it back on." data-confirm-title="Turn off login" data-confirm-ok="Yes, turn it off" data-confirm-tone="danger"' : '' ?>><span class="knob"></span></button>
                      <span class="switch-label"><?= $v ? 'On' : 'Off' ?></span>
                      <?php if (array_key_exists('has_password', $r) && !(int)$r['has_password']): ?><small class="muted-line">No password set</small><?php endif; ?>
                    </form>
                  <?php else: ?>
                  <?= match ($fmt) {
                      'money' => h(money($v)),
                      'date'  => h(fdate($v)),
                      'badge' => badge((string)$v),
                      'secret' => h(id_display((string)$v, $staffMember)),
                      'yesno' => '<span class="badge ' . ($v ? 'yes' : 'no') . '">' . ($v ? 'Yes' : 'No') . '</span>',
                      'image' => admin_thumb_html((string)$v, (string)($r['name'] ?? $r['type_name'] ?? $one)),
                      default => ($ci === 0 && $fmt === '' ? '<strong>' . h($v) . '</strong>' : h($v)),
                  } ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="row-actions">
                <div class="act-group">
                  <?php if (isset($c['row_actions']) && is_callable($c['row_actions'])) { echo $c['row_actions']($r); } ?>
                  <a class="btn sm" href="<?= h($self) ?>?edit=<?= (int)$r[$pk] ?>" data-form-open="crud-modal" data-id="<?= (int)$r[$pk] ?>">Edit</a>
                  <?php if (!$limited): ?><form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="<?= h($pk) ?>" value="<?= (int)$r[$pk] ?>">
                    <?php if ($archive): ?>
                    <button class="btn sm danger" type="submit"  data-confirm="Archive this <?= h($one) ?>? It moves to Archive, where you can restore it. Bookings are kept." data-confirm-title="Archive <?= h($one) ?>" data-confirm-ok="Yes, archive" data-confirm-tone="danger">Archive</button>
                    <?php else: ?>
                    <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to delete this <?= h($one) ?>? This can’t be undone." data-confirm-title="Delete <?= h($one) ?>" data-confirm-ok="Yes, delete" data-confirm-tone="danger">Delete</button>
                    <?php endif; ?>
                  </form><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <?php
    if (isset($c['after_panel']) && is_callable($c['after_panel'])) {
        echo $c['after_panel']($rows);
    }
    // One form for adding and editing, shown as a pop-up (see "form pop-ups" in assets/app.js).
    ?>
    <div class="modal" id="crud-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="crud-title"
         data-title-add="<?= h('Add ' . $one) ?>" data-title-edit="<?= h('Edit ' . $one) ?>"
         <?= $autoOpen ? 'data-autoopen="' . h(json_encode($autoOpen, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
      <div class="modal-card form-card">
        <div class="modal-head">
          <h2 id="crud-title"><?= h('Add ' . $one) ?></h2>
          <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
        </div>
        <?php $hasUpload = (bool)array_filter($c['fields'], fn($f) => ($f['type'] ?? '') === 'image'); ?>
        <form method="post"<?= $hasUpload ? ' enctype="multipart/form-data"' : '' ?> class="grid-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="<?= h($pk) ?>" value="" data-record-id>

          <?php foreach ($c['fields'] as $f):
              if ($limited && !in_array($f['name'], $c['staff_edit'], true)) { continue; }
              $val = in_array($f['type'], ['password', 'image'], true) ? '' : (string)($f['default'] ?? '');
              $req = !empty($f['required']) ? ' required' : '';
              $wide = in_array($f['type'], ['textarea', 'image'], true) ? ' class="wide"' : '';
              ?>
            <label<?= $wide ?>>
              <span><?= h($f['label']) ?></span>
              <?php if ($f['type'] === 'image'): ?>
                <input type="file" name="<?= h($f['name']) ?>" accept="image/jpeg,image/png,image/webp">
                <small class="muted">JPG, PNG or WebP, under 8 MB. Leave blank when editing to keep current photo.</small>
              <?php elseif ($f['type'] === 'textarea'): ?>
                <textarea name="<?= h($f['name']) ?>" rows="2"<?= isset($f['placeholder']) ? ' placeholder="' . h($f['placeholder']) . '"' : '' ?><?= $req ?>><?= h($val) ?></textarea>
              <?php elseif ($f['type'] === 'select'):
                  if (isset($f['options'])) {
                      $opts = $f['options'];
                  } else {
                      $os = $pdo->prepare($f['options_sql']);
                      $os->execute(str_contains($f['options_sql'], ':rid') ? ['rid' => $rid] : []);
                      $opts = $os->fetchAll(PDO::FETCH_KEY_PAIR);
                  } ?>
                <select name="<?= h($f['name']) ?>"<?= $req ?>>
                  <?php if (empty($f['required'])): ?><option value=""><?= h($f['none_label'] ?? 'None') ?></option><?php endif; ?>
                  <?php foreach ($opts as $ov => $ol):
                      $ov = is_int($ov) && isset($f['options']) && array_is_list($f['options']) ? $ol : $ov; ?>
                    <option value="<?= h($ov) ?>"<?= (string)$ov === $val ? ' selected' : '' ?>><?= h($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="<?= h($f['type']) ?>" name="<?= h($f['name']) ?>" value="<?= h($val) ?>"
                       <?= isset($f['step']) ? 'step="' . h($f['step']) . '" min="0"' : '' ?>
                       <?= $f['type'] === 'password' ? 'autocomplete="new-password" minlength="8" data-pw placeholder="Min. 8 characters" data-pw-edit="Leave blank to keep current"' : (!empty($f['secret']) && !staff_can($staffMember, 'admin')
                           ? 'data-pw data-ph-add="' . h($f['placeholder'] ?? '') . '" data-pw-edit="Leave blank to keep current" placeholder="' . h($f['placeholder'] ?? '') . '" autocomplete="off"'
                           : (isset($f['placeholder']) ? 'placeholder="' . h($f['placeholder']) . '"' : '')) ?><?= $req ?>>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>

          <div class="modal-actions wide">
            <button class="btn" type="button" data-modal-close>Cancel</button>
            <button class="btn primary" type="submit">Save <?= h($one) ?></button>
          </div>
        </form>
      </div>
    </div>
    <script type="application/json" id="crud-records"><?= json_encode($records, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?></script>
    <?php
    admin_end();
}
