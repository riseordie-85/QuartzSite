<?php
// QuartzInk Extras: the upload page for wallpapers, overlays and fonts.
//
// Everything it adds lives on the server, not in the repo: files go to
// /extras/files/<id>/ and the list to /extras/catalog.json, which the public
// /extras/ page and QuartzInk dashboards read. The deploy only replaces files
// it uploaded itself, so these survive every deploy. Back them up now and then.
//
// The password comes from the EXTRAS_ADMIN_PASSWORD repository secret: the
// deploy writes its hash to config.php. Written for PHP 7.4 and newer.

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

session_set_cookie_params([
    'path' => '/extras/admin/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $message, bool $error = false): void
{
    $_SESSION['flash'] = ['message' => $message, 'error' => $error];
}

function redirect_self(): void
{
    header('Location: ./', true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('The form expired. Go back and try again.');
    }
}

// ---- Login ------------------------------------------------------------------

function config_hash(): ?string
{
    if (!is_file(CONFIG_PATH)) {
        return null;
    }
    $config = include CONFIG_PATH;
    return is_array($config) && !empty($config['hash']) ? (string)$config['hash'] : null;
}

// Slows password guessing: after 5 wrong tries from one address, 15 minutes off.
function throttle_read(): array
{
    $data = is_file(THROTTLE_PATH) ? json_decode((string)file_get_contents(THROTTLE_PATH), true) : null;
    return is_array($data) ? $data : [];
}

function login_blocked(string $ip): bool
{
    $entry = throttle_read()[$ip] ?? null;
    return $entry && $entry['count'] >= 5 && time() - $entry['time'] < 900;
}

function note_login_failure(string $ip): void
{
    $data = throttle_read();
    foreach ($data as $key => $entry) {
        if (time() - $entry['time'] >= 900) {
            unset($data[$key]);
        }
    }
    $count = ($data[$ip]['count'] ?? 0) + 1;
    $data[$ip] = ['count' => $count, 'time' => time()];
    file_put_contents(THROTTLE_PATH, json_encode($data), LOCK_EX);
}

// ---- Catalog ----------------------------------------------------------------

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

// ---- Files ------------------------------------------------------------------

function slugify(string $text): string
{
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? substr($slug, 0, 48) : 'item';
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

function load_image(string $path)
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($path) ?: null;
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($path) ?: null;
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null;
        case IMAGETYPE_BMP:
            return function_exists('imagecreatefrombmp') ? (@imagecreatefrombmp($path) ?: null) : null;
    }
    return null;
}

// A small preview for the gallery. Overlays keep their transparency (PNG);
// wallpapers become a JPEG. Returns the preview's file name, or null.
function make_image_preview(string $src, string $dir, bool $keepAlpha): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $image = load_image($src);
    if (!$image) {
        return null;
    }
    $w = imagesx($image);
    $h = imagesy($image);
    $pw = min(PREVIEW_WIDTH, $w);
    $ph = max(1, (int)round($h * $pw / $w));
    $preview = imagecreatetruecolor($pw, $ph);
    if ($keepAlpha) {
        imagealphablending($preview, false);
        imagesavealpha($preview, true);
        imagefill($preview, 0, 0, imagecolorallocatealpha($preview, 0, 0, 0, 127));
    }
    imagecopyresampled($preview, $image, 0, 0, 0, 0, $pw, $ph, $w, $h);
    $name = $keepAlpha ? 'preview.png' : 'preview.jpg';
    $ok = $keepAlpha ? imagepng($preview, $dir . '/' . $name, 9) : imagejpeg($preview, $dir . '/' . $name, 82);
    imagedestroy($image);
    imagedestroy($preview);
    return $ok ? $name : null;
}

// A sample of the font as an image, when GD can draw TrueType.
function make_font_preview(string $fontPath, string $family, string $dir): ?string
{
    if (!function_exists('imagettftext')) {
        return null;
    }
    $w = PREVIEW_WIDTH * 2;
    $h = 300;
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $ink = imagecolorallocate($image, 17, 17, 17);
    $lines = [[54, $family], [30, 'The quick brown fox'], [30, 'jumps over the lazy dog.'], [24, 'AaBbCc 0123456789 “!?”']];
    $y = 20;
    foreach ($lines as [$size, $text]) {
        $box = @imagettfbbox($size, 0, $fontPath, $text);
        if (!$box) {
            imagedestroy($image);
            return null;
        }
        $y += $size + 22;
        imagettftext($image, $size, 0, 28, $y, $ink, $fontPath, $text);
    }
    $ok = imagepng($image, $dir . '/preview.png', 9);
    imagedestroy($image);
    return $ok ? 'preview.png' : null;
}

// Files arrive as name[] lists; this turns them into one entry per file.
function uploaded_files(string $field): array
{
    $files = [];
    $raw = $_FILES[$field] ?? null;
    if (!$raw || !is_array($raw['name'])) {
        return $files;
    }
    foreach ($raw['name'] as $i => $name) {
        if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => (string)$name,
            'tmp' => (string)$raw['tmp_name'][$i],
            'size' => (int)$raw['size'][$i],
            'error' => (int)$raw['error'][$i],
        ];
    }
    return $files;
}

function upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'is larger than the server allows';
        case UPLOAD_ERR_PARTIAL:
            return 'only partly arrived';
        default:
            return 'could not be uploaded (error ' . $code . ')';
    }
}

// ---- Actions ----------------------------------------------------------------

function add_item(): string
{
    $type = (string)($_POST['type'] ?? '');
    if (!isset(TYPES[$type])) {
        throw new RuntimeException('Choose wallpaper, overlay or font.');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Give it a name.');
    }
    $files = uploaded_files('files');
    if (!$files) {
        throw new RuntimeException('Choose a file to upload.');
    }
    if ($type !== 'font' && count($files) > 1) {
        throw new RuntimeException('Upload one image at a time.');
    }

    $allowed = TYPES[$type]['exts'];
    $limit = $type === 'font' ? MAX_FONT_BYTES : MAX_IMAGE_BYTES;
    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($file['name'] . ' ' . upload_error_text($file['error']) . '.');
        }
        $ext = ext_of($file['name']);
        if (!in_array($ext, $allowed, true)) {
            throw new RuntimeException($file['name'] . ': ' . TYPES[$type]['label'] . 's must be ' . implode(', ', $allowed) . '.');
        }
        if ($file['size'] > $limit) {
            throw new RuntimeException($file['name'] . ' is over ' . ($limit >> 20) . ' MB.');
        }
        if ($type === 'font') {
            if (!font_signature_ok($file['tmp'], $ext)) {
                throw new RuntimeException($file['name'] . " doesn't look like a font file.");
            }
        } else {
            $info = @getimagesize($file['tmp']);
            if (!$info || ($type === 'overlay' && $info[2] !== IMAGETYPE_PNG)) {
                throw new RuntimeException($file['name'] . " doesn't look like " . ($type === 'overlay' ? 'a PNG.' : 'an image.'));
            }
        }
    }

    ensure_files_dir();
    $taken = array_column(read_catalog()['items'], 'id');
    $base = slugify($name);
    $id = $base;
    for ($n = 2; in_array($id, $taken, true) || is_dir(FILES_DIR . '/' . $id); $n++) {
        $id = $base . '-' . $n;
    }
    $dir = FILES_DIR . '/' . $id;
    if (!mkdir($dir, 0755)) {
        throw new RuntimeException('Could not create a folder for it.');
    }

    try {
        $stored = [];
        $usedNames = [];
        foreach ($files as $file) {
            $fileName = safe_file_name($file['name']);
            if (strtolower($fileName) === 'preview.jpg' || strtolower($fileName) === 'preview.png') {
                $fileName = 'file-' . $fileName;
            }
            while (isset($usedNames[strtolower($fileName)])) {
                $fileName = '_' . $fileName;
            }
            $usedNames[strtolower($fileName)] = true;
            if (!move_uploaded_file($file['tmp'], $dir . '/' . $fileName)) {
                throw new RuntimeException('Could not save ' . $file['name'] . '.');
            }
            $stored[] = $fileName;
        }

        $item = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'credit' => trim((string)($_POST['credit'] ?? '')),
            'creditUrl' => trim((string)($_POST['creditUrl'] ?? '')),
            'license' => trim((string)($_POST['license'] ?? '')),
            'added' => gmdate('Y-m-d'),
            'preview' => null,
            'files' => [],
        ];
        if ($item['creditUrl'] !== '' && !preg_match('#^https?://#i', $item['creditUrl'])) {
            $item['creditUrl'] = 'https://' . $item['creditUrl'];
        }

        if ($type === 'font') {
            // The device installs fonts by family folder, named [A-Za-z0-9_-].
            $family = trim((string)($_POST['family'] ?? '')) ?: $name;
            $item['family'] = (string)preg_replace('/[^A-Za-z0-9_-]/', '_', $family);
            $sample = null;
            foreach ($stored as $fileName) {
                if (in_array(ext_of($fileName), ['ttf', 'otf'], true) && $sample === null) {
                    $sample = $fileName;
                }
            }
            if ($sample !== null) {
                $preview = make_font_preview($dir . '/' . $sample, $name, $dir);
                $item['preview'] = $preview ? catalog_url($id, $preview) : null;
            }
        } else {
            $info = getimagesize($dir . '/' . $stored[0]);
            $item['width'] = $info[0];
            $item['height'] = $info[1];
            $preview = make_image_preview($dir . '/' . $stored[0], $dir, $type === 'overlay');
            $item['preview'] = $preview ? catalog_url($id, $preview) : null;
        }
        foreach ($stored as $fileName) {
            $item['files'][] = [
                'name' => $fileName,
                'url' => catalog_url($id, $fileName),
                'size' => filesize($dir . '/' . $fileName),
                'format' => ext_of($fileName),
            ];
        }

        update_catalog(function (array $items) use ($item) {
            array_unshift($items, $item);
            return $items;
        });
    } catch (Throwable $e) {
        remove_dir($dir);
        throw $e;
    }
    return 'Added ' . $name . '.';
}

function edit_item(): string
{
    $id = (string)($_POST['id'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('The name cannot be empty.');
    }
    $found = false;
    update_catalog(function (array $items) use ($id, $name, &$found) {
        foreach ($items as &$item) {
            if ($item['id'] !== $id) {
                continue;
            }
            $found = true;
            $item['name'] = $name;
            $item['credit'] = trim((string)($_POST['credit'] ?? ''));
            $url = trim((string)($_POST['creditUrl'] ?? ''));
            $item['creditUrl'] = ($url !== '' && !preg_match('#^https?://#i', $url)) ? 'https://' . $url : $url;
            $item['license'] = trim((string)($_POST['license'] ?? ''));
        }
        return $items;
    });
    if (!$found) {
        throw new RuntimeException('That item is gone.');
    }
    return 'Saved ' . $name . '.';
}

function delete_item(): string
{
    $id = (string)($_POST['id'] ?? '');
    $name = null;
    update_catalog(function (array $items) use ($id, &$name) {
        return array_filter($items, function ($item) use ($id, &$name) {
            if ($item['id'] === $id) {
                $name = $item['name'];
                return false;
            }
            return true;
        });
    });
    if ($name === null) {
        throw new RuntimeException('That item is gone.');
    }
    if (preg_match('/^[a-z0-9-]+$/', $id)) {
        remove_dir(FILES_DIR . '/' . $id);
    }
    return 'Deleted ' . $name . '.';
}

// ---- Request handling -------------------------------------------------------

$hash = config_hash();
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$loggedIn = !empty($_SESSION['admin']);
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login' && $hash !== null) {
        check_csrf();
        if (login_blocked($ip)) {
            $loginError = 'Too many tries. Wait 15 minutes.';
        } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect_self();
        } else {
            note_login_failure($ip);
            sleep(1);
            $loginError = 'Wrong password.';
        }
    } elseif ($loggedIn) {
        check_csrf();
        try {
            switch ($action) {
                case 'add':
                    flash(add_item());
                    break;
                case 'edit':
                    flash(edit_item());
                    break;
                case 'delete':
                    flash(delete_item());
                    break;
                case 'logout':
                    $_SESSION = [];
                    session_destroy();
                    break;
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), true);
        }
        redirect_self();
    } elseif (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole form when it's over post_max_size.
        flash('That upload is larger than the server allows.', true);
        redirect_self();
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$catalog = $loggedIn ? read_catalog() : null;
$maxUpload = ini_get('upload_max_filesize');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Extras admin | Quartz</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <style>
    :root { color-scheme: dark; --bg: #1e1a2e; --raised: #27223a; --deep: #17142a; --text: #ece8f4; --muted: #ada6c2; --line: rgb(236 232 244 / 0.16); --stone: #b39cf0; --bad: #f4a7b9; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 380 16px/1.55 Jost, system-ui, sans-serif; }
    .wrap { max-width: 60rem; margin: 0 auto; padding: 1.5rem clamp(1rem, 4vw, 2rem) 4rem; }
    header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
    h1 { font-weight: 340; font-size: 1.8rem; margin: 0; }
    h2 { font-weight: 400; font-size: 1.25rem; margin: 0 0 1rem; }
    a { color: inherit; }
    .card { background: var(--raised); border: 1px solid var(--line); border-radius: 18px; padding: 1.25rem; margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.9rem; color: var(--muted); margin: 0.75rem 0 0.3rem; }
    input[type=text], input[type=url], input[type=password], select { width: 100%; padding: 0.6rem 0.75rem; border-radius: 10px; border: 1px solid var(--line); background: var(--deep); color: var(--text); font: inherit; }
    input[type=file] { width: 100%; color: var(--muted); }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
    button { font: inherit; font-weight: 500; border-radius: 10px; padding: 0.55rem 1.1rem; border: 1px solid var(--line); background: var(--deep); color: var(--text); cursor: pointer; }
    button.primary { background: var(--stone); border-color: var(--stone); color: #17142a; }
    button.danger { color: var(--bad); }
    .actions { margin-top: 1rem; display: flex; gap: 0.6rem; flex-wrap: wrap; }
    .note { color: var(--muted); font-size: 0.9rem; margin: 0.5rem 0 0; }
    .flash { padding: 0.75rem 1rem; border-radius: 12px; margin-bottom: 1.25rem; background: rgb(142 209 168 / 0.15); border: 1px solid rgb(142 209 168 / 0.4); }
    .flash.error { background: rgb(244 167 185 / 0.12); border-color: rgb(244 167 185 / 0.45); }
    .items { display: grid; gap: 0.75rem; }
    .item { display: grid; grid-template-columns: 96px 1fr; gap: 1rem; padding: 0.75rem; border: 1px solid var(--line); border-radius: 14px; background: var(--deep); }
    .thumb { width: 96px; height: 120px; border-radius: 8px; background: #fff; object-fit: contain; }
    .thumb.none { display: grid; place-items: center; color: #555; font-size: 0.8rem; }
    .tag { display: inline-block; font-size: 0.75rem; padding: 0.1rem 0.55rem; border-radius: 99px; border: 1px solid var(--line); color: var(--muted); margin-left: 0.4rem; }
    details summary { cursor: pointer; color: var(--muted); font-size: 0.9rem; margin-top: 0.4rem; }
    .login { max-width: 22rem; margin: 15vh auto 0; }
    [hidden] { display: none !important; }
    @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="wrap">
<?php if ($hash === null): ?>
  <div class="card login">
    <h1>Extras admin</h1>
    <p class="note">Not set up yet. Add a repository secret named <code>EXTRAS_ADMIN_PASSWORD</code> to the QuartzSite repo on GitHub, then run the deploy again.</p>
  </div>
<?php elseif (!$loggedIn): ?>
  <form class="card login" method="post">
    <h1>Extras admin</h1>
    <?php if ($loginError): ?><div class="flash error" role="alert"><?= h($loginError) ?></div><?php endif; ?>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="actions"><button class="primary">Sign in</button></div>
  </form>
<?php else: ?>
  <header>
    <h1>Extras</h1>
    <form method="post">
      <a href="/extras/">View gallery</a>
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button>Sign out</button>
    </form>
  </header>

  <?php if ($flash): ?><div class="flash<?= $flash['error'] ? ' error' : '' ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data">
    <h2>Add to the gallery</h2>
    <label for="type">Type</label>
    <select id="type" name="type">
      <option value="wallpaper">Wallpaper (JPG, PNG, WebP or BMP)</option>
      <option value="overlay">Overlay (transparent PNG, 480 × 800 works on every device)</option>
      <option value="font">Font (TTF or OTF, plus .cpfont files for the X3)</option>
    </select>
    <div class="grid2">
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="80">
      </div>
      <div id="familyField" hidden>
        <label for="family">Family name on the device</label>
        <input type="text" id="family" name="family" maxlength="40" placeholder="Same as the name" pattern="[A-Za-z0-9_\- ]*">
      </div>
    </div>
    <div class="grid2">
      <div>
        <label for="credit">Credit (artist or designer)</label>
        <input type="text" id="credit" name="credit" maxlength="80">
      </div>
      <div>
        <label for="creditUrl">Credit link</label>
        <input type="url" id="creditUrl" name="creditUrl" maxlength="200" placeholder="https://">
      </div>
    </div>
    <label for="license">License</label>
    <input type="text" id="license" name="license" maxlength="80" placeholder="e.g. SIL Open Font License, CC BY 4.0, Used with permission">
    <label for="files">File<span id="filesPlural" hidden>s</span></label>
    <input type="file" id="files" name="files[]" required>
    <p class="note" id="fileNote">Upload the full-size image; each device resizes it for its own screen. Up to <?= h($maxUpload) ?> per file on this server.</p>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="actions"><button class="primary">Add</button></div>
  </form>

  <section class="card">
    <h2><?= count($catalog['items']) ?> in the gallery</h2>
    <div class="items">
      <?php foreach ($catalog['items'] as $item): ?>
        <article class="item">
          <?php if (!empty($item['preview'])): ?>
            <img class="thumb" src="../<?= h($item['preview']) ?>" alt="">
          <?php else: ?>
            <div class="thumb none">No preview</div>
          <?php endif; ?>
          <div>
            <strong><?= h($item['name']) ?></strong><span class="tag"><?= h(TYPES[$item['type']]['label'] ?? $item['type']) ?></span>
            <p class="note">
              <?= h($item['credit'] ? 'By ' . $item['credit'] : 'No credit') ?><?= $item['license'] ? ' · ' . h($item['license']) : '' ?> · added <?= h($item['added']) ?>
              · <?= h(implode(', ', array_column($item['files'], 'name'))) ?>
            </p>
            <details>
              <summary>Edit</summary>
              <form method="post">
                <div class="grid2">
                  <div><label>Name</label><input type="text" name="name" value="<?= h($item['name']) ?>" required maxlength="80"></div>
                  <div><label>License</label><input type="text" name="license" value="<?= h($item['license']) ?>" maxlength="80"></div>
                  <div><label>Credit</label><input type="text" name="credit" value="<?= h($item['credit']) ?>" maxlength="80"></div>
                  <div><label>Credit link</label><input type="url" name="creditUrl" value="<?= h($item['creditUrl']) ?>" maxlength="200"></div>
                </div>
                <input type="hidden" name="id" value="<?= h($item['id']) ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <div class="actions">
                  <button class="primary" name="action" value="edit">Save</button>
                  <button class="danger" name="action" value="delete" formnovalidate onclick="return confirm('Delete <?= h(addslashes($item['name'])) ?> from the gallery?')">Delete</button>
                </div>
              </form>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
  <script>
    // Fonts take several files (styles, and .cpfont packs for the X3).
    const type = document.getElementById('type');
    const files = document.getElementById('files');
    const accept = { wallpaper: '.jpg,.jpeg,.png,.webp,.bmp', overlay: '.png', font: '.ttf,.otf,.cpfont' };
    const notes = {
      wallpaper: 'Upload the full-size image; each device resizes it for its own screen.',
      overlay: 'A transparent PNG. Made at 480 × 800 it fits the X4, X4 Pro and Picco and stretches to the X3.',
      font: "Choose all the styles at once (Regular, Bold, Italic…). Add .cpfont files too so it installs on the X3.",
    };
    function syncType() {
      const font = type.value === 'font';
      files.multiple = font;
      files.accept = accept[type.value];
      document.getElementById('familyField').hidden = !font;
      document.getElementById('filesPlural').hidden = !font;
      document.getElementById('fileNote').textContent = notes[type.value] + ' Up to <?= h($maxUpload) ?> per file on this server.';
    }
    type.addEventListener('change', syncType);
    syncType();
  </script>
<?php endif; ?>
</div>
</body>
</html>
