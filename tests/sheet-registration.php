<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas pela linha de comando.');
}
// Sem rede por padrão. --integration exercita apenas a branch de testes conhecida.
$source = "";
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/csv_helpers.php';
require_once __DIR__ . '/../backend/neon_driver.php';
require_once __DIR__ . '/../backend/google_sheets.php';
require_once __DIR__ . '/../backend/neon_services.php';
$passed = 0;
function check_sheet($condition, $message) {
    global $passed;
    if (!$condition) throw new Exception($message);
    $passed++;
}
function rejects_sheet($task) {
    try { $task(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
$url = 'https://docs.google.com/spreadsheets/d/e/test-publication/pub?gid=123&output=csv';
check_sheet(published_sheet_url($url) === published_sheet_url('https://docs.google.com/spreadsheets/d/e/test-publication/pub?output=html&gid=123&single=true'), 'Links equivalentes não foram normalizados.');
foreach (array('http://127.0.0.1/test', 'https://docs.google.com.evil.test/spreadsheets/d/e/x/pub', 'https://user@docs.google.com/spreadsheets/d/e/x/pub', 'https://docs.google.com/spreadsheets/d/private/edit', 'https://docs.google.com/spreadsheets/d/e/x/pub?gid[]=1') as $invalid) {
    check_sheet(rejects_sheet(fn() => published_sheet_url($invalid)), 'URL inválida aceita.');
}
$csv = "\xEF\xBB\xBFLead_ID,Nome,Score,Questionario\nfixture-1,Ana,Tier: 4,Resposta completa\nfixture-2,Bia,Tier: 2,Outra resposta\n";
$parsed = parse_new_sheet($csv);
check_sheet(count($parsed['rows']) === 2 && $parsed['rows'][0]['Questionario'] === 'Resposta completa', 'Dados do questionário perdidos.');
foreach (array("Nome,Score\nAna,4", "Lead_ID,Nome\n,Ana", "Lead_ID,Nome\nx,Ana,Extra", "Lead_ID,Nome,Nome\nx,Ana,Ana", "Lead_ID,Nome\n") as $invalidCsv) {
    check_sheet(rejects_sheet(fn() => parse_new_sheet($invalidCsv)), 'CSV inválido aceito.');
}
$repeated = parse_new_sheet("Lead_ID,Nome\nx,Ana\nx,Bia\n,\n");
check_sheet(count($repeated['rows']) === 1 && $repeated['rows'][0]['Nome'] === 'Bia' && $repeated['duplicates'] === 1, 'IDs repetidos não seguem a regra de sincronização.');
$queries = new_sheet_queries('fixture', 'Teste', published_sheet_url($url), $parsed);
check_sheet(count($queries) === 2 && json_decode($queries[0]['params'][5], true)['field_map']['Score AI'] === 'Score', 'Mapeamento de Score incorreto.');
check_sheet(str_contains($queries[1]['params'][1], 'Questionario'), 'Campos não preservados no INSERT.');
$menu = 'items.push({name: "DADOS", pageUrl: "ignored", gid: "1"});items.push({name: "QUESTIONARIO", pageUrl: "ignored", gid: "2"});';
$tabs = discover_published_tabs($menu, $url);
check_sheet(count($tabs) === 2 && str_contains($tabs[1]['url'], 'gid=2'), 'Não descobriu todas as abas.');
check_sheet(published_workbook_url($tabs[0]['url']) === published_workbook_url($tabs[1]['url']), 'O mesmo documento foi tratado como fontes diferentes.');
check_sheet(rejects_sheet(fn() => discover_published_tabs('<html>Publicação indisponível</html>', $url)), 'Publicação inválida deveria falhar.');
$multi = merge_published_tabs(array(
    array('name' => 'DADOS', 'parsed' => $parsed),
    array('name' => 'QUESTIONARIO', 'parsed' => parse_new_sheet("Lead_ID,Nome,Score,Resposta\nfixture-1,,Tier: 4,Sim\nfixture-2,Outro nome,,Não\n")),
    array('name' => 'VAZIA', 'parsed' => parse_new_sheet("Lead_ID,Nome\n", true))
));
check_sheet(count($multi['rows']) === 2 && $multi['rows'][0]['Nome'] === 'Ana' && $multi['rows'][0]['Resposta'] === 'Sim', 'A união perdeu respostas ou apagou contato com célula vazia.');
check_sheet($multi['rows'][1]['QUESTIONARIO — Nome'] === 'Outro nome' && $multi['rows'][1]['Nome'] === 'Bia', 'Campos diferentes com mesmo título não foram preservados.');
$multi['sheet_urls'] = array_column($tabs, 'url');
$multi['sheet_names'] = array_column($tabs, 'name');
$multi['workbook_url'] = published_workbook_url($url);
$multiQueries = new_sheet_queries('fixture', 'Teste', $url, $multi);
check_sheet(count(json_decode($multiQueries[0]['params'][4], true)) === 2, 'O cadastro não persiste todos os links.');

if (in_array('--integration', $argv, true)) {
    require __DIR__ . '/../config.php';
    $branch = neon_query("SELECT current_setting('neon.branch_id', true) AS branch_id")['rows'][0]['branch_id'];
    if ($branch !== 'br-empty-paper-atlpc4wa') throw new Exception('Teste interrompido: branch diferente de desenvolvimento.');
    $key = 'codex_test_' . bin2hex(random_bytes(8));
    $queries = new_sheet_queries($key, 'Teste temporário de cadastro', published_sheet_url($url), $parsed);
    $queries[] = array('query' => 'SELECT count(*)::int AS total FROM leads WHERE pipeline_key=$1', 'params' => array($key));
    $queries[] = array('query' => 'DELETE FROM leads WHERE pipeline_key=$1', 'params' => array($key));
    $queries[] = array('query' => 'DELETE FROM pipelines WHERE key=$1', 'params' => array($key));
    $result = neon_transaction($queries);
    $results = $result['results'] ?? $result;
    check_sheet((int)($results[2]['rows'][0]['total'] ?? -1) === 2, 'Cadastro transacional não importou os dois leads de teste.');
    $remaining = neon_query('SELECT count(*)::int AS total FROM pipelines WHERE key=$1', array($key));
    check_sheet((int)$remaining['rows'][0]['total'] === 0, 'Cadastro de teste não foi removido.');
    $badParsed = $parsed;
    $badParsed['rows'][] = $badParsed['rows'][0];
    $failed = false;
    try { neon_transaction(new_sheet_queries($key, 'Teste de rollback', published_sheet_url($url), $badParsed)); }
    catch (Exception $e) { $failed = true; }
    check_sheet($failed, 'A transação com IDs duplicados não falhou.');
    $remaining = neon_query('SELECT count(*)::int AS total FROM pipelines WHERE key=$1', array($key));
    check_sheet((int)$remaining['rows'][0]['total'] === 0, 'A falha deixou um cadastro parcial no banco.');
    $otherPipelines = neon_query('SELECT key FROM pipelines ORDER BY key')['rows'];
    try {
        neon_transaction(new_sheet_queries($key, 'Teste multiabas temporário', $url, $multi));
        neon_query("UPDATE leads SET status='Fechado',internal_notes='Nota preservada' WHERE pipeline_key=$1 AND lead_id='fixture-1'", array($key));
        $pipeline = neon_get_pipeline($key);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $synced = neon_sync_pipeline($pipeline, $multi, true);
        $lead = array_values(array_filter($synced['leads'], fn($lead) => $lead['Lead_ID'] === 'fixture-1'))[0];
        check_sheet($lead['Status'] === 'Fechado' && $lead['Internal_Notes'] === 'Nota preservada' && $lead['Resposta'] === 'Sim', 'Sincronização multiabas alterou status/notas ou perdeu respostas.');
        check_sheet($synced['sheet_count'] === 2 && $synced['statuses'] === $pipeline['statuses'] && $synced['column_labels'] === $pipeline['column_labels'], 'Configuração de colunas alterada na sincronização.');
    } finally {
        neon_delete_pipeline($key);
    }
    check_sheet(neon_query('SELECT key FROM pipelines ORDER BY key')['rows'] === $otherPipelines, 'A exclusão alterou outras fontes.');
    check_sheet((int)neon_query('SELECT count(*)::int AS total FROM leads WHERE pipeline_key=$1', array($key))['rows'][0]['total'] === 0, 'A exclusão deixou leads órfãos.');
    check_sheet(rejects_sheet(fn() => neon_delete_pipeline('real_estate')), 'CSV manual não está protegido.');
    echo "Integração validada no Neon de testes. Cadastros e leads fictícios removidos após as verificações.\n";
}
echo "$passed verificações passaram.\n";
