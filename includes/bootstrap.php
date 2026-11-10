<?php
require_once __DIR__ . '/core.php';
session_set_cookie_params(['httponly'=>true, 'secure'=>str_starts_with(getenv('APP_URL') ?: '', 'https://'), 'samesite'=>'Lax']);
session_start();
if (usuarioLogado()) {
    $stmt = getDB()->prepare('SELECT id, nome, email FROM usuarios WHERE id = ? AND ativo = 1');
    $stmt->execute([usuarioAtualId()]);
    $user = $stmt->fetch();
    if (!$user) { $_SESSION = []; redirect('/login.php'); }
    $_SESSION['usuario'] = $user;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrfToken(), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); exit('Formulário expirado. Atualize a página.');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
