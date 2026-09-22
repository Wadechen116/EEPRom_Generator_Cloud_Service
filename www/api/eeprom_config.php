<?php
/**
 * REST endpoint for the `eeprom_config` table.
 *
 * GET    eeprom_config.php                        list records, summary columns only (?page=1&limit=20), index ASC
 * GET    eeprom_config.php?all=1                   list EVERY record in one response (no pagination), summary columns
 * GET    eeprom_config.php?index=5                 get one record, full row incl. content/ext_txt*
 * GET    eeprom_config.php?index=5&download=1      download `content` as a file (Content-Disposition: attachment)
 * POST   eeprom_config.php                         create (JSON body, see REQUIRED_FIELDS below)
 * POST   eeprom_config.php?index=5&_method=PUT     update (JSON body: any of WRITABLE_FIELDS)
 * POST   eeprom_config.php?index=5&_method=DELETE  delete
 *
 * Update/delete travel as POST with a `_method` override instead of real HTTP PUT/DELETE
 * verbs: some shared-hosting Apache setups (WebDAV modules, WAF rules, certain PHP-CGI
 * configs) silently block or mangle PUT/DELETE before they ever reach this script, and
 * we have no access to server config to fix that at the source. POST always works.
 *
 * All requests require header:  X-API-Key: <configured key>
 * AND one of:
 *   - a logged-in browser session (api/login.php), for the web UI, or
 *   - per-request credentials (api/lib/CredentialAuth.php) for any other frontend/
 *     script/tool: either  Authorization: Basic base64(account:password)  or the
 *     plain  X-Account / X-Password  headers (needed on hosts that strip Authorization
 *     before PHP sees it -- confirmed true for this deployment, see CredentialAuth.php).
 *     Requires HTTPS either way.
 *
 * NOTE: `index` is a SQL reserved word -- every query below backtick-quotes it.
 *
 * file_format, output_format and support_mode have fixed value domains, and a BIN
 * record's `content` is hex text, not raw bytes -- see ENUM_FIELDS and
 * normalizeFields() below for both, and sql/schema.sql for why.
 *
 * `account` is read-only to every client: the server writes the authenticated
 * account name on each create and update. `comment` is free text -- what this
 * version is for -- and is the client's to set.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';
require __DIR__ . '/lib/CredentialAuth.php';

$authenticatedAccount = SessionAuth::currentAccount() ?? CredentialAuth::verify($pdo);
if ($authenticatedAccount === null) {
    Response::error('Login required', 401);
}

const TABLE = 'eeprom_config';

// `account` is deliberately NOT here. It records who wrote the row, and a
// field a client can set is a field a client can lie about -- it is stamped
// from $authenticatedAccount in createRecord()/updateRecord() instead. Anything
// the client sends under that name is dropped by Validator::pick().
const WRITABLE_FIELDS = [
    'file_format', 'fps', 'file_name', 'MHz', 'isPGL', 'output_format', 'support_mode',
    'content', 'comment',
    'ext_str1', 'ext_str2', 'ext_int1', 'ext_txt1', 'ext_txt2', 'ext_txt3', 'ext_txt4', 'ext_txt5',
];
const REQUIRED_FIELDS = ['file_format', 'fps', 'file_name', 'MHz', 'output_format', 'support_mode', 'content'];

/**
 * Fixed value domains.
 *
 * This table is synced into the desktop tool (E2pRom_Generator, "Sync Cloud"),
 * which stores the same columns with the same values. If the two sides spelled
 * a value differently -- 'ini' here, 'INI' there -- every sync would see a
 * difference and rewrite the row, and the next sync would rewrite it back.
 *
 * Input is matched case-insensitively and stored in the canonical spelling
 * below; anything not in the list is a 400 rather than a silently stored
 * value that no longer round-trips.
 */
const ENUM_FIELDS = [
    'file_format'   => ['INI', 'BIN'],
    'output_format' => ['AHD', 'YUV422'],
    'support_mode'  => ['Master', 'Slave'],
];

// List view omits the large TEXT columns (content, ext_txt1..5) to keep the payload
// light. It does carry the first CONTENT_PREVIEW_CHARS characters of content as
// `content_preview`, so the table can show what a record holds without pulling a
// 200 KB hex image per row; the full column comes from the single-record view.
// account and comment are in the summary although comment is a TEXT column:
// it is a one-line note about the row, not a payload like content, and the
// list is where someone reads it to tell two similar rows apart.
const SUMMARY_COLUMNS = '`index`, file_format, fps, file_name, MHz, isPGL, output_format, support_mode, create_time, modify_time, account, comment';
const CONTENT_PREVIEW_CHARS = 200;
const LIST_COLUMNS = SUMMARY_COLUMNS . ', LEFT(content, ' . CONTENT_PREVIEW_CHARS . ') AS content_preview';
const DETAIL_COLUMNS = SUMMARY_COLUMNS . ', content, ext_str1, ext_str2, ext_int1, ext_txt1, ext_txt2, ext_txt3, ext_txt4, ext_txt5';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_GET['_method'])) {
    $method = strtoupper($_GET['_method']);
}

$index = isset($_GET['index']) ? (int) $_GET['index'] : null;

if ($index !== null && !Validator::isPositiveInt($_GET['index'])) {
    Response::error('Invalid index', 400);
}

switch ($method) {
    case 'GET':
        if ($index !== null) {
            if (isset($_GET['download'])) {
                downloadRecord($pdo, $index);
            } else {
                getRecord($pdo, $index);
            }
        } else {
            listRecords($pdo);
        }
        break;

    case 'POST':
        createRecord($pdo, $authenticatedAccount);
        break;

    case 'PUT':
        if ($index === null) {
            Response::error('index is required for update', 400);
        }
        updateRecord($pdo, $index, $authenticatedAccount);
        break;

    case 'DELETE':
        if ($index === null) {
            Response::error('index is required for delete', 400);
        }
        deleteRecord($pdo, $index);
        break;

    default:
        Response::error('Method not allowed', 405);
}

function listRecords(PDO $pdo): void {
    // ?all=1 bypasses pagination entirely and returns every row -- for callers that
    // genuinely want the full table in one response (this admin table is expected to
    // stay small; for a large/growing table, page through with ?page=&limit= instead).
    if (isset($_GET['all'])) {
        $stmt = $pdo->prepare('SELECT ' . LIST_COLUMNS . ' FROM ' . TABLE . ' ORDER BY `index` ASC');
        $stmt->execute();
        $items = $stmt->fetchAll();
        Response::json(['items' => $items, 'total' => count($items)]);
        return;
    }

    $limit = isset($_GET['limit']) && Validator::isPositiveInt($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $limit = min($limit, 100);
    $page = isset($_GET['page']) && Validator::isPositiveInt($_GET['page']) ? (int) $_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . TABLE)->fetchColumn();

    $stmt = $pdo->prepare('SELECT ' . LIST_COLUMNS . ' FROM ' . TABLE . ' ORDER BY `index` ASC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    Response::json([
        'items'       => $stmt->fetchAll(),
        'page'        => $page,
        'limit'       => $limit,
        'total'       => $total,
        'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
    ]);
}

function getRecord(PDO $pdo, int $index): void {
    $stmt = $pdo->prepare('SELECT ' . DETAIL_COLUMNS . ' FROM ' . TABLE . ' WHERE `index` = :index');
    $stmt->execute([':index' => $index]);
    $record = $stmt->fetch();

    if (!$record) {
        Response::error('Record not found', 404);
    }
    Response::json($record);
}

function downloadRecord(PDO $pdo, int $index): void {
    $stmt = $pdo->prepare('SELECT file_name, file_format, content FROM ' . TABLE . ' WHERE `index` = :index');
    $stmt->execute([':index' => $index]);
    $record = $stmt->fetch();

    if (!$record) {
        Response::error('Record not found', 404);
    }

    // A BIN row stores its image as hex text (MySQL TEXT cannot hold raw
    // binary), so the download has to be the bytes back -- a .bin file full of
    // "12 40 AD 01" would look like an image and burn as garbage.
    $content = $record['content'];
    if (strtoupper((string) $record['file_format']) === 'BIN') {
        $bytes = hexToBytes($content);
        if ($bytes === null) {
            Response::error('This BIN record\'s content is not valid hex text', 409);
        }
        $content = $bytes;
    }

    $fileName = $record['file_name'] !== '' ? $record['file_name'] : "eeprom_config_{$index}.txt";
    // The stored file_name reaches an HTTP header verbatim below -- strip characters
    // that could break out of the header value or the quoted filename.
    $fileName = preg_replace('/[\r\n"\\\\]/', '_', $fileName);

    // Overrides bootstrap.php's default JSON content-type -- fine as long as it
    // happens before any output, which it does here.
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function createRecord(PDO $pdo, string $account): void {
    $body = readJsonBody();

    try {
        Validator::requireFields($body, REQUIRED_FIELDS);
    } catch (InvalidArgumentException $e) {
        Response::error($e->getMessage(), 400);
    }

    $fields = normalizeFields(Validator::pick($body, WRITABLE_FIELDS));
    $fields['account'] = $account;

    $columns = array_keys($fields);
    $placeholders = array_map(fn($c) => ":{$c}", $columns);
    $sql = 'INSERT INTO ' . TABLE . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

    $params = [];
    foreach ($fields as $col => $val) {
        $params[":{$col}"] = $val;
    }
    $pdo->prepare($sql)->execute($params);

    getRecordOrFail($pdo, (int) $pdo->lastInsertId(), 201);
}

function updateRecord(PDO $pdo, int $index, string $account): void {
    $body = readJsonBody();
    $picked = Validator::pick($body, WRITABLE_FIELDS);

    if (empty($picked)) {
        Response::error('No updatable fields provided', 400);
    }

    // The row's current file_format decides how `content` is validated when the
    // update changes the content but not the format.
    $exists = $pdo->prepare('SELECT file_format FROM ' . TABLE . ' WHERE `index` = :index');
    $exists->execute([':index' => $index]);
    $current = $exists->fetch();
    if (!$current) {
        Response::error('Record not found', 404);
    }

    $fields = normalizeFields($picked, (string) $current['file_format']);
    // The column means "who wrote this row", so an update re-stamps it. The
    // empty()-check above runs on $picked, before this: an otherwise empty
    // request is still a 400 rather than a write that only changes the owner.
    $fields['account'] = $account;

    $setClauses = [];
    $params = [':index' => $index];
    foreach ($fields as $column => $value) {
        $setClauses[] = "{$column} = :{$column}";
        $params[":{$column}"] = $value;
    }

    $sql = 'UPDATE ' . TABLE . ' SET ' . implode(', ', $setClauses) . ' WHERE `index` = :index';
    $pdo->prepare($sql)->execute($params);

    getRecordOrFail($pdo, $index, 200);
}

function deleteRecord(PDO $pdo, int $index): void {
    $stmt = $pdo->prepare('DELETE FROM ' . TABLE . ' WHERE `index` = :index');
    $stmt->execute([':index' => $index]);

    if ($stmt->rowCount() === 0) {
        Response::error('Record not found', 404);
    }
    Response::json(['index' => $index]);
}

function getRecordOrFail(PDO $pdo, int $index, int $status): void {
    $stmt = $pdo->prepare('SELECT ' . DETAIL_COLUMNS . ' FROM ' . TABLE . ' WHERE `index` = :index');
    $stmt->execute([':index' => $index]);
    Response::json($stmt->fetch(), $status);
}

/**
 * Coerces isPGL/ext_int1 to the right PHP type so PDO binds them correctly,
 * and puts the constrained fields into their canonical spelling. Rejects a
 * value outside its domain with 400 -- see ENUM_FIELDS.
 */
function normalizeFields(array $fields, ?string $currentFormat = null): array {
    if (array_key_exists('isPGL', $fields)) {
        $fields['isPGL'] = filter_var($fields['isPGL'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (array_key_exists('ext_int1', $fields)) {
        $fields['ext_int1'] = ($fields['ext_int1'] === null || $fields['ext_int1'] === '')
            ? null
            : (int) $fields['ext_int1'];
    }

    foreach (ENUM_FIELDS as $field => $allowed) {
        if (!array_key_exists($field, $fields)) {
            continue;
        }
        $canonical = null;
        foreach ($allowed as $candidate) {
            if (strcasecmp((string) $fields[$field], $candidate) === 0) {
                $canonical = $candidate;
                break;
            }
        }
        if ($canonical === null) {
            Response::error(
                "Invalid {$field}: \"{$fields[$field]}\". Allowed: " . implode(', ', $allowed),
                400
            );
        }
        $fields[$field] = $canonical;
    }

    // A BIN row's content is hex text. Stored in one canonical spacing so that
    // a file uploaded here and the same file imported in the desktop tool come
    // out as the same string -- otherwise every sync would see a difference.
    $format = $fields['file_format'] ?? $currentFormat;
    if (array_key_exists('content', $fields) && strcasecmp((string) $format, 'BIN') === 0) {
        $bytes = hexToBytes((string) $fields['content']);
        if ($bytes === null) {
            Response::error(
                'content of a BIN record must be hex bytes, e.g. "12 40 AD 01"',
                400
            );
        }
        $fields['content'] = bytesToHex($bytes);
    }

    return $fields;
}

/**
 * Hex text -> bytes. Accepts bytes as 1-2 hex digits separated by whitespace or
 * commas, optionally 0x-prefixed. Returns null if anything is not a hex byte:
 * guessing at a damaged image is worse than refusing it.
 */
function hexToBytes(string $text): ?string {
    $out = '';
    foreach (preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $token) {
        if (stripos($token, '0x') === 0 && strlen($token) > 2) {
            $token = substr($token, 2);
        }
        if (!preg_match('/^[0-9a-fA-F]{1,2}$/', $token)) {
            return null;
        }
        $out .= chr(hexdec($token));
    }
    return $out;
}

/** Bytes -> "12 40 AD 01", 16 bytes per line, CRLF between lines. */
function bytesToHex(string $bytes): string {
    $parts = [];
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
        if ($i !== 0) {
            $parts[] = ($i % 16 === 0) ? "\r\n" : ' ';
        }
        $parts[] = strtoupper(str_pad(dechex(ord($bytes[$i])), 2, '0', STR_PAD_LEFT));
    }
    return implode('', $parts);
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        Response::error('Invalid JSON body', 400);
    }
    return $decoded;
}
