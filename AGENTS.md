# Trabalho no printPRO

## Contexto
- Laravel 12 / PHP 8.2 do XAMPP; MySQL local. Sem Docker nesta etapa.
- Aplicacao web em portugues, responsiva e preparada para API mobile futura.
- Leia README.md e docs/STATUS.md antes de retomar; confira Git somente nesta pasta.

## Equipe e revisao obrigatoria
- Codex: orquestracao, integracao, ambiente, documentacao e Git.
- Esteio: dominio, backend, banco e testes.
- Trama: frontend e UX, Blade, CSS e JavaScript.
- Crivo: revisor independente permanente. Revisa alteracoes dos outros antes de cada entrega e commit; autor corrige, Crivo reavalia. Correcoes escritas pelo Crivo devem ser revisadas pelo Codex.
- Nunca editar arquivos que outro agente esteja modificando. Combine contratos antes de dividir tarefas.

## Convencoes
- Comente em portugues regras de negocio, limites de seguranca e decisoes nao obvias. Evite comentarios que apenas repitam a linha de codigo.
- Regras reutilizaveis em actions/services; controllers pequenos; validacao no servidor.
- Organizacao deriva do usuario autenticado. Nunca confiar em organization_id recebido do cliente.
- Testar isolamento com duas organizacoes, autorizacao, autenticacao e fluxo persistido.
- Interface sem metricas inventadas, links vazios ou recursos apresentados como concluidos sem funcionar.
- Testes usam SQLite em memoria. Nunca migrate:fresh/reset no banco local do usuario.
- Segredos, .env, sessoes WhatsApp, QR e mensagens nunca entram no Git ou nos logs publicados. Perfis autenticados devem ficar fora de pastas sincronizadas.
- Commits pequenos e descritivos apos revisao e testes. Conferir diff staged e ignorados antes de push. Remoto autorizado: https://github.com/Kaic-Developer/printPRO.git.
- WhatsApp e um modulo futuro de atendimento de vendedores, nunca um canal de desenvolvimento. Administrador conecta o numero da grafica; vendedores atendem por caixa compartilhada. Nao enviar logs, status tecnico, configuracoes ou informacoes internas aos clientes.
- Usuario autorizou implementacao, correcoes, testes, commits e push do projeto, inclusive trabalho dos agentes. Prosseguir dentro desse escopo sem reconfirmacoes rotineiras; respeitar os controles tecnicos de permissao do ambiente.
