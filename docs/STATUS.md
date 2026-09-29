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

- Com nesting em folhas, impressao/corte/corte e vinco calculam consumo em folhas inteiras; folders calculam vincos pela quantidade de dobras e pela tiragem. Os custos unitarios permanecem configurados pela grafica.

- Cadastro da gráfica e proprietário, login com limite de tentativas e logout.
- Clientes e catálogo comercial isolados por organização.
- Financeiro inicial com entradas/saídas, valores em centavos, filtro mensal e gráfico baseado em dados reais.
- 173 entradas no catálogo técnico inicial, incluindo 28 produtos pré-configurados para comunicação visual, gráfica rápida, têxtil/uniformes e brindes/rótulos, sem custos ou preços assumidos.
- Ativação por organização, custo unitário informado pelo usuário, perda percentual e multiplicador de markup opcionais até configuração.
- Wizard declarativo com múltiplas linhas, schema versionado, campos condicionais e grade têxtil.
- Estimativa integrada por item para fachada, lona frontlight, banner com bastão/cordinha, roll-up, adesivo impresso, cartão, folders, flyer, rótulo e acrílico/plásticos; perfil de largura/comprimento configurável por gráfica, snapshot preservado e consumo identificado como manual ou calculado. Chapas/folhas cobram unidades inteiras; bobinas usam comprimento ou área real consumida, sem descartar sobras. A geometria é uma grade retangular simples, não nesting ótimo.
- Orçamentos com snapshots imutáveis, valores exatos em centavos, revisões e aprovação bloqueada quando faltam custos/fatores.
- Aprovação gera ordens de produção por setor e a repetição do comando não duplica ordens.
- API v1 para autenticação por token, catálogo, configuração, orçamento, nesting e ordens de produção.
- Documentação funcional e contratos em [ORCAMENTOS.md](ORCAMENTOS.md).

- A ficha de acrílico liga material à espessura e separa taxas horárias de corte laser e router; PS, PVC expandido e policarbonato usam a referência genérica configurada pela gráfica.
## Verificação e próximos passos

- Os testes usam SQLite em memória e não devem executar `migrate:fresh` no banco local.
- Migrations `2026_09_28_000004` e `2026_09_28_000005` aplicadas ao MySQL local; os presets foram sincronizados sem resetar dados. A migration 005 armazena dimensões nominais por material e organização.
- Última suíte completa: `php artisan test` (95 testes, 809 assertions), `npm.cmd run build`, `php artisan view:cache` e `git diff --check`; revisão cruzada sem novos achados.
- O nesting integrado cobre fachada, grandes formatos, roll-up, adesivo, cartão, flyer/folder promocional, pasta institucional, rótulo em bobina/cartela e acrílico/plásticos, com material e dimensões verificados no servidor. Para bobinas, o estimador escolhe automaticamente a orientação de menor comprimento nas duas grades retangulares avaliadas; confirme a possibilidade de girar a arte. Outros produtos ainda exigem consumo informado pela gráfica.
- Lona, banner e adesivo calculam impressão, aplicação e laminação selecionadas pela área vendida em m²; o nesting calcula separadamente o material de base com as sobras. Banner aplica um kit de bastões/cordinha por cópia quando selecionado. Roll-up calcula impressão pela área acabada, uma estrutura por unidade quando inclusa, e material de bobina com sobras. Copos long drink, squeezes, tirantes e brindes ecológicos calculam base e personalização por peça com custos configurados. Material e técnica em texto livre são dados descritivos e não mudam o custo genérico automaticamente.
- O wizard de adesivos sincroniza e exibe apenas o vinil, filme, acabamento e recorte compatíveis com as respostas atuais; mudanças de opção limpam componentes antigos antes do envio.
- O servidor também rejeita recorte em plotter e kit de bastões/cordinha quando essas opções não foram pedidas no wizard, mesmo se enviados manualmente pela API.
- Os campos descritivos dos brindes explicam no formulário e no schema da API que não mudam o custo genérico; os custos por peça continuam sendo os componentes configurados pela gráfica.
- Pedidos com acompanhamento de chão de fábrica, atendimento compartilhado, relatórios integrados, contas a pagar/receber e aplicativo nativo continuam no roteiro.
- Rotulos: area impressa calculada pelas dimensoes da arte e tiragem, corte por unidade; nesting da bobina/cartela contabiliza o estoque e as sobras em separado.
- Encadernacao de agendas/cadernos e cardapios segue automaticamente uma unidade por exemplar acabado, conforme a escolha no wizard.
