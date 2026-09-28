# Estado do projeto

## Etapa atual

Fluxos implementados: organização, login, painel, clientes, catálogo comercial, financeiro inicial, catálogo técnico de orçamento, wizard, versões, aprovação e ordens de produção. O projeto continua local com Laravel 12, PHP 8.2 do XAMPP e MySQL, sem Docker.

## Decisões

- Blade/CSS responsivo para web; actions e serviços compartilhados entre controladores web e a futura aplicação móvel.
- O tenant sempre deriva do usuário autenticado. IDs de organização não são aceitos como fonte de autorização.
- Valores monetários em centavos; custos, perda e markup não recebem valores fictícios.
- O catálogo técnico global descreve categorias, materiais e processos. Cada gráfica controla ativação, custos e fatores comerciais.
- API v1 usa tokens Bearer Sanctum com abilities e rotas separadas por escopo.
- Comentários explicam em português regras de negócio, segurança e limites geométricos.
- Repositório remoto autorizado: https://github.com/Kaic-Developer/printPRO.git.
- WhatsApp é futuro canal de atendimento dos vendedores, sem compartilhar dados internos com clientes.
- A conta inicial ainda precisa ser criada com e-mail válido: `kaic@developer@gmail.com` foi recusado porque contém dois caracteres `@`.

## Entregas

- Cadastro da gráfica e proprietário, login com limite de tentativas e logout.
- Clientes e catálogo comercial isolados por organização.
- Financeiro inicial com entradas/saídas, valores em centavos, filtro mensal e gráfico baseado em dados reais.
- 169 presets iniciais para comunicação visual, gráfica rápida, têxtil/uniformes e brindes/rótulos, sem custos ou preços assumidos.
- Ativação por organização, custo unitário informado pelo usuário, perda percentual e multiplicador de markup opcionais até configuração.
- Wizard declarativo com múltiplas linhas, schema versionado, campos condicionais e grade têxtil.
- Estimativa integrada por item para presets de fachada, frontlight, adesivo impresso, cartão, folders, flyer, rótulo e acrílico/plásticos; perfil de largura/comprimento configurável por gráfica, snapshot preservado e consumo identificado como manual ou calculado. Chapas/folhas cobram unidades inteiras; bobinas usam comprimento ou área real consumida, sem descartar sobras. A geometria é uma grade retangular simples, não nesting ótimo.
- Orçamentos com snapshots imutáveis, valores exatos em centavos, revisões e aprovação bloqueada quando faltam custos/fatores.
- Aprovação gera ordens de produção por setor e a repetição do comando não duplica ordens.
- API v1 para autenticação por token, catálogo, configuração, orçamento, nesting e ordens de produção.
- Documentação funcional e contratos em [ORCAMENTOS.md](ORCAMENTOS.md).

## Verificação e próximos passos

- Os testes usam SQLite em memória e não devem executar `migrate:fresh` no banco local.
- Migrations `2026_09_28_000004` e `2026_09_28_000005` aplicadas ao MySQL local; os presets foram sincronizados sem resetar dados. A migration 005 armazena dimensões nominais por material e organização.
- Última validação: `php artisan test` (85 testes, 586 assertions), `npm.cmd run build`, `php artisan view:cache`, `git diff --check` e revisão cruzada de domínio.
- O nesting integrado cobre fachada, grandes formatos, adesivo, cartão, flyer/folder promocional, pasta institucional, rótulo em bobina/cartela e acrílico/plásticos, com material e dimensões verificados no servidor. Para bobinas, o estimador escolhe automaticamente a orientação de menor comprimento nas duas grades retangulares avaliadas; confirme a possibilidade de girar a arte. Outros produtos ainda exigem consumo informado pela gráfica.
- Pedidos com acompanhamento de chão de fábrica, atendimento compartilhado, relatórios integrados, contas a pagar/receber e aplicativo nativo continuam no roteiro.
