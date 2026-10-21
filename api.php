<?php
// Speicher-Schnittstelle für den Termin- und Besuchsplaner.
// GET  -> liefert den gespeicherten Stand
// POST -> speichert {rev, items}; lehnt mit 409 ab, wenn ein anderes Gerät zwischenzeitlich gespeichert hat.
// Zugriff nur mit Passwort (Header "X-Kalender-Key"). Den Passwort-Hash erzeugt werkzeuge/passwort-setzen.mjs
// in kalender-auth.php. Ohne diese Datei verweigert die Schnittstelle jeden Zugriff.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = is_file(__DIR__ . '/kalender-auth.php') ? include __DIR__ . '/kalender-auth.php' : null;
if (!is_array($auth) || empty($auth['hash'])) {
    http_response_code(503);
    echo '{"error":"unconfigured"}';
    exit;
}
$given = rawurldecode($_SERVER['HTTP_X_KALENDER_KEY'] ?? ''); // Client sendet URL-kodiert (Umlaute)
$calc  = hash_pbkdf2('sha256', $given, $auth['salt'], (int)$auth['iter'], 64);
if ($given === '' || !hash_equals($auth['hash'], $calc)) {
    sleep(2); // bremst das Durchprobieren von Passwörtern
    http_response_code(401);
    echo '{"error":"unauthorized"}';
    exit;
}

$dir  = __DIR__ . '/data';
$file = $dir . '/kalender.json';
if (!is_dir($dir)) { mkdir($dir, 0750, true); }

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo is_file($file) ? file_get_contents($file) : json_encode(['rev' => 0, 'items' => []]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo '{"error":"method"}';
    exit;
}

$raw = file_get_contents('php://input');
if (strlen($raw) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo '{"error":"zu gross"}';
    exit;
}
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) {
    http_response_code(400);
    echo '{"error":"ungueltig"}';
    exit;
}

$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$cur = json_decode(stream_get_contents($fp) ?: '{"rev":0}', true);
$rev = (int)($cur['rev'] ?? 0);

if ((int)($in['rev'] ?? -1) !== $rev) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(409);
    echo json_encode(['error' => 'conflict', 'rev' => $rev]);
    exit;
}

// Tägliche Sicherung des vorherigen Stands
$backup = $dir . '/backup-' . date('Y-m-d') . '.json';
if ($rev > 0 && !is_file($backup)) {
    file_put_contents($backup, json_encode($cur, JSON_UNESCAPED_UNICODE));
}

$out = ['rev' => $rev + 1, 'savedAt' => date('c'), 'items' => $in['items']];
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['rev' => $out['rev']]);
