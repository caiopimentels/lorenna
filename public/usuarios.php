<?php
require_once __DIR__ . '/../includes/bootstrap.php'; exigirPermissao('admin');
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0); $nome = input('nome'); $email = input('email'); $senha = (string) ($_POST['senha'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    if (!$nome || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email) || ((!$id || $senha !== '') && strlen($senha) < 12)) flash('danger', 'Informe nome, e-mail válido e senha de pelo menos 12 caracteres.');
    elseif ($id === usuarioAtualId() && !$ativo) flash('danger', 'Você não pode desativar seu próprio acesso.');
    else {
        try {
            if ($id) {
                $sql = 'UPDATE usuarios SET nome=?, email=?, ativo=?' . ($senha !== '' ? ', senha=?' : '') . ' WHERE id=?';
                $params = [$nome, $email, $ativo]; if ($senha !== '') $params[] = password_hash($senha, PASSWORD_DEFAULT); $params[] = $id;
                $pdo->prepare($sql)->execute($params);
            } else $pdo->prepare('INSERT INTO usuarios (nome,email,senha,ativo) VALUES (?,?,?,?)')->execute([$nome,$email,password_hash($senha,PASSWORD_DEFAULT),$ativo]);
            flash('success', 'Usuário salvo. Todos os usuários são administradores.'); redirect('/usuarios.php');
        } catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; flash('danger', 'Este e-mail já está cadastrado.'); }
    }
}
$stmt = $pdo->prepare('SELECT id,nome,email,ativo FROM usuarios WHERE id=?'); $stmt->execute([(int) ($_GET['id'] ?? 0)]); $edit = $stmt->fetch() ?: ['id'=>0,'nome'=>'','email'=>'','ativo'=>1];
$pageTitle = 'Usuários administradores'; require __DIR__ . '/../includes/layout_header.php';
?>
<form method="post" class="secao"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><div class="row g-3"><div class="col-md-4"><label>Nome</label><input name="nome" class="form-control" maxlength="150" value="<?= e($edit['nome']) ?>" required></div><div class="col-md-4"><label>E-mail</label><input name="email" type="email" maxlength="254" class="form-control" value="<?= e($edit['email']) ?>" required></div><div class="col-md-4"><label>Senha (12 caracteres; em branco mantém)</label><input name="senha" type="password" class="form-control" minlength="12" <?= !$edit['id'] ? 'required' : '' ?> autocomplete="new-password"></div></div><label class="my-3"><input type="checkbox" name="ativo" <?= $edit['ativo'] ? 'checked' : '' ?>> Ativo</label><br><button class="btn btn-brand">Salvar usuário</button> <a href="/usuarios.php">Novo usuário</a></form>
<table class="table"><tr><th>Nome</th><th>E-mail</th><th>Status</th><th></th></tr><?php foreach ($pdo->query('SELECT id,nome,email,ativo FROM usuarios ORDER BY nome') as $u): ?><tr><td><?= e($u['nome']) ?></td><td><?= e($u['email']) ?></td><td><?= $u['ativo'] ? 'Ativo' : 'Desativado' ?></td><td><a href="?id=<?= (int) $u['id'] ?>">Editar</a></td></tr><?php endforeach; ?></table>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
