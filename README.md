# printPRO

Sistema de gestão para gráficas, feito em Laravel. Esta primeira etapa entrega uma base web responsiva, com acesso da gráfica, painel inicial e cadastro de clientes. Uma API e um aplicativo móvel ficam para etapas futuras.

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
- Interface em português, adaptável a celular e desktop.

## Verificação

```powershell
php artisan test
npm run build
```

Os testes de aplicação usam SQLite em memória e não alteram o banco local. A migration inicial também foi executada no MySQL local `printpro`.

## Acessos e próximos módulos

O proprietário administra sua própria gráfica. O cadastro e o login iniciais não criam um administrador global do SaaS, controle de equipe, convites ou níveis adicionais de permissão.

O WhatsApp será um canal de atendimento dos vendedores. A proposta inicial é o administrador conectar o número da gráfica e os vendedores atenderem numa caixa compartilhada. Conversas com clientes não devem revelar dados internos do painel, do desenvolvimento ou de outras gráficas. A integração ainda não foi implementada.

Próxima etapa: catálogo configurável, depois orçamento versionado, aprovação e conversão em pedido. Consulte [docs/STATUS.md](docs/STATUS.md) e [docs/ATENDIMENTO.md](docs/ATENDIMENTO.md) para decisões e pendências.
