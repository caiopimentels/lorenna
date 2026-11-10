<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (usuarioLogado()) redirect('/');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_tentativas WHERE ip = ? AND criado_em > NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= 10) { http_response_code(429); flash('danger', 'Muitas tentativas. Aguarde 15 minutos.'); }
    else {
        $pdo->prepare('INSERT INTO login_tentativas (ip) VALUES (?)')->execute([$ip]);
        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1');
        $stmt->execute([input('email')]); $u = $stmt->fetch();
        if (password_verify((string) ($_POST['senha'] ?? ''), $u['senha'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.')) {
            if ($u) { session_regenerate_id(true); $_SESSION['usuario'] = ['id'=>$u['id'], 'nome'=>$u['nome'], 'email'=>$u['email']]; $pdo->prepare('DELETE FROM login_tentativas WHERE ip = ?')->execute([$ip]); redirect('/'); }
        }
        flash('danger', 'E-mail ou senha inválidos.');
    }
}
$pageTitle = 'Entrar'; require __DIR__ . '/../includes/layout_header.php';
?>
<form method="post" class="secao mx-auto" style="max-width:420px"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><label class="form-label">E-mail</label><input class="form-control mb-3" name="email" type="email" autocomplete="username" required><label class="form-label">Senha</label><input class="form-control mb-3" name="senha" type="password" autocomplete="current-password" required><button class="btn btn-brand">Entrar</button></form>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
