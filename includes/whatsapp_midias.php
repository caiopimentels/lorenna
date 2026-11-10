<?php
// Os arquivos ficam fora da pasta pública. Nenhum evento de exclusão apaga o arquivo local.
function enfileirarMidiaWhatsapp(PDO $pdo, int $id, array $msg): void
{
    $pdo->prepare('INSERT IGNORE INTO whatsapp_midias (mensagem_id, evento) VALUES (?, ?)')
        ->execute([$id, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function decodificarMidiaWhatsapp(string $base64): string
{
    $base64 = preg_replace('#^data:[^,]*;base64,#', '', $base64);
    $bytes = base64_decode($base64, true);
    if ($bytes === false || $bytes === '' || strlen($bytes) > 40 * 1024 * 1024) {
        throw new RuntimeException('Arquivo vazio, inválido ou maior que 40 MB.');
    }
    return $bytes;
}

function salvarMidiaWhatsapp(string $bytes): array
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
        'audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/mp4'=>'m4a',
        'video/mp4'=>'mp4','audio/x-wav'=>'wav','audio/wav'=>'wav','audio/webm'=>'webm','video/webm'=>'webm'];
    if (!isset($ext[$mime])) throw new RuntimeException('Formato de mídia não suportado: ' . $mime);
    $hash = hash('sha256', $bytes);
    $dir = BASE_PATH . '/storage/whatsapp';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar o arquivo de mídia.');
    $nome = $hash . '.' . $ext[$mime];
    $path = $dir . '/' . $nome;
    if (!is_file($path)) {
        $tmp = tempnam($dir, 'recebendo-');
        try {
            if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Falha ao salvar mídia.');
            chmod($tmp, 0640);
            if (!rename($tmp, $path)) throw new RuntimeException('Falha ao concluir arquivo de mídia.');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
    if (hash_file('sha256', $path) !== $hash) throw new RuntimeException('Arquivo local não passou na verificação de integridade.');
    return ['caminho'=>$nome, 'mime'=>$mime, 'sha256'=>$hash];
}

function transcreverAudioWhatsapp(string $path, string $mime): string
{
    $chave = getenv('GROQ_API_KEY');
    if (!$chave) throw new RuntimeException('Transcrição aguardando configuração pelo administrador.');
    $temporario = null;
    if (in_array($mime, ['audio/ogg', 'application/ogg'], true)) {
        $temporario = tempnam(sys_get_temp_dir(), 'lorenna-audio-');
        $processo = proc_open(['ffmpeg', '-v', 'error', '-nostdin', '-y', '-i', $path, '-vn', '-c:a', 'libmp3lame', '-b:a', '64k', '-f', 'mp3', $temporario],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($processo)) { unlink($temporario); throw new RuntimeException('Conversor de áudio indisponível.'); }
        fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($processo) !== 0 || !filesize($temporario)) { unlink($temporario); throw new RuntimeException('Falha ao converter áudio; original preservado.'); }
        $path = $temporario;
        $mime = 'audio/mpeg';
    }
    try {
        if (filesize($path) > 24 * 1024 * 1024) throw new RuntimeException('áudio preservado; transcrição limitada a 24 MB por arquivo.');
        $curl = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>180, CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $chave],
            CURLOPT_POSTFIELDS=>['model'=>getenv('GROQ_TRANSCRIPTION_MODEL') ?: 'whisper-large-v3-turbo',
                'file'=>new CURLFile($path, $mime, ($mime === 'audio/mpeg' ? 'audio.mp3' : basename($path))), 'response_format'=>'json']]);
        $resposta = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $dados = is_string($resposta) ? json_decode($resposta, true) : null;
        if ($status < 200 || $status >= 300 || !isset($dados['text']) || !is_string($dados['text'])) {
            throw new RuntimeException('Falha na transcrição (HTTP ' . (int) $status . '). Nova tentativa automática.');
        }
        return $dados['text'];
    } finally { if ($temporario && is_file($temporario)) unlink($temporario); }
}

function processarMidiasWhatsapp(PDO $pdo, array $cfg): void
{
    // Um único trabalhador por banco, inclusive durante reinício de contêineres.
    if (!(int) $pdo->query("SELECT GET_LOCK('lorenna_midias', 0)")->fetchColumn()) return;
    try {
        $pendentes = $pdo->query("SELECT a.*, m.tipo FROM whatsapp_midias a JOIN whatsapp_mensagens m ON m.id=a.mensagem_id
            WHERE (a.caminho IS NULL OR (m.tipo='audio' AND a.transcricao IS NULL))
            AND (a.tentado_em IS NULL OR a.tentado_em < NOW() - INTERVAL 5 MINUTE)
            ORDER BY a.tentado_em, a.mensagem_id LIMIT 10")->fetchAll();
        foreach ($pendentes as $item) {
            $id = (int) $item['mensagem_id'];
            $pdo->prepare('UPDATE whatsapp_midias SET tentativas=tentativas+1, tentado_em=NOW() WHERE mensagem_id=?')->execute([$id]);
            try {
                if (!$item['caminho']) {
                    $msg = json_decode($item['evento'], true, 512, JSON_THROW_ON_ERROR);
                    $b64 = $msg['message']['base64'] ?? $msg['base64'] ?? '';
                    if (!$b64) {
                        $r = chamarEvolutionApi($cfg, 'POST', '/chat/getBase64FromMediaMessage/' . rawurlencode($cfg['instancia']),
                            ['message'=>$msg, 'convertToMp4'=>false], 60);
                        if (!$r['ok']) throw new RuntimeException('Download pendente: ' . $r['erro']);
                        $b64 = $r['dados']['base64'] ?? '';
                    }
                    $arquivo = salvarMidiaWhatsapp(decodificarMidiaWhatsapp($b64));
                    $pdo->prepare('UPDATE whatsapp_midias SET caminho=?, mime=?, sha256=?, erro=NULL WHERE mensagem_id=?')
                        ->execute([$arquivo['caminho'], $arquivo['mime'], $arquivo['sha256'], $id]);
                    $item = array_merge($item, $arquivo);
                }
                if ($item['tipo'] === 'audio' && $item['transcricao'] === null) {
                    $texto = transcreverAudioWhatsapp(BASE_PATH . '/storage/whatsapp/' . $item['caminho'], $item['mime']);
                    $pdo->prepare('UPDATE whatsapp_midias SET transcricao=?, erro=NULL WHERE mensagem_id=?')->execute([$texto, $id]);
                }
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE whatsapp_midias SET erro=? WHERE mensagem_id=?')->execute([mb_substr($e->getMessage(), 0, 255), $id]);
            }
        }
    } finally { $pdo->query("SELECT RELEASE_LOCK('lorenna_midias')"); }
}
