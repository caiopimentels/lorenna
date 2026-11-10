<?php
function configEmail(PDO $pdo): array {
    $c = $pdo->query('SELECT * FROM email_config WHERE id=1')->fetch();
    $c['smtp_senha'] = descriptografar($c['smtp_senha']); return $c;
}
// Uma ocorrência permanece pendente até a confirmação SMTP; eventos repetidos não duplicam o alerta.
function registrarEstadoWhatsapp(PDO $pdo, string $estado): void {
    if (!in_array($estado, ['open','close','connecting','sem_instancia','erro'], true)) return;
    $pdo->beginTransaction();
    try {
        $c = $pdo->query('SELECT * FROM whatsapp_monitor WHERE id=1 FOR UPDATE')->fetch();
        $pdo->prepare('UPDATE whatsapp_monitor SET estado=?, verificado_em=NOW() WHERE id=1')->execute([$estado]);
        if ($estado === 'open') {
            $pdo->exec('UPDATE whatsapp_monitor SET conectado_antes=1, desconectado_em=NULL WHERE id=1');
        } elseif ($c['conectado_antes'] && !$c['desconectado_em']) {
            $pdo->exec('UPDATE whatsapp_monitor SET desconectado_em=NOW() WHERE id=1');
            $pdo->prepare('INSERT INTO email_alertas (estado) VALUES (?)')->execute([$estado]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function enviarAlertasPendentes(PDO $pdo): void {
    if (!(int) $pdo->query("SELECT GET_LOCK('lorenna_email', 0)")->fetchColumn()) return;
    try {
        $cfg = configEmail($pdo);
        if (!$cfg['alertas_ativos'] || !$cfg['destino']) return;
        foreach ($pdo->query('SELECT * FROM email_alertas WHERE enviado_em IS NULL AND (tentado_em IS NULL OR tentado_em < NOW() - INTERVAL 5 MINUTE) ORDER BY id LIMIT 10')->fetchAll() as $alerta) {
            $wa = whatsappConfig($pdo);
            $pdo->prepare('UPDATE email_alertas SET tentado_em=NOW(), tentativas=tentativas+1 WHERE id=?')->execute([$alerta['id']]);
            $r = enviarEmail($cfg, $cfg['destino'], '', 'Lorenna: sessão do WhatsApp desconectada', '<p>A sessão <strong>' . e($wa['instancia']) . '</strong> perdeu a conexão em ' . e($alerta['criado_em']) . '.</p><p>Estado detectado: ' . e($alerta['estado']) . '.</p><p>As conversas salvas continuam disponíveis. Acesse <a href="' . e(urlAbsoluta('/whatsapp/configuracao.php')) . '">o painel</a> para verificar e reconectar.</p>');
            $pdo->prepare('UPDATE email_alertas SET enviado_em=?, erro=? WHERE id=?')->execute([$r['sucesso'] ? date('Y-m-d H:i:s') : null, $r['sucesso'] ? null : mb_substr($r['mensagem'],0,500), $alerta['id']]);
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK('lorenna_email')"); }
}
