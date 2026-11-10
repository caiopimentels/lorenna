<?php
require_once __DIR__ . '/../includes/core.php';
require __DIR__ . '/migrar_midias.php';
$pdo = getDB();
if (!$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn()) {
    $email = getenv('ADMIN_EMAIL'); $senha = getenv('ADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha ?: '') < 12) throw new RuntimeException('Configure ADMIN_EMAIL e ADMIN_PASSWORD (mínimo 12 caracteres).');
    $pdo->prepare('INSERT IGNORE INTO usuarios (nome,email,senha) VALUES (?,?,?)')->execute(['Administrador',$email,password_hash($senha,PASSWORD_DEFAULT)]);
}
chaveCriptografiaApp();
$cfg = whatsappConfig($pdo);
if (!$cfg['webhook_token']) $pdo->prepare('UPDATE whatsapp_config SET api_url=?,api_chave=?,instancia=?,webhook_base=?,webhook_token=? WHERE id=1')->execute(['http://evolution:8080',criptografar(getenv('EVOLUTION_API_KEY')),'lorenna','http://app',bin2hex(random_bytes(20))]);
