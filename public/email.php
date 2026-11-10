<?php
require_once __DIR__ . '/../includes/bootstrap.php'; exigirPermissao('admin');
$pdo = getDB(); $cfg = configEmail($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (input('acao') === 'testar') {
        $r = enviarEmail($cfg, $cfg['destino'], '', 'Lorenna: teste de e-mail', '<p>O envio de e-mail do Lorenna está funcionando.</p>');
        flash($r['sucesso'] ? 'success' : 'danger', $r['mensagem']);
    } else {
        $email = input('smtp_remetente_email'); $destino = input('destino'); $host = input('smtp_host');
        $porta = (int) input('smtp_porta'); $seg = input('smtp_seguranca');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($destino, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email . $destino . $host) || !preg_match('/^[a-zA-Z0-9.-]+$/', $host) || $porta < 1 || $porta > 65535 || !in_array($seg, ['tls','ssl'], true)) flash('danger', 'Confira servidor, porta e endereços de e-mail.');
        else {
            $senha = (string) ($_POST['smtp_senha'] ?? '');
            $pdo->prepare('UPDATE email_config SET smtp_host=?,smtp_porta=?,smtp_seguranca=?,smtp_usuario=?,smtp_senha=COALESCE(?,smtp_senha),smtp_remetente_email=?,smtp_remetente_nome=?,destino=?,alertas_ativos=? WHERE id=1')->execute([$host,$porta,$seg,input('smtp_usuario'),$senha !== '' ? criptografar($senha) : null,$email,input('smtp_remetente_nome'),$destino,isset($_POST['alertas_ativos']) ? 1 : 0]);
            flash('success','Configurações salvas.');
        }
    }
    redirect('/email.php');
}
$pageTitle = 'E-mail e alertas de desconexão'; require __DIR__ . '/../includes/layout_header.php';
?>
<form method="post" class="secao" style="max-width:850px"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><div class="row g-3">
<?php foreach (['smtp_host'=>'Servidor SMTP','smtp_porta'=>'Porta SMTP','smtp_usuario'=>'Usuário SMTP','smtp_remetente_email'=>'E-mail remetente','smtp_remetente_nome'=>'Nome remetente','destino'=>'E-mail que recebe os avisos'] as $key=>$label): ?><div class="col-md-6"><label class="form-label"><?= e($label) ?></label><input class="form-control" name="<?= e($key) ?>" value="<?= e((string) $cfg[$key]) ?>" maxlength="254" <?= in_array($key,['smtp_remetente_email','destino'],true) ? 'type="email"' : '' ?> required></div><?php endforeach; ?>
<div class="col-md-6"><label class="form-label">Senha SMTP (em branco mantém)</label><input type="password" name="smtp_senha" class="form-control" autocomplete="new-password"></div><div class="col-md-6"><label class="form-label">Segurança</label><select name="smtp_seguranca" class="form-select"><option value="tls" <?= $cfg['smtp_seguranca']==='tls'?'selected':'' ?>>STARTTLS (587)</option><option value="ssl" <?= $cfg['smtp_seguranca']==='ssl'?'selected':'' ?>>TLS implícito (465)</option></select></div></div><label class="my-3"><input type="checkbox" name="alertas_ativos" <?= $cfg['alertas_ativos'] ? 'checked' : '' ?>> Avisar quando a sessão perder a conexão</label><p class="text-muted">Um aviso por queda após a primeira conexão. Falhas no SMTP são tentadas novamente a cada 5 minutos.</p><button class="btn btn-brand">Salvar</button></form>
<form method="post" class="mb-4"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="btn btn-outline-secondary" name="acao" value="testar">Enviar e-mail de teste com as configurações salvas</button></form>
<?php $monitor=$pdo->query('SELECT * FROM whatsapp_monitor WHERE id=1')->fetch(); ?><p>Monitor: <?= e($monitor['estado']) ?> · Última verificação: <?= e($monitor['verificado_em']) ?></p>
<table class="table"><tr><th>Queda detectada</th><th>Enviado</th><th>Tentativas</th><th>Erro SMTP</th></tr><?php foreach ($pdo->query('SELECT * FROM email_alertas ORDER BY id DESC LIMIT 30') as $a): ?><tr><td><?= e($a['criado_em']) ?></td><td><?= e($a['enviado_em'] ?? 'Pendente') ?></td><td><?= (int) $a['tentativas'] ?></td><td><?= e($a['erro']) ?></td></tr><?php endforeach; ?></table>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
