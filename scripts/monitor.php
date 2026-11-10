<?php
require_once __DIR__ . '/../includes/core.php';
while (true) {
    try {
        $pdo = getDB(); $cfg = whatsappConfig($pdo);
        if (whatsappConfigurado($cfg)) {
            $r = estadoConexaoWhatsapp($cfg);
            registrarEstadoWhatsapp($pdo, $r['estado']);
        }
        enviarAlertasPendentes($pdo);
        $pdo->exec('DELETE FROM login_tentativas WHERE criado_em < NOW() - INTERVAL 1 DAY');
    } catch (Throwable $e) { error_log('[Monitor] ' . $e->getMessage()); }
    sleep(60);
}
