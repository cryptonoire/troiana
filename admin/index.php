<?php
/**
 * Troiana admin console — /admin/
 * Dark, Troiana-styled dashboard. Manage contact messages, portfolio
 * projects, services and site text; view the visitor counter. Content edits
 * save to data/content.json and republish the static .html pages via lib.php.
 */
session_start();
require __DIR__ . '/lib.php';

$cfg = file_exists(ROOT_DIR . '/config.php') ? require ROOT_DIR . '/config.php' : null;

function token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function redirect_to($section, $notice = '', $warnings = []) {
    $p = ['s' => $section];
    if ($notice !== '') $p['notice'] = $notice;
    if (!empty($warnings)) $p['warn'] = implode(' | ', $warnings);
    header('Location: index.php?' . http_build_query($p));
    exit;
}
function excerpt($s, $n = 130) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/* ---------- setup / auth ---------- */
$setupNeeded = !$cfg || empty($cfg['admin_password']) || $cfg['admin_password'] === 'change-this-now';

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php'); exit; }

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

$section = $_GET['s'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'messages', 'projects', 'services', 'content'], true)) $section = 'dashboard';

/* ---------- actions ---------- */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['act'])) {
    if (!hash_equals(token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Bad request'); }
    $act = $_POST['act'];

    /* --- messages --- */
    if (strpos($act, 'msg_') === 0) {
        $messages = read_json(MESSAGES_FILE, []);
        if ($act === 'msg_delete') {
            $id = (string)$_POST['id'];
            $messages = array_filter($messages, fn($m) => ($m['id'] ?? '') !== $id);
            $note = 'Message deleted.';
        } elseif ($act === 'msg_toggle') {
            $id = (string)$_POST['id'];
            foreach ($messages as &$m) if (($m['id'] ?? '') === $id) $m['read'] = empty($m['read']);
            unset($m); $note = 'Message updated.';
        } elseif ($act === 'msg_readall') {
            foreach ($messages as &$m) $m['read'] = true; unset($m); $note = 'All messages marked read.';
        } elseif ($act === 'msg_bulk') {
            $ids = array_map('strval', (array)($_POST['ids'] ?? []));
            $ba = (string)($_POST['bulk_action'] ?? ''); $n = count($ids);
            if ($n && $ba === 'delete') { $messages = array_filter($messages, fn($m) => !in_array((string)($m['id'] ?? ''), $ids, true)); $note = "$n deleted."; }
            elseif ($n && ($ba === 'read' || $ba === 'unread')) { $val = $ba === 'read'; foreach ($messages as &$m) if (in_array((string)($m['id'] ?? ''), $ids, true)) $m['read'] = $val; unset($m); $note = "$n marked $ba."; }
            else $note = '';
        }
        write_json(MESSAGES_FILE, array_values($messages));
        redirect_to('messages', $note ?? '');
    }

    /* --- content-bearing actions (save, then republish) --- */
    $c = load_content();

    if ($act === 'save_project') {
        $cats = array_values(array_intersect(array_keys(categories()), (array)($_POST['cats'] ?? [])));
        $p = [
            'title'    => trim((string)$_POST['title']),
            'tag'      => trim((string)$_POST['tag']),
            'desc'     => trim((string)$_POST['desc']),
            'img'      => trim((string)$_POST['img']),
            'href'     => trim((string)$_POST['href']),
            'external' => !empty($_POST['external']),
            'cta'      => trim((string)$_POST['cta']) !== '' ? trim((string)$_POST['cta']) : 'View →',
            'cats'     => $cats,
        ];
        $idx = (string)($_POST['idx'] ?? '');
        if ($idx === '' || $idx === 'new') $c['projects'][] = $p;
        elseif (isset($c['projects'][(int)$idx])) $c['projects'][(int)$idx] = $p;
        save_content($c); $w = publish_all($c);
        redirect_to('projects', 'Project saved & published.', $w);
    }
    if ($act === 'del_project') {
        $i = (int)$_POST['idx'];
        if (isset($c['projects'][$i])) { array_splice($c['projects'], $i, 1); save_content($c); $w = publish_all($c); redirect_to('projects', 'Project deleted & published.', $w); }
        redirect_to('projects');
    }
    if ($act === 'move_project') {
        $i = (int)$_POST['idx']; $j = $i + ($_POST['dir'] === 'up' ? -1 : 1);
        if (isset($c['projects'][$i], $c['projects'][$j])) { $t = $c['projects'][$i]; $c['projects'][$i] = $c['projects'][$j]; $c['projects'][$j] = $t; save_content($c); $w = publish_all($c); redirect_to('projects', 'Order updated & published.', $w); }
        redirect_to('projects');
    }
    if ($act === 'save_service') {
        $bullets = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['bullets'] ?? ''))), fn($b) => $b !== ''));
        $s = [
            'icon'    => array_key_exists((string)$_POST['icon'], icons()) ? (string)$_POST['icon'] : 'monitor',
            'title'   => trim((string)$_POST['title']),
            'desc'    => trim((string)$_POST['desc']),
            'bullets' => $bullets,
        ];
        $idx = (string)($_POST['idx'] ?? '');
        if ($idx === '' || $idx === 'new') $c['services'][] = $s;
        elseif (isset($c['services'][(int)$idx])) $c['services'][(int)$idx] = $s;
        save_content($c); $w = publish_all($c);
        redirect_to('services', 'Service saved & published.', $w);
    }
    if ($act === 'del_service') {
        $i = (int)$_POST['idx'];
        if (isset($c['services'][$i])) { array_splice($c['services'], $i, 1); save_content($c); $w = publish_all($c); redirect_to('services', 'Service deleted & published.', $w); }
        redirect_to('services');
    }
    if ($act === 'move_service') {
        $i = (int)$_POST['idx']; $j = $i + ($_POST['dir'] === 'up' ? -1 : 1);
        if (isset($c['services'][$i], $c['services'][$j])) { $t = $c['services'][$i]; $c['services'][$i] = $c['services'][$j]; $c['services'][$j] = $t; save_content($c); $w = publish_all($c); redirect_to('services', 'Order updated & published.', $w); }
        redirect_to('services');
    }
    if ($act === 'save_content') {
        $c['hero']['title']    = trim((string)($_POST['hero_title'] ?? ''));
        $c['hero']['sub']      = trim((string)($_POST['hero_sub'] ?? ''));
        $c['contact']['email'] = trim((string)($_POST['contact_email'] ?? ''));
        $ns = (array)($_POST['metric_n'] ?? []); $ls = (array)($_POST['metric_l'] ?? []);
        $metrics = [];
        foreach ($ns as $i => $n) {
            $n = trim((string)$n); $l = trim((string)($ls[$i] ?? ''));
            if ($n !== '' || $l !== '') $metrics[] = ['n' => $n, 'l' => $l];
        }
        $c['metrics'] = $metrics;
        save_content($c); $w = publish_all($c);
        redirect_to('content', 'Site content saved & published.', $w);
    }
    if ($act === 'republish') {
        $w = publish_all($c);
        redirect_to($section, 'Site republished from saved content.', $w);
    }
}

/* ---------- data for views ---------- */
$notice   = (string)($_GET['notice'] ?? '');
$warn     = (string)($_GET['warn'] ?? '');
$content  = $authed ? load_content() : default_content();
$allMsgs  = $authed ? read_json(MESSAGES_FILE, []) : [];
$unread   = 0; foreach ($allMsgs as $m) if (empty($m['read'])) $unread++;
$stats    = $authed ? visit_stats() : ['total' => 0, 'today' => 0, 'week' => 0, 'series' => [], 'visitors' => 0, 'visitors_today' => 0];

/* messages list (search + newest first) */
$q = trim((string)($_GET['q'] ?? ''));
$msgList = array_reverse($allMsgs);
if ($q !== '') {
    $needle = mb_strtolower($q);
    $msgList = array_filter($msgList, function ($m) use ($needle) {
        $hay = mb_strtolower(($m['name'] ?? '') . ' ' . ($m['email'] ?? '') . ' ' . ($m['company'] ?? '') . ' ' . ($m['message'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    });
}

/* project/service edit target */
$editIdx = $_GET['edit'] ?? null;
$editing = null;
if ($section === 'projects' && $editIdx !== null) {
    $editing = ($editIdx === 'new') ? ['title'=>'','tag'=>'','desc'=>'','img'=>'','href'=>'','external'=>false,'cta'=>'View case study →','cats'=>[]]
             : ($content['projects'][(int)$editIdx] ?? null);
}
$editSvc = null;
if ($section === 'services' && $editIdx !== null) {
    $editSvc = ($editIdx === 'new') ? ['icon'=>'monitor','title'=>'','desc'=>'','bullets'=>[]]
             : ($content['services'][(int)$editIdx] ?? null);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>Troiana Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<style>
  :root{--bg:#000;--bg-elev:#0a0a0a;--card:#0e0e0f;--line:rgba(255,255,255,.08);--line-strong:rgba(255,255,255,.16);
    --fg:#f5f5f7;--muted:#9a9a9f;--muted-2:#6b6b70;--accent:#fff;--green:#34d399;--red:#f87171;--radius:16px}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--fg);line-height:1.5;-webkit-font-smoothing:antialiased}
  a{color:inherit;text-decoration:none}
  .wrap{max-width:1040px;margin:0 auto;padding:0 22px}
  header{position:sticky;top:0;z-index:5;background:rgba(0,0,0,.8);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
  .bar{display:flex;align-items:center;gap:16px;height:64px}
  .brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-.02em}
  .brand img{height:26px}
  .navtabs{display:flex;gap:4px;margin-left:14px;flex-wrap:wrap}
  .navtabs a{padding:8px 13px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--muted);position:relative}
  .navtabs a:hover{color:var(--fg);background:var(--bg-elev)}
  .navtabs a.on{color:#000;background:var(--accent)}
  .navtabs a .cnt{display:inline-block;margin-left:6px;background:var(--red);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:0 6px}
  .navtabs a.on .cnt{background:rgba(0,0,0,.25);color:#000}
  .bar .right{margin-left:auto;display:flex;gap:8px}
  .muted{color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:7px;font:inherit;font-weight:600;font-size:13.5px;cursor:pointer;
    border:1px solid var(--line-strong);background:var(--bg-elev);color:var(--fg);padding:9px 15px;border-radius:10px;transition:.15s}
  .btn:hover{border-color:#fff}
  .btn.sm{padding:6px 11px;font-size:12.5px;border-radius:8px}
  .btn.danger:hover{border-color:var(--red);color:var(--red)}
  .btn.primary{background:var(--accent);color:#000;border-color:#fff}
  .btn.primary:hover{opacity:.9}
  .btn-link{background:none;border:none;color:var(--muted);cursor:pointer;font:inherit;font-weight:600;font-size:12.5px;padding:0}
  .btn-link:hover{color:var(--fg)}
  .btn-link.del{color:#c66}
  .btn-link.del:hover{color:var(--red)}
  main{padding:26px 0 80px}
  h1.page{font-size:1.7rem;letter-spacing:-.02em;margin-bottom:4px}
  .sub{color:var(--muted);margin-bottom:22px}
  .notice{border:1px solid var(--line-strong);border-left:3px solid var(--green);background:#0c1410;border-radius:10px;padding:12px 15px;margin-bottom:16px;font-size:14px}
  .notice.warn{border-left-color:#dba617;background:#14110a;color:#f0dca8}
  /* login */
  .login{min-height:100vh;display:grid;place-items:center;padding:24px}
  .login .box{width:100%;max-width:380px;border:1px solid var(--line);background:var(--card);border-radius:var(--radius);padding:34px;text-align:center}
  .login img{height:40px;margin-bottom:18px}
  .login h1{font-size:1.4rem;letter-spacing:-.02em}
  .login p{color:var(--muted);font-size:14px;margin-top:8px}
  .login input{width:100%;margin-top:18px;background:var(--bg-elev);border:1px solid var(--line-strong);border-radius:11px;padding:13px 14px;color:var(--fg);font:inherit;font-size:15px}
  .login input:focus{outline:none;border-color:#fff}
  .login button{width:100%;margin-top:14px;justify-content:center}
  .err{color:var(--red);font-size:13px;margin-top:12px;font-weight:600}
  .warnbox{border:1px solid var(--line-strong);background:#1a1205;border-radius:12px;padding:16px;margin-top:18px;font-size:13px;color:#f5d9a8;text-align:left}
  .warnbox code{background:#000;padding:1px 5px;border-radius:4px}
  /* cards / grid */
  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
  @media(max-width:820px){.cards{grid-template-columns:1fr 1fr}}
  .stat{border:1px solid var(--line);background:var(--card);border-radius:14px;padding:18px}
  .stat .n{font-size:1.9rem;font-weight:800;letter-spacing:-.02em}
  .stat .l{color:var(--muted);font-size:12.5px;margin-top:3px}
  .stat.accent .n{color:var(--green)}
  .panel{border:1px solid var(--line);background:var(--card);border-radius:var(--radius);padding:20px;margin-bottom:18px}
  .panel h2{font-size:1.05rem;font-weight:700;margin-bottom:14px}
  .chart{display:flex;align-items:flex-end;gap:5px;height:90px;margin-top:6px}
  .chart .b{flex:1;background:linear-gradient(180deg,#3a3a40,#17171a);border-radius:4px 4px 0 0;min-height:3px;position:relative}
  .chart .b span{position:absolute;bottom:-18px;left:0;right:0;text-align:center;font-size:9px;color:var(--muted-2)}
  .chart .b.today{background:linear-gradient(180deg,var(--green),#0b5)}
  /* tables */
  table{width:100%;border-collapse:collapse}
  th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted-2);font-weight:700;padding:8px 10px;border-bottom:1px solid var(--line)}
  td{padding:11px 10px;border-bottom:1px solid var(--line);vertical-align:top;font-size:14px}
  tr:last-child td{border-bottom:0}
  .row-actions{display:flex;gap:12px;margin-top:6px;visibility:hidden}
  tr:hover .row-actions{visibility:visible}
  .pill{display:inline-block;border:1px solid var(--line-strong);border-radius:999px;padding:2px 9px;font-size:11.5px;color:var(--muted);margin:2px 3px 0 0}
  .thumb-sm{width:66px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--line);background:#060607}
  .dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);display:inline-block;margin-right:6px}
  .toolbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
  .toolbar .right{margin-left:auto;display:flex;gap:8px}
  form.inline{display:inline}
  input[type=text],input[type=search],input[type=email],input[type=url],textarea,select{
    background:var(--bg-elev);border:1px solid var(--line-strong);border-radius:10px;padding:10px 12px;color:var(--fg);font:inherit;font-size:14px;width:100%}
  input:focus,textarea:focus,select:focus{outline:none;border-color:#fff}
  textarea{resize:vertical;min-height:74px}
  label.fld{display:block;margin-bottom:14px}
  label.fld .lab{display:block;font-size:12.5px;color:var(--muted);margin-bottom:6px;font-weight:600}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:640px){.two{grid-template-columns:1fr}}
  .checks{display:flex;flex-wrap:wrap;gap:14px}
  .checks label{display:flex;align-items:center;gap:7px;font-size:14px}
  .checks input,.chk input{width:auto}
  .chk{display:flex;align-items:center;gap:8px;font-size:14px}
  .icochoice{display:flex;flex-wrap:wrap;gap:8px}
  .icochoice label{cursor:pointer}
  .icochoice input{display:none}
  .icochoice .ic{width:46px;height:46px;border:1px solid var(--line-strong);border-radius:12px;display:grid;place-items:center;background:#000}
  .icochoice .ic svg{width:22px;height:22px;stroke:currentColor;stroke-width:1.6;fill:none;stroke-linecap:round;stroke-linejoin:round;color:#fff}
  .icochoice input:checked + .ic{border-color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.25)}
  .empty{text-align:center;color:var(--muted);border:1px dashed var(--line-strong);border-radius:var(--radius);padding:50px 20px}
  .metric-row{display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:8px}
  .hint{color:var(--muted-2);font-size:12px;margin-top:5px}
  footer{color:var(--muted-2);font-size:12.5px;text-align:center;padding:40px 0}
</style>
</head>
<body>

<?php if (!$authed): ?>
  <div class="login">
    <div class="box">
      <img src="../logo.svg" alt="Troiana" />
      <h1>Troiana Admin</h1>
      <?php if ($setupNeeded): ?>
        <div class="warnbox"><b>Setup needed.</b> Copy <code>config.sample.php</code> to <code>config.php</code> on the server and set a real <code>admin_password</code>, then reload.</div>
      <?php else: ?>
        <p>Sign in to manage the site.</p>
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
      <div class="brand"><img src="../logo.svg" alt="Troiana" /></div>
      <nav class="navtabs">
        <a class="<?= $section==='dashboard'?'on':'' ?>" href="?s=dashboard">Dashboard</a>
        <a class="<?= $section==='messages'?'on':'' ?>" href="?s=messages">Messages<?php if($unread):?><span class="cnt"><?= (int)$unread ?></span><?php endif;?></a>
        <a class="<?= $section==='projects'?'on':'' ?>" href="?s=projects">Projects</a>
        <a class="<?= $section==='services'?'on':'' ?>" href="?s=services">Services</a>
        <a class="<?= $section==='content'?'on':'' ?>" href="?s=content">Site Content</a>
      </nav>
      <div class="right">
        <a class="btn sm" href="../index.html" target="_blank">View site ↗</a>
        <a class="btn sm" href="?logout">Log out</a>
      </div>
    </div>
  </header>

  <main class="wrap">
    <?php if ($notice !== ''): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($warn !== ''): ?><div class="notice warn"><b>Heads up:</b> <?= e($warn) ?></div><?php endif; ?>

    <?php if ($section === 'dashboard'): ?>
      <h1 class="page">Dashboard</h1>
      <p class="sub">Overview of your visitors and messages.</p>
      <div class="cards">
        <div class="stat"><div class="n"><?= number_format($stats['visitors']) ?></div><div class="l">Unique visitors</div></div>
        <div class="stat accent"><div class="n"><?= number_format($stats['visitors_today']) ?></div><div class="l">Visitors today</div></div>
        <div class="stat"><div class="n"><?= number_format($stats['total']) ?></div><div class="l">Total page views</div></div>
        <div class="stat"><div class="n"><?= number_format($stats['today']) ?></div><div class="l">Page views today</div></div>
      </div>
      <div class="panel">
        <h2>Page views — last 14 days</h2>
        <?php $max = max(1, max($stats['series'] ?: [0])); $todayKey = date('Y-m-d'); ?>
        <div class="chart">
          <?php foreach ($stats['series'] as $day => $val): ?>
            <div class="b <?= $day===$todayKey?'today':'' ?>" style="height:<?= (int)round($val / $max * 100) ?>%" title="<?= e($day) ?>: <?= (int)$val ?>"><span><?= e(date('j', strtotime($day))) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="cards" style="grid-template-columns:1fr 1fr">
        <div class="stat"><div class="n"><?= count($content['projects']) ?></div><div class="l">Portfolio projects</div></div>
        <div class="stat"><div class="n"><?= (int)$unread ?> / <?= count($allMsgs) ?></div><div class="l">Unread / total messages</div></div>
      </div>

    <?php elseif ($section === 'messages'): ?>
      <h1 class="page">Messages</h1>
      <p class="sub"><?= count($allMsgs) ?> total · <?= (int)$unread ?> unread</p>
      <form method="GET" class="toolbar">
        <input type="hidden" name="s" value="messages" />
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, text…" style="max-width:280px" />
        <button class="btn" type="submit">Search</button>
        <?php if ($q!==''): ?><a class="btn" href="?s=messages">Clear</a><?php endif; ?>
        <?php if ($unread): ?>
          <form class="inline right" method="POST"><input type="hidden" name="csrf" value="<?= e(token()) ?>" /><input type="hidden" name="act" value="msg_readall" /><button class="btn" type="submit">Mark all read</button></form>
        <?php endif; ?>
      </form>
      <?php if (empty($msgList)): ?>
        <div class="empty"><?= $q!=='' ? 'No messages match your search.' : 'No messages yet. Contact-form submissions will appear here.' ?></div>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
        <input type="hidden" name="act" value="msg_bulk" />
        <div class="toolbar">
          <select name="bulk_action" style="max-width:170px"><option value="">Bulk actions</option><option value="read">Mark read</option><option value="unread">Mark unread</option><option value="delete">Delete</option></select>
          <button class="btn" type="submit">Apply</button>
        </div>
        <table>
          <thead><tr><th style="width:26px"></th><th>From</th><th>Message</th><th style="width:130px">Received</th></tr></thead>
          <tbody>
            <?php foreach ($msgList as $m): $id=(string)($m['id']??''); $u=empty($m['read']); ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= e($id) ?>" style="width:auto" /></td>
              <td>
                <div><?php if($u):?><span class="dot"></span><?php endif;?><b><?= e($m['name']??'—') ?></b></div>
                <a class="muted" href="mailto:<?= e($m['email']??'') ?>" style="font-size:13px"><?= e($m['email']??'') ?></a>
                <?php if(!empty($m['company'])):?><div class="pill"><?= e($m['company']) ?></div><?php endif;?>
                <?php if(!empty($m['project_type'])):?><div class="pill"><?= e($m['project_type']) ?></div><?php endif;?>
              </td>
              <td style="white-space:pre-wrap;color:#dcdce0"><?= e($m['message']??'') ?>
                <div class="row-actions">
                  <button class="btn-link" form="rowform_<?= e($id) ?>" name="act" value="msg_toggle"><?= $u?'Mark read':'Mark unread' ?></button>
                  <a class="btn-link" href="mailto:<?= e($m['email']??'') ?>?subject=Re:%20your%20enquiry%20to%20Troiana" style="color:var(--muted)">Reply</a>
                  <button class="btn-link del" form="rowform_<?= e($id) ?>" name="act" value="msg_delete" onclick="return confirm('Delete this message?')">Delete</button>
                </div>
              </td>
              <td class="muted" style="font-size:12.5px"><?= e(date('M j, Y', strtotime($m['time']??'now'))) ?><br><?= e(date('H:i', strtotime($m['time']??'now'))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </form>
      <?php foreach ($msgList as $m): $id=(string)($m['id']??''); ?>
        <form id="rowform_<?= e($id) ?>" method="POST" style="display:none"><input type="hidden" name="csrf" value="<?= e(token()) ?>" /><input type="hidden" name="id" value="<?= e($id) ?>" /></form>
      <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'projects'): ?>
      <?php if ($editing !== null): ?>
        <h1 class="page"><?= $editIdx==='new'?'Add project':'Edit project' ?></h1>
        <p class="sub"><a href="?s=projects">← Back to projects</a></p>
        <form method="POST" class="panel">
          <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
          <input type="hidden" name="act" value="save_project" />
          <input type="hidden" name="idx" value="<?= e($editIdx) ?>" />
          <div class="two">
            <label class="fld"><span class="lab">Title</span><input type="text" name="title" value="<?= e($editing['title']) ?>" required /></label>
            <label class="fld"><span class="lab">Tag line (e.g. “Full-stack · Web app”)</span><input type="text" name="tag" value="<?= e($editing['tag']) ?>" /></label>
          </div>
          <label class="fld"><span class="lab">Description</span><textarea name="desc"><?= e($editing['desc']) ?></textarea></label>
          <div class="two">
            <label class="fld"><span class="lab">Image URL / path</span><input type="text" name="img" value="<?= e($editing['img']) ?>" /><span class="hint">Local (e.g. images/foo/home.jpg) or a screenshot URL.</span></label>
            <label class="fld"><span class="lab">Link (href)</span><input type="text" name="href" value="<?= e($editing['href']) ?>" /></label>
          </div>
          <div class="two">
            <label class="fld"><span class="lab">Call-to-action label</span><input type="text" name="cta" value="<?= e($editing['cta']) ?>" /></label>
            <label class="fld"><span class="lab">External link</span><span class="chk"><input type="checkbox" name="external" value="1" <?= !empty($editing['external'])?'checked':'' ?> /> Opens in a new tab (offsite)</span></label>
          </div>
          <label class="fld"><span class="lab">Categories (filter tabs)</span>
            <div class="checks">
              <?php foreach (categories() as $k=>$label): ?>
                <label><input type="checkbox" name="cats[]" value="<?= e($k) ?>" <?= in_array($k,(array)$editing['cats'],true)?'checked':'' ?> /> <?= e($label) ?></label>
              <?php endforeach; ?>
            </div>
          </label>
          <button class="btn primary" type="submit">Save & publish</button>
        </form>
      <?php else: ?>
        <div class="toolbar"><h1 class="page" style="margin:0">Projects</h1><div class="right"><a class="btn primary" href="?s=projects&edit=new">+ Add project</a></div></div>
        <p class="sub">These render on the home page and portfolio page. Edits publish instantly.</p>
        <?php if (empty($content['projects'])): ?>
          <div class="empty">No projects yet. <a href="?s=projects&edit=new">Add your first →</a></div>
        <?php else: ?>
        <table>
          <thead><tr><th style="width:78px"></th><th>Project</th><th>Categories</th><th style="width:120px">Order</th></tr></thead>
          <tbody>
            <?php foreach ($content['projects'] as $i=>$p): ?>
            <tr>
              <td><img class="thumb-sm" src="<?= e($p['img']??'') ?>" alt="" loading="lazy" /></td>
              <td>
                <b><?= e($p['title']??'') ?></b> <span class="muted" style="font-size:12px"><?= e($p['tag']??'') ?></span>
                <div class="muted" style="font-size:13px;margin-top:3px"><?= e(excerpt($p['desc']??'',90)) ?></div>
                <div class="row-actions">
                  <a class="btn-link" href="?s=projects&edit=<?= $i ?>">Edit</a>
                  <button class="btn-link del" form="delp_<?= $i ?>" onclick="return confirm('Delete “<?= e(addslashes($p['title']??'')) ?>”?')">Delete</button>
                </div>
              </td>
              <td><?php foreach((array)($p['cats']??[]) as $c):?><span class="pill"><?= e(categories()[$c]??$c) ?></span><?php endforeach;?></td>
              <td>
                <form class="inline" method="POST"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="move_project"/><input type="hidden" name="idx" value="<?= $i ?>"/><input type="hidden" name="dir" value="up"/><button class="btn sm" <?= $i===0?'disabled':'' ?>>↑</button></form>
                <form class="inline" method="POST"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="move_project"/><input type="hidden" name="idx" value="<?= $i ?>"/><input type="hidden" name="dir" value="down"/><button class="btn sm" <?= $i===count($content['projects'])-1?'disabled':'' ?>>↓</button></form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php foreach ($content['projects'] as $i=>$p): ?>
          <form id="delp_<?= $i ?>" method="POST" style="display:none"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="del_project"/><input type="hidden" name="idx" value="<?= $i ?>"/></form>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($section === 'services'): ?>
      <?php if ($editSvc !== null): ?>
        <h1 class="page"><?= $editIdx==='new'?'Add service':'Edit service' ?></h1>
        <p class="sub"><a href="?s=services">← Back to services</a></p>
        <form method="POST" class="panel">
          <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
          <input type="hidden" name="act" value="save_service" />
          <input type="hidden" name="idx" value="<?= e($editIdx) ?>" />
          <label class="fld"><span class="lab">Icon</span>
            <div class="icochoice">
              <?php foreach (icons() as $k=>$svg): ?>
                <label><input type="radio" name="icon" value="<?= e($k) ?>" <?= ($editSvc['icon']??'')===$k?'checked':'' ?> /><span class="ic"><?= $svg ?></span></label>
              <?php endforeach; ?>
            </div>
          </label>
          <label class="fld"><span class="lab">Title</span><input type="text" name="title" value="<?= e($editSvc['title']) ?>" required /></label>
          <label class="fld"><span class="lab">Description</span><textarea name="desc"><?= e($editSvc['desc']) ?></textarea></label>
          <label class="fld"><span class="lab">Bullet points (one per line — shown on the Services page)</span><textarea name="bullets" style="min-height:96px"><?= e(implode("\n",(array)($editSvc['bullets']??[]))) ?></textarea></label>
          <button class="btn primary" type="submit">Save & publish</button>
        </form>
      <?php else: ?>
        <div class="toolbar"><h1 class="page" style="margin:0">Services</h1><div class="right"><a class="btn primary" href="?s=services&edit=new">+ Add service</a></div></div>
        <p class="sub">Shown on the home page and the Services page.</p>
        <?php if (empty($content['services'])): ?>
          <div class="empty">No services yet. <a href="?s=services&edit=new">Add one →</a></div>
        <?php else: ?>
        <table>
          <thead><tr><th style="width:50px"></th><th>Service</th><th style="width:120px">Order</th></tr></thead>
          <tbody>
            <?php foreach ($content['services'] as $i=>$s): ?>
            <tr>
              <td><span class="icochoice" style="pointer-events:none"><span class="ic" style="width:40px;height:40px"><?= icon_svg($s['icon']??'monitor') ?></span></span></td>
              <td>
                <b><?= e($s['title']??'') ?></b>
                <div class="muted" style="font-size:13px;margin-top:3px"><?= e($s['desc']??'') ?></div>
                <div class="row-actions">
                  <a class="btn-link" href="?s=services&edit=<?= $i ?>">Edit</a>
                  <button class="btn-link del" form="dels_<?= $i ?>" onclick="return confirm('Delete this service?')">Delete</button>
                </div>
              </td>
              <td>
                <form class="inline" method="POST"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="move_service"/><input type="hidden" name="idx" value="<?= $i ?>"/><input type="hidden" name="dir" value="up"/><button class="btn sm" <?= $i===0?'disabled':'' ?>>↑</button></form>
                <form class="inline" method="POST"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="move_service"/><input type="hidden" name="idx" value="<?= $i ?>"/><input type="hidden" name="dir" value="down"/><button class="btn sm" <?= $i===count($content['services'])-1?'disabled':'' ?>>↓</button></form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php foreach ($content['services'] as $i=>$s): ?>
          <form id="dels_<?= $i ?>" method="POST" style="display:none"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="del_service"/><input type="hidden" name="idx" value="<?= $i ?>"/></form>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($section === 'content'): ?>
      <h1 class="page">Site Content</h1>
      <p class="sub">Edit the hero, metrics and contact email. Saving republishes the pages.</p>
      <form method="POST" class="panel">
        <input type="hidden" name="csrf" value="<?= e(token()) ?>" />
        <input type="hidden" name="act" value="save_content" />
        <label class="fld"><span class="lab">Hero heading (each line becomes a new line on the page)</span><textarea name="hero_title" style="min-height:70px"><?= e($content['hero']['title']??'') ?></textarea></label>
        <label class="fld"><span class="lab">Hero sub-text</span><textarea name="hero_sub"><?= e($content['hero']['sub']??'') ?></textarea></label>
        <div class="fld"><span class="lab">Metrics (shown under the hero)</span>
          <?php $ms = $content['metrics']; $ms[] = ['n'=>'','l'=>'']; foreach ($ms as $mrow): ?>
            <div class="metric-row"><input type="text" name="metric_n[]" value="<?= e($mrow['n']??'') ?>" placeholder="50+" /><input type="text" name="metric_l[]" value="<?= e($mrow['l']??'') ?>" placeholder="Projects shipped" /></div>
          <?php endforeach; ?>
          <span class="hint">Leave a row blank to remove it. The last empty row lets you add another.</span>
        </div>
        <label class="fld" style="max-width:360px"><span class="lab">Contact email</span><input type="email" name="contact_email" value="<?= e($content['contact']['email']??'') ?>" /></label>
        <button class="btn primary" type="submit">Save & publish</button>
      </form>
    <?php endif; ?>
  </main>
  <footer>
    Troiana admin · content saved to <code>data/</code> on your server.
    <form class="inline" method="POST" style="margin-left:8px"><input type="hidden" name="csrf" value="<?= e(token()) ?>"/><input type="hidden" name="act" value="republish"/><button class="btn-link" type="submit">Re-publish site</button></form>
  </footer>
<?php endif; ?>

</body>
</html>
