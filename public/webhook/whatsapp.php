<?php
// Recebe e arquiva eventos; mídias e transcrição são processadas em segundo plano.
require_once __DIR__ . '/../../includes/core.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"erro":"use_post"}');
}

$pdo = getDB();
$cfg = whatsappConfig($pdo);
$token = (string) ($_GET['token'] ?? '');
if (empty($cfg['webhook_token']) || !hash_equals($cfg['webhook_token'], $token)) {
    http_response_code(401);
    exit('{"erro":"token_invalido"}');
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($corpo)) { http_response_code(400); exit('{"erro":"json_invalido"}'); }
if (($corpo['instance'] ?? '') !== $cfg['instancia']) { http_response_code(400); exit('{"erro":"instancia_invalida"}'); }
$evento = strtolower(str_replace('_', '.', (string) ($corpo['event'] ?? '')));
$itens = $corpo['data'] ?? [];
$itens = is_array($itens) && array_is_list($itens) ? $itens : [$itens];

if ($evento === 'connection.update') {
    registrarEstadoWhatsapp($pdo, (string) ($corpo['data']['state'] ?? ''));
}
if ($evento === 'messages.upsert') {
    foreach ($itens as $msg) {
        if (!is_array($msg)) continue;
        $jid = (string) ($msg['key']['remoteJid'] ?? '');
        // Só conversas individuais (grupos, status e canais ficam de fora).
        if (!str_ends_with($jid, '@s.whatsapp.net')) {
            continue;
        }
        $numero = numeroDoJidWhatsapp($jid);
        $idExterno = (string) ($msg['key']['id'] ?? '') ?: null;
        $conteudo = $msg['message'] ?? [];
        while (isset($conteudo['ephemeralMessage']['message']) || isset($conteudo['viewOnceMessage']['message']) || isset($conteudo['viewOnceMessageV2']['message'])) {
            $conteudo = $conteudo['ephemeralMessage']['message'] ?? $conteudo['viewOnceMessage']['message'] ?? $conteudo['viewOnceMessageV2']['message'];
        }
        $msg['message'] = $conteudo;
        $texto = $conteudo['conversation'] ?? $conteudo['extendedTextMessage']['text'] ?? null;
        $tipo = 'texto';
        if ($texto === null) {
            $tipo = match (true) {
                isset($conteudo['audioMessage']) => 'audio',
                isset($conteudo['imageMessage']) => 'imagem',
                isset($conteudo['documentMessage']) => 'documento',
                isset($conteudo['videoMessage']) => 'video',
                isset($conteudo['stickerMessage']) => 'figurinha',
                default => 'outro',
            };
            $texto = $conteudo['imageMessage']['caption'] ?? $conteudo['documentMessage']['caption'] ?? $conteudo['videoMessage']['caption'] ?? null;

        }

        $conversa = conversaWhatsapp($pdo, $numero, ($msg['key']['fromMe'] ?? false) ? null : ($msg['pushName'] ?? null));
        $autor = !empty($msg['key']['fromMe']) ? 'atendente' : 'cliente';
        $pdo->beginTransaction();
        try {
            $idMensagem = gravarMensagemWhatsapp($pdo, (int) $conversa['id'], $autor, $texto ?? '[' . $tipo . ']', $tipo, $idExterno);
            if ($idMensagem === null && $idExterno) {
                $q = $pdo->prepare('SELECT id FROM whatsapp_mensagens WHERE id_externo=?');
                $q->execute([$idExterno]);
                $idMensagem = (int) $q->fetchColumn();
            }
            if ($idMensagem && in_array($tipo, ['audio', 'imagem'], true)) enfileirarMidiaWhatsapp($pdo, $idMensagem, $msg);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}

echo '{"ok":true}';
