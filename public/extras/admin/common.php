<?php
// Shared by the Extras admin page (index.php) and the font converter's
// endpoint (convert.php): paths, the catalog, and file checks.

declare(strict_types=1);

const EXTRAS_DIR = __DIR__ . '/..';
const FILES_DIR = EXTRAS_DIR . '/files';
const CATALOG_PATH = EXTRAS_DIR . '/catalog.json';
const CONFIG_PATH = __DIR__ . '/config.php';
const THROTTLE_PATH = __DIR__ . '/.login-throttle.json';
const PREVIEW_WIDTH = 360;
const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
const MAX_FONT_BYTES = 8 * 1024 * 1024;
const TYPES = [
    'wallpaper' => ['label' => 'Wallpaper', 'exts' => ['jpg', 'jpeg', 'png', 'webp', 'bmp']],
    'overlay' => ['label' => 'Overlay', 'exts' => ['png']],
    'font' => ['label' => 'Font', 'exts' => ['ttf', 'otf', 'cpfont']],
];
const CONVERSIONS_PATH = __DIR__ . '/conversions.json';

// config.php is written by the deploy: the admin password hash, and the
// GitHub token that starts font conversions (both repository secrets).
function config(): array
{
    static $config = null;
    if ($config === null) {
        $loaded = is_file(CONFIG_PATH) ? include CONFIG_PATH : null;
        $config = is_array($loaded) ? $loaded : [];
    }
    return $config;
}

function config_hash(): ?string
{
    return !empty(config()['hash']) ? (string)config()['hash'] : null;
}

function github_token(): ?string
{
    return !empty(config()['github_token']) ? (string)config()['github_token'] : null;
}

function empty_catalog(): array
{
    return ['format' => 'quartzink-extras', 'version' => 1, 'updated' => gmdate('c'), 'items' => []];
}

function read_catalog(): array
{
    if (!is_file(CATALOG_PATH)) {
        return empty_catalog();
    }
    $data = json_decode((string)file_get_contents(CATALOG_PATH), true);
    return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data : empty_catalog();
}

// Runs $change on the catalog under a lock and writes it back in one step, so
// a reader never sees half a file and two uploads never lose each other.
function update_catalog(callable $change): void
{
    $lock = fopen(CATALOG_PATH . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the catalog.');
    }
    try {
        $catalog = read_catalog();
        $catalog['items'] = array_values($change($catalog['items']));
        $catalog['updated'] = gmdate('c');
        $tmp = CATALOG_PATH . '.tmp';
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, CATALOG_PATH)) {
            throw new RuntimeException('Could not save the catalog.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Names the device and the web can both use: letters, numbers, space, - _ ( ) .
function safe_file_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string)preg_replace('/[^A-Za-z0-9 _\-().]+/', '_', $name);
    $name = trim($name, ". \t");
    return $name !== '' ? substr($name, 0, 100) : 'file';
}

function ext_of(string $name): string
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function ensure_files_dir(): void
{
    if (!is_dir(FILES_DIR) && !mkdir(FILES_DIR, 0755, true)) {
        throw new RuntimeException('Could not create the files folder.');
    }
    // Uploaded files are only ever downloaded, never run.
    $guard = FILES_DIR . '/.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|html?|svg|js)$\">\n  Require all denied\n</FilesMatch>\n");
    }
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? remove_dir($path) : unlink($path);
    }
    rmdir($dir);
}

function catalog_url(string $id, string $file): string
{
    return 'files/' . rawurlencode($id) . '/' . rawurlencode($file);
}

// Checks the bytes, not just the name: a font must start with a font signature.
function font_signature_ok(string $path, string $ext): bool
{
    $head = (string)file_get_contents($path, false, null, 0, 8);
    if ($ext === 'cpfont') {
        return strncmp($head, "CPFONT\0", 7) === 0;
    }
    $tag = substr($head, 0, 4);
    return $tag === "\x00\x01\x00\x00" || $tag === 'true' || $tag === 'OTTO';
}

// ---- Font conversion ----------------------------------------------------------
//
// The X3 can't load TTF/OTF fonts, only .cpfont files. Starting a conversion
// asks GitHub to run .github/workflows/convert-fonts.yml, which fetches the
// font through convert.php, runs CrossPoint's own converter, and sends the
// .cpfont files back to convert.php. Each conversion has a job id; the job
// proves it was started here with an HMAC of that id keyed by the GitHub token,
// which only this server and the workflow's secrets hold.

const CONVERT_REPO = 'riseordie-85/QuartzSite';
const CONVERT_WORKFLOW = 'convert-fonts.yml';
const CONVERT_STALE_SECONDS = 20 * 60;

// conversions.json: item id => ['job', 'state' (running|done|failed), 'at', 'message'].
function read_conversions(): array
{
    $data = is_file(CONVERSIONS_PATH) ? json_decode((string)file_get_contents(CONVERSIONS_PATH), true) : null;
    return is_array($data) ? $data : [];
}

function update_conversions(callable $change)
{
    $lock = fopen(CONVERSIONS_PATH . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the conversion list.');
    }
    try {
        $conversions = read_conversions();
        $result = $change($conversions);
        $tmp = CONVERSIONS_PATH . '.tmp';
        if (file_put_contents($tmp, json_encode($conversions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !rename($tmp, CONVERSIONS_PATH)) {
            throw new RuntimeException('Could not save the conversion list.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function font_sources(array $item): array
{
    return array_values(array_filter($item['files'], function ($file) {
        return in_array(ext_of($file['name']), ['ttf', 'otf'], true);
    }));
}

function job_signature(string $job): ?string
{
    $token = github_token();
    return $token === null ? null : hash_hmac('sha256', $job, $token);
}

// Starts converting a font item's TTF/OTF files to .cpfont on GitHub.
function start_conversion(array $item): void
{
    $token = github_token();
    if ($token === null) {
        throw new RuntimeException('Conversion is not set up: add the EXTRAS_GITHUB_TOKEN repository secret, then run the deploy again.');
    }
    if (($item['type'] ?? '') !== 'font' || !font_sources($item)) {
        throw new RuntimeException('Only fonts with a TTF or OTF file can be converted.');
    }
    $job = $item['id'] . '.' . bin2hex(random_bytes(8));
    update_conversions(function (array &$conversions) use ($item, $job) {
        $conversions[$item['id']] = ['job' => $job, 'state' => 'running', 'at' => time(), 'message' => ''];
    });

    $ch = curl_init('https://api.github.com/repos/' . CONVERT_REPO . '/actions/workflows/' . CONVERT_WORKFLOW . '/dispatches');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['ref' => 'main', 'inputs' => ['job' => $job]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'quartz.timcordova.com extras',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 204 && $status !== 200) {
        $detail = is_string($body) ? (json_decode($body, true)['message'] ?? '') : '';
        $message = 'GitHub refused to start the conversion (' . $status . ($detail !== '' ? ': ' . $detail : '') . ').';
        finish_conversion($item['id'], $job, 'failed', $message);
        throw new RuntimeException($message);
    }
}

// Records the end of a conversion, if $job is still the item's current one.
function finish_conversion(string $id, string $job, string $state, string $message = ''): bool
{
    return update_conversions(function (array &$conversions) use ($id, $job, $state, $message) {
        if (($conversions[$id]['job'] ?? null) !== $job) {
            return false;
        }
        $conversions[$id] = ['job' => $job, 'state' => $state, 'at' => time(), 'message' => $message];
        return true;
    });
}
