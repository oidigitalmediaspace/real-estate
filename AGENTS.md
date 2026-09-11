# Instruções de manutenção

## Escopo e segurança

- Leia README.md, documentacao/PIPELINES.md e documentacao/REVIEW.md antes de mudanças relevantes.
- Trabalhe localmente. Não faça commit, push, deploy, alteração de secrets ou operação em produção sem pedido explícito do usuário.
- Não deduza o ambiente pelo nome da pasta ou por localhost. Verifique o destino do banco sem imprimir credenciais.
- Preserve alterações preexistentes e dados reais. Nunca sobrescreva config.php, data.json ou CSVs para preparar testes.
- Não exponha senhas, sessões ou dados pessoais em logs, fixtures e respostas. Não desabilite TLS.

## Arquitetura e contratos

- Preserve o frontend em index.html, com Vanilla JS; não introduza framework ou build obrigatório sem aprovação.
- Mantenha os contratos de api.php e o estado compartilhado (leads, currentPipeline, currentStatuses e columnLabels).
- real_estate é a fonte manual baseada em arquivo; as demais usam Neon. Não migre nem remova esse driver implicitamente.
- Status, anotações e ordem de colunas devem ser persistidos no backend, não apenas no navegador.
- Use o mecanismo central de processamento para impedir operações concorrentes na interface; sempre libere o bloqueio após falha.
- Sincronização remota é explícita. Preserve status e anotações ao atualizar dados importados.
- Exclusão de fonte requer confirmação e autorização administrativa; real_estate não é removível.
- Dados importados são não confiáveis: escape HTML, valide URLs e use parâmetros SQL.

## Verificação e documentação

- Execute lint PHP, tests/sheet-registration.php e tests/crm-regression.cjs após alterações.
- Testes padrão não podem fazer rede. Integração exige a branch de testes protegida e fixtures descartáveis; nunca flexibilize a proteção para produção.
- Testes com DOM simulado não comprovam aparência, foco ou drag and drop real. Declare a cobertura e as limitações.
- Atualize documentação e testes quando mudar comportamento. Não registre exemplos com dados reais.
- Revise git diff e git status antes do encerramento, sem staging automático.
- O workflow de main publica automaticamente. Não o execute durante uma revisão local.
