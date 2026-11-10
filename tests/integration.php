<?php
// Use apenas um banco descartável chamado lorenna_test.
if (getenv('DB_NAME') !== 'lorenna_test') throw new RuntimeException('DB_NAME deve ser lorenna_test.');
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT'), getenv('DB_USER'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS lorenna_test CHARACTER SET utf8mb4');
$pdo->exec('USE lorenna_test');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['whatsapp_midias','whatsapp_mensagens','whatsapp_conversas','whatsapp_config','whatsapp_uso_ia','whatsapp_ia_pausa','usuarios','email_config','whatsapp_monitor','email_alertas','login_tentativas'] as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.sql'));
require __DIR__ . '/../scripts/iniciar.php';
$pdo = getDB();
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK $label\n"; }
check(date_default_timezone_get() === 'America/Bahia', 'PHP no fuso da Bahia');
check($pdo->query('SELECT @@session.time_zone')->fetchColumn() === '-03:00', 'banco no mesmo fuso do PHP');
$agoraBanco = $pdo->query('SELECT NOW()')->fetchColumn();
check(abs(strtotime($agoraBanco) - time()) <= 5, 'horarios do PHP e banco sincronizados');
check(!function_exists('enviarTextoWhatsapp') && !function_exists('chamarIaChat') && function_exists('transcreverAudioWhatsapp'), 'sem envio de mensagens; transcricao disponivel');
check((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 1, 'administrador inicial');
$user = $pdo->query('SELECT * FROM usuarios')->fetch();
check(password_verify(getenv('ADMIN_PASSWORD'), $user['senha']), 'senha armazenada com hash');
check(whatsappConfig($pdo)['api_chave'] === getenv('EVOLUTION_API_KEY'), 'criptografia de credenciais');
$conversa = conversaWhatsapp($pdo, '5577999990000', 'Teste');
$id = gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'cliente', 'Histórico permanente', 'texto', 'teste-unico');
check($id !== null, 'mensagem gravada');
check(gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'cliente', 'Duplicada', 'texto', 'teste-unico') === null, 'webhook duplicado ignorado');
foreach (["DELETE FROM whatsapp_mensagens WHERE id=$id", "UPDATE whatsapp_mensagens SET texto='alterado' WHERE id=$id", "DELETE FROM whatsapp_conversas WHERE id=" . (int) $conversa['id']] as $sql) {
    $bloqueou = false;
    try { $pdo->exec($sql); } catch (PDOException $e) { $bloqueou = $e->getCode() === '45000' || $e->getCode() === '23000'; }
    check($bloqueou, 'bloqueio de alteração/exclusão do histórico');
}
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
$idImagem = gravarMensagemWhatsapp($pdo, (int) $conversa['id'], 'cliente', '[imagem]', 'imagem', 'imagem-teste');
enfileirarMidiaWhatsapp($pdo, $idImagem, ['message'=>['base64'=>$png]]);
enfileirarMidiaWhatsapp($pdo, $idImagem, ['message'=>['base64'=>'invalida']]);
processarMidiasWhatsapp($pdo, whatsappConfig($pdo));
require __DIR__ . '/../scripts/migrar_midias.php';
$a = $pdo->query('SELECT * FROM whatsapp_midias')->fetch();
check($a['caminho'] && $a['mime']==='image/png' && hash_file('sha256', BASE_PATH . '/storage/whatsapp/' . $a['caminho'])===$a['sha256'], 'imagem baixada, validada e preservada');
check((int) $pdo->query('SELECT COUNT(*) FROM whatsapp_midias')->fetchColumn() === 1, 'mídia duplicada ignorada');
$bloqueou = false;
try { $pdo->exec('DELETE FROM whatsapp_midias'); } catch (PDOException $e) { $bloqueou = $e->getCode()==='45000'; }
check($bloqueou, 'exclusão da mídia bloqueada');
try { decodificarMidiaWhatsapp('!invalido'); throw new LogicException('Base64 inválido aceito'); } catch (RuntimeException $e) { check(true, 'base64 inválido recusado'); }
registrarEstadoWhatsapp($pdo, 'close');
check((int) $pdo->query('SELECT COUNT(*) FROM email_alertas')->fetchColumn() === 0, 'sem alerta antes da primeira conexão');
registrarEstadoWhatsapp($pdo, 'open'); registrarEstadoWhatsapp($pdo, 'close'); registrarEstadoWhatsapp($pdo, 'close'); registrarEstadoWhatsapp($pdo, 'erro');
check((int) $pdo->query('SELECT COUNT(*) FROM email_alertas')->fetchColumn() === 1, 'um alerta por queda');
registrarEstadoWhatsapp($pdo, 'open'); registrarEstadoWhatsapp($pdo, 'close');
check((int) $pdo->query('SELECT COUNT(*) FROM email_alertas')->fetchColumn() === 2, 'nova queda gera novo alerta');
check((int) $pdo->query('SELECT COUNT(*) FROM whatsapp_mensagens')->fetchColumn() === 2, 'histórico preservado após desconexão e reconexão');
$pdo->prepare('UPDATE email_config SET smtp_host=?,smtp_porta=?,smtp_usuario=?,smtp_senha=?,smtp_remetente_email=?,destino=? WHERE id=1')->execute(['127.0.0.1',33319,'teste',criptografar('teste'),'origem@example.com','destino@example.com']);
enviarAlertasPendentes($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM email_alertas WHERE enviado_em IS NULL AND tentativas=1 AND erro IS NOT NULL')->fetchColumn() === 2, 'falha SMTP mantém alertas pendentes');
enviarAlertasPendentes($pdo);
check((int) $pdo->query('SELECT SUM(tentativas) FROM email_alertas')->fetchColumn() === 2, 'intervalo de retentativa SMTP respeitado');
// O servidor SMTP local de teste não usa TLS. A interface de produção exige TLS/SSL.
$pdo->exec("UPDATE email_config SET smtp_porta=33318,smtp_seguranca='none'");
$pdo->exec('UPDATE email_alertas SET tentado_em=NULL');
enviarAlertasPendentes($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM email_alertas WHERE enviado_em IS NOT NULL')->fetchColumn() === 2, 'SMTP aceitou e confirmou os alertas');
enviarAlertasPendentes($pdo);
check((int) $pdo->query('SELECT SUM(tentativas) FROM email_alertas')->fetchColumn() === 4, 'alerta enviado não é reenviado');
$pdo->exec('UPDATE email_config SET alertas_ativos=0');
echo "Integração concluída.\n";
