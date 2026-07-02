<?php
/**
 * submit.php — receives the contact form, stores it to data/messages.json,
 * and emails a backup copy. Redirects back to contact.html.
 */
$cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$notify = $cfg['notify_email'] ?? 'hello@troiana.net';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: contact.html');
    exit;
}

// Honeypot — bots fill hidden fields; silently accept and drop.
if (!empty($_POST['_honey'])) {
    header('Location: contact.html?sent=1');
    exit;
}

function field($k) { return trim((string)($_POST[$k] ?? '')); }
$name    = field('name');
$email   = field('email');
$company = field('company');
$ptype   = field('project_type');
$message = field('message');

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: contact.html?error=1#form');
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }

// Block direct web access to the data folder.
$ht = $dataDir . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}

$entry = [
    'id'           => uniqid('m_', true),
    'time'         => date('c'),
    'name'         => mb_substr($name, 0, 200),
    'email'        => mb_substr($email, 0, 200),
    'company'      => mb_substr($company, 0, 200),
    'project_type' => mb_substr($ptype, 0, 100),
    'message'      => mb_substr($message, 0, 5000),
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
    'read'         => false,
];

// Append under an exclusive lock.
$file = $dataDir . '/messages.json';
$fp = fopen($file, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $messages = json_decode($raw, true);
    if (!is_array($messages)) { $messages = []; }
    $messages[] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Best-effort email backup (won't block the save if mail() is disabled).
$subject = 'New enquiry from web.troiana.net';
$body  = "Name: {$name}\nEmail: {$email}\nCompany: {$company}\nProject type: {$ptype}\n\n{$message}\n";
$headers = "From: website@troiana.net\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
@mail($notify, $subject, $body, $headers);

header('Location: contact.html?sent=1');
exit;
