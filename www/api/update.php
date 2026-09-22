<?php
/**
 * Update manifest for the desktop applications.
 *
 * GET update.php?project=<name>&channel=release|debug
 *     [&format=text|json] [&version=<pinned>] [&list=1]
 *
 * One endpoint, not one per product: a new application is a new folder under
 * project/<channel>/, and nothing here changes. Four URLs still exist --
 * E2pRom_Generator and SOICamConfig, release and debug -- they are just four
 * sets of parameters.
 *
 * NO AUTHENTICATION, deliberately. The applications ask for their manifest
 * with no headers at all (WinINet InternetOpenUrl, no custom headers), so any
 * key would have to travel in the URL, where it sits in every installed copy
 * and is not a secret. It also would not protect anything: the packages are
 * static files under project/, which Apache serves to whoever knows the path.
 * Not being linked from anywhere is the whole of the protection, and that is a
 * decision about a test server, not a security design. If real internal builds
 * ever live here, move project/debug/ out of the document root and stream it
 * from PHP -- half-measures on top of a public directory buy nothing.
 *
 * This endpoint does NOT include bootstrap.php: that enforces the API key and
 * opens a database connection, and this needs neither.
 *
 * -- Response, text form (the default; what the applications parse) ----------
 *   line 1  latest version, dotted
 *   line 2  absolute URL of the package
 *   line 3  release notes, single line
 *   line 4  SHA-256 of the package, hex
 *
 * Line 3 is never empty: the client's parser drops blank lines, so an empty
 * notes line would silently shift the checksum up into the notes.
 *
 * Nothing published for that project/channel answers 404, with a body whose
 * first line is 0.0.0 -- every client then compares it against its own version
 * and concludes, correctly, that there is no update.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

const PROJECT_ROOT = __DIR__ . '/../project';
const CHANNELS = ['release', 'debug'];

// A package is "<anything>-<version>[-setup].exe|zip". The version in the file
// name is the only place a version is recorded: an index file that has to be
// edited alongside every upload is an index file that will one day be wrong.
const PACKAGE_PATTERN = '/^(?<stem>.+)-(?<version>\d+(?:\.\d+){0,3})(?<suffix>-setup)?\.(?<ext>exe|zip)$/i';

function fail(string $message, int $status, string $format): void {
    http_response_code($status);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        // 0.0.0 rather than an error page: the applications parse whatever comes
        // back, and a version older than any real one is read as "nothing new".
        echo "0.0.0\n\n" . $message . "\n";
    }
    exit;
}

/** Compares dotted versions numerically: 1.0.10 is newer than 1.0.9. */
function compareVersions(string $a, string $b): int {
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    $n = max(count($pa), count($pb));
    for ($i = 0; $i < $n; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x < $y ? -1 : 1;
    }
    return 0;
}

/**
 * SHA-256 of the package, cached next to it as <file>.sha256.
 *
 * Hashing 8 MB on every check would be paid by every installed copy on every
 * start-up. The cache is written when the directory allows it and simply
 * recomputed when it does not, so an upload never has to remember to make one.
 */
function packageSha256(string $path): string {
    $cache = $path . '.sha256';
    if (is_file($cache) && filemtime($cache) >= filemtime($path)) {
        $cached = trim((string) file_get_contents($cache));
        if (preg_match('/^[0-9a-f]{64}$/i', $cached)) {
            return strtolower($cached);
        }
    }
    $digest = hash_file('sha256', $path);
    if ($digest === false) return '';
    @file_put_contents($cache, $digest . "\n");
    return $digest;
}

/** Every published package in a directory, newest first. */
function listPackages(string $dir): array {
    $found = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . '/' . $entry;
        if (!is_file($full)) continue;
        if (!preg_match(PACKAGE_PATTERN, $entry, $m)) continue;
        $found[] = [
            'file'    => $entry,
            'version' => $m['version'],
            'size'    => filesize($full) ?: 0,
            'mtime'   => date('Y-m-d H:i:s', filemtime($full) ?: 0),
        ];
    }
    usort($found, fn($a, $b) => compareVersions($b['version'], $a['version']));
    return $found;
}

/**
 * Release notes for a package: the file next to it with the same name and a
 * .txt extension. Missing, the notes fall back to naming the release -- line 3
 * must not be empty, see the note at the top.
 */
function releaseNotes(string $dir, string $file, string $project, string $version): string {
    $notesFile = $dir . '/' . preg_replace('/\.(exe|zip)$/i', '.txt', $file);
    if (is_file($notesFile)) {
        $text = trim((string) file_get_contents($notesFile));
        if ($text !== '') {
            // One line: the manifest is line-based, and a client reading line 4
            // as the checksum would otherwise read the second line of notes.
            return trim(preg_replace('/\s+/u', ' ', $text));
        }
    }
    return $project . ' ' . $version;
}

/** Absolute URL of a published package, derived from the request. */
function packageUrl(string $channel, string $project, string $file): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // This script lives in <base>/api/, the packages in <base>/project/.
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/update.php')), '/');
    return $scheme . '://' . $host . $base . '/project/' . $channel . '/' .
           rawurlencode($project) . '/' . rawurlencode($file);
}

// -- request ---------------------------------------------------------------

$format = (($_GET['format'] ?? 'text') === 'json') ? 'json' : 'text';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('Method not allowed', 405, $format);
}

$project = (string) ($_GET['project'] ?? '');
$channel = strtolower((string) ($_GET['channel'] ?? 'release'));

// The project name becomes a path segment, so it is checked rather than
// trusted: no separators, no dots leading anywhere. strpos() rather than
// str_contains(): this deployment runs PHP 7.4, where str_contains does not
// exist and the whole request dies as a blank 500.
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $project) || strpos($project, '..') !== false) {
    fail('Invalid or missing project', 400, $format);
}
if (!in_array($channel, CHANNELS, true)) {
    fail('Invalid channel: use ' . implode(' or ', CHANNELS), 400, $format);
}

$dir = PROJECT_ROOT . '/' . $channel . '/' . $project;
if (!is_dir($dir)) {
    fail("No packages published for {$project} on {$channel}", 404, $format);
}

$packages = listPackages($dir);
if (!$packages) {
    fail("No packages published for {$project} on {$channel}", 404, $format);
}

// ?list=1 -- everything published, for a person deciding what to roll back to.
if (isset($_GET['list'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'project'  => $project,
            'channel'  => $channel,
            'versions' => array_map(fn($p) => [
                'version' => $p['version'],
                'file'    => $p['file'],
                'size'    => $p['size'],
                'built'   => $p['mtime'],
                'url'     => packageUrl($channel, $project, $p['file']),
            ], $packages),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ?version=1.0.1 pins one, for a deliberate downgrade; otherwise the newest.
$wanted = (string) ($_GET['version'] ?? '');
$chosen = null;
if ($wanted !== '') {
    if (!preg_match('/^\d+(\.\d+){0,3}$/', $wanted)) {
        fail('Invalid version', 400, $format);
    }
    foreach ($packages as $p) {
        if (compareVersions($p['version'], $wanted) === 0) { $chosen = $p; break; }
    }
    if (!$chosen) {
        fail("Version {$wanted} is not published for {$project} on {$channel}", 404, $format);
    }
} else {
    $chosen = $packages[0];
}

$url    = packageUrl($channel, $project, $chosen['file']);
$notes  = releaseNotes($dir, $chosen['file'], $project, $chosen['version']);
$sha256 = packageSha256($dir . '/' . $chosen['file']);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => [
            'project'     => $project,
            'channel'     => $channel,
            'version'     => $chosen['version'],
            'url'         => $url,
            'notes'       => $notes,
            'sha256'      => $sha256,
            'size'        => $chosen['size'],
            'built'       => $chosen['mtime'],
            'file'        => $chosen['file'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo $chosen['version'] . "\n" . $url . "\n" . $notes . "\n" . $sha256 . "\n";
