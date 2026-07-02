<?php
/**
 * admin/lib.php — shared content model + renderers + publisher for the
 * Troiana admin. The public site stays static HTML: editing here saves to
 * data/content.json, and "Publish" regenerates the marked-off sections of
 * the .html files (a .bak backup is written before each file is touched).
 */

define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('CONTENT_FILE', DATA_DIR . '/content.json');
define('MESSAGES_FILE', DATA_DIR . '/messages.json');
define('VISITS_FILE', DATA_DIR . '/visits.json');

/* ---------- generic helpers ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ensure_data_dir() {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
}

function read_json($file, $fallback = []) {
    if (!file_exists($file)) return $fallback;
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : $fallback;
}

function write_json($file, $data) {
    ensure_data_dir();
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN); fclose($fp);
    return true;
}

/* ---------- content ---------- */
function default_content() {
    return [
        'hero'     => ['title' => "We build the web\nyour business deserves.", 'sub' => ''],
        'metrics'  => [],
        'contact'  => ['email' => 'hello@troiana.net'],
        'services' => [],
        'projects' => [],
    ];
}

function load_content() {
    $c = read_json(CONTENT_FILE, []);
    $c = array_replace_recursive(default_content(), is_array($c) ? $c : []);
    // Normalise arrays (array_replace_recursive merges list keys oddly if empty)
    foreach (['metrics', 'services', 'projects'] as $k) {
        if (!isset($c[$k]) || !is_array($c[$k])) $c[$k] = [];
        else $c[$k] = array_values($c[$k]);
    }
    return $c;
}

function save_content($c) { return write_json(CONTENT_FILE, $c); }

/* ---------- categories + icons ---------- */
function categories() {
    return [
        'webdev'    => 'Web Development',
        'webapp'    => 'Web App Development',
        'wordpress' => 'WordPress Web',
        'ecommerce' => 'E-Commerce',
        'web3'      => 'Web3',
    ];
}

/** Icon key => full <svg> markup (24x24, stroked to match the site). */
function icons() {
    return [
        'monitor' => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="1.5"/><path d="M8 21h8M12 17v4"/></svg>',
        'gear'    => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg>',
        'cart'    => '<svg viewBox="0 0 24 24"><path d="M4 5h2l1.5 11h10L20 8H7"/><circle cx="9" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/></svg>',
        'smiley'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="9.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.1" fill="currentColor" stroke="none"/><path d="M12 21a3 3 0 0 0 0-6 2 2 0 0 1 0-4"/></svg>',
        'bolt'    => '<svg viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
        'wrench'  => '<svg viewBox="0 0 24 24"><path d="M14.5 5.5a3.5 3.5 0 0 0-4.9 4.9l-6 6 2 2 6-6a3.5 3.5 0 0 0 4.9-4.9l-2.2 2.2-2-2z"/></svg>',
        'shield'  => '<svg viewBox="0 0 24 24"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6z"/><path d="M9.5 12l1.8 1.8 3.5-3.6"/></svg>',
        'globe'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 6 3.5 9S14.5 18.5 12 21C9.5 18.5 8.5 15 8.5 12S9.5 5.5 12 3z"/></svg>',
        'chat'    => '<svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-4 4V5z"/></svg>',
        'chart'   => '<svg viewBox="0 0 24 24"><path d="M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6"/></svg>',
    ];
}
function icon_svg($key) { $i = icons(); return $i[$key] ?? reset($i); }

/* ---------- renderers (must mirror the site's card markup) ---------- */
function render_project($p) {
    $cats  = implode(' ', array_map('strval', (array)($p['cats'] ?? [])));
    $ext   = !empty($p['external']) ? ' target="_blank" rel="noopener"' : '';
    return
      '<a class="proj reveal" data-cat="' . e($cats) . '" href="' . e($p['href'] ?? '#') . '"' . $ext . '>' . "\n" .
      '          <div class="thumb"><img loading="lazy" src="' . e($p['img'] ?? '') . '" alt="' . e($p['title'] ?? '') . '" /></div>' . "\n" .
      '          <div class="body">' . "\n" .
      '            <span class="tag">' . e($p['tag'] ?? '') . '</span>' . "\n" .
      '            <h3>' . e($p['title'] ?? '') . ' <span class="arrow">↗</span></h3>' . "\n" .
      '            <p>' . e($p['desc'] ?? '') . '</p>' . "\n" .
      '            <div class="url">' . e($p['cta'] ?? 'View →') . '</div>' . "\n" .
      '          </div>' . "\n" .
      '        </a>';
}

function render_projects($projects) {
    $out = [];
    foreach ($projects as $p) $out[] = render_project($p);
    return "\n        " . implode("\n        ", $out) . "\n      ";
}

/** Home-page compact service card (.card). */
function render_service_card($s) {
    return '<div class="card reveal"><div class="ico">' . icon_svg($s['icon'] ?? 'monitor') . '</div>'
         . '<h3>' . e($s['title'] ?? '') . '</h3><p>' . e($s['desc'] ?? '') . '</p></div>';
}
function render_services_cards($services) {
    $out = [];
    foreach ($services as $s) $out[] = render_service_card($s);
    return "\n        " . implode("\n        ", $out) . "\n      ";
}

/** Services-page detailed card (.svc with bullet list). */
function render_service_full($s) {
    $lis = '';
    foreach ((array)($s['bullets'] ?? []) as $b) {
        $b = trim((string)$b);
        if ($b !== '') $lis .= '<li>' . e($b) . '</li>';
    }
    return '<div class="svc reveal">' . "\n"
         . '        <div class="ico">' . icon_svg($s['icon'] ?? 'monitor') . '</div>' . "\n"
         . '        <h3>' . e($s['title'] ?? '') . '</h3>' . "\n"
         . '        <p>' . e($s['desc'] ?? '') . '</p>' . "\n"
         . ($lis !== '' ? '        <ul>' . $lis . '</ul>' . "\n" : '')
         . '      </div>';
}
function render_services_full($services) {
    $out = [];
    foreach ($services as $s) $out[] = render_service_full($s);
    return "\n      " . implode("\n      ", $out) . "\n    ";
}

function render_metrics($metrics) {
    $out = [];
    foreach ($metrics as $m) {
        $out[] = '<div class="metric reveal"><div class="n">' . e($m['n'] ?? '') . '</div>'
               . '<div class="l">' . e($m['l'] ?? '') . '</div></div>';
    }
    return "\n        " . implode("\n        ", $out) . "\n      ";
}

function render_hero_title($title) {
    $lines = array_map('trim', preg_split('/\r\n|\r|\n/', (string)$title));
    return implode('<br/>', array_map('e', $lines));
}

/* ---------- marker-based publisher ---------- */
/**
 * Replace the content between <!-- @KEY --> and <!-- @/KEY --> in $html.
 * Returns [newHtml, replaced?]. If markers are missing, $html is untouched.
 */
function replace_marker($html, $key, $inner) {
    $start = '<!-- @' . $key . ' -->';
    $end   = '<!-- @/' . $key . ' -->';
    $pattern = '/(' . preg_quote($start, '/') . ').*?(' . preg_quote($end, '/') . ')/s';
    $count = 0;
    $new = preg_replace_callback($pattern, function ($m) use ($inner) {
        return $m[1] . $inner . $m[2];
    }, $html, -1, $count);
    return [$new === null ? $html : $new, $count > 0];
}

/**
 * Apply a set of [KEY => inner] replacements to one file, backing it up first.
 * Returns a list of human-readable warnings (missing markers).
 */
function publish_file($relPath, $replacements, &$warnings) {
    $path = ROOT_DIR . '/' . $relPath;
    if (!is_file($path)) { $warnings[] = "$relPath not found — skipped."; return; }
    $html = (string)file_get_contents($path);
    $orig = $html;
    foreach ($replacements as $key => $inner) {
        [$html, $ok] = replace_marker($html, $key, $inner);
        if (!$ok) $warnings[] = "$relPath: marker @$key not found — that section was left unchanged.";
    }
    if ($html !== $orig) {
        @copy($path, $path . '.bak');
        @file_put_contents($path, $html);
    }
}

/** Regenerate every managed section across the site from $content. */
function publish_all($content) {
    $warnings = [];
    $email = trim((string)($content['contact']['email'] ?? 'hello@troiana.net'));

    publish_file('index.html', [
        'HERO_TITLE'    => render_hero_title($content['hero']['title'] ?? ''),
        'HERO_SUB'      => e($content['hero']['sub'] ?? ''),
        'METRICS'       => render_metrics($content['metrics']),
        'SERVICES'      => render_services_cards($content['services']),
        'PROJECTS'      => render_projects($content['projects']),
        'CTA_EMAIL'     => '<a class="btn btn-primary" href="mailto:' . e($email) . '">' . e($email) . '</a>',
    ], $warnings);

    publish_file('services.html', [
        'SERVICES_FULL' => render_services_full($content['services']),
    ], $warnings);

    publish_file('portfolio.html', [
        'PROJECTS'      => render_projects($content['projects']),
    ], $warnings);

    publish_file('contact.html', [
        'INFO_EMAIL'    => '<a href="mailto:' . e($email) . '">' . e($email) . '</a>',
    ], $warnings);

    return $warnings;
}

/* ---------- visits ---------- */
function visit_stats() {
    $v = read_json(VISITS_FILE, []);
    $total = (int)($v['total'] ?? 0);
    $days  = is_array($v['days'] ?? null) ? $v['days'] : [];
    $today = $days[date('Y-m-d')] ?? 0;
    // last 7 days sum
    $week = 0;
    for ($i = 0; $i < 7; $i++) { $week += (int)($days[date('Y-m-d', strtotime("-$i days"))] ?? 0); }
    // trailing 14-day series for a mini chart
    $series = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[$d] = (int)($days[$d] ?? 0);
    }
    $uniqDays  = is_array($v['uniq_days'] ?? null) ? $v['uniq_days'] : [];
    $visitors  = (int)($v['uniq_total'] ?? 0);
    $visToday  = (int)($uniqDays[date('Y-m-d')] ?? 0);
    return [
        'total' => $total, 'today' => (int)$today, 'week' => $week, 'series' => $series,
        'visitors' => $visitors, 'visitors_today' => $visToday,
    ];
}
