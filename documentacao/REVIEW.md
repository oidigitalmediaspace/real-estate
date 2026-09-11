# Revisão para preparação do repositório — 05/09/2026

Escopo: revisão local de código, documentação, instruções para agentes, arquivos versionados, testes e workflow. Não houve commit, push, deploy ou alteração do banco nesta revisão. Alterações funcionais preexistentes foram preservadas.

## Ajustes realizados

- README, guia de fontes e especificação atualizados para refletir autenticação, Neon, armazenamento manual, importação multiabas e testes.
- AGENTS.md criado; CLAUDE.md passa a apontar para as mesmas regras, sem instruções operacionais conflitantes.
- Modelo de configuração sem credenciais, regras de edição e atributos Git adicionados.
- Exclusões do Git ampliadas para arquivos locais. Nenhum arquivo já rastreado foi removido do índice.
- Deploy agora testa antes de enviar e exclui dados, testes e documentos. A configuração PHP é gerada por variável de ambiente e `var_export`, sem interpolação da senha no shell.
- CI offline adicionada para pull requests, branches diferentes de main e execução manual. Main continua com deploy automático após testes.
- `.htaccess` adiciona restrições de acesso a arquivos internos; teste PHP recusa execução via navegador.
- Links vindos dos dados só recebem âncoras navegáveis com HTTP/HTTPS, bloqueando protocolos executáveis. Logout exige POST, como já utilizado pelo frontend.

## Verificações realizadas

- Lint de api.php, config.example.php e tests/sheet-registration.php: aprovado.
- Backend offline: 21 verificações aprovadas.
- Frontend com DOM/API simulados: 18 cenários aprovados, zero chamadas externas.
- `git diff --check`: sem erros de whitespace; Git avisa sobre normalização de finais de linha.
- `config.php` e `data.json`: ignorados; a logo utilizada permanece em assets/. A cópia idêntica Slogan.png foi removida na limpeza posterior.
- Busca pontual por padrões comuns de credenciais nos arquivos de trabalho, excluindo configuração local e dados: sem ocorrências. Isso não é auditoria completa de segredos nem do histórico Git.

## Pendências antes de publicar

1. **Dados no Git:** `dados/real_estate_fase1.csv` e `dados/real_estate_raw.csv` já são rastreados e contêm colunas de contato. Confirmar autorização de compartilhamento ou providenciar, separadamente, a retirada do versionamento e eventual saneamento do histórico. O `.gitignore` não resolve o histórico. Não houve exclusão ou staging nesta revisão.
2. **Documentos locais:** revisar `documentacao/` antes de incluí-la em um commit; ela foi preservada e está excluída do deploy. Não foi feita auditoria do conteúdo do PDF.
3. **Primeiro acesso:** enquanto a senha do administrador estiver vazia, a rota pública de configuração permite defini-la. Concluir o primeiro acesso em ambiente restrito antes de expor uma nova instalação. Não deixar produção acessível nesse estado.
4. **Servidor e navegador:** validar visualmente dropdown, arraste de leads/colunas, foco e mensagens. Verificar no SiteGround que `.htaccess` é aceito e que arquivos internos retornam acesso negado. O servidor PHP local não testa essas regras.
5. **Publicação anterior:** excluir arquivos do próximo deploy não comprova que cópias antigas deixaram de existir no servidor. Auditar arquivos previamente enviados e qualquer dado que possa ter sido exposto. Nenhum arquivo remoto foi removido nesta revisão.
6. **Credenciais e backups:** conferir os secrets e o destino do deploy, fazer backup do Neon e de data.json e validar HTTPS. Não executar o workflow de main apenas para experimentar.

## Limitações conhecidas

- Os testes desta revisão foram offline. Não validam a disponibilidade atual do Google, o Neon, o FTP nem o ambiente GitHub Actions.
- O armazenamento manual usa bloqueio durante escrita, mas não uma transação que englobe leitura e alteração; edições simultâneas podem se sobrescrever. Não foi feita migração de persistência.
- Dependências visuais ainda são carregadas por CDN, sem build local. Falhas de rede podem afetar a apresentação.
- O índice HTML publicado pelo Google é uma dependência externa sujeita a mudanças.
- O banco precisa estar provisionado: falta um conjunto completo de migrações para instalar uma instância vazia.

Conclusão: preparação local e verificações offline concluídas; as pendências acima impedem afirmar que a publicação está integralmente validada.
