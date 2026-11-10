# Lorenna — histórico de WhatsApp

Extraído do módulo de WhatsApp do UniPHD. Inclui visualização de conversas, QR Code, usuários administradores e alertas SMTP.
O painel permite consultar o histórico, preservar imagens e áudios e ler transcrições por IA.
Não depende do código ou banco de dados do UniPHD e não copia credenciais ou
conversas existentes dele.

## Subir no servidor (Docker já instalado)

1. Copie esta pasta para o servidor, por exemplo `/opt/lorenna`.
2. Copie `.env.example` para `.env` e configure todas as variáveis.
   Use segredos diferentes. Para cada chave/senha de infraestrutura, gere um
   valor com `openssl rand -hex 24` (a senha PostgreSQL deve ser hexadecimal
   para poder ser usada diretamente na URI). Não deixe os valores de exemplo.
   `APP_URL` é o endereço HTTPS público, sem barra final.
   `ADMIN_EMAIL` e `ADMIN_PASSWORD` criam o primeiro administrador somente
   quando o banco ainda não tem usuários. Depois altere senhas em Usuários.
3. Execute:

   ```sh
   docker compose up -d --build
   docker compose ps
   docker compose logs --tail=100 app monitor evolution
   ```

4. Configure seu proxy reverso HTTPS para `127.0.0.1:8080` (ou `APP_PORT`).
   Exemplo Nginx dentro do servidor HTTPS existente:

   ```nginx
   location / {
       proxy_pass http://127.0.0.1:8080;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
   }
   ```

   Para um teste por túnel SSH, use `APP_URL=http://localhost:8080` e
   `ssh -L 8080:127.0.0.1:8080 usuario@servidor`. A porta da aplicação fica
   restrita ao próprio servidor por padrão. Bancos, Redis e Evolution não
   publicam portas.

5. Entre com o administrador do `.env`. Em Conexão WhatsApp, as configurações
   internas da Evolution já vêm preenchidas. Mantenha `http://evolution:8080`
   e webhook base `http://app`. Clique **Conectar / mostrar QR Code** e
   leia pelo celular. 
6. Em **E-mail e alertas**, cadastre servidor, porta, segurança, usuário,
   senha SMTP, remetente e destinatário. Salve e envie um teste.
7. Em **Usuários**, crie os demais acessos. Todos são administradores;
   podem alterar configurações e criar, editar ou desativar usuários.

## Histórico e sessão

As mensagens recebidas e enviadas pelo módulo ficam no MariaDB (`app_db`),
independentemente da sessão Evolution. Desconectar, reconectar ou reiniciar
os containers não apaga as conversas. O painel mostra as últimas 300 mensagens
e permite abrir mensagens anteriores. Imagens e áudios são arquivados no volume
`app_storage`; os áudios são transcritos pela Groq quando a chave está configurada.

O histórico começa com as mensagens recebidas pelos webhooks após conectar;
não há importação automática de conversas antigas do celular ou do UniPHD.
Enquanto o WhatsApp está desconectado, o sistema não consegue receber novas
mensagens; a entrega posterior depende da sincronização da Evolution.

Após a primeira conexão bem-sucedida, uma queda gera um aviso pendente.
O webhook acompanha CONNECTION_UPDATE e o serviço `monitor` consulta a
Evolution a cada 60 segundos, inclusive quando a API está indisponível.
Eventos repetidos da mesma queda geram um único aviso; uma nova queda após
reconexão gera outro. STARTTLS/SSL são suportados. Falhas SMTP ficam visíveis
no painel e são tentadas novamente a cada 5 minutos. Uma falha extrema entre
a aceitação SMTP e a gravação no banco pode causar reenvio do mesmo alerta.
Alertas pendentes continuam na fila após reconexão. O monitor precisa estar
rodando e o SMTP acessível para entregar os avisos.

## Persistência e backup

Os volumes `app_db`, `app_storage` (chave de criptografia, imagens e áudios), `evolution_db`,
`evolution_instances` e `redis_data` persistem fora dos containers.
`docker compose down` preserva volumes. **`docker compose down -v` apaga
os dados persistidos.** Mantenha `COMPOSE_PROJECT_NAME=lorenna` ao atualizar.
Guarde cópia segura do `.env` e dos volumes. A chave de `app_storage` é
necessária para recuperar as credenciais criptografadas do banco.

Para um backup consistente completo, pare a pilha sem remover volumes e
exporte os cinco volumes com sua ferramenta de backup do Docker; então
reinicie com `docker compose up -d`. Para cópias lógicas dos bancos:

```sh
mkdir -p backups
chmod 700 backups
umask 077
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction lorenna' > backups/lorenna.sql
docker compose exec -T postgres pg_dump -U evolution evolution > backups/evolution.sql
```

Esses dumps não incluem a chave de criptografia nem os demais volumes.
Teste a restauração em ambiente separado antes de depender do backup.

## Validação e operação

Para conferir a configuração antes de subir: `docker compose config --quiet`.
Para atualizar o código: copie os arquivos e use `docker compose up -d --build`.
O schema é aplicado somente na criação inicial do volume do MariaDB.
Use `docker compose logs --tail=100 monitor` para investigar o monitor.
As folhas de estilo Bootstrap são carregadas por CDN.

Evolution mantida na versão `v2.3.7`, usada no projeto de origem. Os eventos
de conexão e persistência seguem a documentação e configuração oficiais:
- https://github.com/evolution-foundation/evolution-docs/blob/main/docs/02-Configuration/Webhooks.md
- https://github.com/evolution-foundation/evolution-api/blob/main/env.example

Não foi feita implantação em servidor nem configuração de um provedor SMTP real.

## Atualização da primeira versão

Se já iniciou a versão anterior, atualize os arquivos e execute `docker compose up -d --build`. As colunas e tabelas antigas podem permanecer no banco, sem uso; não é necessário apagar volumes ou histórico. O painel permanece sem envio de mensagens; a IA é usada para transcrever áudios arquivados.


## Midias permanentes e transcricao

Configure `GROQ_API_KEY` no `.env` do servidor e execute `docker compose up -d --build`.
O servico `midias` baixa imagens e audios recebidos/enviados pela conta conectada e transcreve
os audios com `GROQ_TRANSCRIPTION_MODEL` (padrao `whisper-large-v3-turbo`). A chave fica no servidor.
O audio e enviado a Groq apenas para transcricao, conforme a documentacao oficial:
https://console.groq.com/docs/speech-to-text

A migracao aditiva roda na inicializacao do app, inclusive em bancos existentes. Fora do Docker,
execute `php scripts/migrar_midias.php` e mantenha `php scripts/midias.php` ativo com as mesmas
variaveis de ambiente do app. O trabalhador requer PHP curl, fileinfo e FFmpeg no PATH.

Imagens e audios ficam em `storage/whatsapp`, no volume `app_storage`, fora da pasta publica.
A tela permite visualizar, ouvir e baixar arquivos mediante login. A transcricao aparece abaixo
do audio; a mensagem original permanece intacta. Falhas sao mostradas na conversa e repetidas
a cada cinco minutos. O arquivo original e mantido mesmo quando a transcricao falha.
OGG/Opus e convertido temporariamente para MP3; o original continua arquivado.
Limites: download de ate 40 MB e envio a transcricao de ate 24 MB por arquivo convertido.

Mensagens e conversas nao possuem acao de exclusao; gatilhos bloqueiam DELETE e alteracao
das mensagens originais, e chaves estrangeiras impedem exclusao em cascata. Eventos de
revogacao/exclusao no WhatsApp nao removem o arquivo local nem o texto arquivado.
Essas protecoes nao impedem um administrador do banco de remover tabelas ou volumes.
Inclua o banco MariaDB e o volume `app_storage` juntos nos backups; nunca use
`docker compose down -v` para atualizar. Arquivos antigos que nao foram armazenados antes
podem estar indisponiveis no WhatsApp; a migracao tenta recuperar imagens e audios antigos
pelo identificador salvo, e a mudanca arquiva os novos eventos recebidos.
