<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('atender_whatsapp');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
$q = getDB()->prepare('SELECT caminho, mime, sha256 FROM whatsapp_midias WHERE mensagem_id=?');
$q->execute([(int) ($_GET['id'] ?? 0)]);
$m = $q->fetch();
if (!$m || !$m['caminho'] || !preg_match('/^[a-f0-9]{64}\.[a-z0-9]+$/D', $m['caminho'])) { http_response_code(404); exit; }
$path = BASE_PATH . '/storage/whatsapp/' . $m['caminho'];
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . $m['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $m['caminho'] . '"');
session_write_close();
readfile($path);
