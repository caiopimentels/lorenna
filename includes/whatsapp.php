<?php
// Registro e consulta de conversas da conta conectada.

function whatsappConfig(PDO $pdo): array
{
    $cfg = $pdo->query('SELECT * FROM whatsapp_config WHERE id = 1')->fetch() ?: [];
    foreach (['api_chave'] as $campo) {
        $cfg[$campo] = descriptografar($cfg[$campo] ?? null);
    }
    return $cfg;
}

function whatsappConfigurado(array $cfg): bool
{
    return !empty($cfg['api_url']) && !empty($cfg['api_chave']) && !empty($cfg['instancia']);
}

// "5577981118760@s.whatsapp.net" → "5577981118760".
function numeroDoJidWhatsapp(string $jid): string
{
    return preg_replace('/\D/', '', explode('@', $jid)[0]);
}

function numeroWhatsappFormatado(string $numero): string
{
    if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $numero, $m)) {
        return '(' . $m[1] . ') ' . $m[2] . '-' . $m[3];
    }
    return '+' . $numero;
}

function conversaWhatsapp(PDO $pdo, string $numero, ?string $nome): array
{
    $stmt = $pdo->prepare('SELECT * FROM whatsapp_conversas WHERE numero = ?');
    $stmt->execute([$numero]);
    $conversa = $stmt->fetch();
    if (!$conversa) {
        $pdo->prepare('INSERT IGNORE INTO whatsapp_conversas (numero, nome) VALUES (?, ?)')
            ->execute([$numero, $nome ? mb_substr($nome, 0, 150) : null]);
        $stmt->execute([$numero]);
        $conversa = $stmt->fetch();
    } elseif ($nome && !$conversa['nome']) {
        $pdo->prepare('UPDATE whatsapp_conversas SET nome = ? WHERE id = ?')->execute([mb_substr($nome, 0, 150), $conversa['id']]);
        $conversa['nome'] = $nome;
    }
    return $conversa;
}

/** Grava uma mensagem; devolve o id (ou null se já existia — mesmo id do WhatsApp). */
function gravarMensagemWhatsapp(PDO $pdo, int $conversaId, string $autor, ?string $texto, string $tipo = 'texto', ?string $idExterno = null, ?string $erro = null): ?int
{
    $usuarioId = $autor === 'atendente' && function_exists('usuarioLogado') && usuarioLogado() ? usuarioAtualId() : null;
    $nome = $usuarioId ? usuarioAtualNome() : null;
    $stmt = $pdo->prepare('INSERT IGNORE INTO whatsapp_mensagens (conversa_id, autor, usuario_id, autor_nome, texto, tipo, id_externo, erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$conversaId, $autor, $usuarioId, $nome, $texto, $tipo, $idExterno, $erro ? mb_substr($erro, 0, 255) : null]);
    if (!$stmt->rowCount()) {
        return null;
    }
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE whatsapp_conversas SET ultima_mensagem_em = NOW()' . ($autor === 'cliente' ? ', nao_lidas = nao_lidas + 1' : '') . ' WHERE id = ?')->execute([$conversaId]);
    return $id;
}

// ---------------------------------------------------------------------
// Evolution API
// ---------------------------------------------------------------------

/** Chamada à Evolution API. Retorna ['ok' => bool, 'status' => int, 'dados' => mixed, 'erro' => string]. */
function chamarEvolutionApi(array $cfg, string $metodo, string $caminho, ?array $corpo = null, int $tempo = 20): array
{
    $curl = curl_init(rtrim((string) $cfg['api_url'], '/') . $caminho);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $tempo,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['apikey: ' . $cfg['api_chave'], 'Content-Type: application/json'],
    ]);
    if ($corpo !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($curl);
    curl_close($curl);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($resposta === false) {
        return ['ok' => false, 'status' => 0, 'dados' => null, 'erro' => 'Não foi possível falar com a Evolution API: ' . $erroCurl];
    }
    $ok = $status >= 200 && $status < 300;
    $mensagem = is_array($dados) ? ($dados['response']['message'] ?? $dados['message'] ?? $dados['error'] ?? '') : '';
    if (is_array($mensagem)) {
        $mensagem = json_encode($mensagem, JSON_UNESCAPED_UNICODE);
    }
    return ['ok' => $ok, 'status' => $status, 'dados' => $dados ?? $resposta,
        'erro' => $ok ? '' : 'Evolution API respondeu ' . $status . ($mensagem !== '' ? ': ' . mb_strimwidth((string) $mensagem, 0, 200, '…') : '')];
}

// Situação da conexão do número: open (conectado), connecting, close.
function estadoConexaoWhatsapp(array $cfg): array
{
    if (!whatsappConfigurado($cfg)) {
        return ['estado' => 'sem_config', 'erro' => ''];
    }
    $r = chamarEvolutionApi($cfg, 'GET', '/instance/connectionState/' . rawurlencode($cfg['instancia']), null, 10);
    if (!$r['ok']) {
        return ['estado' => $r['status'] === 404 ? 'sem_instancia' : 'erro', 'erro' => $r['erro']];
    }
    return ['estado' => (string) ($r['dados']['instance']['state'] ?? $r['dados']['state'] ?? 'desconhecido'), 'erro' => ''];
}

// Endereço que a Evolution API chama. No teste local (Evolution no Docker)
// "localhost" não é o computador: usa-se o endereço configurado.
function urlWebhookWhatsapp(array $cfg): string
{
    $caminho = '/webhook/whatsapp.php?token=' . $cfg['webhook_token'];
    $base = rtrim(trim((string) ($cfg['webhook_base'] ?? '')), '/');
    return $base !== '' ? $base . $caminho : urlAbsoluta($caminho);
}

/**
 * Conecta o número: cria a instância na Evolution API (se ainda não existe),
 * aponta o webhook para o sistema e devolve o QR Code para ler no celular.
 * Retorna ['ok', 'mensagem', 'qrcode' => ?string (data:image/png;base64,...)].
 */
function conectarWhatsapp(array $cfg): array
{
    $webhook = ['enabled' => true, 'url' => urlWebhookWhatsapp($cfg), 'byEvents' => false, 'base64' => true, 'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE']];
    $estado = estadoConexaoWhatsapp($cfg);
    if ($estado['estado'] === 'sem_instancia') {
        $criar = chamarEvolutionApi($cfg, 'POST', '/instance/create', [
            'instanceName' => $cfg['instancia'], 'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => true, 'webhook' => $webhook,
        ]);
        if (!$criar['ok']) {
            return ['ok' => false, 'mensagem' => $criar['erro'], 'qrcode' => null];
        }
    } elseif ($estado['estado'] === 'erro') {
        return ['ok' => false, 'mensagem' => $estado['erro'], 'qrcode' => null];
    }
    $hook = chamarEvolutionApi($cfg, 'POST', '/webhook/set/' . rawurlencode($cfg['instancia']), ['webhook' => $webhook]);
    if (!$hook['ok']) {
        return ['ok' => false, 'mensagem' => 'Não foi possível configurar o webhook: ' . $hook['erro'], 'qrcode' => null];
    }
    if ($estado['estado'] === 'open') {
        return ['ok' => true, 'mensagem' => 'O número já está conectado. O webhook foi atualizado.', 'qrcode' => null];
    }
    $qr = chamarEvolutionApi($cfg, 'GET', '/instance/connect/' . rawurlencode($cfg['instancia']));
    $imagem = is_array($qr['dados']) ? ($qr['dados']['base64'] ?? $qr['dados']['qrcode']['base64'] ?? null) : null;
    return $imagem
        ? ['ok' => true, 'mensagem' => 'Abra o WhatsApp no celular › Aparelhos conectados › Conectar um aparelho, e leia o QR Code.', 'qrcode' => $imagem]
        : ['ok' => false, 'mensagem' => 'A Evolution API não devolveu o QR Code. ' . $qr['erro'], 'qrcode' => null];
}

