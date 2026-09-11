<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

define('ADMIN_EMAIL',    'comercial@oidigitalmedia.com');
define('SESSION_COOKIE', 'oi_session');
define('REMEMBER_TTL',   30 * 24 * 60 * 60);  // 30 dias
define('SHORT_TTL',      12 * 60 * 60);        // 12h (sessão curta / "não lembrar")
define('MAX_FAILED',     5);                   // tentativas antes de bloquear
define('LOCK_MINUTES',   15);                  // duração do bloqueio

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ── helpers ───────────────────────────────────────────────────────────────────

function real_estate_statuses() {
    return array('Novo', 'Em andamento', 'Fechado');
}

function labels_from_statuses($statuses) {
    $labels = array();
    foreach ($statuses as $status) {
        $labels[$status] = $status;
    }
    return $labels;
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── autenticação (tabela users/sessions no Neon; sem session_start()) ─────────

function auth_current_user() {
    static $cached = false;                 // false = ainda não resolvido; null = anônimo
    if ($cached !== false) return $cached;
    $token = isset($_COOKIE[SESSION_COOKIE]) ? $_COOKIE[SESSION_COOKIE] : '';
    if ($token === '') return $cached = null;
    $r = neon_query(
        'SELECT u.id, u.email, u.role, s.expires_at
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token = $1', array($token));
    if (empty($r['rows'])) return $cached = null;
    $row = $r['rows'][0];
    if (strtotime($row['expires_at']) < time()) {              // expirada → limpa
        neon_query('DELETE FROM sessions WHERE token = $1', array($token));
        return $cached = null;
    }
    return $cached = $row;
}

function require_auth() {
    if (auth_current_user() === null) {
        json_response(array('success' => false, 'error' => 'Não autenticado',
                            'code' => 'unauthenticated'), 401);
    }
}

function auth_set_cookie($token, $remember) {
    setcookie(SESSION_COOKIE, $token, array(
        'expires'  => $remember ? time() + REMEMBER_TTL : 0,   // 0 = cookie de sessão
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

function auth_needs_setup() {
    $r = neon_query('SELECT password_hash FROM users WHERE email = $1', array(ADMIN_EMAIL));
    if (empty($r['rows'])) return false;                        // migração não rodou
    return $r['rows'][0]['password_hash'] === null;
}

// ── driver: file (real_estate only, intocável) ────────────────────────────────

function file_pipeline_config() {
    $statuses = real_estate_statuses();
    return array(
        'key'            => 'real_estate',
        'name'           => 'Real Estate',
        'driver'         => 'file',
        'file'           => __DIR__ . '/data.json',
        'statuses'       => $statuses,
        'column_labels'  => labels_from_statuses($statuses),
        'editable_fields'=> array('Status', 'Observação', 'Internal_Notes'),
        'supports_import'=> true,
        'supports_sync'  => false,
        'supports_delete'=> false,
    );
}

function empty_data($pipeline) {
    return array(
        'leads'        => array(),
        'last_updated' => '',
        'last_synced'  => '',
        'statuses'     => $pipeline['statuses'],
        'column_labels'=> $pipeline['column_labels']
    );
}

function read_data($pipeline) {
    $dataFile = $pipeline['file'];

    if (!file_exists($dataFile)) {
        return empty_data($pipeline);
    }

    $contents = file_get_contents($dataFile);
    if ($contents === false || trim($contents) === '') {
        return empty_data($pipeline);
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        json_response(array('success' => false, 'error' => basename($dataFile) . ' inválido'), 500);
    }

    if (!isset($data['leads']) || !is_array($data['leads'])) {
        $data['leads'] = array();
    }

    if (!isset($data['last_updated'])) {
        $data['last_updated'] = '';
    }

    if (!isset($data['last_synced'])) {
        $data['last_synced'] = '';
    }

    if (!isset($data['statuses']) || !is_array($data['statuses']) || empty($data['statuses'])) {
        $data['statuses'] = $pipeline['statuses'];
    }

    $defaultLabels = labels_from_statuses($data['statuses']);
    if (!isset($data['column_labels']) || !is_array($data['column_labels'])) {
        $data['column_labels'] = $defaultLabels;
    } else {
        $storedLabels = $data['column_labels'];
        $data['column_labels'] = $defaultLabels;
        foreach ($data['statuses'] as $status) {
            if (isset($storedLabels[$status]) && trim((string)$storedLabels[$status]) !== '') {
                $data['column_labels'][$status] = trim((string)$storedLabels[$status]);
            }
        }
    }

    return $data;
}

function write_data($pipeline, &$data) {
    $data['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false || file_put_contents($pipeline['file'], $json, LOCK_EX) === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível escrever ' . basename($pipeline['file'])), 500);
    }
}

// ── CSV helpers (compartilhado) ───────────────────────────────────────────────

function normalize_header($value) {
    return trim((string)$value);
}

function get_existing_map($leads) {
    $map = array();
    foreach ($leads as $index => $lead) {
        if (isset($lead['Lead_ID']) && $lead['Lead_ID'] !== '') {
            $map[$lead['Lead_ID']] = $index;
        }
    }
    return $map;
}

function read_csv_headers($handle) {
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        return false;
    }

    $headers = array_map('normalize_header', $headers);
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    return $headers;
}

function row_to_assoc($headers, $row) {
    $lead = array();
    foreach ($headers as $index => $header) {
        if ($header === '') {
            continue;
        }
        $lead[$header] = isset($row[$index]) ? (string)$row[$index] : '';
    }
    return $lead;
}

function fetch_csv($url) {
    if (function_exists('curl_init')) {
        $csv = http_request_body($url, 'GET', array('User-Agent: OiDigitalMediaCRM/1.0'), null, true);
        if (trim($csv) === '') {
            json_response(array('success' => false, 'error' => 'A planilha publicada está vazia'), 502);
        }
        return $csv;
    }
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 20,
            'header'  => "User-Agent: OiDigitalMediaCRM/1.0\r\nConnection: close\r\n"
        )
    ));

    $csv = @file_get_contents($url, false, $context);
    if ($csv === false || trim($csv) === '') {
        json_response(array('success' => false, 'error' => 'Não foi possível baixar o CSV publicado'), 502);
    }

    return $csv;
}

function open_csv_string($csv) {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível preparar o CSV'), 500);
    }

    fwrite($handle, $csv);
    rewind($handle);
    return $handle;
}

// ── import: somente real_estate ───────────────────────────────────────────────

function import_real_estate($pipeline) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        json_response(array('success' => false, 'error' => 'Arquivo CSV obrigatório'), 400);
    }

    $expectedFields = array(
        'Lead_ID', 'Name', 'Type', 'Phone', 'Email', 'Website', 'Instagram', 'Linkedin',
        'Observação', 'Score_Fase1', 'Tier_Fase1', 'Recommended_Angle', 'IG_Followers',
        'IG_PostCount', 'IG_Posts_30d', 'IG_Last_Post_Days', 'IG_Activity', 'LI_Followers',
        'LI_Connections', 'LI_Headline', 'LI_Company', 'Website_Active', 'Website_Summary',
        'Gender', 'Brand_Score', 'Approach_Type', 'New_Tier', 'Website_Match',
        'Short_Note', 'First_Message'
    );

    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    if ($handle === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível ler o CSV'), 400);
    }

    $headers = read_csv_headers($handle);
    if ($headers === false) {
        fclose($handle);
        json_response(array('success' => false, 'error' => 'CSV vazio ou sem headers'), 400);
    }

    $data     = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);
    $imported = 0;
    $updated  = 0;
    $new      = 0;
    $skipped  = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        $csvLead = array();
        foreach ($expectedFields as $field) {
            $index = array_search($field, $headers, true);
            $csvLead[$field] = ($index !== false && isset($row[$index])) ? (string)$row[$index] : '';
        }

        if ($csvLead['Lead_ID'] === '') {
            $skipped++;
            continue;
        }

        $imported++;
        if (isset($existingMap[$csvLead['Lead_ID']])) {
            $leadIndex  = $existingMap[$csvLead['Lead_ID']];
            $existingLead = $data['leads'][$leadIndex];
            $csvLead['Status']        = isset($existingLead['Status'])        ? $existingLead['Status']        : 'Novo';
            $csvLead['Observação']    = isset($existingLead['Observação'])    ? $existingLead['Observação']    : $csvLead['Observação'];
            $csvLead['Internal_Notes']= isset($existingLead['Internal_Notes'])? $existingLead['Internal_Notes']: '';
            $data['leads'][$leadIndex] = $csvLead;
            $updated++;
        } else {
            $csvLead['Status']         = 'Novo';
            $csvLead['Internal_Notes'] = '';
            $data['leads'][]           = $csvLead;
            $existingMap[$csvLead['Lead_ID']] = count($data['leads']) - 1;
            $new++;
        }
    }
    fclose($handle);

    write_data($pipeline, $data);
    json_response(array(
        'success'  => true,
        'imported' => $imported,
        'updated'  => $updated,
        'new'      => $new,
        'skipped'  => $skipped
    ));
}

// ── driver: Neon (HTTP SQL API — sem pdo_pgsql) ───────────────────────────────

function neon_http_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $dsn = defined('NEON_DATABASE_URL') ? NEON_DATABASE_URL : getenv('NEON_DATABASE_URL');
    if (!$dsn) throw new Exception('NEON_DATABASE_URL não configurada no servidor');
    $p = parse_url($dsn);
    // HTTP SQL API usa endpoint sem -pooler
    $host = str_replace('-pooler.', '.', $p['host']);
    // Neon-Connection-String header usa a connection string sem params de TLS
    $conn = 'postgresql://' . $p['user'] . ':' . $p['pass'] . '@' . $host . $p['path'];
    $cfg = array(
        'endpoint'    => 'https://' . $host . '/sql',
        'conn_string' => $conn,
    );
    return $cfg;
}

function http_request_body($url, $method, $headers, $body = null, $followRedirects = false, $maxBytes = 0) {
    $handle = curl_init($url);
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    );
    // Usa os certificados confiáveis do Windows sem desativar a validação TLS.
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    $buffer = '';
    $tooLarge = false;
    if ($maxBytes > 0) {
        $options[CURLOPT_WRITEFUNCTION] = function ($handle, $chunk) use (&$buffer, &$tooLarge, $maxBytes) {
            if (strlen($buffer) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
            $buffer .= $chunk;
            return strlen($chunk);
        };
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($tooLarge) throw new InvalidArgumentException('A planilha excede o limite de 5 MB para cadastro.');
    if ($raw === false) {
        throw new Exception('Falha de conexão com o serviço. Não foi possível confirmar a operação; recarregue os dados antes de repetir uma alteração.');
    }
    if ($status < 200 || $status >= 300) {
        throw new Exception('O serviço respondeu com erro HTTP ' . $status . '.');
    }
    return $maxBytes > 0 ? $buffer : $raw;
}

function published_sheet_url($url) {
    $parts = parse_url(trim((string)$url));
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'docs.google.com'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || !preg_match('~^/spreadsheets/d/e/([A-Za-z0-9_-]+)/pub(?:html)?$~', $parts['path'] ?? '', $match)) {
        throw new InvalidArgumentException('Use o link do Google Sheets em Arquivo > Compartilhar > Publicar na Web.');
    }
    parse_str($parts['query'] ?? '', $query);
    $params = array('output' => 'csv');
    if (isset($query['gid'])) {
        if (!is_string($query['gid']) || !ctype_digit($query['gid'])) throw new InvalidArgumentException('A aba indicada no link é inválida.');
        $params['gid'] = $query['gid'];
        $params['single'] = 'true';
    }
    return 'https://docs.google.com/spreadsheets/d/e/' . $match[1] . '/pub?' . http_build_query($params);
}

function published_workbook_url($url) {
    return explode('?', published_sheet_url($url))[0] . '?output=csv';
}

function discover_published_tabs($html, $source) {
    $base = explode('?', published_workbook_url($source))[0];
    preg_match_all('/items\.push\(\{name:\s*("(?:\\\\.|[^"\\\\])*").*?\bgid:\s*"(\d+)"/s', $html, $matches, PREG_SET_ORDER);
    $tabs = array();
    foreach ($matches as $match) {
        $name = json_decode($match[1], true);
        $gid = $match[2];
        $tabs[$gid] = array('name' => is_string($name) ? $name : 'Aba ' . $gid, 'url' => $base . '?output=csv&gid=' . $gid . '&single=true');
    }
    // Algumas publicações usam um menu HTML em vez de items.push.
    if (!$tabs) {
        preg_match_all('/<li\b[^>]*id=["\']sheet-button-(\d+)["\'][^>]*>(.*?)<\/li>/s', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) $tabs[$match[1]] = array('name' => trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8')), 'url' => $base . '?output=csv&gid=' . $match[1] . '&single=true');
    }
    if (!$tabs) throw new InvalidArgumentException('Não foi possível identificar as abas publicadas. Publique o documento inteiro em “Publicar na Web” e tente novamente.');
    if (count($tabs) > 20) throw new InvalidArgumentException('Limite de 20 abas publicadas por planilha.');
    return array_values($tabs);
}

function merge_published_tabs($tabs) {
    $rows = array(); $headers = array(); $duplicates = 0;
    foreach ($tabs as $tab) {
        $parsed = $tab['parsed'];
        $duplicates += $parsed['duplicates'];
        foreach ($parsed['headers'] as $header) $headers[$header] = true;
        foreach ($parsed['rows'] as $row) {
            $id = $row['Lead_ID'];
            if (!isset($rows[$id])) $rows[$id] = array('Lead_ID' => $id);
            foreach ($row as $field => $value) {
                if ($field === 'Lead_ID') continue;
                if (!isset($rows[$id][$field]) || trim($rows[$id][$field]) === '') {
                    $rows[$id][$field] = $value;
                } elseif (trim($value) !== '' && $rows[$id][$field] !== $value) {
                    // Não apaga respostas diferentes que usam o mesmo título em outras abas.
                    $alias = $tab['name'] . ' — ' . $field;
                    $rows[$id][$alias] = $value;
                    $headers[$alias] = true;
                }
            }
            if (count($rows) > 5000) throw new InvalidArgumentException('Limite de 5.000 leads por planilha.');
        }
    }
    if (!$rows) throw new InvalidArgumentException('As abas publicadas ainda não possuem leads.');
    return array('headers' => array_keys($headers), 'rows' => array_values($rows), 'duplicates' => $duplicates);
}

function load_published_workbook($url) {
    $source = published_workbook_url($url);
    $html = http_request_body(explode('?', $source)[0] . 'html', 'GET', array(), null, true, 5 * 1024 * 1024);
    $tabs = discover_published_tabs($html, $source);
    $totalBytes = 0;
    foreach ($tabs as &$tab) {
        $csv = http_request_body($tab['url'], 'GET', array(), null, true, 5 * 1024 * 1024);
        $totalBytes += strlen($csv);
        if ($totalBytes > 20 * 1024 * 1024) throw new InvalidArgumentException('O conjunto de abas excede 20 MB.');
        try { $tab['parsed'] = parse_new_sheet($csv, true); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Aba “' . $tab['name'] . '”: ' . $e->getMessage()); }
    }
    unset($tab);
    $parsed = merge_published_tabs($tabs);
    $parsed['sheet_urls'] = array_column($tabs, 'url');
    $parsed['sheet_names'] = array_column($tabs, 'name');
    $parsed['workbook_url'] = $source;
    return $parsed;
}

function parse_new_sheet($csv, $allowEmpty = false) {
    if (strlen($csv) > 5 * 1024 * 1024) throw new InvalidArgumentException('A planilha excede o limite de 5 MB.');
    $handle = open_csv_string($csv);
    try {
        $headers = read_csv_headers($handle);
        if (!$headers || !in_array('Lead_ID', $headers, true)) {
            throw new InvalidArgumentException('A primeira linha precisa conter a coluna Lead_ID. Publique a aba correta como CSV separado por vírgulas.');
        }
        if (count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
            throw new InvalidArgumentException('As colunas precisam ter nomes preenchidos e sem repetição.');
        }
        $rows = array();
        $duplicates = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (!array_filter($row, fn($value) => trim((string)$value) !== '')) continue;
            if (count($row) !== count($headers)) throw new InvalidArgumentException('Há uma linha com quantidade de campos diferente do cabeçalho.');
            $data = row_to_assoc($headers, $row);
            $id = trim($data['Lead_ID']);
            if ($id === '') throw new InvalidArgumentException('Cada linha com dados precisa de um Lead_ID preenchido.');
            if (isset($rows[$id])) $duplicates++;
            $data['Lead_ID'] = $id;
            $rows[$id] = $data;
            if (count($rows) > 5000) throw new InvalidArgumentException('Limite de 5.000 leads por cadastro.');
        }
        if (!$rows && !$allowEmpty) throw new InvalidArgumentException('A planilha ainda não possui leads para importar.');
        return array('headers' => $headers, 'rows' => array_values($rows), 'duplicates' => $duplicates);
    } finally {
        fclose($handle);
    }
}

function sheet_display_config($parsed) {
    $display = array('last_synced' => gmdate('Y-m-d\TH:i:s\Z'));
    if (isset($parsed['workbook_url'])) {
        $display['published_workbook_url'] = $parsed['workbook_url'];
        $display['sheet_names'] = $parsed['sheet_names'];
    }
    foreach (array('Number' => 'Número', 'Padronized Number' => 'Número Padronizado', 'DateTime' => 'Data de Entrada') as $target => $field) {
        if (!in_array($target, $parsed['headers'], true) && in_array($field, $parsed['headers'], true)) $display['field_map'][$target] = $field;
    }
    if (!in_array('Score AI', $parsed['headers'], true)) {
        foreach (array('Tier', 'Score', 'New_Tier') as $field) {
            if (in_array($field, $parsed['headers'], true)) { $display['field_map']['Score AI'] = $field; break; }
        }
    }
    return $display;
}

function new_sheet_queries($key, $name, $url, $parsed) {
    $statuses = real_estate_statuses();
    $display = sheet_display_config($parsed);
    return array(
        array('query' => 'INSERT INTO pipelines (key, name, statuses, board_statuses, column_labels, sheet_urls, display) VALUES ($1,$2,$3::jsonb,$3::jsonb,$4::jsonb,$5::jsonb,$6::jsonb)',
            'params' => array($key, $name, json_encode($statuses), json_encode(labels_from_statuses($statuses)), json_encode($parsed['sheet_urls'] ?? array($url)), json_encode($display))),
        array('query' => "INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes) SELECT \$1, item->>'Lead_ID', item - 'Lead_ID', 'Novo', '' FROM jsonb_array_elements(\$2::jsonb) AS item",
            'params' => array($key, json_encode($parsed['rows'], JSON_UNESCAPED_UNICODE)))
    );
}

function neon_http($body) {
    $c = neon_http_config();
    if (function_exists('curl_init')) {
        $raw = http_request_body($c['endpoint'], 'POST', array(
            'Content-Type: application/json',
            'Neon-Connection-String: ' . $c['conn_string']
        ), json_encode($body, JSON_UNESCAPED_UNICODE));
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        =>
                "Content-Type: application/json\r\n" .
                "Connection: close\r\n" .
                "Neon-Connection-String: " . $c['conn_string'] . "\r\n",
            'content'       => json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout'       => 20,
            'ignore_errors' => true,
        )));
        $raw = @file_get_contents($c['endpoint'], false, $ctx);
        if ($raw === false) throw new Exception('Neon HTTP: sem resposta do servidor');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('Neon HTTP: resposta inválida');
    if (isset($data['message'])) throw new Exception('Neon: ' . $data['message']);
    return $data;
}

function neon_query($sql, $params = array()) {
    return neon_http(array('query' => $sql, 'params' => array_values($params)));
}

function neon_transaction($queries) {
    return neon_http(array('queries' => $queries));
}

function neon_row_to_pipeline($row) {
    // HTTP API retorna JSONB como array nativo; PDO retornaria string — aceita os dois
    $d = function($v) { return is_string($v) ? json_decode($v, true) : $v; };
    $cl = $d($row['column_labels']);
    return array(
        'key'             => $row['key'],
        'name'            => $row['name'],
        'driver'          => 'neon',
        'statuses'        => $d($row['statuses'])        ?: array(),
        'board_statuses'  => isset($row['board_statuses']) && $row['board_statuses'] !== null ? $d($row['board_statuses']) : null,
        'editable_fields' => $d($row['editable_fields']) ?: array('Status', 'Internal_Notes'),
        'column_labels'   => is_array($cl) && !empty($cl) ? $cl : array(),
        'sheet_urls'      => $d($row['sheet_urls'])      ?: array(),
        'display'         => $d($row['display'])         ?: array(),
        'supports_import' => false,
        'supports_sync'   => (bool)$row['supports_sync'],
        'supports_delete' => (bool)$row['supports_delete'],
    );
}

function neon_list_pipelines() {
    $result = neon_query('SELECT * FROM pipelines ORDER BY created_at');
    $out = array();
    foreach ($result['rows'] as $row) {
        $out[$row['key']] = neon_row_to_pipeline($row);
    }
    return $out;
}

function neon_get_pipeline($key) {
    $result = neon_query('SELECT * FROM pipelines WHERE key = $1', array($key));
    if (empty($result['rows'])) return null;
    return neon_row_to_pipeline($result['rows'][0]);
}

function neon_flatten_lead($row) {
    $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
    if (!is_array($data)) $data = array();
    return array_merge(
        array('Lead_ID' => $row['lead_id']),
        $data,
        array('Status' => $row['status'], 'Internal_Notes' => $row['internal_notes'])
    );
}

function neon_read_leads($pipeline) {
    $result = neon_query(
        'SELECT lead_id, data, status, internal_notes FROM leads WHERE pipeline_key = $1 ORDER BY created_at',
        array($pipeline['key'])
    );
    $leads = array();
    foreach ($result['rows'] as $row) {
        $leads[] = neon_flatten_lead($row);
    }
    return $leads;
}

function neon_update_lead($pipeline, $input) {
    $result = neon_query(
        'SELECT status, internal_notes FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $input['Lead_ID'])
    );
    if (empty($result['rows'])) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
    $existing  = $result['rows'][0];
    $newStatus = array_key_exists('Status', $input)         ? (string)$input['Status']        : $existing['status'];
    $newNotes  = array_key_exists('Internal_Notes', $input) ? (string)$input['Internal_Notes'] : $existing['internal_notes'];
    neon_query(
        'UPDATE leads SET status = $1, internal_notes = $2 WHERE pipeline_key = $3 AND lead_id = $4',
        array($newStatus, $newNotes, $pipeline['key'], $input['Lead_ID'])
    );
}

function neon_delete_pipeline($key) {
    if ($key === '' || $key === 'real_estate') throw new InvalidArgumentException('O pipeline CSV manual é protegido.');
    return neon_transaction(array(
        array('query' => 'DELETE FROM leads WHERE pipeline_key = $1', 'params' => array($key)),
        array('query' => 'DELETE FROM pipelines WHERE key = $1', 'params' => array($key))
    ));
}

function neon_delete_lead($pipeline, $leadId) {
    $result = neon_query(
        'DELETE FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $leadId)
    );
    if (($result['rowCount'] ?? 0) === 0) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
}

function neon_update_pipeline_columns($pipeline, $statuses, $labels, $moveFrom = array(), $moveTo = null) {
    $queries = array();
    if (!empty($moveFrom) && $moveTo !== null) {
        $queries[] = array(
            'query' => 'UPDATE leads
                           SET status = $1, updated_at = now()
                         WHERE pipeline_key = $2
                           AND status IN (SELECT jsonb_array_elements_text($3::jsonb))',
            'params' => array(
                $moveTo,
                $pipeline['key'],
                json_encode(array_values($moveFrom), JSON_UNESCAPED_UNICODE)
            )
        );
    }
    $queries[] = array(
        'query' => 'UPDATE pipelines
                      SET statuses = $1::jsonb,
                          board_statuses = CASE WHEN board_statuses IS NULL THEN NULL ELSE $1::jsonb END,
                          column_labels = $2::jsonb,
                          updated_at = now()
                    WHERE key = $3',
        'params' => array(
            json_encode(array_values($statuses), JSON_UNESCAPED_UNICODE),
            json_encode($labels, JSON_UNESCAPED_UNICODE),
            $pipeline['key']
        )
    );
    neon_transaction($queries);
    return array('statuses' => array_values($statuses), 'column_labels' => $labels);
}

function neon_sync_pipeline($pipeline, $loaded = null, $returnResult = false) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    $sheetUrls = $pipeline['sheet_urls'];
    if (empty($sheetUrls)) {
        json_response(array('success' => false, 'error' => 'Nenhuma sheet_url configurada para este pipeline'), 400);
    }

    if ($loaded === null) {
        $workbooks = array();
        foreach ($sheetUrls as $sheetUrl) {
            try { $workbooks[published_workbook_url($sheetUrl)] = true; }
            catch (InvalidArgumentException $e) { $workbooks = array(); break; }
        }
        if (count($workbooks) === 1) $loaded = load_published_workbook(array_key_first($workbooks));
    }
    if ($loaded !== null) {
        $mergedByLeadId = array_column($loaded['rows'], null, 'Lead_ID');
        $sheetUrls = $loaded['sheet_urls'];
    } else {
        // Merge todas as abas por Lead_ID (LEFT JOIN: aba contato = base)
        $mergedByLeadId = array();
        foreach ($sheetUrls as $urlIndex => $url) {
            $csv    = fetch_csv($url);
            $handle = open_csv_string($csv);
            $headers = read_csv_headers($handle);
            if ($headers === false) {
                fclose($handle);
                json_response(array('success' => false, 'error' => 'CSV da aba ' . ($urlIndex + 1) . ' vazio ou sem headers'), 400);
            }

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) === 1 && trim($row[0]) === '') continue;

                $csvRow = row_to_assoc($headers, $row);
                $leadId = isset($csvRow['Lead_ID']) ? trim((string)$csvRow['Lead_ID']) : '';
                if ($leadId === '') continue;

                if (!isset($mergedByLeadId[$leadId])) {
                    $mergedByLeadId[$leadId] = array('Lead_ID' => $leadId);
                }
                foreach ($csvRow as $k => $v) {
                    if ($k !== 'Lead_ID') $mergedByLeadId[$leadId][$k] = $v;
                }
            }
            fclose($handle);
        }

    }

    $pipelineKey = $pipeline['key'];

    $existingResult = neon_query('SELECT lead_id FROM leads WHERE pipeline_key = $1', array($pipelineKey));
    $existingIds = array();
    foreach ($existingResult['rows'] as $row) {
        $existingIds[$row['lead_id']] = true;
    }

    $queries  = array();
    $imported = 0; $new = 0; $updated = 0;

    foreach ($mergedByLeadId as $leadId => $mergedRow) {
        $data = $mergedRow;
        unset($data['Lead_ID']);
        $queries[] = array(
            'query'  => "INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes)
                         VALUES (\$1, \$2, \$3::jsonb, 'Novo', '')
                         ON CONFLICT (pipeline_key, lead_id)
                         DO UPDATE SET data = excluded.data",
            'params' => array($pipelineKey, $leadId, json_encode($data, JSON_UNESCAPED_UNICODE)),
        );
        $imported++;
        if (isset($existingIds[$leadId])) { $updated++; } else { $new++; }
    }

    $display = $pipeline['display'] ?? array();
    if ($loaded !== null) {
        $detected = sheet_display_config($loaded);
        $detected['field_map'] = array_replace($detected['field_map'] ?? array(), $display['field_map'] ?? array());
        $display = array_replace($display, $detected);
    }
    $display['last_synced'] = gmdate('Y-m-d\\TH:i:s\\Z');
    $queries[] = array(
        'query' => 'UPDATE pipelines SET display = COALESCE(display, \'{}\'::jsonb) || $2::jsonb, sheet_urls = $3::jsonb, updated_at = now() WHERE key = $1',
        'params' => array($pipelineKey, json_encode($display, JSON_UNESCAPED_UNICODE), json_encode($sheetUrls))
    );
    neon_transaction($queries);

    $leads        = neon_read_leads($pipeline);
    $columnLabels = !empty($pipeline['column_labels'])
        ? $pipeline['column_labels']
        : labels_from_statuses($pipeline['statuses']);

    $payload = array(
        'display' => $display,
        'sheet_count' => count($sheetUrls),
        'success'      => true,
        'imported'     => $imported,
        'updated'      => $updated,
        'new'          => $new,
        'skipped'      => 0,
        'leads'        => $leads,
        'column_labels'=> $columnLabels,
        'last_updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'last_synced'  => gmdate('Y-m-d\TH:i:s\Z'),
        'statuses'     => $pipeline['statuses'],
    );
    if ($returnResult) return $payload;
    json_response($payload);
}

// ── resolução de pipeline ─────────────────────────────────────────────────────

function all_pipeline_configs() {
    $configs = array('real_estate' => file_pipeline_config());
    foreach (neon_list_pipelines() as $key => $config) {
        $configs[$key] = $config;
    }
    return $configs;
}

function current_pipeline() {
    $pipelineKey = isset($_GET['pipeline']) && $_GET['pipeline'] !== '' ? $_GET['pipeline'] : 'real_estate';

    if ($pipelineKey === 'real_estate') {
        return file_pipeline_config();
    }

    try {
        $config = neon_get_pipeline($pipelineKey);
        if ($config) return $config;
    } catch (Throwable $e) {
        json_response(array('success' => false, 'error' => 'Erro ao carregar pipeline: ' . $e->getMessage()), 500);
    }

    json_response(array('success' => false, 'error' => 'Pipeline inválido'), 400);
}

// ── roteamento ────────────────────────────────────────────────────────────────

try {
    // ── auth: público (sem sessão) ─────────────────────────────────────────
    if ($action === 'session') {
        if (auth_needs_setup()) {
            json_response(array('success' => true, 'authenticated' => false, 'needs_setup' => true));
        }
        $u = auth_current_user();
        json_response(array(
            'success'       => true,
            'authenticated' => $u !== null,
            'needs_setup'   => false,
            'email'         => $u ? $u['email'] : null,
        ));
    }

    if ($action === 'setup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success'=>false,'error'=>'Método inválido'), 405);
        if (!auth_needs_setup()) json_response(array('success'=>false,'error'=>'Senha já configurada'), 403);
        $input = json_decode(file_get_contents('php://input'), true);
        $pw = isset($input['password']) ? (string)$input['password'] : '';
        if (strlen($pw) < 8) json_response(array('success'=>false,'error'=>'Senha muito curta (mínimo 8 caracteres)'), 400);
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        neon_query('UPDATE users SET password_hash = $1 WHERE email = $2', array($hash, ADMIN_EMAIL));
        json_response(array('success' => true));   // front redireciona pra tela de login
    }

    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success'=>false,'error'=>'Método inválido'), 405);
        $input    = json_decode(file_get_contents('php://input'), true);
        $email    = isset($input['email']) ? strtolower(trim((string)$input['email'])) : '';
        $password = isset($input['password']) ? (string)$input['password'] : '';
        $remember = !empty($input['remember']);

        $r = neon_query('SELECT * FROM users WHERE email = $1', array($email));
        if (empty($r['rows'])) json_response(array('success'=>false,'error'=>'Email ou senha inválidos'), 401);
        $u = $r['rows'][0];

        if ($u['locked_until'] !== null && strtotime($u['locked_until']) > time()) {
            json_response(array('success'=>false,'error'=>'Conta temporariamente bloqueada. Tente novamente em alguns minutos.'), 423);
        }
        if (empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
            $fail = intval($u['failed_attempts']) + 1;
            $lock = $fail >= MAX_FAILED ? gmdate('Y-m-d\TH:i:s\Z', time() + LOCK_MINUTES * 60) : null;
            neon_query('UPDATE users SET failed_attempts = $1, locked_until = $2 WHERE id = $3',
                       array($fail, $lock, $u['id']));
            json_response(array('success'=>false,'error'=>'Email ou senha inválidos'), 401);
        }

        // sucesso
        $ttl   = $remember ? REMEMBER_TTL : SHORT_TTL;
        $token = bin2hex(random_bytes(32));
        neon_query('INSERT INTO sessions (token, user_id, expires_at, user_agent) VALUES ($1,$2,$3,$4)',
            array($token, $u['id'], gmdate('Y-m-d\TH:i:s\Z', time() + $ttl),
                  substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 255)));
        neon_query('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = now() WHERE id = $1',
                   array($u['id']));
        auth_set_cookie($token, $remember);
        json_response(array('success' => true, 'email' => $u['email']));
    }

    if ($action === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        $token = isset($_COOKIE[SESSION_COOKIE]) ? $_COOKIE[SESSION_COOKIE] : '';
        if ($token !== '') neon_query('DELETE FROM sessions WHERE token = $1', array($token));
        setcookie(SESSION_COOKIE, '', array('expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));
        json_response(array('success' => true));
    }

    require_auth();   // daqui pra baixo, tudo exige sessão válida

    if ($action === 'delete_pipeline') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Apenas administradores podem excluir pipelines.'), 403);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['pipeline'] ?? null) || ($input['confirmed'] ?? false) !== true) {
            json_response(array('success' => false, 'error' => 'Confirme a exclusão do pipeline.'), 400);
        }
        $key = trim($input['pipeline']);
        if ($key === '' || $key === 'real_estate') json_response(array('success' => false, 'error' => 'A opção CSV manual não pode ser excluída.'), 400);
        if (!neon_get_pipeline($key)) json_response(array('success' => false, 'error' => 'Pipeline não encontrado. Atualize a página.'), 404);
        neon_delete_pipeline($key);
        json_response(array('success' => true, 'pipeline' => $key));
    }

    if ($action === 'create_sheet_pipeline') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Apenas administradores podem cadastrar planilhas.'), 403);
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || !is_string($input['name'] ?? null) || !is_string($input['url'] ?? null)) {
                throw new InvalidArgumentException('Informe o nome e o link publicado da planilha.');
            }
            $name = trim($input['name']);
            if (!preg_match('/^.{1,120}$/us', $name)) throw new InvalidArgumentException('Informe um nome entre 1 e 120 caracteres.');
            $url = published_workbook_url($input['url']);
            $key = 'sheet_' . substr(hash('sha256', $url), 0, 24);
            foreach (neon_list_pipelines() as $existing) {
                foreach ($existing['sheet_urls'] as $existingUrl) {
                    try { $same = published_workbook_url($existingUrl) === $url; }
                    catch (InvalidArgumentException $e) { $same = false; }
                    if ($same) {
                        $loaded = load_published_workbook($url);
                        $result = neon_sync_pipeline($existing, $loaded, true);
                        json_response(array('success' => true, 'pipeline' => $existing['key'], 'existing' => true, 'sheet_count' => $result['sheet_count'], 'imported' => $result['imported']));
                    }
                }
            }
            if (!function_exists('curl_init')) throw new Exception('A extensão cURL precisa estar habilitada para cadastrar planilhas.');
            $parsed = load_published_workbook($url);
            neon_transaction(new_sheet_queries($key, $name, $url, $parsed));
            json_response(array('success' => true, 'pipeline' => $key, 'imported' => count($parsed['rows']), 'duplicates' => $parsed['duplicates'], 'sheet_count' => count($parsed['sheet_urls']), 'existing' => false));
        } catch (InvalidArgumentException $e) {
            json_response(array('success' => false, 'error' => $e->getMessage()), 400);
        }
    }

    // action=pipelines não precisa de pipeline específico
    if ($action === 'pipelines') {
        $configs = all_pipeline_configs();
        $output  = array();
        foreach ($configs as $config) {
            $entry = array(
                'key'             => $config['key'],
                'name'            => $config['name'],
                'driver'          => $config['driver'],
                'statuses'        => $config['statuses'],
                'supports_import' => !empty($config['supports_import']),
                'supports_sync'   => !empty($config['supports_sync']),
                'supports_delete' => !empty($config['supports_delete']),
                'editable_fields' => $config['editable_fields'],
            );
            if (!empty($config['board_statuses'])) $entry['board_statuses'] = $config['board_statuses'];
            if (!empty($config['display']))         $entry['display']        = $config['display'];
            $output[] = $entry;
        }
        json_response(array('success' => true, 'pipelines' => $output));
    }

    $pipeline = current_pipeline();

    if ($action === 'get_leads') {
        if ($pipeline['driver'] === 'neon') {
            $leads        = neon_read_leads($pipeline);
            $columnLabels = !empty($pipeline['column_labels'])
                ? $pipeline['column_labels']
                : labels_from_statuses($pipeline['statuses']);
            json_response(array(
                'success'       => true,
                'pipeline'      => $pipeline['key'],
                'leads'         => $leads,
                'column_labels' => $columnLabels,
                'display'       => $pipeline['display'],
                'last_updated'  => '',
                'last_synced'   => $pipeline['display']['last_synced'] ?? '',
                'statuses'      => $pipeline['statuses'],
                'supports_import'=> false,
                'supports_sync'  => $pipeline['supports_sync'],
                'supports_delete'=> $pipeline['supports_delete'],
            ));
        }

        $data = read_data($pipeline);
        json_response(array(
            'success'        => true,
            'pipeline'       => $pipeline['key'],
            'leads'          => $data['leads'],
            'column_labels'  => $data['column_labels'],
            'last_updated'   => $data['last_updated'],
            'last_synced'    => $data['last_synced'],
            'statuses'       => $data['statuses'],
            'supports_import'=> $pipeline['supports_import'],
            'supports_sync'  => $pipeline['supports_sync'],
            'supports_delete'=> $pipeline['supports_delete'],
        ));
    }

    if ($action === 'update_column_labels') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['column_labels']) || !is_array($input['column_labels'])) {
            json_response(array('success' => false, 'error' => 'column_labels obrigatório'), 400);
        }

        $fileData = null;
        $existingStatuses = $pipeline['statuses'];
        if ($pipeline['driver'] === 'file') {
            $fileData = read_data($pipeline);
            $existingStatuses = $fileData['statuses'];
        }

        $requestedStatuses = isset($input['statuses']) && is_array($input['statuses'])
            ? $input['statuses']
            : $existingStatuses;
        $statuses = array();
        $normalizedStatuses = array();
        foreach ($requestedStatuses as $requestedStatus) {
            $status = trim((string)$requestedStatus);
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($status, 'UTF-8') : strtolower($status);
            if ($status === '' || strlen($status) > 80 || isset($normalizedStatuses[$normalized])) continue;
            $normalizedStatuses[$normalized] = true;
            $statuses[] = $status;
        }
        if (empty($statuses)) {
            json_response(array('success' => false, 'error' => 'Ao menos um status válido é obrigatório'), 400);
        }
        if (count($statuses) > 50) {
            json_response(array('success' => false, 'error' => 'Limite de 50 colunas excedido'), 400);
        }

        $removedStatuses = array_values(array_diff($existingStatuses, $statuses));
        $moveRemovedLeadsTo = isset($input['move_removed_leads_to'])
            ? trim((string)$input['move_removed_leads_to'])
            : '';
        if ($moveRemovedLeadsTo !== '' && !in_array($moveRemovedLeadsTo, $statuses, true)) {
            json_response(array('success' => false, 'error' => 'A coluna de chegada precisa continuar no quadro'), 400);
        }
        if (!empty($removedStatuses)) {
            $statusesInUse = array();
            if ($pipeline['driver'] === 'neon') {
                $inUseResult = neon_query(
                    'SELECT status, count(*)::int AS lead_count
                       FROM leads
                      WHERE pipeline_key = $1
                        AND status IN (SELECT jsonb_array_elements_text($2::jsonb))
                      GROUP BY status',
                    array($pipeline['key'], json_encode($removedStatuses, JSON_UNESCAPED_UNICODE))
                );
                foreach ($inUseResult['rows'] as $row) {
                    $statusesInUse[] = $row['status'];
                }
            } else {
                foreach ($fileData['leads'] as $lead) {
                    $leadStatus = isset($lead['Status']) && $lead['Status'] !== '' ? $lead['Status'] : 'Novo';
                    if (in_array($leadStatus, $removedStatuses, true) && !in_array($leadStatus, $statusesInUse, true)) {
                        $statusesInUse[] = $leadStatus;
                    }
                }
            }
            if (!empty($statusesInUse) && $moveRemovedLeadsTo === '') {
                json_response(array(
                    'success' => false,
                    'error' => 'Mova os leads antes de remover: ' . implode(', ', $statusesInUse)
                ), 409);
            }
        }

        $labels = labels_from_statuses($statuses);
        foreach ($statuses as $status) {
            if (isset($input['column_labels'][$status])) {
                $label = trim((string)$input['column_labels'][$status]);
                $labels[$status] = $label !== '' ? $label : $status;
            }
        }

        if ($pipeline['driver'] === 'neon') {
            $saved = neon_update_pipeline_columns(
                $pipeline,
                $statuses,
                $labels,
                $moveRemovedLeadsTo !== '' ? $removedStatuses : array(),
                $moveRemovedLeadsTo !== '' ? $moveRemovedLeadsTo : null
            );
        } else {
            if ($moveRemovedLeadsTo !== '' && !empty($removedStatuses)) {
                foreach ($fileData['leads'] as &$lead) {
                    $leadStatus = isset($lead['Status']) && $lead['Status'] !== '' ? $lead['Status'] : 'Novo';
                    if (in_array($leadStatus, $removedStatuses, true)) {
                        $lead['Status'] = $moveRemovedLeadsTo;
                    }
                }
                unset($lead);
            }
            $fileData['statuses'] = $statuses;
            $fileData['column_labels'] = $labels;
            write_data($pipeline, $fileData);
            $saved = array('statuses' => $statuses, 'column_labels' => $labels);
        }

        json_response(array(
            'success' => true,
            'statuses' => $saved['statuses'],
            'column_labels' => $saved['column_labels']
        ));
    }

    if ($action === 'update_lead') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['Lead_ID'])) {
            json_response(array('success' => false, 'error' => 'Lead_ID obrigatório'), 400);
        }

        if ($pipeline['driver'] === 'neon') {
            neon_update_lead($pipeline, $input);
            json_response(array('success' => true));
        }

        $data  = read_data($pipeline);
        $found = false;

        foreach ($data['leads'] as &$lead) {
            if (isset($lead['Lead_ID']) && $lead['Lead_ID'] === $input['Lead_ID']) {
                foreach ($pipeline['editable_fields'] as $field) {
                    if (array_key_exists($field, $input)) {
                        $lead[$field] = (string)$input[$field];
                    }
                }
                $found = true;
                break;
            }
        }
        unset($lead);

        if (!$found) {
            json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
        }

        write_data($pipeline, $data);
        json_response(array('success' => true));
    }

    if ($action === 'delete_lead') {
        if (!$pipeline['supports_delete']) {
            json_response(array('success' => false, 'error' => 'Excluir lead não está disponível neste pipeline'), 400);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input['Lead_ID'])) {
            json_response(array('success' => false, 'error' => 'Lead_ID obrigatório'), 400);
        }

        if ($pipeline['driver'] === 'neon') {
            neon_delete_lead($pipeline, $input['Lead_ID']);
            json_response(array('success' => true));
        }

        $data            = read_data($pipeline);
        $found           = false;
        $remainingLeads  = array();

        foreach ($data['leads'] as $lead) {
            if (isset($lead['Lead_ID']) && $lead['Lead_ID'] === $input['Lead_ID']) {
                $found = true;
                continue;
            }
            $remainingLeads[] = $lead;
        }

        if (!$found) {
            json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
        }

        $data['leads'] = $remainingLeads;
        write_data($pipeline, $data);
        json_response(array('success' => true));
    }

    if ($action === 'import') {
        if (!$pipeline['supports_import']) {
            json_response(array('success' => false, 'error' => 'Import manual não está disponível neste pipeline'), 400);
        }

        import_real_estate($pipeline);
    }

    if ($action === 'sync') {
        if (!$pipeline['supports_sync']) {
            json_response(array('success' => false, 'error' => 'Sync não está disponível neste pipeline'), 400);
        }

        if ($pipeline['driver'] === 'neon') {
            neon_sync_pipeline($pipeline);
        }
    }

    json_response(array('success' => false, 'error' => 'Ação inválida'), 400);
} catch (Exception $e) {
    json_response(array('success' => false, 'error' => $e->getMessage()), 500);
}
