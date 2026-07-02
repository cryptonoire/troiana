<?php
/**
 * Troiana admin console — /admin/
 * WordPress-style dashboard for contact-form messages (data/messages.json).
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
function excerpt($s, $n = 120) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
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
        $loginError = 'The password you entered is incorrect.';
    }
}
$authed = !empty($_SESSION['auth']);

/* ---------- routing ---------- */
$page = ($_GET['page'] ?? 'dashboard') === 'messages' ? 'messages' : 'dashboard';
$q = trim((string)($_GET['q'] ?? ''));
$notice = '';

/* ---------- actions (require auth + csrf) ---------- */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['password'])) {
    if (!hash_equals(token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Bad request');
    }
    $messages = load_messages($dataFile);

    if (isset($_POST['do_delete'])) {
        $id = (string)$_POST['do_delete'];
        $messages = array_filter($messages, fn($m) => ($m['id'] ?? '') !== $id);
        $flash = 'Message deleted.';
    } elseif (isset($_POST['do_toggle'])) {
        $id = (string)$_POST['do_toggle'];
        foreach ($messages as &$m) { if (($m['id'] ?? '') === $id) $m['read'] = empty($m['read']); }
        unset($m);
        $flash = 'Message updated.';
    } elseif (isset($_POST['do_read_all'])) {
        foreach ($messages as &$m) { $m['read'] = true; } unset($m);
        $flash = 'All messages marked as read.';
    } elseif (isset($_POST['do_bulk'])) {
        $ids = array_map('strval', (array)($_POST['ids'] ?? []));
        $ba  = (string)($_POST['bulk_action'] ?? '');
        $n   = count($ids);
        if ($n && $ba === 'delete') {
            $messages = array_filter($messages, fn($m) => !in_array((string)($m['id'] ?? ''), $ids, true));
            $flash = $n . ' message' . ($n > 1 ? 's' : '') . ' deleted.';
        } elseif ($n && ($ba === 'read' || $ba === 'unread')) {
            $val = $ba === 'read';
            foreach ($messages as &$m) { if (in_array((string)($m['id'] ?? ''), $ids, true)) $m['read'] = $val; }
            unset($m);
            $flash = $n . ' message' . ($n > 1 ? 's' : '') . ' marked as ' . $ba . '.';
        }
    }
    save_messages($dataFile, $messages);
    $params = ['page' => $page];
    if ($q !== '') $params['q'] = $q;
    if (!empty($flash)) $params['notice'] = $flash;
    header('Location: index.php?' . http_build_query($params));
    exit;
}

if (isset($_GET['notice'])) $notice = (string)$_GET['notice'];

/* ---------- data for views ---------- */
$all = $authed ? load_messages($dataFile) : [];
$total = count($all);
$unread = 0;
$recent7 = 0;
$weekAgo = strtotime('-7 days');
foreach ($all as $m) {
    if (empty($m['read'])) $unread++;
    if (strtotime($m['time'] ?? 'now') >= $weekAgo) $recent7++;
}

$list = array_reverse($all);
if ($q !== '') {
    $needle = mb_strtolower($q);
    $list = array_filter($list, function ($m) use ($needle) {
        $hay = mb_strtolower(($m['name'] ?? '') . ' ' . ($m['email'] ?? '') . ' ' . ($m['company'] ?? '') . ' ' . ($m['message'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    });
}
$recent = array_slice(array_reverse($all), 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title><?= $authed ? ($page === 'messages' ? 'Messages' : 'Dashboard') . ' ‹ ' : '' ?>Troiana Admin</title>
<style>
  :root{
    --wp-bg:#f0f0f1;--wp-menu:#1d2327;--wp-menu-2:#2c3338;--wp-menu-fg:#f0f0f1;
    --wp-blue:#2271b1;--wp-blue-h:#135e96;--wp-accent:#72aee6;
    --wp-line:#c3c4c7;--wp-line-2:#dcdcde;--wp-text:#1d2327;--wp-muted:#646970;
    --wp-green:#00a32a;--wp-red:#d63638;--wp-orange:#dba617;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{background:var(--wp-bg);color:var(--wp-text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
    font-size:13px;line-height:1.4;-webkit-font-smoothing:antialiased}
  a{color:var(--wp-blue);text-decoration:none}
  a:hover{color:var(--wp-blue-h)}

  /* ===== login ===== */
  .login-wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
  .login-box{width:100%;max-width:340px}
  .login-logo{text-align:center;margin-bottom:22px}
  .login-logo img{height:44px}
  .login-card{background:#fff;border:1px solid var(--wp-line-2);box-shadow:0 1px 3px rgba(0,0,0,.04);padding:26px 24px;border-radius:4px}
  .login-card label{display:block;font-size:14px;margin:0 0 6px}
  .login-card input[type=password]{width:100%;padding:8px 10px;font-size:16px;border:1px solid #8c8f94;border-radius:4px;box-shadow:inset 0 1px 2px rgba(0,0,0,.07)}
  .login-card input:focus{outline:2px solid var(--wp-accent);outline-offset:-1px;border-color:var(--wp-blue)}
  .login-sub{color:var(--wp-muted);text-align:center;margin:14px 0 0;font-size:12px}
  .notice-error{background:#fcf0f1;border-left:4px solid var(--wp-red);padding:10px 12px;margin:0 0 16px;border-radius:2px}
  .notice-warn{background:#fcf9e8;border-left:4px solid var(--wp-orange);padding:12px;margin:0 0 16px;border-radius:2px}
  .notice-warn code{background:#f6f7f7;padding:1px 5px;border-radius:3px}

  /* ===== button ===== */
  .button{display:inline-block;font:inherit;font-size:13px;line-height:2.15;min-height:30px;
    padding:0 12px;border-radius:3px;border:1px solid #c3c4c7;background:#f6f7f7;color:#2c3338;
    cursor:pointer;vertical-align:middle;white-space:nowrap}
  .button:hover{background:#f0f0f1;border-color:#8c8f94;color:#2c3338}
  .button-primary{background:var(--wp-blue);border-color:var(--wp-blue);color:#fff}
  .button-primary:hover{background:var(--wp-blue-h);border-color:var(--wp-blue-h);color:#fff}
  .button-large{min-height:34px;line-height:2.4;padding:0 14px;font-size:14px}
  .button-link{background:none;border:none;padding:0;min-height:0;color:var(--wp-blue);cursor:pointer;font:inherit}
  .button-link:hover{color:var(--wp-blue-h);text-decoration:underline;background:none}
  .button-link.delete{color:var(--wp-red)}

  /* ===== admin bar ===== */
  #wpadminbar{position:fixed;top:0;left:0;right:0;height:32px;background:var(--wp-menu);color:#c3c4c7;
    display:flex;align-items:center;z-index:99;font-size:13px}
  #wpadminbar .ab-item{display:flex;align-items:center;gap:8px;padding:0 12px;height:32px;color:#eee}
  #wpadminbar a.ab-item:hover{background:var(--wp-menu-2);color:#72aee6}
  #wpadminbar .spacer{margin-left:auto}
  #wpadminbar .site-name{font-weight:600}

  /* ===== layout ===== */
  #adminmenu{position:fixed;top:32px;left:0;bottom:0;width:160px;background:var(--wp-menu);z-index:98;overflow-y:auto}
  #adminmenu a{display:flex;align-items:center;gap:9px;padding:9px 12px;color:var(--wp-menu-fg);font-size:14px}
  #adminmenu a .ico{width:18px;height:18px;flex:0 0 auto;opacity:.7}
  #adminmenu a:hover{background:var(--wp-menu-2);color:#72aee6}
  #adminmenu a:hover .ico{opacity:1}
  #adminmenu a.current{background:var(--wp-blue);color:#fff;font-weight:600}
  #adminmenu a.current .ico{opacity:1}
  #adminmenu .menu-count{margin-left:auto;background:#d63638;color:#fff;border-radius:10px;
    font-size:11px;font-weight:600;padding:1px 7px;line-height:1.6}
  #adminmenu a.current .menu-count{background:rgba(255,255,255,.25)}
  .menu-sep{height:1px;background:rgba(255,255,255,.08);margin:6px 0}

  #wpbody{margin:32px 0 0 160px;min-height:calc(100vh - 32px);padding:0}
  .wrap{padding:16px 20px 60px;max-width:1120px}
  .wrap h1.page-title{font-size:23px;font-weight:400;margin:8px 0 4px;padding:9px 0 4px}
  .subtitle{color:var(--wp-muted);font-size:13px;margin:0 0 12px}

  .notice-success{background:#fff;border-left:4px solid var(--wp-green);box-shadow:0 1px 1px rgba(0,0,0,.04);
    padding:10px 12px;margin:0 0 16px;border-radius:2px}

  /* ===== dashboard widgets ===== */
  .dash-cols{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:8px}
  @media(max-width:900px){.dash-cols{grid-template-columns:1fr}}
  .postbox{background:#fff;border:1px solid var(--wp-line);border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
  .postbox h2{font-size:14px;font-weight:600;padding:10px 14px;margin:0;border-bottom:1px solid var(--wp-line-2)}
  .postbox .inside{padding:12px 14px}
  .glance{display:flex;flex-wrap:wrap;gap:10px}
  .glance a,.glance span{display:flex;align-items:center;gap:8px;font-size:14px;color:var(--wp-muted);
    padding:6px 0;width:100%}
  .glance .big{font-size:20px;font-weight:600;color:var(--wp-text);min-width:40px}
  .glance .unread .big{color:var(--wp-blue)}
  .recent-item{display:block;padding:10px 0;border-bottom:1px solid var(--wp-line-2)}
  .recent-item:last-child{border-bottom:0}
  .recent-item .r-top{display:flex;gap:8px;align-items:center}
  .recent-item .r-name{font-weight:600;color:var(--wp-text)}
  .recent-item.is-unread .r-name::before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;
    background:var(--wp-blue);margin-right:6px;vertical-align:middle}
  .recent-item .r-when{color:var(--wp-muted);font-size:12px;margin-left:auto}
  .recent-item .r-msg{color:var(--wp-muted);margin-top:3px}
  .empty-inside{color:var(--wp-muted);padding:6px 0}

  /* ===== list table ===== */
  .tablenav{display:flex;align-items:center;gap:8px;margin:10px 0;flex-wrap:wrap}
  .tablenav .actions{display:flex;gap:6px;align-items:center}
  .tablenav select{height:30px;border:1px solid #8c8f94;border-radius:3px;background:#fff;font:inherit;font-size:13px;padding:0 6px}
  .tablenav .count{margin-left:auto;color:var(--wp-muted)}
  .search-form{display:flex;gap:6px;margin-bottom:10px}
  .search-form input[type=search]{height:30px;border:1px solid #8c8f94;border-radius:3px;padding:0 8px;font:inherit;font-size:13px;min-width:220px}
  .search-form input:focus{outline:2px solid var(--wp-accent);outline-offset:-1px}

  table.wp-list-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--wp-line);
    border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04);overflow:hidden}
  .wp-list-table thead th,.wp-list-table tfoot th{text-align:left;font-weight:600;font-size:13px;
    padding:9px 10px;border-bottom:1px solid var(--wp-line)}
  .wp-list-table tfoot th{border-bottom:0;border-top:1px solid var(--wp-line)}
  .wp-list-table td{padding:11px 10px;border-bottom:1px solid var(--wp-line-2);vertical-align:top}
  .wp-list-table tbody tr:last-child td{border-bottom:0}
  .wp-list-table tbody tr:hover{background:#f6f7f7}
  .wp-list-table .check-column{width:2.2em;padding-left:12px}
  .wp-list-table .col-from{width:24%}
  .wp-list-table .col-type{width:14%}
  .wp-list-table .col-when{width:16%;white-space:nowrap}
  .row-unread{background:#fff}
  .row-unread td.col-from .from-name{font-weight:700}
  .row-unread td.col-from .from-name::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;
    background:var(--wp-blue);margin-right:7px;vertical-align:middle}
  .from-name{color:var(--wp-text)}
  .from-email{display:block;color:var(--wp-muted);font-size:12px;margin-top:2px}
  .msg-excerpt{color:var(--wp-text)}
  .badge{display:inline-block;background:#f0f0f1;border:1px solid var(--wp-line);color:var(--wp-muted);
    border-radius:3px;padding:1px 7px;font-size:12px}
  .row-actions{margin-top:6px;color:#a7aaad;font-size:12px;visibility:hidden}
  tr:hover .row-actions{visibility:visible}
  .row-actions span{margin-right:4px}
  .row-actions .sep{color:#c3c4c7}
  .when-muted{color:var(--wp-muted)}
  .empty-row td{text-align:center;color:var(--wp-muted);padding:34px 10px}

  @media(max-width:782px){
    #adminmenu{width:52px}
    #adminmenu a .label,#adminmenu .menu-count{display:none}
    #wpbody{margin-left:52px}
    #wpadminbar .site-name{display:none}
    .wp-list-table .col-type,.wp-list-table .col-when{display:none}
  }
</style>
</head>
<body>

<?php if (!$authed): ?>
  <div class="login-wrap">
    <div class="login-box">
      <div class="login-logo"><img src="../logo.svg" alt="Troiana" /></div>
      <div class="login-card">
        <?php if ($setupNeeded): ?>
          <div class="notice-warn"><b>Setup needed.</b> Copy <code>config.sample.php</code> to <code>config.php</code> on the server and set a real <code>admin_password</code>, then reload this page.</div>
        <?php else: ?>
          <?php if ($loginError): ?><div class="notice-error"><?= e($loginError) ?></div><?php endif; ?>
          <form method="POST">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autofocus required />
            <p style="margin:16px 0 0;text-align:right">
              <button class="button button-primary button-large" type="submit">Log In</button>
            </p>
          </form>
        <?php endif; ?>
      </div>
      <p class="login-sub">Troiana admin · <a href="../index.html">← Back to site</a></p>
    </div>
  </div>

<?php else: ?>
  <div id="wpadminbar">
    <a class="ab-item" href="../index.html" target="_blank"><span class="site-name">Troiana</span></a>
    <a class="ab-item" href="../index.html" target="_blank">Visit site ↗</a>
    <span class="spacer"></span>
    <span class="ab-item">Howdy, Admin</span>
    <a class="ab-item" href="?logout">Log Out</a>
  </div>

  <nav id="adminmenu">
    <a class="<?= $page === 'dashboard' ? 'current' : '' ?>" href="index.php?page=dashboard">
      <svg class="ico" viewBox="0 0 20 20" fill="currentColor"><path d="M3 3h6v6H3V3zm0 8h6v6H3v-6zm8-8h6v6h-6V3zm0 8h6v6h-6v-6z"/></svg>
      <span class="label">Dashboard</span>
    </a>
    <a class="<?= $page === 'messages' ? 'current' : '' ?>" href="index.php?page=messages">
      <svg class="ico" viewBox="0 0 20 20" fill="currentColor"><path d="M2 4h16v12H2V4zm2 2v.8l6 3.7 6-3.7V6H4zm12 8V9l-6 3.7L4 9v5h12z"/></svg>
      <span class="label">Messages</span>
      <?php if ($unread): ?><span class="menu-count"><?= (int)$unread ?></span><?php endif; ?>
    </a>
    <div class="menu-sep"></div>
    <a href="../index.html" target="_blank">
      <svg class="ico" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm5.6 5h-2.1a11 11 0 00-1-2.6A6 6 0 0115.6 7zM10 4c.6.8 1.1 1.8 1.4 3H8.6c.3-1.2.8-2.2 1.4-3zM4.3 13A6 6 0 014 11h2.3c0 .7.1 1.4.2 2H4.3zm0-4A6 6 0 015.6 7H7.5c-.1.6-.2 1.3-.2 2H4.3zm.9 6h2.1c.3 1 .6 1.9 1 2.6A6 6 0 015.2 15zM10 16c-.6-.8-1.1-1.8-1.4-3h2.8c-.3 1.2-.8 2.2-1.4 3zm1.7-5H8.3c-.1-.6-.2-1.3-.2-2s.1-1.4.2-2h3.4c.1.6.2 1.3.2 2s-.1 1.4-.2 2zm.6 4.6c.4-.7.7-1.6 1-2.6h2.1a6 6 0 01-3.1 2.6zM13.7 13c.1-.6.2-1.3.2-2h2.4a6 6 0 01-.3 2h-2.3z"/></svg>
      <span class="label">View Site</span>
    </a>
  </nav>

  <div id="wpbody">
    <div class="wrap">
      <?php if ($notice !== ''): ?>
        <div class="notice-success"><?= e($notice) ?></div>
      <?php endif; ?>

      <?php if ($page === 'dashboard'): ?>
        <h1 class="page-title">Dashboard</h1>
        <p class="subtitle">Welcome to the Troiana admin. Contact-form submissions land here.</p>
        <div class="dash-cols">
          <div class="postbox">
            <h2>At a Glance</h2>
            <div class="inside">
              <div class="glance">
                <a href="index.php?page=messages"><span class="big"><?= (int)$total ?></span> total message<?= $total === 1 ? '' : 's' ?></a>
                <a class="unread" href="index.php?page=messages"><span class="big"><?= (int)$unread ?></span> unread</a>
                <span><span class="big"><?= (int)$recent7 ?></span> in the last 7 days</span>
              </div>
            </div>
          </div>
          <div class="postbox">
            <h2>Recent Messages</h2>
            <div class="inside">
              <?php if (empty($recent)): ?>
                <p class="empty-inside">No messages yet. Submissions from the contact form will appear here.</p>
              <?php else: foreach ($recent as $m): ?>
                <a class="recent-item <?= empty($m['read']) ? 'is-unread' : '' ?>" href="index.php?page=messages&q=<?= e(urlencode($m['email'] ?? '')) ?>">
                  <span class="r-top">
                    <span class="r-name"><?= e($m['name'] ?? '—') ?></span>
                    <span class="r-when"><?= e(date('M j, H:i', strtotime($m['time'] ?? 'now'))) ?></span>
                  </span>
                  <span class="r-msg"><?= e(excerpt($m['message'] ?? '', 90)) ?></span>
                </a>
              <?php endforeach; endif; ?>
              <?php if (!empty($recent)): ?>
                <p style="margin:12px 0 0"><a href="index.php?page=messages">View all messages →</a></p>
              <?php endif; ?>
            </div>
          </div>
        </div>

      <?php else: /* ===== messages ===== */ ?>
        <h1 class="page-title">Messages
          <?php if ($q !== ''): ?><span style="font-size:13px;color:var(--wp-muted)">— search results for “<?= e($q) ?>”</span><?php endif; ?>
        </h1>
        <p class="subtitle"><?= (int)$total ?> total · <?= (int)$unread ?> unread</p>

        <form class="search-form" method="GET">
          <input type="hidden" name="page" value="messages" />
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, company, text…" />
          <button class="button" type="submit">Search Messages</button>
          <?php if ($q !== ''): ?><a class="button" href="index.php?page=messages">Clear</a><?php endif; ?>
        </form>

        <form method="POST">
          <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
          <div class="tablenav">
            <div class="actions">
              <select name="bulk_action" aria-label="Bulk action">
                <option value="">Bulk actions</option>
                <option value="read">Mark as read</option>
                <option value="unread">Mark as unread</option>
                <option value="delete">Delete</option>
              </select>
              <button class="button" type="submit" name="do_bulk" value="1">Apply</button>
              <?php if ($unread): ?>
                <button class="button" type="submit" name="do_read_all" value="1">Mark all read</button>
              <?php endif; ?>
            </div>
            <span class="count"><?= count($list) ?> item<?= count($list) === 1 ? '' : 's' ?></span>
          </div>

          <table class="wp-list-table">
            <thead>
              <tr>
                <td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)" aria-label="Select all" /></td>
                <th class="col-from">From</th>
                <th class="col-msg">Message</th>
                <th class="col-type">Type</th>
                <th class="col-when">Received</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($list)): ?>
                <tr class="empty-row"><td colspan="5"><?= $q !== '' ? 'No messages match your search.' : 'No messages yet. Submissions from the contact form will appear here.' ?></td></tr>
              <?php else: foreach ($list as $m): $id = (string)($m['id'] ?? ''); $isUnread = empty($m['read']); ?>
                <tr class="<?= $isUnread ? 'row-unread' : '' ?>">
                  <td class="check-column"><input class="cb" type="checkbox" name="ids[]" value="<?= e($id) ?>" aria-label="Select message" /></td>
                  <td class="col-from">
                    <span class="from-name"><?= e($m['name'] ?? '—') ?></span>
                    <a class="from-email" href="mailto:<?= e($m['email'] ?? '') ?>"><?= e($m['email'] ?? '') ?></a>
                    <?php if (!empty($m['company'])): ?><span class="from-email"><?= e($m['company']) ?></span><?php endif; ?>
                    <div class="row-actions">
                      <span><button class="button-link" type="submit" name="do_toggle" value="<?= e($id) ?>"><?= $isUnread ? 'Mark read' : 'Mark unread' ?></button></span>
                      <span class="sep">|</span>
                      <span><a href="mailto:<?= e($m['email'] ?? '') ?>?subject=Re:%20your%20enquiry%20to%20Troiana">Reply</a></span>
                      <span class="sep">|</span>
                      <span><button class="button-link delete" type="submit" name="do_delete" value="<?= e($id) ?>" onclick="return confirm('Delete this message permanently?')">Delete</button></span>
                    </div>
                  </td>
                  <td class="col-msg"><span class="msg-excerpt"><?= e(excerpt($m['message'] ?? '')) ?></span></td>
                  <td class="col-type"><?php if (!empty($m['project_type'])): ?><span class="badge"><?= e($m['project_type']) ?></span><?php endif; ?></td>
                  <td class="col-when when-muted"><?= e(date('M j, Y', strtotime($m['time'] ?? 'now'))) ?><br><?= e(date('H:i', strtotime($m['time'] ?? 'now'))) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)" aria-label="Select all" /></td>
                <th class="col-from">From</th>
                <th class="col-msg">Message</th>
                <th class="col-type">Type</th>
                <th class="col-when">Received</th>
              </tr>
            </tfoot>
          </table>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
