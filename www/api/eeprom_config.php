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
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/SessionAuth.php';
require __DIR__ . '/lib/CredentialAuth.php';

$authenticatedAccount = SessionAuth::currentAccount() ?? CredentialAuth::verify($pdo);
if ($authenticatedAccount === null) {
    Response::error('Login required', 401);
}

const TABLE = 'eeprom_config';

const WRITABLE_FIELDS = [
    'file_format', 'fps', 'file_name', 'MHz', 'isPGL', 'output_format', 'support_mode',
    'content', 'ext_str1', 'ext_str2', 'ext_int1', 'ext_txt1', 'ext_txt2', 'ext_txt3', 'ext_txt4', 'ext_txt5',
];
const REQUIRED_FIELDS = ['file_format', 'fps', 'file_name', 'MHz', 'output_format', 'support_mode', 'content'];

// List view omits the large TEXT columns (content, ext_txt1..5) to keep the payload light.
const LIST_COLUMNS = '`index`, file_format, fps, file_name, MHz, isPGL, output_format, support_mode, create_time, modify_time';
const DETAIL_COLUMNS = LIST_COLUMNS . ', content, ext_str1, ext_str2, ext_int1, ext_txt1, ext_txt2, ext_txt3, ext_txt4, ext_txt5';

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
        createRecord($pdo);
        break;

    case 'PUT':
        if ($index === null) {
            Response::error('index is required for update', 400);
        }
        updateRecord($pdo, $index);
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
    $stmt = $pdo->prepare('SELECT file_name, content FROM ' . TABLE . ' WHERE `index` = :index');
    $stmt->execute([':index' => $index]);
    $record = $stmt->fetch();

    if (!$record) {
        Response::error('Record not found', 404);
    }

    $fileName = $record['file_name'] !== '' ? $record['file_name'] : "eeprom_config_{$index}.txt";
    // The stored file_name reaches an HTTP header verbatim below -- strip characters
    // that could break out of the header value or the quoted filename.
    $fileName = preg_replace('/[\r\n"\\\\]/', '_', $fileName);

    // Overrides bootstrap.php's default JSON content-type -- fine as long as it
    // happens before any output, which it does here.
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($record['content']));
    echo $record['content'];
    exit;
}

function createRecord(PDO $pdo): void {
    $body = readJsonBody();

    try {
        Validator::requireFields($body, REQUIRED_FIELDS);
    } catch (InvalidArgumentException $e) {
        Response::error($e->getMessage(), 400);
    }

    $fields = normalizeFields(Validator::pick($body, WRITABLE_FIELDS));

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

function updateRecord(PDO $pdo, int $index): void {
    $body = readJsonBody();
    $fields = normalizeFields(Validator::pick($body, WRITABLE_FIELDS));

    if (empty($fields)) {
        Response::error('No updatable fields provided', 400);
    }

    $exists = $pdo->prepare('SELECT `index` FROM ' . TABLE . ' WHERE `index` = :index');
    $exists->execute([':index' => $index]);
    if (!$exists->fetch()) {
        Response::error('Record not found', 404);
    }

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

/** Coerces isPGL/ext_int1 to the right PHP type so PDO binds them correctly. */
function normalizeFields(array $fields): array {
    if (array_key_exists('isPGL', $fields)) {
        $fields['isPGL'] = filter_var($fields['isPGL'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (array_key_exists('ext_int1', $fields)) {
        $fields['ext_int1'] = ($fields['ext_int1'] === null || $fields['ext_int1'] === '')
            ? null
            : (int) $fields['ext_int1'];
    }
    return $fields;
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
