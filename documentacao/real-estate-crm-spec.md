# Especificação funcional atual

Este documento substitui a especificação inicial baseada apenas em CSV e sem autenticação. Para instalação, consulte [README.md](../README.md).

## Interface

- Tema escuro, marca Oi Digital Media, destaques rosa e laranja.
- Login com logo e título centralizados.
- Seletor de fontes por dropdown; cadastro por link publicado e exclusão confirmada de fontes Neon.
- Quadro de leads com filtros e ordenação por maior Tier, menor Tier ou nome A–Z.
- Arraste de leads entre colunas; edição de status e anotações no detalhe.
- Criação, remoção e reorganização de colunas persistidas no backend.
- Indicador de processamento e bloqueio temporário de interações durante operações demoradas; falhas devem liberar a interface e apresentar mensagem.

## Contratos de manutenção

O frontend permanece em um único index.html, sem framework. api.php atende as ações autenticadas e mantém separados o armazenamento manual em arquivo e as fontes Neon. Alterações não devem quebrar o estado compartilhado ou os nomes dos campos enviados pelo frontend.

A atualização de lead usa a ação `update_lead` e os campos `Lead_ID` e `Status` ou `Internal_Notes`, no contexto do pipeline selecionado. Configurações de colunas usam `update_column_labels` e precisam persistir também o conjunto ordenado de status.

Sincronizações não devem apagar status e anotações existentes. As regras de junção de abas estão em PIPELINES.md. Exclusões precisam de confirmação explícita e a fonte manual reservada não é removível.

## Qualidade e limites

Os testes automatizados verificam lógica PHP e estado JavaScript com API e DOM simulados. Integração Neon é opcional e restrita à branch de testes conhecida. Comportamento visual, arraste real, acessibilidade e configuração do servidor exigem conferência adicional no ambiente de testes.

Não há promessa de resolução automática de conflitos entre edições simultâneas de vários usuários. Não há agendador de sincronização, pipeline de migrações nem instalador de banco vazio.
