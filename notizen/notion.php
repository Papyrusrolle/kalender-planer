<?php
// Brücke zwischen der Notiz-App und einer Notion-Datenbank.
//
// Der Notion-Schlüssel bleibt auf dem Server (data/notizen-config.json) und gelangt nie in den Browser.
// Zugriff nur mit dem Kalenderpasswort (Header "X-Kalender-Key", Hash in kalender-auth.php).
//
//   GET  ?a=status            -> {configured, dbTitle, dbUrl}
//   POST ?a=setup  {token,db} -> prüft Schlüssel + Datenbank, legt fehlende Spalten an, speichert
//   GET  ?a=list              -> {notes:[…], tags:[…]}
//   GET  ?a=get&id=…          -> eine Notiz inkl. Text
//   POST ?a=save   {id?,title,tags,pinned,body,base?,force?} -> legt an / aktualisiert (409 bei Konflikt)
//   POST ?a=delete {id}       -> verschiebt die Seite in den Notion-Papierkorb
//
// Jede Zeile des Notiztexts wird ein Notion-Block (wie die Eingabetaste in Notion):
//   # / ## / ###  Überschriften     - Punkt        1. Nummeriert     [ ] / [x] Aufgabe
//   > Zitat       --- Trennlinie     ``` Code ```   **fett** *kursiv* ~~durch~~ `code` [Text](https://…)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const NOTION_VERSION = '2022-06-28';
const PROP_TAGS = 'Tags';
const PROP_PIN = 'Angepinnt';
const CHUNK = 1800; // Notion erlaubt max. 2000 Zeichen je Textstück

final class Fail extends Exception
{
    public $status;
    public $data;
    public function __construct(int $status, array $data)
    {
        parent::__construct($data['message'] ?? $data['error'] ?? 'Fehler');
        $this->status = $status;
        $this->data = $data;
    }
}

function out(int $code, $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Anmeldung (gleiches Passwort wie der Kalender) ----------
$auth = is_file(__DIR__ . '/../kalender-auth.php') ? include __DIR__ . '/../kalender-auth.php' : null;
if (!is_array($auth) || empty($auth['hash'])) {
    out(503, ['error' => 'nopassword']);
}
$given = rawurldecode($_SERVER['HTTP_X_KALENDER_KEY'] ?? '');
if ($given === '') {
    out(401, ['error' => 'unauthorized']);
}
if (!hash_equals($auth['hash'], hash_pbkdf2('sha256', $given, $auth['salt'], (int)$auth['iter'], 64))) {
    sleep(2); // bremst das Durchprobieren von Passwörtern
    out(401, ['error' => 'unauthorized']);
}

// ---------- Konfiguration ----------
$cfgFile = __DIR__ . '/../data/notizen-config.json';
$cfg = is_file($cfgFile) ? json_decode((string)file_get_contents($cfgFile), true) : null;
$configured = is_array($cfg) && !empty($cfg['token']) && !empty($cfg['db']);

$a = $_GET['a'] ?? '';
$raw = file_get_contents('php://input');
if (strlen((string)$raw) > 2 * 1024 * 1024) {
    out(413, ['error' => 'zu gross']);
}
$in = json_decode($raw ?: 'null', true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_array($in)) {
    out(400, ['error' => 'ungueltig']);
}

// ---------- Notion-Aufrufe ----------
function curl_for(string $method, string $path, $body, ?string $token)
{
    global $cfg;
    $base = $cfg['api'] ?? 'https://api.notion.com/v1';
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . ($token ?? $cfg['token']),
            'Notion-Version: ' . NOTION_VERSION,
            'Content-Type: application/json',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $ch;
}

function notion_result(string $res, int $code)
{
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = $data['message'] ?? ('Notion antwortet mit ' . $code);
        throw new Fail($code === 401 || $code === 403 || $code === 404 ? 424 : 502, [
            'error' => 'notion', 'notionStatus' => $code, 'code' => $data['code'] ?? '', 'message' => $msg,
        ]);
    }
    return $data;
}

function notion(string $method, string $path, $body = null, ?string $token = null)
{
    for ($try = 0; ; $try++) {
        $ch = curl_for($method, $path, $body, $token);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new Fail(502, ['error' => 'notion', 'message' => 'Notion nicht erreichbar: ' . $err]);
        }
        if (($code === 429 || $code >= 500) && $try < 4) {
            sleep(1 + $try);
            continue;
        }
        return notion_result($res, $code);
    }
}

// Mehrere Aufrufe gleichzeitig (z. B. Blöcke löschen). Notion erlaubt im Schnitt 3 Anfragen/Sekunde.
function notion_multi(array $reqs): void
{
    foreach (array_chunk($reqs, 3) as $batch) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($batch as $i => [$m, $p, $b]) {
            $hs[$i] = curl_for($m, $p, $b, null);
            curl_multi_add_handle($mh, $hs[$i]);
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $retry = [];
        foreach ($hs as $i => $h) {
            $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $res = curl_multi_getcontent($h);
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
            if ($code === 0 || $code === 429 || $code >= 500) {
                $retry[] = $batch[$i];
            } elseif ($code === 404 && $batch[$i][0] === 'DELETE') {
                // schon weg – egal
            } else {
                notion_result((string)$res, $code);
            }
        }
        curl_multi_close($mh);
        foreach ($retry as [$m, $p, $b]) {
            notion($m, $p, $b);
        }
    }
}

// ---------- Text <-> Notion ----------
function rt_plain(array $rts): string
{
    return implode('', array_map(fn($r) => $r['plain_text'] ?? ($r['text']['content'] ?? ''), $rts));
}

function rt_to_md(array $rts): string
{
    $s = '';
    foreach ($rts as $r) {
        $t = $r['plain_text'] ?? ($r['text']['content'] ?? '');
        if ($t === '') {
            continue;
        }
        $an = $r['annotations'] ?? [];
        $href = $r['href'] ?? ($r['text']['link']['url'] ?? null);
        preg_match('/^(\s*)(.*?)(\s*)$/su', $t, $m);
        [, $lead, $core, $trail] = $m;
        if ($core !== '') {
            if (!empty($an['code'])) {
                $core = '`' . $core . '`';
            } else {
                if (!empty($an['strikethrough'])) $core = '~~' . $core . '~~';
                $b = !empty($an['bold']);
                $i = !empty($an['italic']);
                if ($b && $i) $core = '***' . $core . '***';
                elseif ($b) $core = '**' . $core . '**';
                elseif ($i) $core = '*' . $core . '*';
            }
            if ($href) $core = '[' . $core . '](' . $href . ')';
        }
        $s .= $lead . $core . $trail;
    }
    return $s;
}

function rt_push(array &$out, string $text, array $an, ?string $link): void
{
    if ($text === '') return;
    $len = mb_strlen($text);
    for ($o = 0; $o < $len; $o += CHUNK) {
        $item = ['type' => 'text', 'text' => ['content' => mb_substr($text, $o, CHUNK)]];
        if ($link) $item['text']['link'] = ['url' => $link];
        if ($an) $item['annotations'] = $an;
        $out[] = $item;
    }
}

function md_to_rt(string $s, array $an = [], ?string $link = null): array
{
    $out = [];
    $re = '/\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:)[^)\s]+)\)'   // 1,2 Link
        . '|\*\*\*(?!\s)(.+?)(?<!\s)\*\*\*'                         // 3 fett+kursiv
        . '|\*\*(?!\s)(.+?)(?<!\s)\*\*'                             // 4 fett
        . '|~~(?!\s)(.+?)(?<!\s)~~'                                 // 5 durchgestrichen
        . '|`([^`\n]+)`'                                            // 6 Code
        . '|(?<![*\w])\*(?![\s*])([^*\n]+?)(?<!\s)\*(?![*\w])/u';   // 7 kursiv
    $pos = 0;
    $g = fn($m, $i) => (isset($m[$i]) && $m[$i][1] >= 0) ? $m[$i][0] : null;
    while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $start = $m[0][1];
        rt_push($out, substr($s, $pos, $start - $pos), $an, $link);
        if (($v = $g($m, 1)) !== null) {
            $out = array_merge($out, md_to_rt($v, $an, $g($m, 2)));
        } elseif (($v = $g($m, 3)) !== null) {
            $out = array_merge($out, md_to_rt($v, $an + ['bold' => true, 'italic' => true], $link));
        } elseif (($v = $g($m, 4)) !== null) {
            $out = array_merge($out, md_to_rt($v, $an + ['bold' => true], $link));
        } elseif (($v = $g($m, 5)) !== null) {
            $out = array_merge($out, md_to_rt($v, $an + ['strikethrough' => true], $link));
        } elseif (($v = $g($m, 6)) !== null) {
            rt_push($out, $v, $an + ['code' => true], $link);
        } elseif (($v = $g($m, 7)) !== null) {
            $out = array_merge($out, md_to_rt($v, $an + ['italic' => true], $link));
        }
        $pos = $start + strlen($m[0][0]);
    }
    rt_push($out, substr($s, $pos), $an, $link);
    return $out;
}

const CODE_LANGS = ['bash', 'c', 'c#', 'c++', 'css', 'go', 'html', 'java', 'javascript', 'json', 'kotlin', 'markdown',
    'php', 'powershell', 'python', 'ruby', 'rust', 'shell', 'sql', 'swift', 'typescript', 'xml', 'yaml'];

// Ein Block als Textzeile(n). null = kann die App nicht bearbeiten (Bild, Tabelle, Unterseite, eingerückt …)
function block_to_md(array $b): ?string
{
    $t = $b['type'] ?? '';
    if (!empty($b['has_children'])) return null;
    $d = $b[$t] ?? [];
    $rt = fn() => rt_to_md($d['rich_text'] ?? []);
    switch ($t) {
        case 'paragraph': return $rt();
        case 'heading_1': case 'heading_2': case 'heading_3':
            if (!empty($d['is_toggleable'])) return null;
            return str_repeat('#', (int)substr($t, -1)) . ' ' . $rt();
        case 'bulleted_list_item': return '- ' . $rt();
        case 'numbered_list_item': return '1. ' . $rt();
        case 'to_do': return (!empty($d['checked']) ? '[x] ' : '[ ] ') . $rt();
        case 'quote': return '> ' . $rt();
        case 'divider': return '---';
        case 'code':
            $lang = $d['language'] ?? 'plain text';
            return '```' . ($lang === 'plain text' ? '' : $lang) . "\n" . rt_plain($d['rich_text'] ?? []) . "\n```";
    }
    return null;
}

function blk(string $type, $data): array
{
    return ['object' => 'block', 'type' => $type, $type => $data];
}

function md_to_blocks(string $body): array
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    if ($body === '') return [];
    $lines = explode("\n", $body);
    $out = [];
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $l = $lines[$i];
        if (preg_match('/^```\s*([\w#+ -]*)\s*$/', $l, $m)) {
            $code = [];
            for ($i++; $i < $n && rtrim($lines[$i]) !== '```'; $i++) $code[] = $lines[$i];
            $lang = strtolower(trim($m[1]));
            $rt = [];
            rt_push($rt, implode("\n", $code), [], null);
            $out[] = blk('code', ['rich_text' => $rt, 'language' => in_array($lang, CODE_LANGS, true) ? $lang : 'plain text']);
        } elseif (trim($l) === '---') {
            $out[] = blk('divider', new stdClass());
        } elseif (preg_match('/^(#{1,3}) (.*)$/', $l, $m)) {
            $out[] = blk('heading_' . strlen($m[1]), ['rich_text' => md_to_rt($m[2])]);
        } elseif (preg_match('/^(?:- )?\[( |x|X)\] (.*)$/', $l, $m)) {
            $out[] = blk('to_do', ['rich_text' => md_to_rt($m[2]), 'checked' => $m[1] !== ' ']);
        } elseif (preg_match('/^[-*•] (.*)$/u', $l, $m)) {
            $out[] = blk('bulleted_list_item', ['rich_text' => md_to_rt($m[1])]);
        } elseif (preg_match('/^\d+[.)] (.*)$/', $l, $m)) {
            $out[] = blk('numbered_list_item', ['rich_text' => md_to_rt($m[1])]);
        } elseif (preg_match('/^> ?(.*)$/', $l, $m)) {
            $out[] = blk('quote', ['rich_text' => md_to_rt($m[1])]);
        } else {
            $out[] = blk('paragraph', ['rich_text' => md_to_rt($l)]);
        }
    }
    return $out;
}

function load_blocks(string $id): array
{
    $blocks = [];
    $cursor = null;
    do {
        $r = notion('GET', '/blocks/' . rawurlencode($id) . '/children?page_size=100' . ($cursor ? '&start_cursor=' . rawurlencode($cursor) : ''));
        array_push($blocks, ...$r['results']);
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);
    $lines = [];
    $readonly = false;
    foreach ($blocks as $b) {
        $md = block_to_md($b);
        if ($md === null) {
            $readonly = true;
            $md = '⟦' . ($b['has_children'] ? 'verschachtelter Inhalt' : str_replace('_', ' ', $b['type'])) . ' – nur in Notion bearbeitbar⟧';
        }
        $lines[] = $md;
    }
    return [$blocks, $lines, $readonly];
}

function append_blocks(string $parent, array $blocks, ?string $after): void
{
    foreach (array_chunk($blocks, 100) as $chunk) {
        $body = ['children' => $chunk];
        if ($after) $body['after'] = $after;
        $r = notion('PATCH', '/blocks/' . rawurlencode($parent) . '/children', $body);
        if ($after) {
            $res = $r['results'] ?? [];
            $after = $res ? end($res)['id'] : null;
        }
    }
}

// ---------- Notizen ----------
function title_prop(array $props): string
{
    foreach ($props as $name => $p) if (($p['type'] ?? '') === 'title') return $name;
    return 'Name';
}

function page_meta(array $p): array
{
    $props = $p['properties'] ?? [];
    return [
        'id' => $p['id'],
        'title' => rt_plain($props[title_prop($props)]['title'] ?? []),
        'tags' => array_map(fn($o) => $o['name'], $props[PROP_TAGS]['multi_select'] ?? []),
        'pinned' => !empty($props[PROP_PIN]['checkbox']),
        'edited' => $p['last_edited_time'] ?? null,
        'created' => $p['created_time'] ?? null,
        'url' => $p['url'] ?? null,
    ];
}

function ver(array $n): string
{
    $tags = $n['tags'];
    sort($tags);
    return sha1(json_encode([$n['title'], $tags, (bool)$n['pinned'], $n['body']], JSON_UNESCAPED_UNICODE));
}

function full_note(string $id): array
{
    $page = notion('GET', '/pages/' . rawurlencode($id));
    if (!empty($page['archived']) || !empty($page['in_trash'])) {
        throw new Fail(404, ['error' => 'gone', 'message' => 'Notiz wurde in Notion gelöscht.']);
    }
    [$blocks, $lines, $readonly] = load_blocks($id);
    $n = page_meta($page) + ['body' => implode("\n", $lines), 'readonly' => $readonly];
    $n['ver'] = ver($n);
    return [$n, $blocks, $lines];
}

function clean_tags($tags): array
{
    $out = [];
    foreach ((array)$tags as $t) {
        $t = trim(str_replace(',', ' ', (string)$t));
        if ($t !== '') $out[mb_strtolower($t)] = mb_substr($t, 0, 100);
    }
    return array_values($out);
}

function props_for(string $title, array $tags, bool $pinned): array
{
    global $cfg;
    $tt = [];
    rt_push($tt, $title, [], null);
    return [
        $cfg['titleProp'] => ['title' => $tt],
        PROP_TAGS => ['multi_select' => array_map(fn($t) => ['name' => $t], $tags)],
        PROP_PIN => ['checkbox' => $pinned],
    ];
}

function db_id(string $s): ?string
{
    if (preg_match('/[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}/i', parse_url($s, PHP_URL_PATH) ?: $s, $m)) {
        return strtolower(str_replace('-', '', $m[0]));
    }
    return null;
}

try {
    switch ($a) {
        case 'status':
            out(200, ['configured' => $configured, 'dbTitle' => $cfg['dbTitle'] ?? null, 'dbUrl' => $cfg['dbUrl'] ?? null]);

        case 'setup':
            $token = trim((string)($in['token'] ?? ''));
            if ($token === '' && $configured) $token = $cfg['token']; // nur Datenbank wechseln
            $db = db_id((string)($in['db'] ?? ''));
            if ($token === '' || !$db) out(400, ['error' => 'input', 'message' => 'Bitte Schlüssel und Datenbank-Link angeben.']);
            try {
                $d = notion('GET', '/databases/' . $db, null, $token);
            } catch (Fail $e) {
                $s = $e->data['notionStatus'] ?? 0;
                $msg = $s === 401 ? 'Der Notion-Schlüssel ist ungültig.'
                    : ($s === 404 ? 'Datenbank nicht gefunden. Ist die Integration in Notion mit der Datenbank verbunden (••• → Verbindungen)? Und ist es wirklich eine Datenbank, keine normale Seite?'
                    : $e->getMessage());
                out(400, ['error' => 'setup', 'message' => $msg]);
            }
            $props = $d['properties'] ?? [];
            $add = [];
            foreach ([PROP_TAGS => 'multi_select', PROP_PIN => 'checkbox'] as $name => $type) {
                if (!isset($props[$name])) $add[$name] = [$type => new stdClass()];
                elseif ($props[$name]['type'] !== $type) out(400, ['error' => 'setup', 'message' => "Die Spalte „{$name}“ hat in Notion den falschen Typ. Bitte umbenennen oder löschen."]);
            }
            if ($add) notion('PATCH', '/databases/' . $db, ['properties' => $add], $token);
            $new = [
                'token' => $token, 'db' => $db, 'titleProp' => title_prop($props),
                'dbTitle' => rt_plain($d['title'] ?? []) ?: 'Notion-Datenbank', 'dbUrl' => $d['url'] ?? null,
            ];
            if (isset($cfg['api'])) $new['api'] = $cfg['api'];
            if (!is_dir(dirname($cfgFile))) mkdir(dirname($cfgFile), 0750, true);
            file_put_contents($cfgFile, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($cfgFile, 0600);
            out(200, ['configured' => true, 'dbTitle' => $new['dbTitle'], 'dbUrl' => $new['dbUrl']]);
    }

    if (!$configured) out(409, ['error' => 'setup', 'message' => 'Notion ist noch nicht verbunden.']);

    switch ($a) {
        case 'list':
            $notes = [];
            $cursor = null;
            do {
                $q = ['page_size' => 100, 'sorts' => [['timestamp' => 'last_edited_time', 'direction' => 'descending']]];
                if ($cursor) $q['start_cursor'] = $cursor;
                $r = notion('POST', '/databases/' . $cfg['db'] . '/query', $q);
                foreach ($r['results'] as $p) $notes[] = page_meta($p);
                $cursor = $r['has_more'] ? $r['next_cursor'] : null;
            } while ($cursor && count($notes) < 5000);
            $d = notion('GET', '/databases/' . $cfg['db']);
            $tags = array_map(fn($o) => $o['name'], $d['properties'][PROP_TAGS]['multi_select']['options'] ?? []);
            out(200, ['notes' => $notes, 'tags' => $tags]);

        case 'get':
            out(200, full_note((string)($_GET['id'] ?? ''))[0]);

        case 'save':
            $title = trim((string)($in['title'] ?? ''));
            $tags = clean_tags($in['tags'] ?? []);
            $pinned = !empty($in['pinned']);
            $body = isset($in['body']) ? str_replace(["\r\n", "\r"], "\n", (string)$in['body']) : null;
            $id = (string)($in['id'] ?? '');

            if ($id === '') {
                $blocks = md_to_blocks($body ?? '');
                $page = notion('POST', '/pages', [
                    'parent' => ['database_id' => $cfg['db']],
                    'properties' => props_for($title, $tags, $pinned),
                    'children' => array_slice($blocks, 0, 100),
                ]);
                if (count($blocks) > 100) append_blocks($page['id'], array_slice($blocks, 100), null);
                out(200, full_note($page['id'])[0]);
            }

            [$cur, $oldBlocks, $oldMd] = full_note($id);
            if (empty($in['force']) && !empty($in['base']) && $in['base'] !== $cur['ver']) {
                out(409, ['error' => 'conflict', 'note' => $cur]);
            }
            $ct = $cur['tags'];
            $nt = $tags;
            sort($ct);
            sort($nt);
            if ($cur['title'] !== $title || $ct !== $nt || $cur['pinned'] !== $pinned) {
                notion('PATCH', '/pages/' . rawurlencode($id), ['properties' => props_for($title, $tags, $pinned)]);
            }
            if ($body !== null && !$cur['readonly'] && $body !== $cur['body']) {
                // Nur den geänderten Mittelteil ersetzen: gleicher Anfang und gleiches Ende bleiben stehen.
                $new = md_to_blocks($body);
                $newMd = array_map('block_to_md', $new);
                $no = count($oldMd);
                $nn = count($newMd);
                $k = 0;
                while ($k < $no && $k < $nn && $oldMd[$k] === $newMd[$k]) $k++;
                $s = 0;
                if ($k > 0) {
                    while ($s < $no - $k && $s < $nn - $k && $oldMd[$no - 1 - $s] === $newMd[$nn - 1 - $s]) $s++;
                }
                $del = [];
                for ($i = $k; $i < $no - $s; $i++) $del[] = ['DELETE', '/blocks/' . $oldBlocks[$i]['id'], null];
                notion_multi($del);
                $ins = array_slice($new, $k, $nn - $s - $k);
                if ($ins) append_blocks($id, $ins, $s > 0 ? $oldBlocks[$k - 1]['id'] : null);
            }
            out(200, full_note($id)[0]);

        case 'delete':
            notion('PATCH', '/pages/' . rawurlencode((string)($in['id'] ?? '')), ['archived' => true]);
            out(200, ['ok' => true]);
    }
    out(400, ['error' => 'action']);
} catch (Fail $e) {
    out($e->status, $e->data);
}
