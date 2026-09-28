# Atendimento por WhatsApp — escopo definido

O WhatsApp sera utilizado por vendedores no atendimento da grafica. Nao se conecta um grupo para transmitir o andamento do desenvolvimento.

## Papeis previstos
- Administrador da grafica: gerencia numeros comerciais, conexoes, equipe e permissoes da propria organizacao.
- Vendedor: acessa conversas permitidas e envia atendimento comercial pela caixa compartilhada.
- Cliente final: recebe mensagens comerciais destinadas a ele. Nao recebe logs, status de desenvolvimento, credenciais, configuracoes ou painel interno.
- Administrador da plataforma: administra o SaaS; acesso a conteudo privado das graficas exige autorizacao e auditoria especificas.

## Primeira abordagem
Um numero comercial por grafica, conectado pelo administrador, com atendimento compartilhado entre vendedores. Multiplos numeros e conexoes individuais poderao ser adicionados depois.

## Dependencias antes de implementar
1. Cadastro/convidados de equipe e papeis com autorizacao no servidor.
2. Contrato de integracao e provedor verificado na documentacao oficial vigente.
3. Caixa de entrada com distribuicao, historico e isolamento entre graficas.
4. Testes de recebimento, envio, repeticao de eventos e falhas de conexao.

Nenhuma conexao WhatsApp esta instalada ou ativa nesta entrega. Login por QR nao sera anunciado como funcionalidade pronta sem validacao da integracao selecionada. A primeira entrega atual cobre proprietario, grafica e clientes.
