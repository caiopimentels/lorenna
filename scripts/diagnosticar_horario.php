<?php
// Diagnostico somente leitura; nao mostra credenciais nem mensagens.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/core.php';
echo 'Fuso PHP: ', date_default_timezone_get(), PHP_EOL;
echo 'Agora PHP: ', date('Y-m-d H:i:s P'), PHP_EOL;
echo 'Agora UTC: ', gmdate('Y-m-d H:i:s'), PHP_EOL;
$pdo = getDB();
$dados = $pdo->query('SELECT NOW() AS agora, UTC_TIMESTAMP() AS utc, @@session.time_zone AS fuso_sessao, @@global.time_zone AS fuso_global')->fetch();
foreach ($dados as $campo => $valor) echo 'Banco ', $campo, ': ', $valor, PHP_EOL;
$diferenca = strtotime($dados['agora']) - time();
echo 'Diferenca banco/PHP (segundos): ', $diferenca, PHP_EOL;
$ultima = $pdo->query('SELECT criado_em FROM whatsapp_mensagens ORDER BY id DESC LIMIT 1')->fetchColumn();
echo 'Ultima mensagem registrada: ', $ultima ?: '(nenhuma)', PHP_EOL;
