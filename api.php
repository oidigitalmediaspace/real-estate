<?php
require_once __DIR__ . '/backend/init.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/neon_driver.php';
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/file_driver.php';
require_once __DIR__ . '/backend/csv_helpers.php';
require_once __DIR__ . '/backend/google_sheets.php';
require_once __DIR__ . '/backend/neon_services.php';
require_once __DIR__ . '/backend/pipeline_helpers.php';

// ── roteamento ────────────────────────────────────────────────────────────────

$action = isset($_GET['action']) ? $_GET['action'] : '';

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
            'role'          => $u ? $u['role'] : null,
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
        json_response(array('success' => true, 'email' => $u['email'], 'role' => $u['role']));
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
        $u = auth_current_user();
        $allowed = null;
        if ($u['role'] !== 'admin' && $u['allowed_pipelines'] !== null) {
            $allowed = is_string($u['allowed_pipelines']) ? json_decode($u['allowed_pipelines'], true) : $u['allowed_pipelines'];
            if (!is_array($allowed)) $allowed = array();
        }
        $output  = array();
        foreach ($configs as $config) {
            if ($allowed !== null && !in_array($config['key'], $allowed, true)) continue;
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

    // ── gestão de usuários (admin only) ───────────────────────────────────────
    if ($action === 'list_users') {
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Acesso negado'), 403);
        $r = neon_query('SELECT id, email, role, allowed_pipelines, created_at, last_login_at FROM users ORDER BY created_at');
        $users = array();
        foreach ($r['rows'] as $row) {
            $ap = $row['allowed_pipelines'];
            if (is_string($ap)) $ap = json_decode($ap, true);
            $users[] = array(
                'id'                 => (int)$row['id'],
                'email'              => $row['email'],
                'role'               => $row['role'],
                'allowed_pipelines'  => $ap,
                'created_at'         => $row['created_at'],
                'last_login_at'      => $row['last_login_at'],
            );
        }
        json_response(array('success' => true, 'users' => $users));
    }

    if ($action === 'create_user') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Acesso negado'), 403);
        $input = json_decode(file_get_contents('php://input'), true);
        $email = isset($input['email']) ? strtolower(trim((string)$input['email'])) : '';
        $pw    = isset($input['password']) ? (string)$input['password'] : '';
        $role  = (isset($input['role']) && $input['role'] === 'admin') ? 'admin' : 'user';
        $ap    = isset($input['allowed_pipelines']) && is_array($input['allowed_pipelines']) ? $input['allowed_pipelines'] : array();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(array('success' => false, 'error' => 'Email inválido'), 400);
        if (strlen($pw) < 8) json_response(array('success' => false, 'error' => 'Senha muito curta (mínimo 8 caracteres)'), 400);

        $existing = neon_query('SELECT id FROM users WHERE email = $1', array($email));
        if (!empty($existing['rows'])) json_response(array('success' => false, 'error' => 'Já existe um usuário com este email'), 409);

        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $apJson = $role === 'admin' ? null : json_encode($ap);
        neon_query(
            'INSERT INTO users (email, password_hash, role, allowed_pipelines, failed_attempts) VALUES ($1, $2, $3, $4::jsonb, 0)',
            array($email, $hash, $role, $apJson)
        );
        json_response(array('success' => true));
    }

    if ($action === 'update_user') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Acesso negado'), 403);
        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        if ($userId <= 0) json_response(array('success' => false, 'error' => 'ID de usuário inválido'), 400);

        $target = neon_query('SELECT id, email, role FROM users WHERE id = $1', array($userId));
        if (empty($target['rows'])) json_response(array('success' => false, 'error' => 'Usuário não encontrado'), 404);

        if (isset($input['allowed_pipelines']) && is_array($input['allowed_pipelines'])) {
            $ap = $target['rows'][0]['role'] === 'admin' ? null : json_encode($input['allowed_pipelines']);
            neon_query('UPDATE users SET allowed_pipelines = $1::jsonb WHERE id = $2', array($ap, $userId));
        }

        if (isset($input['password']) && (string)$input['password'] !== '') {
            $pw = (string)$input['password'];
            if (strlen($pw) < 8) json_response(array('success' => false, 'error' => 'Senha muito curta (mínimo 8 caracteres)'), 400);
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            neon_query('UPDATE users SET password_hash = $1, failed_attempts = 0, locked_until = NULL WHERE id = $2', array($hash, $userId));
        }

        json_response(array('success' => true));
    }

    if ($action === 'delete_user') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Acesso negado'), 403);
        $input  = json_decode(file_get_contents('php://input'), true);
        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        if ($userId <= 0) json_response(array('success' => false, 'error' => 'ID de usuário inválido'), 400);

        $me = auth_current_user();
        if ((int)$me['id'] === $userId) json_response(array('success' => false, 'error' => 'Você não pode excluir a própria conta'), 400);

        $target = neon_query('SELECT id FROM users WHERE id = $1', array($userId));
        if (empty($target['rows'])) json_response(array('success' => false, 'error' => 'Usuário não encontrado'), 404);

        neon_query('DELETE FROM sessions WHERE user_id = $1', array($userId));
        neon_query('DELETE FROM users WHERE id = $1', array($userId));
        json_response(array('success' => true));
    }

    $pipeline = current_pipeline();

    // Verificar se o usuário tem acesso ao pipeline solicitado
    $u = auth_current_user();
    if ($u['role'] !== 'admin' && $u['allowed_pipelines'] !== null) {
        $allowed = is_string($u['allowed_pipelines']) ? json_decode($u['allowed_pipelines'], true) : $u['allowed_pipelines'];
        if (is_array($allowed) && !in_array($pipeline['key'], $allowed, true)) {
            json_response(array('success' => false, 'error' => 'Você não tem acesso a este pipeline'), 403);
        }
    }

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

    if ($action === 'add_lead') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(array('success' => false, 'error' => 'Método inválido'), 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            json_response(array('success' => false, 'error' => 'Payload JSON inválido'), 400);
        }

        if ($pipeline['driver'] === 'neon') {
            $createdLead = neon_add_lead($pipeline, $input);
            json_response(array('success' => true, 'lead' => $createdLead));
        }

        $createdLead = file_add_lead($pipeline, $input);
        json_response(array('success' => true, 'lead' => $createdLead));
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
        if ((auth_current_user()['role'] ?? '') !== 'admin') json_response(array('success' => false, 'error' => 'Apenas administradores podem importar dados.'), 403);
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
