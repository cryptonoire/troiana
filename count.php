<?php
/**
 * count.php — tiny visitor-counter beacon. Public pages ping this once on
 * load; it records page views + unique daily visitors into data/visits.json
 * and returns a 1x1 transparent GIF. No cookies of personal data — just an
 * anonymous "last seen day" marker to dedupe unique visitors per day.
 */
require __DIR__ . '/admin/lib.php';

// Ignore obvious bots so the count reflects real people.
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|pingdom|uptime/', $ua);

if (!$isBot) {
    $today = date('Y-m-d');
    $v = read_json(VISITS_FILE, []);
    $v['total'] = (int)($v['total'] ?? 0) + 1;
    if (!isset($v['days']) || !is_array($v['days'])) $v['days'] = [];
    $v['days'][$today] = (int)($v['days'][$today] ?? 0) + 1;

    // Unique visitor per day, tracked via an anonymous last-seen cookie.
    $seen = $_COOKIE['tv_seen'] ?? '';
    if ($seen !== $today) {
        $v['uniq_total'] = (int)($v['uniq_total'] ?? 0) + 1;
        if (!isset($v['uniq_days']) || !is_array($v['uniq_days'])) $v['uniq_days'] = [];
        $v['uniq_days'][$today] = (int)($v['uniq_days'][$today] ?? 0) + 1;
        @setcookie('tv_seen', $today, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
    }

    // Keep the day maps from growing forever — retain ~120 days.
    foreach (['days', 'uniq_days'] as $k) {
        if (count($v[$k] ?? []) > 200) {
            krsort($v[$k]);
            $v[$k] = array_slice($v[$k], 0, 120, true);
        }
    }

    write_json(VISITS_FILE, $v);
}

// 1x1 transparent GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
