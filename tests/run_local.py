"""Testes locais com MariaDB descartável em 127.0.0.1:33317; sem Docker."""
from pathlib import Path
import base64, io, wave
import os, subprocess, socketserver, threading, urllib.request, urllib.parse, http.cookiejar, re, json

root = Path(__file__).resolve().parents[1]
env = dict(os.environ, DB_HOST='127.0.0.1', DB_PORT='33317', DB_NAME='lorenna_test', DB_USER='root', DB_PASSWORD='', ADMIN_EMAIL='admin@example.com', ADMIN_PASSWORD='senha-de-teste-123456', EVOLUTION_API_KEY='chave-de-teste', APP_URL='http://127.0.0.1:33316')
(root / 'tests/sessions').mkdir(exist_ok=True)
php = ['php', '-d', 'extension=php_sodium.dll', '-d', 'session.save_path=' + str(root / 'tests/sessions')]
messages = []
class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 teste\r\n')
        auth = 0
        while line := self.rfile.readline():
            cmd = line.decode().strip()
            if auth:
                auth -= 1
                reply = b'235 OK\r\n' if not auth else b'334 UGFzc3dvcmQ6\r\n'
            elif cmd.startswith('EHLO'): reply = b'250 teste\r\n'
            elif cmd == 'AUTH LOGIN': auth = 2; reply = b'334 VXNlcm5hbWU6\r\n'
            elif cmd == 'DATA':
                self.wfile.write(b'354 Envie\r\n'); data = b''
                while (part := self.rfile.readline()) != b'.\r\n': data += part
                messages.append(data); reply = b'250 Aceito\r\n'
            elif cmd == 'QUIT': self.wfile.write(b'221 Tchau\r\n'); break
            else: reply = b'250 OK\r\n'
            self.wfile.write(reply)

server = socketserver.TCPServer(('127.0.0.1',33318), SMTP)
threading.Thread(target=server.serve_forever, daemon=True).start()
subprocess.run(php + ['tests/integration.php'], cwd=root, env=env, check=True)
assert len(messages) == 2
log = open(root / 'tests/http.log', 'w')
web = subprocess.Popen(php + ['-S', '127.0.0.1:33316', '-t', 'public'], cwd=root, env=env, stdout=log, stderr=log)
jar = http.cookiejar.CookieJar(); client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def req(path, data=None):
    try:
        r = client.open('http://127.0.0.1:33316' + path, urllib.parse.urlencode(data).encode() if data is not None else None)
        return r.status, r.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()
def token(html): return re.search(r'name="csrf_token" value="([a-f0-9]+)"', html).group(1)
try:
    for attempt in range(50):
        try: status, html = req('/login.php'); break
        except OSError: threading.Event().wait(.1)
    assert status == 200
    assert req('/login.php', {'email':env['ADMIN_EMAIL'],'senha':env['ADMIN_PASSWORD']})[0] == 403
    status, html = req('/login.php', {'email':env['ADMIN_EMAIL'],'senha':env['ADMIN_PASSWORD'],'csrf_token':token(html)})
    assert status == 200 and 'Escolha uma conversa' in html
    assert 'wa-texto' not in html and 'bi-robot' not in html
    assert req('/whatsapp/conversas.php', {'csrf_token':token(html),'acao':'responder','texto':'não enviar'})[0] == 405
    for path in ['/whatsapp/configuracao.php','/email.php','/usuarios.php','/whatsapp/conversas.php?id=1','/whatsapp/conversas.php?busca=Teste']:
        status, html = req(path); assert status == 200 and 'Fatal error' not in html, path
    status, html = req('/usuarios.php')
    status, html = req('/usuarios.php', {'csrf_token':token(html),'nome':'Segunda admin','email':'segunda@example.com','senha':'outra-senha-12345','ativo':'on'})
    assert status == 200 and 'segunda@example.com' in html
    status, html = req('/usuarios.php?id=2')
    status, html = req('/usuarios.php?id=2', {'csrf_token':token(html),'id':'2','nome':'Segunda admin','email':'segunda@example.com','senha':''})
    assert status == 200 and 'Desativado' in html
    status, html = req('/email.php')
    status, html = req('/email.php', {'csrf_token':token(html),'smtp_host':'smtp.example.com','smtp_porta':'587','smtp_seguranca':'tls','smtp_usuario':'teste','smtp_senha':'senha-nova','smtp_remetente_email':'remetente@example.com','smtp_remetente_nome':'Lorenna','destino':'alerta@example.com','alertas_ativos':'on'})
    assert status == 200 and 'Configurações salvas' in html
    status, html = req('/whatsapp/conversas.php?id=1&parcial=1')
    assert status == 200 and 'Histórico permanente' in json.loads(html)['mensagens']
    assert req('/webhook/whatsapp.php?token=invalido', {})[0] == 401
    status, html = req('/whatsapp/conversas.php?id=1')
    assert 'Baixar arquivo preservado' in html
    status, data = req('/whatsapp/midia.php?id=999999')
    assert status == 404
    # Webhook real com midia embutida; repeticao e revogacao nao removem o historico.
    hook = subprocess.check_output(php + ['-r', "require 'includes/core.php'; echo whatsappConfig(getDB())['webhook_token'];"], cwd=root, env=env).decode()
    png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='
    def webhook(event, message):
        payload = json.dumps({'instance':'lorenna','event':event,'data':message}).encode()
        request = urllib.request.Request('http://127.0.0.1:33316/webhook/whatsapp.php?token='+hook, payload, {'Content-Type':'application/json'})
        with client.open(request) as response: assert json.load(response)['ok']
    image = {'key':{'remoteJid':'5577999990000@s.whatsapp.net','fromMe':False,'id':'http-image'},'message':{'imageMessage':{'caption':'Imagem HTTP'},'base64':png}}
    webhook('MESSAGES_UPSERT', image); webhook('MESSAGES_UPSERT', image)
    wav = io.BytesIO()
    with wave.open(wav, 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(8000); w.writeframes(b'\x00\x00'*800)
    audio = {'key':{'remoteJid':'5577999990000@s.whatsapp.net','id':'http-audio'},'message':{'audioMessage':{},'base64':base64.b64encode(wav.getvalue()).decode()}}
    webhook('MESSAGES_UPSERT', audio)
    subprocess.run(php+['scripts/midias.php','--once'], cwd=root, env=dict(env, OPENAI_API_KEY=''), check=True)
    webhook('MESSAGES_DELETE', image['key'])
    status, html = req('/whatsapp/conversas.php?id=1')
    assert 'Imagem HTTP' in html and 'Transcrição aguardando configuração pelo administrador.' in html
    media_ids = re.findall(r'src="/whatsapp/midia.php\?id=(\d+)"', html)
    assert len(media_ids) == 3
    with client.open('http://127.0.0.1:33316/whatsapp/midia.php?id='+media_ids[-2]) as response:
        assert response.headers['Content-Type']=='image/png' and response.read()==base64.b64decode(png)
    unauth = urllib.request.urlopen('http://127.0.0.1:33316/whatsapp/midia.php?id='+media_ids[-2])
    assert unauth.geturl().endswith('/login.php')
    print('OK midias HTTP: download autenticado, duplicacao, audio pendente sem chave, revogacao preservada')
    print('OK HTTP: login, CSRF, telas, criação/desativação de administrador, SMTP, histórico e autenticação webhook')
finally:
    web.terminate(); web.wait(); log.close(); server.shutdown(); server.server_close()
