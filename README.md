# printPRO

Sistema de gestão para gráficas, feito em Laravel. A base web responsiva entrega acesso da gráfica, painel, clientes, catálogo comercial, financeiro inicial e orçamentos técnicos versionados. A API v1 autenticada prepara o domínio para um aplicativo móvel futuro; o aplicativo nativo ainda não existe.

## Iniciar no Windows com XAMPP

1. Ligue o MySQL no XAMPP.
2. Na pasta do projeto, execute `composer install` e `npm install`.
3. Copie `.env.example` para `.env`, se ainda não existir. A configuração inicial usa MySQL local, usuário `root` sem senha e banco `printpro`.
4. Execute `php artisan key:generate`.
5. Execute `php scripts/create-local-database.php` para criar o banco local, preservando qualquer banco já existente.
6. Execute `php artisan migrate` para criar as tabelas.
7. Execute `npm run build` para compilar os estilos.
8. Execute `iniciar-projeto.cmd` e acesse [http://127.0.0.1:8000](http://127.0.0.1:8000).
9. Crie a primeira conta proprietária em outra janela com `php artisan printpro:create-owner`. O comando pede e-mail, nome, gráfica e senha em prompts interativos. Ele não altera contas existentes.

O `.env` contém a chave local e as credenciais do banco. Ele é privado e não deve ser enviado ao Git.

## O que já funciona

- Cadastro inicial de uma gráfica e seu proprietário.
- Login com limite de tentativas, encerramento de sessão e logout.
- Painel com total de clientes reais e estado vazio inicial.
- Cadastro, edição, busca e listagem paginada de clientes.
- Isolamento de clientes por gráfica e testes contra acesso por ID de outra organização.
- Catálogo por gráfica com busca, filtro por situação, SKU exclusivo dentro de cada gráfica, unidade configurável e preço opcional armazenado em centavos.
- Edição e ativação/desativação de produtos, preservando registros para uso futuro em orçamentos.
- Biblioteca de presets para comunicação visual, gráfica, têxtil e brindes, com ativação e custos próprios por gráfica.
- Wizard de orçamento com múltiplas linhas, grade de tamanhos, componentes sugeridos, versões imutáveis e cálculo em centavos.
- Aprovação transacional que gera ordens de produção por setor sem duplicidade.
- Estimativa retangular por item para chapas, folhas e bobinas, usando dimensões cadastradas por gráfica e incluindo sobra de mídia no custo; sem presumir custos ou fatores comerciais.
- API v1 com tokens Bearer Sanctum e escopos para catálogo, orçamentos e ordens de produção.
- Controle financeiro inicial com lançamentos manuais de entradas e saídas, resumo mensal, filtro por período e gráfico com dados reais.
- Máscara de reais nos valores financeiros, com armazenamento exato em centavos.
- Página de módulos para consultar o que está disponível e o roteiro das próximas entregas.
- Interface em português, adaptável a celular e desktop.

## Verificação

```powershell
php artisan test
npm run build
```

Os testes de aplicação usam SQLite em memória e não alteram o banco local. A migration inicial e as migrations aditivas do orçamento foram executadas no MySQL local `printpro`, sem recriar tabelas.

## Acessos e próximos módulos

O proprietário administra sua própria gráfica. O cadastro e o login iniciais não criam um administrador global do SaaS, controle de equipe, convites ou níveis adicionais de permissão.

O WhatsApp será um canal de atendimento dos vendedores. A proposta inicial é o administrador conectar o número da gráfica e os vendedores atenderem numa caixa compartilhada. Conversas com clientes não devem revelar dados internos do painel, do desenvolvimento ou de outras gráficas. A integração ainda não foi implementada.

Consulte [docs/ORCAMENTOS.md](docs/ORCAMENTOS.md) para o contrato do wizard, cálculo, schemas e rotas. Consulte [docs/STATUS.md](docs/STATUS.md) e [docs/ATENDIMENTO.md](docs/ATENDIMENTO.md) para as demais decisões e pendências.
