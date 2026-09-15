// Testes sem rede: executar com Node.js. Nenhum dado real é alterado.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const source = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)]
  .map(m => m[1]).find(s => s.includes('const API_URL')).replace('    initApp();', '');

function fixture() {
  const elements = new Map();
  const events = {};
  const document = {
    body: { style: { overflow: 'auto' } }, activeElement: null,
    getElementById(id) {
      if (!elements.has(id)) elements.set(id, {
        innerHTML: '', textContent: '', value: '', dataset: {}, isConnected: true,
        setAttribute() {}, removeAttribute() {}, replaceChildren() { this.innerHTML = ''; },
        focus() { document.activeElement = this; },
        addEventListener(type, handler) { this[type] = handler; },
        classList: { add() {}, remove() {} }
      });
      return elements.get(id);
    },
    querySelectorAll() { return []; },
    addEventListener(type, handler) { events[type] = handler; }
  };
  const context = vm.createContext({ document,
    window: { confirm: () => true, alert() {}, prompt: () => 'Teste' },
    setTimeout() { return 1; }, clearTimeout() {},
    FormData: class { append() {} },
    fetch: async () => { throw Error('Falha de rede simulada'); }
  });
  vm.runInContext(source, context);
  const evaluate = code => vm.runInContext(code, context);
  evaluate(`renderCRM = () => {}; openLeadModal = () => {}; renderCurrentView = () => {};
    leads = [{Lead_ID:'lead-teste',Status:'Novo',Internal_Notes:'Original'}];
    selectedLeadId = 'lead-teste';`);
  return { context, document, events, evaluate };
}
const response = data => ({ ok: true, status: 200, json: async () => data, text: async () => JSON.stringify(data) });

module.exports = (async () => {
  let passed = 0;
  async function test(name, task) { await task(); passed++; console.log('OK - ' + name); }
  await test('abrir quadro faz apenas leitura, mesmo sem last_synced', async () => {
    const f = fixture(); const urls = [];
    f.evaluate('PIPELINES.real_estate.supportsSync = true;');
    f.context.fetch = async url => { urls.push(url); return response({ success: true, leads: [], statuses: ['Novo'] }); };
    await f.context.loadLeads();
    assert.equal(urls.length, 1);
    assert.match(urls[0], /action=get_leads/);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('bloqueio dura até leitura completa e impede escrita duplicada', async () => {
    const f = fixture(); let resolve; let requests = 0;
    const pendingBody = new Promise(done => { resolve = done; });
    f.context.fetch = async () => { requests++; return { ok: true, status: 200, json: () => pendingBody }; };
    const pending = f.context.moveLeadToStatus('lead-teste', 'Fechado');
    assert.equal(f.document.getElementById('app').inert, true);
    await f.context.moveLeadToStatus('lead-teste', 'Novo');
    assert.equal(requests, 1);
    let blocked = 0;
    f.events.keydown({ preventDefault() { blocked++; }, stopImmediatePropagation() { blocked++; } });
    assert.equal(blocked, 2);
    resolve({ success: true }); await pending;
    assert.equal(f.document.getElementById('app').inert, false);
    assert.equal(f.evaluate('leads[0].Status'), 'Fechado');
  });
  await test('erro de escrita libera tela e preserva nota digitada', async () => {
    const f = fixture(); f.document.getElementById('notes-input').value = 'Rascunho';
    await f.context.saveField('Internal_Notes');
    assert.equal(f.document.getElementById('notes-input').value, 'Rascunho');
    assert.equal(f.evaluate('leads[0].Internal_Notes'), 'Original');
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('falha ao listar planilhas não é escondida como sucesso', async () => {
    const f = fixture();
    await assert.rejects(f.context.bootPipelines(), /Falha de rede/);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('erro 500 no início mostra erro e botão de nova tentativa', async () => {
    const f = fixture();
    f.context.fetch = async () => ({ ok: false, status: 500, json: async () => ({ error: 'Neon indisponível' }) });
    await f.context.initApp();
    assert.match(f.document.getElementById('app').innerHTML, /Neon indisponível/);
    assert.equal(typeof f.document.getElementById('retry-startup').click, 'function');
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('criação, reordenação e exclusão persistem com bloqueio', async () => {
    const f = fixture();
    f.context.fetch = async (url, opts) => {
      assert.equal(f.evaluate('isProcessing()'), true);
      const payload = JSON.parse(opts.body);
      return response({ success: true, statuses: payload.statuses, column_labels: payload.column_labels });
    };
    await f.context.createNewColumn();
    await f.context.moveColumn('Teste', -1);
    await f.context.moveColumnTo('Teste', 'Novo');
    await f.context.removeColumn('Teste');
    assert.equal(f.evaluate("currentStatuses.includes('Teste')"), false);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('importação mantém bloqueio até recarregar, sem espera artificial', async () => {
    const f = fixture(); const urls = [];
    f.context.fetch = async url => {
      assert.equal(f.evaluate('isProcessing()'), true); urls.push(url);
      return response({ success: true, leads: [], statuses: ['Novo'] });
    };
    const form = { addEventListener(type, handler) { this[type] = handler; }, querySelector() { return { files: [{}] }; } };
    f.context.bindImportForm(form, f.document.getElementById('import-message'));
    await form.submit({ preventDefault() {} });
    assert.equal(urls.length, 2);
    assert.match(urls[0], /action=import/);
    assert.match(urls[1], /action=get_leads/);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('sessão expirada retorna ao login e desbloqueia a tela', async () => {
    const f = fixture();
    f.evaluate("renderLoginScreen = message => { document.getElementById('login-message').textContent = message; };");
    f.context.fetch = async () => ({ status: 401, ok: false });
    await f.context.moveLeadToStatus('lead-teste', 'Fechado');
    assert.match(f.document.getElementById('login-message').textContent, /sessão expirou/);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('Tier numérico crescente/decrescente, ausentes no final e array original intacto', async () => {
    const f = fixture();
    f.evaluate(`PIPELINES.real_estate.driver = 'neon';
      PIPELINES.real_estate.display = { field_map: { 'Score AI': 'Questionario_Tier' } };
      leads = [
        {Lead_ID:'a',Nome:'Zeca',Questionario_Tier:'Tier: 2',Status:'Novo'},
        {Lead_ID:'b',Nome:'Ana',Questionario_Tier:'Tier 10',Status:'Novo'},
        {Lead_ID:'c',Nome:'Bia',Questionario_Tier:'-',Status:'Fechado'},
        {Lead_ID:'d',Nome:'Carlos',Questionario_Tier:0,Status:'Novo'}
      ];`);
    const original = f.evaluate('JSON.stringify(leads)');
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'b,a,d,c');
    f.evaluate("filters.sort = 'tier_asc';");
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'd,a,b,c');
    assert.equal(f.evaluate('JSON.stringify(leads)'), original);
  });
  await test('classificação por letras e combinação com filtros existentes', async () => {
    const f = fixture();
    f.evaluate(`leads = [
      {Lead_ID:'d',Name:'Zeca',New_Tier:'D'},
      {Lead_ID:'a',Name:'Ana',New_Tier:'A'},
      {Lead_ID:'ap',Name:'Bruno',New_Tier:'A+'},
      {Lead_ID:'c',Name:'Carlos',Tier_Fase1:'C'}
    ];`);
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'ap,a,c,d');
    f.evaluate("filters.sort = 'tier_asc';");
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'd,c,a,ap');
    f.evaluate("filters.tier = 'A';");
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'a');
    f.context.resetFilters();
    assert.equal(f.evaluate('filters.sort'), 'tier_desc');
  });
  await test('ordem alfabética considera acentos e mantém empates estáveis', async () => {
    const f = fixture();
    f.evaluate(`filters.sort = 'name_asc'; leads = [
      {Lead_ID:'z',Name:'Zeca'}, {Lead_ID:'b',Name:'Bruna'},
      {Lead_ID:'a1',Name:'Álvaro'}, {Lead_ID:'a2',Name:'alvaro'},
      {Lead_ID:'empty',Name:''}
    ];`);
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'a1,a2,b,z,empty');
    f.evaluate("filters.search = 'bruna';");
    assert.equal(f.evaluate("filteredLeads().map(l=>l.Lead_ID).join(',')"), 'b');
  });
  await test('ordenação é preservada ao atualizar os dados e respeita a paginação', async () => {
    const f = fixture();
    f.evaluate(`PIPELINES.real_estate.driver = 'neon'; filters.sort = 'tier_desc';
      hydrateData({statuses:['Novo','Fechado'], leads:Array.from({length:1000}, (_,i)=>({
        Lead_ID:String(i),Nome:'Pessoa '+i,'Score AI':'Tier '+i,Status:i%2?'Novo':'Fechado'
      }))});`);
    const original = f.evaluate('JSON.stringify(leads)');
    for (let i = 0; i < 20; i++) {
      assert.equal(f.evaluate("filteredLeads().filter(l=>l.Status==='Novo').slice(0,15)[0].Lead_ID"), '999');
    }
    assert.equal(f.evaluate('JSON.stringify(leads)'), original);
    assert.equal(f.evaluate('filters.sort'), 'tier_desc');
  });
  await test('cadastro por link importa e abre a nova fonte sem permitir envio duplicado', async () => {
    const f = fixture(); const requests = []; let complete;
    const pendingCreate = new Promise(resolve => { complete = resolve; });
    const form = f.document.getElementById('sheet-registration-form');
    form.elements = { namedItem: name => ({ value: name === 'name' ? 'Fonte de teste' : 'https://docs.google.com/spreadsheets/d/e/test/pub?output=csv' }) };
    f.context.fetch = async (url, opts) => {
      requests.push(url);
      if (url.includes('action=create_sheet_pipeline')) {
        assert.equal(JSON.parse(opts.body).name, 'Fonte de teste');
        return { ok: true, status: 200, json: () => pendingCreate };
      }
      if (url.includes('action=pipelines')) return response({ success: true, pipelines: [{ key: 'sheet_test', name: 'Fonte de teste', driver: 'neon', statuses: ['Novo'] }] });
      return response({ success: true, statuses: ['Novo'], leads: [] });
    };
    f.context.bindSheetRegistration();
    const pending = form.submit({ preventDefault() {} });
    await form.submit({ preventDefault() {} });
    assert.equal(requests.length, 1);
    assert.equal(f.evaluate('isProcessing()'), true);
    complete({ success: true, pipeline: 'sheet_test', imported: 2, existing: false });
    await pending;
    assert.equal(requests.length, 3);
    assert.match(requests[2], /pipeline=sheet_test/);
    assert.equal(f.evaluate('currentPipeline'), 'sheet_test');
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('erro no cadastro mantém os dados preenchidos e libera o formulário', async () => {
    const f = fixture();
    const form = f.document.getElementById('sheet-registration-form');
    const values = { name: { value: 'Minha fonte' }, url: { value: 'https://link-invalido.test' } };
    form.elements = { namedItem: name => values[name] };
    f.context.fetch = async () => ({ ok: false, status: 400, json: async () => ({ error: 'Use o link publicado do Google Sheets.' }) });
    f.context.bindSheetRegistration();
    await form.submit({ preventDefault() {} });
    assert.match(f.document.getElementById('sheet-registration-message').textContent, /link publicado/);
    assert.equal(values.name.value, 'Minha fonte');
    assert.equal(f.evaluate('currentPipeline'), 'real_estate');
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('cancelar exclusão ou tentar apagar CSV manual não faz requisição', async () => {
    const f = fixture(); let requests = 0;
    f.evaluate("PIPELINES.test_delete = { key:'test_delete',title:'Descartável',driver:'neon' };");
    f.context.fetch = async () => { requests++; return response({ success: true }); };
    f.context.window.confirm = () => false;
    await f.context.deletePipeline('test_delete');
    await f.context.deletePipeline('real_estate');
    assert.equal(requests, 0);
    assert.equal(f.evaluate("Boolean(PIPELINES.test_delete)"), true);
  });
  await test('excluir outra fonte preserva o quadro aberto e confirma alvo no payload', async () => {
    const f = fixture();
    f.evaluate("PIPELINES.test_delete = { key:'test_delete',title:'Descartável',driver:'neon' };");
    const originalLeads = f.evaluate('JSON.stringify(leads)');
    f.context.fetch = async (url, opts) => {
      assert.match(url, /action=delete_pipeline/);
      assert.deepEqual(JSON.parse(opts.body), { pipeline: 'test_delete', confirmed: true });
      assert.equal(f.evaluate('isProcessing()'), true);
      return response({ success: true });
    };
    await f.context.deletePipeline('test_delete');
    assert.equal(f.evaluate("Boolean(PIPELINES.test_delete)"), false);
    assert.equal(f.evaluate('currentPipeline'), 'real_estate');
    assert.equal(f.evaluate('JSON.stringify(leads)'), originalLeads);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('excluir fonte ativa abre CSV manual e falha preserva a fonte', async () => {
    const f = fixture();
    f.evaluate("PIPELINES.test_delete = { key:'test_delete',title:'Descartável',driver:'neon' }; currentPipeline = 'test_delete';");
    f.context.fetch = async () => { throw new Error('Erro simulado'); };
    await f.context.deletePipeline('test_delete');
    assert.equal(f.evaluate('currentPipeline'), 'test_delete');
    assert.equal(f.evaluate('isProcessing()'), false);
    const urls = [];
    f.context.fetch = async url => { urls.push(url); return response({ success: true, leads: [], statuses: ['Novo'] }); };
    await f.context.deletePipeline('test_delete');
    assert.equal(f.evaluate('currentPipeline'), 'real_estate');
    assert.match(urls[1], /action=get_leads&pipeline=real_estate/);
    assert.equal(f.evaluate('isProcessing()'), false);
  });
  await test('links importados não permitem protocolos executáveis', async () => {
    const f = fixture();
    for (const value of ['javascript:alert(1)', 'data:text/html,test', '//evil.example', 'java\nscript:alert(1)']) {
      assert.equal(f.context.safeWebUrl(value), '');
      assert.doesNotMatch(f.context.linkLine('Website', value), /<a\s/);
    }
    assert.equal(f.context.safeWebUrl('https://example.com/path?q=1'), 'https://example.com/path?q=1');
    assert.match(f.context.linkLine('Website', 'https://example.com/'), /rel="noopener noreferrer"/);
  });

  await test('add_lead rejeita envio com Nome vazio na UI', async () => {
    const f = fixture();
    f.context.renderAddLeadModal();
    const form = f.document.getElementById('add-lead-form');
    f.document.getElementById('add-lead-name').value = '  ';
    let fetchCalled = false;
    f.context.fetch = async () => { fetchCalled = true; return {}; };
    await form.submit({ preventDefault() {} });
    assert.equal(fetchCalled, false);
    assert.match(f.document.getElementById('add-lead-message').textContent, /obrigatório/i);
    assert.equal(f.evaluate('isProcessing()'), false);
  });

  await test('add_lead no frontend exibe loader e atualiza dados em sucesso (driver file e neon)', async () => {
    const f = fixture();
    let lockStateDuringRequest;
    
    // Test 1: File driver (real_estate)
    f.evaluate(`currentPipeline = 'real_estate'`);
    f.context.renderAddLeadModal();
    f.document.getElementById('add-lead-name').value = 'Novo Contato File';
    f.document.getElementById('add-lead-phone').value = '11999998888';
    
    f.context.fetch = async (url, opts) => {
      lockStateDuringRequest = f.evaluate('isProcessing()');
      const payload = JSON.parse(opts.body);
      assert.equal(payload.Name, 'Novo Contato File');
      assert.equal(payload.Phone, '11999998888');
      return response({ success: true, lead: { Lead_ID: 'manual-file-123', Name: 'Novo Contato File', Phone: '11999998888', Status: 'Novo' } });
    };
    
    await f.document.getElementById('add-lead-form').submit({ preventDefault() {} });
    assert.equal(lockStateDuringRequest, true);
    assert.equal(f.evaluate('isProcessing()'), false);
    assert.equal(f.evaluate('leads[0].Lead_ID'), 'manual-file-123');
    assert.equal(f.document.getElementById('modal-root').innerHTML, '');
    
    // Test 2: Neon driver
    f.evaluate(`PIPELINES.sheet_pipe = { key:'sheet_pipe', driver:'neon', display: { field_map: { 'Nome': 'Nome da pessoa' } } }; currentPipeline = 'sheet_pipe';`);
    f.context.renderAddLeadModal();
    f.document.getElementById('add-lead-name').value = 'Contato Neon';
    
    f.context.fetch = async (url, opts) => {
      lockStateDuringRequest = f.evaluate('isProcessing()');
      const payload = JSON.parse(opts.body);
      assert.equal(payload['Nome da pessoa'], 'Contato Neon');
      return response({ success: true, lead: { Lead_ID: 'manual-neon-123', 'Nome da pessoa': 'Contato Neon', Status: 'Novo' } });
    };
    
    await f.document.getElementById('add-lead-form').submit({ preventDefault() {} });
    assert.equal(lockStateDuringRequest, true);
    assert.equal(f.evaluate('leads[0].Lead_ID'), 'manual-neon-123');
  });

  await test('backend file_add_lead cria corretamente e mock de retry de colisão de UUID funciona', async () => {
    const cp = require('node:child_process');
    const phpCode = `
      $source = file_get_contents(__DIR__ . '/api.php');
      $end = strpos($source, '// ── roteamento');
      eval(substr($source, 5, $end - 5));
      
      $pipeline = file_pipeline_config();
      $pipeline['file'] = __DIR__ . '/test_backend_add_lead.json';
      file_put_contents($pipeline['file'], json_encode(['leads' => [['Lead_ID' => 'manual-colide']]]));
      
      $attemptCount = 0;
      function mock_generator() {
          global $attemptCount;
          $attemptCount++;
          if ($attemptCount === 1) return 'manual-colide';
          if ($attemptCount === 2) return 'manual-colide';
          return 'manual-sucesso';
      }
      
      $input = ['Name' => 'Contato Retry', 'Email' => 'retry@test'];
      $created = file_add_lead($pipeline, $input, 'mock_generator');
      
      $data = json_decode(file_get_contents($pipeline['file']), true);
      unlink($pipeline['file']);
      
      if ($attemptCount !== 3) exit("Erro: attemptCount foi $attemptCount (esperado 3)");
      if ($created['Lead_ID'] !== 'manual-sucesso') exit("Erro: Lead_ID incorreto");
      if (count($data['leads']) !== 2) exit("Erro: o lead nao foi gravado");
      echo "OK";
    `;
    const res = cp.execFileSync('php', ['-r', phpCode]).toString().trim();
    assert.equal(res, 'OK');
  });

  await test('frontend RBAC: admin vê painel Equipe e importação, user não vê', async () => {
    const f = fixture();
    f.evaluate(`currentUserRole = 'admin'; PIPELINES.real_estate.supportsImport = true;`);
    f.context.renderHeaderShell = f.evaluate('renderHeaderShell');
    f.context.renderHeaderActions = f.evaluate('renderHeaderActions');
    f.context.renderPipelineTabs = f.evaluate('renderPipelineTabs');
    
    // Testa Admin
    f.document.getElementById('app').innerHTML = f.evaluate('renderHeaderShell(renderHeaderActions(10))');
    assert.match(f.document.getElementById('app').innerHTML, /Equipe/);
    assert.match(f.document.getElementById('app').innerHTML, /Adicionar nova planilha/);
    
    // Testa User
    f.evaluate(`currentUserRole = 'user';`);
    f.document.getElementById('app').innerHTML = f.evaluate('renderHeaderShell(renderHeaderActions(10))');
    assert.doesNotMatch(f.document.getElementById('app').innerHTML, /Equipe/);
    assert.doesNotMatch(f.document.getElementById('app').innerHTML, /Adicionar nova planilha/);
  });

  return { passed, externalRequests: 0 };
})();

if (require.main === module) {
  module.exports.then(result => console.log(result)).catch(error => {
    console.error(error);
    process.exitCode = 1;
  });
}
