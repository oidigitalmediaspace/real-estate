# Fontes de dados e sincronização

## CSV manual

A fonte reservada `real_estate` usa `data.json`, local ao servidor. Ela não pode ser excluída pelo menu de fontes. Fazer backup desse arquivo é responsabilidade da operação; ele não fica no Git.

## Adicionar uma planilha

Na área CSV manual, informe nome e link de uma planilha Google **publicada na web**. O cadastro exige administrador e importa os dados no Neon. Não é necessário executar SQL manualmente.

Formato de exemplo, com identificador fictício:

```text
https://docs.google.com/spreadsheets/d/e/IDENTIFICADOR_PUBLICADO/pub?output=csv
```

Links privados de edição não são aceitos. Publicar na web torna as informações acessíveis por esse link: obtenha autorização antes de publicar dados pessoais.

## Todas as abas publicadas

- O sistema lê o índice público da planilha e busca cada aba publicada, inclusive questionários. Não acessa abas privadas ou não publicadas.
- O `gid` do link inicial não limita o cadastro a uma única aba.
- Cada aba precisa de cabeçalhos únicos e da coluna `Lead_ID`. Linhas preenchidas sem ID e linhas com quantidade inválida de campos interrompem a operação.
- Abas apenas com cabeçalho são aceitas no conjunto. O cadastro precisa de leads válidos.
- As abas são combinadas por `Lead_ID`. Campos vazios não apagam valores já encontrados; divergências não vazias ficam preservadas com o nome da aba no campo adicional.
- Repetições de um ID dentro da mesma aba usam a última linha.
- Limites atuais: 20 abas, 5 MB por aba, 20 MB no conjunto e 5.000 IDs únicos.
- O reconhecimento do índice publicado depende do formato HTML do Google. Se ele mudar, a importação deve falhar claramente, sem gravar uma importação parcial.

## Atualizar dados

Use **Sincronizar** para buscar novamente as abas publicadas. Abrir a fonte não sincroniza automaticamente e não existe agendador neste projeto.

A atualização preserva status e anotações internas dos leads existentes. Adicionar novamente um link do mesmo documento é tratado como atualização da fonte existente, não como nova fonte independente.

Os campos de exibição são mapeados em `pipelines.display`, inclusive Score/Tier e dados de contato. Não substitua esse JSON inteiro por SQL para mudar apenas um campo: ele também guarda metadados da publicação.

## Colunas e exclusões

O conjunto inicial é `Novo`, `Em andamento` e `Fechado`. Criação, nomes e ordenação são salvos pelo backend. Ao remover uma coluna com leads, eles são transferidos para a primeira coluna restante. Confira o destino antes de confirmar.

O menu de fontes permite excluir pipelines Neon, com confirmação. A exclusão remove a fonte e seus leads do CRM, incluindo status e anotações; não exclui nem modifica a planilha Google. Faça backup antes de excluir dados que precisem ser recuperados.

## Persistência

- `pipelines`: chave, nome, status, colunas, URLs e configurações de exibição.
- `leads`: ID por pipeline, dados JSONB, status e anotações.
- `users` e `sessions`: autenticação, compartilhada também pelo CSV manual.

Os esquemas precisam estar previamente provisionados. O projeto não contém migração completa para instalar um banco vazio. Consulte [README.md](../README.md) antes de testar ou publicar.
