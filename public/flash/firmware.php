<?php
// Serves QuartzInk firmware to the flasher page (/flash/).
//
// Browsers can't download GitHub release files directly (GitHub sends no
// CORS headers), so this fetches them on the server and serves them from
// this site. It reads the "latest-build" release, which QuartzInk's CI
// replaces on every push to main, and keeps one cached copy per device.
//
//   firmware.php                 JSON: the build (commit, date) and each device's file and size
//   firmware.php?device=picco    the .bin (x3-x4, x4-pro or picco)
//
// Written for PHP 7.4 and newer.

declare(strict_types=1);

const REPO = 'riseordie-85/QuartzInk';
const RELEASE_TAG = 'latest-build';
const DEVICES = ['x3-x4', 'x4-pro', 'picco'];
const CACHE_DIR = __DIR__ . '/cache';
const RELEASE_TTL = 300; // seconds between GitHub checks (its anonymous limit is 60 an hour)
const MAX_BIN_BYTES = 16 * 1024 * 1024;

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}

function http_get(string $url, bool $json)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'quartz.timcordova.com flasher',
        CURLOPT_HTTPHEADER => [$json ? 'Accept: application/vnd.github+json' : 'Accept: application/octet-stream'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $status === 200) ? $body : null;
}

function ensure_cache_dir(): void
{
    if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) {
        fail(500, 'The server could not create its firmware cache.');
    }
    $guard = CACHE_DIR . '/.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Require all denied\n");
    }
}

// The release, from GitHub at most every RELEASE_TTL seconds.
function release(): array
{
    $path = CACHE_DIR . '/release.json';
    if (is_file($path) && time() - filemtime($path) < RELEASE_TTL) {
        $cached = json_decode((string)file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $body = http_get('https://api.github.com/repos/' . REPO . '/releases/tags/' . RELEASE_TAG, true);
    $data = $body !== null ? json_decode($body, true) : null;
    if (!is_array($data) || empty($data['assets'])) {
        // GitHub unreachable or rate-limited: an older answer beats none.
        $stale = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (is_array($stale)) {
            return $stale;
        }
        fail(502, 'Could not read the latest QuartzInk build from GitHub. Try again in a minute.');
    }
    $release = [
        'commit' => substr((string)($data['target_commitish'] ?? ''), 0, 7),
        'title' => (string)($data['name'] ?? ''),
        'notes' => (string)($data['body'] ?? ''),
        'published' => (string)($data['published_at'] ?? ''),
        'files' => [],
    ];
    foreach ($data['assets'] as $asset) {
        if (preg_match('/^quartzink-([a-z0-9-]+)\.bin$/', (string)$asset['name'], $m) && in_array($m[1], DEVICES, true)) {
            $release['files'][$m[1]] = [
                'id' => (int)$asset['id'],
                'name' => (string)$asset['name'],
                'size' => (int)$asset['size'],
                'url' => (string)$asset['browser_download_url'],
                'updated' => (string)$asset['updated_at'],
            ];
        }
    }
    file_put_contents($path . '.tmp', json_encode($release));
    rename($path . '.tmp', $path);
    return $release;
}

ensure_cache_dir();
$release = release();
$device = (string)($_GET['device'] ?? '');

if ($device === '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $files = [];
    foreach ($release['files'] as $key => $file) {
        $files[$key] = ['name' => $file['name'], 'size' => $file['size']];
    }
    echo json_encode([
        'commit' => $release['commit'],
        'title' => $release['title'],
        'notes' => $release['notes'],
        'published' => $release['published'],
        'files' => $files,
    ]);
    exit;
}

if (!in_array($device, DEVICES, true)) {
    fail(404, 'Unknown device.');
}
$file = $release['files'][$device] ?? null;
if ($file === null) {
    fail(404, 'The latest build has no firmware for this device.');
}

// One cached copy per device, named for the asset it came from.
$cached = CACHE_DIR . '/' . $device . '-' . $file['id'] . '.bin';
if (!is_file($cached) || filesize($cached) !== $file['size']) {
    $bin = http_get($file['url'], false);
    if ($bin === null || strlen($bin) !== $file['size'] || strlen($bin) > MAX_BIN_BYTES || ord($bin[0]) !== 0xE9) {
        fail(502, 'Could not download the firmware from GitHub. Try again in a minute.');
    }
    file_put_contents($cached . '.tmp', $bin);
    rename($cached . '.tmp', $cached);
    foreach (glob(CACHE_DIR . '/' . $device . '-*.bin') ?: [] as $old) {
        if ($old !== $cached) {
            unlink($old);
        }
    }
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($cached));
header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
header('Cache-Control: no-store');
header('X-QuartzInk-Commit: ' . $release['commit']);
readfile($cached);
