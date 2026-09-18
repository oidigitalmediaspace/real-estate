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

// Token de segurança estático
define('WEBHOOK_SECRET_TOKEN', 'my-secret-token');

// Verifica o método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(array('success' => false, 'error' => 'Método não suportado. Use POST.'), 405);
}

// Verifica o Header de Autenticação
$headers = getallheaders();
$token = isset($headers['X-Webhook-Token']) ? $headers['X-Webhook-Token'] : '';

if ($token !== WEBHOOK_SECRET_TOKEN) {
    json_response(array('success' => false, 'error' => 'Token de autorização inválido ou ausente.'), 403);
}

// Lê o JSON da requisição
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!is_array($input)) {
    json_response(array('success' => false, 'error' => 'Payload JSON inválido.'), 400);
}

// Valida campos obrigatórios
$name = trim(isset($input['Name']) ? (string)$input['Name'] : (isset($input['Nome']) ? (string)$input['Nome'] : ''));

if ($name === '') {
    json_response(array('success' => false, 'error' => 'O campo Nome é obrigatório.'), 400);
}

// Adiciona o Lead no banco de dados "real_estate"
try {
    $pipelineConfigs = all_pipeline_configs();
    $realEstatePipeline = $pipelineConfigs['real_estate'];
    
    // As funções recebem a config do pipeline e os dados a serem salvos
    if ($realEstatePipeline['driver'] === 'neon') {
        $createdLead = neon_add_lead($realEstatePipeline, $input);
    } else {
        $createdLead = file_add_lead($realEstatePipeline, $input);
    }
    
    json_response(array(
        'success' => true,
        'message' => 'Lead adicionado com sucesso',
        'lead_id' => $createdLead['Lead_ID']
    ), 201);

} catch (Throwable $e) {
    json_response(array('success' => false, 'error' => 'Erro interno ao processar lead: ' . $e->getMessage()), 500);
}
