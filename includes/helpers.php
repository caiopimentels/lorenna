<?php

function ehRequisicaoAjax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * Token de CSRF por sessão — gerado uma vez e reaproveitado. Todo POST do
 * sistema (formulário normal ou fetch) precisa mandar esse valor de volta,
 * senão é rejeitado (ver o bootstrap.php). Não depende de estar logado:
 * login e recuperação de senha também são formulários POST.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function responderJson(array $dados): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados);
    exit;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Monta a URL de um arquivo dentro de public/ (assets, uploads) a partir da
 * pasta real de onde a página atual está sendo executada, comparando o
 * caminho de disco do script atual com o caminho de disco de public/. Evita
 * que imagens/CSS quebrem quando o servidor não está apontado direto pra
 * pasta public/ (ex: rodando "php -S" a partir da raiz do projeto).
 */
function publicUrl(string $caminho): string
{
    static $prefixo = null;
    if ($prefixo === null) {
        $prefixo = '';
        $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        $publicDir = realpath(BASE_PATH . '/public') ?: '';
        $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';

        if ($scriptFile !== '' && $publicDir !== '' && str_starts_with($scriptFile, $publicDir . DIRECTORY_SEPARATOR)) {
            $relativo = str_replace(DIRECTORY_SEPARATOR, '/', substr($scriptFile, strlen($publicDir) + 1));
            if (str_ends_with($scriptUrl, '/' . $relativo)) {
                $prefixo = substr($scriptUrl, 0, -strlen('/' . $relativo));
            }
        }
    }
    return $prefixo . $caminho;
}

/**
 * publicUrl() com "?v=" baseado na data de modificação do arquivo — assim,
 * toda vez que um CSS/JS muda, o navegador busca a versão nova sozinho, sem
 * precisar de um hard refresh manual pra enxergar a alteração.
 */
function assetUrl(string $caminho): string
{
    $caminhoFs = BASE_PATH . '/public' . $caminho;
    $versao = is_file($caminhoFs) ? filemtime($caminhoFs) : time();
    return publicUrl($caminho) . '?v=' . $versao;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function formatDate(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return date('d/m/Y', strtotime($value));
}

function formatDateTime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($value));
}

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function input(string $key, $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function decimalInput(string $key, $default = 0): float
{
    $raw = $_POST[$key] ?? $default;
    $raw = str_replace('.', '', (string) $raw);
    $raw = str_replace(',', '.', $raw);
    return (float) $raw;
}

function buscarConfiguracoes(PDO $pdo): array
{
    static $config = null;
    if ($config === null) {
        $config = $pdo->query('SELECT * FROM configuracoes WHERE id = 1')->fetch();
    }
    return $config;
}

