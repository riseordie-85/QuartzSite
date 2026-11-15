<?php
// The font converter's side door (see "Font conversion" in common.php).
// Only the GitHub job that convert-fonts.yml runs can use it: every request
// carries the job id and its HMAC.
//
//   GET  convert.php?job=…&sig=…   JSON: the family name and its TTF/OTF files
//   POST convert.php?job=…&sig=…   files[]: the .cpfont files, or error: why it failed

declare(strict_types=1);

require __DIR__ . '/common.php';

const MAX_CPFONT_BYTES = 24 * 1024 * 1024;

function reply(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$job = (string)($_GET['job'] ?? '');
$sig = (string)($_GET['sig'] ?? '');
if (!preg_match('/^([a-z0-9-]+)\.[0-9a-f]{16}$/', $job, $m)) {
    reply(400, ['error' => 'Bad job.']);
}
$id = $m[1];
$expected = job_signature($job);
if ($expected === null || !hash_equals($expected, $sig)) {
    reply(403, ['error' => 'Bad signature.']);
}
$conversion = read_conversions()[$id] ?? null;
if (($conversion['job'] ?? null) !== $job || $conversion['state'] !== 'running') {
    reply(409, ['error' => 'This conversion is no longer waiting.']);
}
$item = null;
foreach (read_catalog()['items'] as $candidate) {
    if ($candidate['id'] === $id && $candidate['type'] === 'font') {
        $item = $candidate;
    }
}
if ($item === null) {
    finish_conversion($id, $job, 'failed', 'The font was deleted.');
    reply(404, ['error' => 'The font is gone.']);
}
$family = (string)$item['family'];
$host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    reply(200, [
        'family' => $family,
        'files' => array_map(function ($file) use ($host) {
            return ['name' => $file['name'], 'url' => $host . '/extras/' . $file['url']];
        }, font_sources($item)),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'GET or POST.']);
}

if (isset($_POST['error'])) {
    $message = 'Conversion failed: ' . mb_substr(trim((string)$_POST['error']), 0, 300);
    finish_conversion($id, $job, 'failed', $message);
    reply(200, ['ok' => true]);
}

// Check every file before keeping any: <Family>_<size>.cpfont, CPFONT header.
$uploads = [];
$files = $_FILES['files'] ?? null;
$count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
for ($i = 0; $i < $count; $i++) {
    $name = (string)$files['name'][$i];
    $tmp = (string)$files['tmp_name'][$i];
    if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        reply(400, ['error' => $name . ' did not arrive.']);
    }
    if (!preg_match('/^' . preg_quote($family, '/') . '_(\d{1,2})\.cpfont$/', $name)) {
        reply(400, ['error' => $name . ' is not named ' . $family . '_<size>.cpfont.']);
    }
    if ($files['size'][$i] > MAX_CPFONT_BYTES || !font_signature_ok($tmp, 'cpfont')) {
        reply(400, ['error' => $name . ' is not a .cpfont file.']);
    }
    $uploads[$name] = $tmp;
}
if (!$uploads) {
    reply(400, ['error' => 'No .cpfont files.']);
}

$dir = FILES_DIR . '/' . $id;
if (!is_dir($dir)) {
    finish_conversion($id, $job, 'failed', 'The font folder is gone.');
    reply(404, ['error' => 'The font folder is gone.']);
}
// Replace any earlier .cpfont files with this set.
foreach (glob($dir . '/*.cpfont') ?: [] as $old) {
    unlink($old);
}
foreach ($uploads as $name => $tmp) {
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        finish_conversion($id, $job, 'failed', 'Could not save ' . $name . '.');
        reply(500, ['error' => 'Could not save ' . $name . '.']);
    }
}
$names = array_keys($uploads);
natsort($names);
update_catalog(function (array $items) use ($id, $dir, $names) {
    foreach ($items as &$entry) {
        if ($entry['id'] !== $id) {
            continue;
        }
        $entry['files'] = array_values(array_filter($entry['files'], function ($file) {
            return ext_of($file['name']) !== 'cpfont';
        }));
        foreach ($names as $name) {
            $entry['files'][] = [
                'name' => $name,
                'url' => catalog_url($id, $name),
                'size' => filesize($dir . '/' . $name),
                'format' => 'cpfont',
            ];
        }
    }
    return $items;
});
finish_conversion($id, $job, 'done', count($names) . ' sizes');
reply(200, ['ok' => true, 'saved' => array_values($names)]);
