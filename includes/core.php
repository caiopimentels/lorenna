<?php
define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Bahia');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/whatsapp_midias.php';
require_once __DIR__ . '/monitor.php';
function getDB(): PDO {
    static $db;
    if ($db) return $db;
    $conexao = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_NAME') ?: 'lorenna') . ';charset=utf8mb4', getenv('DB_USER') ?: 'lorenna', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    // Mesmo fuso do PHP, independente do fuso do servidor ou de volumes existentes.
    $conexao->exec("SET time_zone = '-03:00'");
    return $db = $conexao;
}
function urlAbsoluta(string $path): string { return rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/') . $path; }
function usuarioLogado(): bool { return !empty($_SESSION['usuario']); }
function usuarioAtualId(): int { return (int) ($_SESSION['usuario']['id'] ?? 0); }
function usuarioAtualNome(): string { return $_SESSION['usuario']['nome'] ?? ''; }
function usuarioTemPermissao(string $p): bool { return usuarioLogado(); }
function exigirPermissao(string $p, string $m=''): void { if (!usuarioLogado()) redirect('/login.php'); }
function registrarAtividade(string $a, string $b, string $c): void { error_log("[Lorenna] $a/$b: $c"); }
function criarNotificacao(PDO $pdo, string $tipo, string $titulo, string $texto, string $url, int $id): void { error_log('[Lorenna] ' . $titulo . ': ' . $texto); }
