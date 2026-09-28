# Estado do projeto

## Etapa atual
Primeiro fluxo implementado e migrado: organizacao, login, painel e clientes. O servidor local esta disponivel em http://127.0.0.1:8000.

## Decisoes
- Sem Docker, conforme solicitacao. PHP 8.2 do XAMPP e MySQL local.
- Laravel 12 compativel com PHP 8.2; atualizar PHP e Laravel em etapa de manutencao antes de producao. Suporte de seguranca do Laravel 12 ate 24/02/2027: https://laravel.com/docs/12.x/releases.
- Blade e CSS responsivo; regras em actions para reaproveitamento na futura API.
- Um usuario pertence a uma grafica nesta primeira etapa; papeis granulares e multiplos vinculos ficam no backlog.
- O comando `php artisan printpro:create-owner` cria com prompt interativo um proprietario local sem expor senha em arquivos ou argumentos.
- Crivo revisa codigo de terceiros antes de entregar; comentarios explicam decisoes e regras em portugues.
- Repositorio remoto autorizado: https://github.com/Kaic-Developer/printPRO.git.
- WhatsApp destinado ao atendimento de vendedores. Administrador conecta o numero da grafica; vendedores atendem numa caixa compartilhada. Dados tecnicos e administrativos nao sao enviados ao cliente final.
- A ideia anterior de grupo de acompanhamento foi cancelada pelo usuario antes de autenticar. Processo interrompido e rascunho retirado do repositorio. Nenhum envio realizado.

## Em andamento
- Crivo revisou backend antes de integrar as views; a revisao visual final esta pendente.
- Criacao da conta inicial aguarda um e-mail valido: `kaic@developer@gmail.com` foi recusado pela validacao porque contem dois caracteres `@`. Nenhuma conta foi criada.

## Proximos modulos
Catalogo e regras comerciais → orcamentos versionados → aprovacao → pedidos.
API autenticada para mobile, convites/papeis, recuperacao de senha com envio real e integracoes entram em etapas proprias.
