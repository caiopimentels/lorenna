<?php
require_once __DIR__ . '/../includes/core.php';
do {
    try { $pdo = getDB(); processarMidiasWhatsapp($pdo, whatsappConfig($pdo)); }
    catch (Throwable $e) { error_log('[Mídias] ' . $e->getMessage()); }
    if (in_array('--once', $argv, true)) break;
    sleep(5);
} while (true);
