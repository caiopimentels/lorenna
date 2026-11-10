# Validação local

Executada em 10/11/2026, com PHP 8.2, MariaDB 10.4 isolado e servidor SMTP de teste.

- Sintaxe de todos os arquivos PHP aprovada.
- Schema criado em banco novo, sem tabelas do UniPHD.
- Criação do administrador inicial, hash de senha e criptografia de credenciais.
- Mensagem recebida gravada; repetição do mesmo ID externo ignorada.
- Histórico preservado após queda, reconexão e nova queda.
- Sem alerta antes da primeira conexão; um alerta por queda, mesmo com eventos repetidos.
- SMTP indisponível mantém alerta pendente; respeita intervalo de retentativa.
- SMTP de teste aceita alertas; confirmação registrada no banco e sem reenvio posterior.
- HTTP: login, rejeição de POST sem CSRF, abertura das telas, criação e desativação
  de administrador, gravação de configuração SMTP, atualização de conversas e
  rejeição de webhook com token inválido.
- YAML lido e estrutura dos seis serviços e cinco volumes conferida.

Docker não está instalado no computador de desenvolvimento: build, `docker compose
config` e inicialização dos containers precisam ser executados no servidor.
Conexão com WhatsApp real, proxy HTTPS e SMTP real não foram testados.

Os testes em `tests/` usam somente um banco descartável `lorenna_test`. O executor
local Python foi preparado para o PHP/XAMPP do Windows com extensão sodium habilitada
e uma instância isolada MariaDB em `127.0.0.1:33317`. Ele recria as tabelas desse banco.

Atualização: transcrição em segundo plano e arquivo permanente de imagens/áudios implementados.
Validados com MariaDB descartável, SMTP simulado e servidor PHP local:
- DELETE de mensagens, conversas e mídias recusado; UPDATE de mensagens recusado.
- Gravação de PNG com hash SHA-256 e idempotência da fila.
- Webhooks HTTP de imagem e áudio, repetição de evento e revogação preservando histórico.
- Download autenticado de imagem retorna os bytes originais; acesso sem login redireciona.
- Áudio original arquivado e erro de configuração visível quando falta chave de IA.
- Migração executada sobre schema existente sem perder mensagens.
- Login, CSRF, usuários, SMTP e consulta do histórico continuam aprovados.

Não houve chamada real à OpenAI, pareamento com WhatsApp ou execução do Docker neste ambiente.
A conversão OGG/Opus depende do FFmpeg instalado na imagem Docker; não foi exercitada localmente.
Configure OPENAI_API_KEY e reconstrua a pilha para ativar a transcrição real.
