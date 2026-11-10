<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

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

/**
 * Renders a list + add/edit form for a simple table.
 *
 * $c keys: title, active, table, pk, order, fields[], columns[], list_sql (optional), empty (optional),
 *         level ('admin' by default, or 'staff'), staff_edit (fields front-desk staff may change; they can't add or delete)
 * field:   name, label, type (text|email|number|textarea|select), required, step, options | options_sql, default
 * column:  label, key, format (optional: money|date|badge)
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
        try {
            $action = $_POST['action'] ?? '';

            if ($limited && ($action === 'delete' || ($action === 'save' && (int)($_POST[$pk] ?? 0) === 0))) {
                throw new RuntimeException('Only resort admins can add or delete these.');
            }
            if ($action === 'save') {
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
                    $st = $pdo->prepare("UPDATE $table SET $set WHERE $pk = :$pk$and");
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
                $pdo->prepare("DELETE FROM $table WHERE $pk = ?$and")->execute([(int)($_POST[$pk] ?? 0)]);
                flash($c['singular'] . ' deleted.');
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        } catch (PDOException $e) {
            $msg = ($e->errorInfo[1] ?? 0) === 1062
                ? 'That value is already in use. Enter a different one.'
                : (($e->errorInfo[1] ?? 0) === 1451
                    ? 'This record is linked to other data, so it can’t be deleted.'
                    : 'The database rejected that change. Check the values and try again.');
            flash($msg, 'error');
        }
        redirect($self);
    }

    $edit = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $pk = ?$and");
        $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }

    $listSql = $c['list_sql'] ?? ("SELECT * FROM $table" . ($scopeSql !== '' ? " WHERE ($scopeSql)" : '') . " ORDER BY {$c['order']}");
    $stmt = $pdo->prepare($listSql);
    $stmt->execute(str_contains($listSql, ':rid') ? ['rid' => $rid] : []);
    $rows = $stmt->fetchAll();

    // A column is a "number" column (right-aligned, header too) when it holds money or only numbers.
    $isNum = [];
    foreach ($c['columns'] as $ci => $col) {
        $isNum[$ci] = ($col['format'] ?? '') === 'money' || ($rows && !($col['format'] ?? '') && count(array_filter($rows, fn($r) => is_numeric($r[$col['key']] ?? null))) === count($rows));
    }
    $count = count($rows);

    admin_start($c['title'], $c['active']);
    admin_head($c['title'], $c['sub'] ?? ($limited ? 'Set a room’s status after housekeeping. Only resort admins can add or remove rooms.' : ($count === 0 ? 'Nothing here yet.' : plural($count, strtolower($c['singular'])) . ' at ' . ($GLOBALS['config']['resort'] ?? ''))));
    ?>

    <?php if (!$limited || $edit): ?>
    <section class="panel">
      <div class="panel-head"><h2><?= $edit ? 'Edit ' . h(strtolower($c['singular'])) : 'Add ' . h(strtolower($c['singular'])) ?></h2></div>
      <form method="post" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($edit): ?><input type="hidden" name="<?= h($pk) ?>" value="<?= h($edit[$pk]) ?>"><?php endif; ?>

        <?php foreach ($c['fields'] as $f):
            if ($limited && !in_array($f['name'], $c['staff_edit'], true)) { continue; }
            $val = $f['type'] === 'password' ? '' : ($edit[$f['name']] ?? ($f['default'] ?? ''));
            $req = !empty($f['required']) ? ' required' : '';
            $wide = $f['type'] === 'textarea' ? ' class="wide"' : '';
            ?>
          <label<?= $wide ?>>
            <span><?= h($f['label']) ?></span>
            <?php if ($f['type'] === 'textarea'): ?>
              <textarea name="<?= h($f['name']) ?>" rows="2"<?= $req ?>><?= h($val) ?></textarea>
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
                  <option value="<?= h($ov) ?>"<?= (string)$ov === (string)$val ? ' selected' : '' ?>><?= h($ol) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="<?= h($f['type']) ?>" name="<?= h($f['name']) ?>" value="<?= h($val) ?>"
                     <?= isset($f['step']) ? 'step="' . h($f['step']) . '" min="0"' : '' ?>
                     <?= $f['type'] === 'password' ? 'autocomplete="new-password" minlength="8" placeholder="' . ($edit ? 'Leave blank to keep current' : 'At least 8 characters') . '"' : '' ?><?= $req ?>>
            <?php endif; ?>
            <?php if (!empty($f['hint'])): ?><small class="hint"><?= h($f['hint']) ?></small><?php endif; ?>
          </label>
        <?php endforeach; ?>

        <div class="actions wide">
          <button class="btn primary" type="submit">Save <?= h(strtolower($c['singular'])) ?></button>
          <?php if ($edit): ?><a class="btn" href="<?= h($self) ?>">Cancel</a><?php endif; ?>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel flush">
      <?php if (!$rows): ?>
        <p class="empty">No <?= h(strtolower($c['title'])) ?> yet.<?= $limited ? '' : ' Add the first one above.' ?></p>
      <?php else: ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <?php foreach ($c['columns'] as $ci => $col): ?><th<?= $isNum[$ci] ? ' class="num"' : '' ?>><?= h($col['label']) ?></th><?php endforeach; ?>
              <th class="right"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <?php foreach ($c['columns'] as $ci => $col):
                  $v = $r[$col['key']] ?? '';
                  $fmt = $col['format'] ?? '';
                  ?>
                <td<?= $isNum[$ci] ? ' class="num"' : (!empty($col['muted']) ? ' class="sub"' : '') ?>>
                  <?= match ($fmt) {
                      'money' => h(money($v)),
                      'date'  => h(fdate($v)),
                      'badge' => badge((string)$v),
                      'yesno' => '<span class="badge ' . ($v ? 'yes' : 'no') . '">' . ($v ? 'Yes' : 'No') . '</span>',
                      default => h($v),
                  } ?>
                </td>
              <?php endforeach; ?>
              <td class="row-actions">
                <a class="btn sm" href="<?= h($self) ?>?edit=<?= (int)$r[$pk] ?>">Edit</a>
                <?php if (!$limited): ?><form method="post" onsubmit="return confirm('Delete this <?= h(strtolower($c['singular'])) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="<?= h($pk) ?>" value="<?= (int)$r[$pk] ?>">
                  <button class="btn sm danger" type="submit">Delete</button>
                </form><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
    <?php
    admin_end();
}
