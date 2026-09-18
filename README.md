# Real Estate CRM — Oi Digital Media

CRM em português para importar leads, reunir respostas de questionários e acompanhar atendimento em um quadro de colunas. Frontend componentizado em `js/` (Vanilla JS e Tailwind via CDN); backend modularizado na pasta `backend/` e centralizado no `api.php`.

## Como funciona

- Login e sessões são armazenados no PostgreSQL do Neon.
- A opção **CSV manual** (`real_estate`) armazena leads e configurações no arquivo local `data.json`.
- As demais fontes usam as tabelas `pipelines` e `leads` do Neon.
- Planilhas publicadas podem ser cadastradas pela interface. O sistema descobre as abas publicadas e combina seus dados pelo `Lead_ID`.
- Status, anotações e configurações de colunas são persistidos no backend. Ordenação e filtros são opções de visualização.
- Sincronizar é uma ação explícita. Abrir uma fonte não deve disparar uma importação remota automaticamente.

## Execução local

O ambiente local foi exercitado com PHP 8.5.10. São necessários PHP com JSON, OpenSSL e, preferencialmente, cURL, além de acesso à internet para Neon, fontes e Tailwind. Node.js é usado apenas nos testes do frontend.

1. Obtenha com o responsável uma branch Neon **de testes**, com as tabelas `users`, `sessions`, `pipelines` e `leads` já preparadas. Este repositório não contém um instalador completo do banco.
2. Copie `config.example.php` para `config.php` **somente se ainda não existir** e preencha a conexão dessa branch. Nunca compartilhe a senha nem envie esse arquivo ao Git.
3. Confira as extensões com `php -m`. Em Windows, habilite `extension=curl` no `php.ini` carregado, se necessário. Não desabilite a validação TLS.
4. Na pasta do projeto, execute:

```powershell
php -S 127.0.0.1:8000 -t .
```

5. Abra `http://127.0.0.1:8000`. O usuário administrador precisa existir no banco; a tela de primeiro acesso define sua senha quando ela ainda não foi cadastrada.

**Localhost não garante isolamento do banco:** confira o destino de `config.php` antes de alterar dados. O servidor embutido é exclusivo para desenvolvimento e não interpreta `.htaccess`; mantenha-o em `127.0.0.1`, nunca exposto à rede.

## Testes

Na raiz do projeto, sem credenciais nem chamadas externas:

```powershell
php -l api.php
php -l config.example.php
php tests/sheet-registration.php
node tests/crm-regression.cjs
```

O teste JavaScript simula DOM e API: cobre estado, falhas, bloqueio durante operações, ordenação, colunas e fontes. Não substitui uma verificação visual no navegador.

Teste de integração **opcional**, com escritas temporárias no Neon de testes:

```powershell
php tests/sheet-registration.php --integration
```

Esse teste recusa qualquer branch diferente de `br-empty-paper-atlpc4wa`, usa registros fictícios e tenta removê-los ao terminar. Não altere a proteção para apontar à produção. Uma interrupção abrupta pode exigir conferir resíduos de testes.

## Publicação: atenção

O workflow de deploy continua configurado para executar em **push na branch `main`**. Portanto, enviar alterações para `main` pode atualizar o SiteGround. A revisão local não publica nada.

Antes de autorizar um deploy:

- Revise o diff e execute os testes; valide login, dropdown, arraste, sincronização e mensagens no navegador de testes.
- Confira os secrets `NEON_DATABASE_URL`, `FTP_HOST`, `FTP_USER` e `FTP_PASS` no GitHub e o destino `/public_html/`.
- Faça backup do banco e de `data.json`. Nunca substitua o arquivo de leads por um arquivo vazio.
- Confira a versão PHP e cURL no servidor, HTTPS e as regras de acesso do `.htaccess`.
- Revise dados pessoais e documentos antes de compartilhar o repositório. **Os CSVs em `dados/` já são rastreados pelo Git**; adicioná-los ao `.gitignore` não remove as cópias existentes nem o histórico.
- Verifique o relatório em [REVIEW.md](documentacao/REVIEW.md). CI aprovada não é garantia de segurança ou teste completo de produção.

O deploy exclui testes, documentação, CSVs e arquivos locais. `config.php` é gerado no runner a partir do secret, sem interpolar a credencial como código shell. Não há migração automática de banco.

## Arquivos e documentação

- `index.html`: interface, estado e chamadas à API.
- `api.php`: autenticação, persistência e integração com planilhas.
- `assets/oi-digital-logo.png`: logo utilizada pela interface.
- `config.example.php`: modelo sem credenciais; `config.php` e `data.json` ficam fora do Git.
- [PIPELINES.md](documentacao/PIPELINES.md): fontes, abas e regras de sincronização.
- [real-estate-crm-spec.md](documentacao/real-estate-crm-spec.md): contrato funcional atual.
- [AGENTS.md](AGENTS.md): instruções para agentes e manutenção.
- `documentacao/historico/`: documentos e captura da versão anterior, preservados como referência, não como instruções atuais.
- `tests/`: testes automatizados; `dados/`: CSVs de origem preservados, não utilizados automaticamente pela aplicação.

Não foi definida uma licença de redistribuição. Confirme com a empresa antes de tornar o repositório público.
