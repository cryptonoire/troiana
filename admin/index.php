<?php
/**
 * Troiana admin console — /admin/
 * Password-protected inbox for contact-form messages (data/messages.json).
 */
session_start();

$cfg = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : null;
$dataFile = __DIR__ . '/../data/messages.json';

/* ---------- helpers ---------- */
function load_messages($f) {
    if (!file_exists($f)) return [];
    $m = json_decode((string)file_get_contents($f), true);
    return is_array($m) ? $m : [];
}
function save_messages($f, $m) {
    $fp = fopen($f, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(array_values($m), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN); fclose($fp);
    return true;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

/* ---------- setup guard ---------- */
$setupNeeded = !$cfg || empty($cfg['admin_password']) || $cfg['admin_password'] === 'change-this-now';

/* ---------- logout ---------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

/* ---------- login ---------- */
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password']) && !$setupNeeded) {
    if (hash_equals((string)$cfg['admin_password'], (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
    } else {
        $loginError = 'Wrong password.';
    }
}
$authed = !empty($_SESSION['auth']);

/* ---------- actions (require auth + csrf) ---------- */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (!hash_equals(token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request');
    }
    $messages = load_messages($dataFile);
    $id = (string)($_POST['id'] ?? '');
    if ($_POST['action'] === 'delete') {
        $messages = array_filter($messages, fn($m) => ($m['id'] ?? '') !== $id);
    } elseif ($_POST['action'] === 'toggle') {
        foreach ($messages as &$m) { if (($m['id'] ?? '') === $id) { $m['read'] = empty($m['read']); } }
        unset($m);
    } elseif ($_POST['action'] === 'read_all') {
        foreach ($messages as &$m) { $m['read'] = true; } unset($m);
    }
    save_messages($dataFile, $messages);
    header('Location: index.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    exit;
}

$messages = $authed ? array_reverse(load_messages($dataFile)) : [];
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $needle = mb_strtolower($q);
    $messages = array_filter($messages, function ($m) use ($needle) {
        $hay = mb_strtolower(($m['name'] ?? '') . ' ' . ($m['email'] ?? '') . ' ' . ($m['company'] ?? '') . ' ' . ($m['message'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    });
}
$unread = 0;
foreach (($authed ? load_messages($dataFile) : []) as $m) { if (empty($m['read'])) $unread++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>Admin — Troiana</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<style>
  :root{--bg:#000;--bg-elev:#0a0a0a;--card:#0e0e0f;--line:rgba(255,255,255,.08);--line-strong:rgba(255,255,255,.16);
    --fg:#f5f5f7;--muted:#9a9a9f;--muted-2:#6b6b70;--accent:#fff;--green:#34d399;--red:#f87171;--radius:16px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--fg);line-height:1.5;-webkit-font-smoothing:antialiased}
  a{color:inherit;text-decoration:none}
  .wrap{max-width:1000px;margin:0 auto;padding:0 22px}
  header{position:sticky;top:0;z-index:5;background:rgba(0,0,0,.7);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
  .bar{display:flex;align-items:center;justify-content:space-between;height:64px;gap:16px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-.02em}
  .brand img{height:26px}
  .badge{background:var(--green);color:#000;font-size:12px;font-weight:800;border-radius:999px;padding:2px 9px}
  .muted{color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:7px;font:inherit;font-weight:600;font-size:13.5px;cursor:pointer;
    border:1px solid var(--line-strong);background:var(--bg-elev);color:var(--fg);padding:9px 15px;border-radius:10px;transition:.15s}
  .btn:hover{border-color:#fff}
  .btn.sm{padding:6px 11px;font-size:12.5px;border-radius:8px}
  .btn.danger:hover{border-color:var(--red);color:var(--red)}
  .btn.primary{background:var(--accent);color:#000;border-color:#fff}
  /* login */
  .login{min-height:100vh;display:grid;place-items:center;padding:24px}
  .login .box{width:100%;max-width:380px;border:1px solid var(--line);background:var(--card);border-radius:var(--radius);padding:34px}
  .login h1{font-size:1.5rem;letter-spacing:-.02em}
  .login p{color:var(--muted);font-size:14px;margin-top:8px}
  .login input{width:100%;margin-top:18px;background:var(--bg-elev);border:1px solid var(--line-strong);border-radius:11px;
    padding:13px 14px;color:var(--fg);font:inherit;font-size:15px}
  .login input:focus{outline:none;border-color:#fff}
  .login button{width:100%;margin-top:14px;justify-content:center}
  .err{color:var(--red);font-size:13px;margin-top:12px;font-weight:600}
  .warn{border:1px solid var(--line-strong);background:#1a1205;border-radius:12px;padding:16px;margin-top:18px;font-size:13px;color:#f5d9a8}
  /* toolbar */
  .toolbar{display:flex;gap:12px;align-items:center;margin:26px 0 18px;flex-wrap:wrap}
  .toolbar h1{font-size:1.5rem;letter-spacing:-.02em;margin-right:auto}
  .search{display:flex;gap:8px}
  .search input{background:var(--bg-elev);border:1px solid var(--line-strong);border-radius:10px;padding:9px 13px;color:var(--fg);font:inherit;font-size:14px;min-width:200px}
  .search input:focus{outline:none;border-color:#fff}
  /* list */
  .msg{border:1px solid var(--line);background:var(--card);border-radius:var(--radius);padding:18px 20px;margin-bottom:12px}
  .msg.unread{border-color:var(--line-strong);background:linear-gradient(180deg,#101012,#0b0b0c)}
  .msg .top{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);flex:0 0 auto}
  .msg .who{font-weight:700}
  .msg .who a{color:var(--muted);font-weight:500;font-size:13.5px}
  .msg .when{color:var(--muted-2);font-size:12.5px;margin-left:auto}
  .pill{border:1px solid var(--line-strong);border-radius:999px;padding:3px 10px;font-size:11.5px;color:var(--muted)}
  .msg .body{margin-top:12px;white-space:pre-wrap;color:#dcdce0;font-size:14.5px}
  .msg .acts{display:flex;gap:8px;margin-top:14px}
  .empty{text-align:center;color:var(--muted);border:1px dashed var(--line-strong);border-radius:var(--radius);padding:60px 20px;margin-top:8px}
  form.inline{display:inline}
  footer{color:var(--muted-2);font-size:12.5px;text-align:center;padding:40px 0}
</style>
</head>
<body>

<?php if (!$authed): ?>
  <div class="login">
    <div class="box">
      <h1>Troiana Admin</h1>
      <p>Sign in to view contact messages.</p>
      <?php if ($setupNeeded): ?>
        <div class="warn"><b>Setup needed.</b> Copy <code>config.sample.php</code> to <code>config.php</code> on the server and set a real <code>admin_password</code>, then reload this page.</div>
      <?php else: ?>
        <form method="POST">
          <input type="password" name="password" placeholder="Password" autofocus required />
          <button class="btn primary" type="submit">Sign in →</button>
          <?php if ($loginError): ?><div class="err"><?= e($loginError) ?></div><?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <header>
    <div class="wrap bar">
      <div class="brand"><img src="../logo.svg" alt="Troiana" /> <span>Admin</span>
        <?php if ($unread): ?><span class="badge"><?= (int)$unread ?> new</span><?php endif; ?>
      </div>
      <div style="display:flex;gap:10px">
        <a class="btn sm" href="../index.html" target="_blank">View site ↗</a>
        <a class="btn sm" href="?logout">Log out</a>
      </div>
    </div>
  </header>

  <main class="wrap">
    <div class="toolbar">
      <h1>Messages <span class="muted" style="font-weight:500;font-size:1rem">(<?= count($messages) ?><?= $q!=='' ? ' found' : '' ?>)</span></h1>
      <form class="search" method="GET">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, email, text…" />
        <button class="btn" type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="btn" href="index.php">Clear</a><?php endif; ?>
      </form>
      <?php if ($unread): ?>
      <form class="inline" method="POST">
        <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
        <input type="hidden" name="action" value="read_all" />
        <button class="btn" type="submit">Mark all read</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (empty($messages)): ?>
      <div class="empty"><?= $q !== '' ? 'No messages match your search.' : 'No messages yet. Submissions from the contact form will appear here.' ?></div>
    <?php else: foreach ($messages as $m): ?>
      <div class="msg <?= empty($m['read']) ? 'unread' : '' ?>">
        <div class="top">
          <?php if (empty($m['read'])): ?><span class="dot" title="Unread"></span><?php endif; ?>
          <span class="who"><?= e($m['name'] ?? '—') ?>
            <a href="mailto:<?= e($m['email'] ?? '') ?>">&lt;<?= e($m['email'] ?? '') ?>&gt;</a>
          </span>
          <?php if (!empty($m['company'])): ?><span class="pill"><?= e($m['company']) ?></span><?php endif; ?>
          <?php if (!empty($m['project_type'])): ?><span class="pill"><?= e($m['project_type']) ?></span><?php endif; ?>
          <span class="when"><?= e(date('M j, Y · H:i', strtotime($m['time'] ?? 'now'))) ?></span>
        </div>
        <div class="body"><?= e($m['message'] ?? '') ?></div>
        <div class="acts">
          <form class="inline" method="POST">
            <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
            <input type="hidden" name="id" value="<?= e($m['id'] ?? '') ?>" />
            <input type="hidden" name="action" value="toggle" />
            <button class="btn sm" type="submit"><?= empty($m['read']) ? 'Mark read' : 'Mark unread' ?></button>
          </form>
          <a class="btn sm" href="mailto:<?= e($m['email'] ?? '') ?>?subject=Re: your enquiry to Troiana">Reply</a>
          <form class="inline" method="POST" onsubmit="return confirm('Delete this message?')">
            <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
            <input type="hidden" name="id" value="<?= e($m['id'] ?? '') ?>" />
            <input type="hidden" name="action" value="delete" />
            <button class="btn sm danger" type="submit">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </main>
  <footer>Troiana admin · messages are stored privately on your server.</footer>
<?php endif; ?>

</body>
</html>
