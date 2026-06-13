<?php
// admin.php — minimal admin panel for A.Email.
// Authenticates against the admin_users table (bcrypt). When that table is empty it
// offers a one-time "create first admin" form. Session + CSRF protected. No framework.
//
// SECURITY NOTE: this is a basic panel. For real production use, put an extra layer in
// front of it (IP allowlist / VPN / HTTP auth) and serve the site over HTTPS only.

require_once 'config.php';
session_start();

$mysqli = getDBConnection();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_token() { return $_SESSION['csrf']; }
function require_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back and retry.');
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge($s) {
    $c = $s === 'completed' ? 'b-completed' : ($s === 'pending' ? 'b-pending' : 'b-other');
    return '<span class="badge ' . $c . '">' . h($s) . '</span>';
}
function action_btn($id, $act, $label, $cls = 'sm', $confirm = '') {
    $onsubmit = $confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '';
    echo '<form method="post" class="inline"' . $onsubmit . '>'
       . '<input type="hidden" name="form" value="action">'
       . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
       . '<input type="hidden" name="action" value="' . h($act) . '">'
       . '<input type="hidden" name="user_id" value="' . (int)$id . '">'
       . '<button class="' . h($cls) . '" type="submit">' . h($label) . '</button></form> ';
}
// Local mailbox delete (config.php only ships a create helper).
function adminDeleteMailbox($username) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, str_replace('/add/', '/delete/', MAILBOX_API_URL));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, VERIFY_TLS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, VERIFY_TLS ? 2 : 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . MAILBOX_API_KEY]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $username . DOMAIN_SUFFIX]));
    curl_exec($ch);
    curl_close($ch);
}

$adminCount = (int) ($mysqli->query("SELECT COUNT(*) c FROM admin_users")->fetch_assoc()['c'] ?? 0);
$flash = '';
$flashOk = false;

// ---- Create first admin (only when none exist) ----
if ($adminCount === 0 && ($_POST['form'] ?? '') === 'bootstrap') {
    require_csrf();
    $u = trim($_POST['username'] ?? '');
    $e = trim($_POST['email'] ?? '');
    $p = $_POST['password'] ?? '';
    if (strlen($u) < 3 || !filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($p) < 8) {
        $flash = 'Need a username (≥3 chars), a valid email, and a password (≥8 chars).';
    } else {
        $hash = password_hash($p, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("INSERT INTO admin_users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->bind_param("sss", $u, $e, $hash);
        if ($stmt->execute()) { $flash = 'Admin account created — please sign in.'; $flashOk = true; $adminCount = 1; }
        else { $flash = 'Could not create admin: ' . $mysqli->error; }
        $stmt->close();
    }
}

// ---- Login ----
if (($_POST['form'] ?? '') === 'login') {
    require_csrf();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $stmt = $mysqli->prepare("SELECT id, username, password_hash, role, is_active FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $u);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($admin && (int)$admin['is_active'] === 1 && password_verify($p, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_user'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        $mysqli->query("UPDATE admin_users SET last_login = NOW() WHERE id = " . (int)$admin['id']);
        header('Location: admin.php'); exit;
    }
    $flash = 'Invalid credentials.';
    logError('Admin login failed', ['username' => $u]);
}

// ---- Logout ----
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php'); exit;
}

$loggedIn = !empty($_SESSION['admin_id']);

// ---- Authenticated POST actions ----
if ($loggedIn && ($_POST['form'] ?? '') === 'action') {
    require_csrf();
    $act = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0) {
        if ($act === 'complete') {
            $mysqli->query("UPDATE users SET payment_status='completed', updated_at=NOW() WHERE id=$uid");
            $flash = "User #$uid marked completed."; $flashOk = true;
        } elseif ($act === 'cancel') {
            $mysqli->query("UPDATE users SET payment_status='canceled', updated_at=NOW() WHERE id=$uid");
            $flash = "User #$uid marked canceled."; $flashOk = true;
        } elseif ($act === 'reprovision') {
            $stmt = $mysqli->prepare("SELECT username, full_name FROM users WHERE id=?");
            $stmt->bind_param("i", $uid); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($row) {
                $tmp = 'TempPass' . rand(100000, 999999) . '!';
                try {
                    $r = createMailboxViaAPI($row['username'], $tmp, $row['full_name'] ?: $row['username']);
                    if (!empty($r['success'])) { $flash = "Re-provisioned {$row['username']} — temp password: $tmp (user should reset it)."; $flashOk = true; }
                    else { $flash = 'Re-provision failed: ' . ($r['message'] ?? 'unknown'); }
                } catch (Exception $e) { $flash = 'Re-provision error: ' . $e->getMessage(); }
            }
        } elseif ($act === 'delete') {
            $stmt = $mysqli->prepare("SELECT username FROM users WHERE id=?");
            $stmt->bind_param("i", $uid); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($row) { try { adminDeleteMailbox($row['username']); } catch (Exception $e) {} }
            $mysqli->query("DELETE FROM users WHERE id=$uid");
            $flash = "User #$uid deleted (DB row removed, mailbox delete attempted)."; $flashOk = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>A.Email — Admin</title>
<style>
  :root{--bg:#0f172a;--card:#1e293b;--ink:#e2e8f0;--muted:#94a3b8;--accent:#6366f1;--bad:#ef4444;--line:#334155}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
  a{color:#93c5fd;text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:24px}
  .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
  .brand{font-weight:700;font-size:18px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:18px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px}
  .stat .n{font-size:22px;font-weight:700}
  .stat .l{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
  th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase}
  input,select{background:#0b1220;border:1px solid var(--line);color:var(--ink);border-radius:6px;padding:8px 10px;font:inherit}
  button{background:var(--accent);color:#fff;border:0;border-radius:6px;padding:7px 12px;font:inherit;font-weight:600;cursor:pointer}
  button.sm{padding:4px 8px;font-size:12px}
  button.bad{background:var(--bad)} button.ghost{background:#334155}
  .badge{padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}
  .b-completed{background:rgba(34,197,94,.15);color:#86efac}
  .b-pending{background:rgba(234,179,8,.15);color:#fde047}
  .b-other{background:rgba(148,163,184,.15);color:#cbd5e1}
  .flash{padding:10px 14px;border-radius:8px;margin-bottom:16px;border:1px solid}
  .flash.ok{background:rgba(34,197,94,.12);border-color:#22c55e;color:#bbf7d0}
  .flash.err{background:rgba(239,68,68,.12);border-color:#ef4444;color:#fecaca}
  form.inline{display:inline}
  .login{max-width:380px;margin:8vh auto}
  .login label{display:block;margin:10px 0 4px;color:var(--muted)}
  .login input{width:100%}
  .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
</style>
</head>
<body>
<div class="wrap">

<?php if ($flash): ?>
  <div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<?php if (!$loggedIn): ?>
  <div class="login">
    <div class="brand" style="text-align:center;margin-bottom:16px">A.Email Admin</div>
    <?php if ($adminCount === 0): ?>
      <div class="card">
        <h3 style="margin-top:0">Create the first admin</h3>
        <p style="color:var(--muted)">No admin account exists yet — create one to continue.</p>
        <form method="post">
          <input type="hidden" name="form" value="bootstrap">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Username</label><input name="username" required>
          <label>Email</label><input type="email" name="email" required>
          <label>Password (min 8)</label><input type="password" name="password" required>
          <div style="margin-top:14px"><button type="submit">Create admin</button></div>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <h3 style="margin-top:0">Sign in</h3>
        <form method="post">
          <input type="hidden" name="form" value="login">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <label>Username</label><input name="username" required autofocus>
          <label>Password</label><input type="password" name="password" required>
          <div style="margin-top:14px"><button type="submit">Sign in</button></div>
        </form>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php
    $stats = $mysqli->query("
        SELECT
          COUNT(*) total,
          SUM(payment_status='completed') completed,
          SUM(payment_status='pending') pending,
          SUM(plan_type<>'free' AND payment_status='completed') paid_done,
          SUM(CASE WHEN payment_status='completed' THEN amount_paid ELSE 0 END) revenue,
          SUM(DATE(created_at)=CURDATE()) today
        FROM users
    ")->fetch_assoc();

    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $mysqli->prepare("SELECT id, username, full_email, existing_email, plan_type, amount_paid, payment_status, created_at FROM users WHERE username LIKE ? OR full_email LIKE ? OR existing_email LIKE ? ORDER BY created_at DESC LIMIT 100");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute(); $users = $stmt->get_result(); $stmt->close();
    } else {
        $users = $mysqli->query("SELECT id, username, full_email, existing_email, plan_type, amount_paid, payment_status, created_at FROM users ORDER BY created_at DESC LIMIT 50");
    }

    $tx = $mysqli->query("SELECT t.transaction_id, t.amount, t.currency, t.status, t.created_at, u.username FROM payment_transactions t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.created_at DESC LIMIT 25");
  ?>
  <div class="top">
    <div class="brand">A.Email Admin</div>
    <div class="row">
      <span style="color:var(--muted)">Signed in as <b><?= h($_SESSION['admin_user']) ?></b> (<?= h($_SESSION['admin_role']) ?>)</span>
      <a href="admin.php?action=logout"><button class="ghost sm">Log out</button></a>
    </div>
  </div>

  <div class="grid">
    <div class="stat"><div class="n"><?= (int)$stats['total'] ?></div><div class="l">Registrations</div></div>
    <div class="stat"><div class="n"><?= (int)$stats['completed'] ?></div><div class="l">Completed</div></div>
    <div class="stat"><div class="n"><?= (int)$stats['pending'] ?></div><div class="l">Pending</div></div>
    <div class="stat"><div class="n"><?= (int)$stats['paid_done'] ?></div><div class="l">Paid accounts</div></div>
    <div class="stat"><div class="n"><?= number_format((float)$stats['revenue'], 2) ?></div><div class="l">Revenue (<?= h(CURRENCY) ?>)</div></div>
    <div class="stat"><div class="n"><?= (int)$stats['today'] ?></div><div class="l">Today</div></div>
  </div>

  <div class="card">
    <form method="get" class="row" style="margin-bottom:14px">
      <input name="q" value="<?= h($q) ?>" placeholder="Search username / email…" style="flex:1;min-width:220px">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?><a href="admin.php"><button type="button" class="ghost">Clear</button></a><?php endif; ?>
    </form>
    <div style="overflow:auto">
    <table>
      <tr><th>ID</th><th>Mailbox</th><th>Plan</th><th>Amount</th><th>Status</th><th>Contact</th><th>Created</th><th>Actions</th></tr>
      <?php while ($u = $users->fetch_assoc()): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= h($u['full_email']) ?></td>
        <td><?= h($u['plan_type']) ?></td>
        <td><?= number_format((float)$u['amount_paid'], 2) ?></td>
        <td><?= badge($u['payment_status']) ?></td>
        <td><?= h($u['existing_email']) ?></td>
        <td><?= h($u['created_at']) ?></td>
        <td><div class="row">
          <?php
            if ($u['payment_status'] !== 'completed') action_btn($u['id'], 'complete', 'Complete');
            action_btn($u['id'], 'reprovision', 'Re-provision');
            if ($u['payment_status'] !== 'canceled') action_btn($u['id'], 'cancel', 'Cancel', 'sm ghost');
            action_btn($u['id'], 'delete', 'Delete', 'sm bad', 'Delete user #' . $u['id'] . ' and its mailbox?');
          ?>
        </div></td>
      </tr>
      <?php endwhile; ?>
    </table>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Recent transactions</h3>
    <div style="overflow:auto">
    <table>
      <tr><th>Transaction</th><th>User</th><th>Amount</th><th>Status</th><th>Created</th></tr>
      <?php while ($t = $tx->fetch_assoc()): ?>
      <tr>
        <td><?= h($t['transaction_id']) ?></td>
        <td><?= h($t['username']) ?></td>
        <td><?= number_format((float)$t['amount'], 2) ?> <?= h($t['currency']) ?></td>
        <td><?= badge($t['status']) ?></td>
        <td><?= h($t['created_at']) ?></td>
      </tr>
      <?php endwhile; ?>
    </table>
    </div>
  </div>
<?php endif; ?>

</div>
</body>
</html>
