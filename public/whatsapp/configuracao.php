<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('configurar_whatsapp');
$pdo = getDB(); $cfg = whatsappConfig($pdo); $qrcode = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (input('acao') === 'conectar') {
        $r = conectarWhatsapp($cfg); flash($r['ok'] ? 'success' : 'danger', $r['mensagem']);
        $qrcode = $r['qrcode'];
    } else {
        $url = rtrim(input('api_url'), '/'); $base = rtrim(input('webhook_base'), '/');
        $instancia = input('instancia');
        if (!filter_var($url, FILTER_VALIDATE_URL) || ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $instancia)) flash('danger', 'Confira os endereços e o nome da instância.');
        else {
            $key = input('api_chave');
            $pdo->prepare('UPDATE whatsapp_config SET api_url=?,api_chave=COALESCE(?,api_chave),instancia=?,webhook_base=? WHERE id=1')->execute([$url,$key !== '' ? criptografar($key) : null,$instancia,$base ?: null]);
            flash('success', 'Configuração salva.'); redirect('/whatsapp/configuracao.php');
        }
    }
}
$estado = estadoConexaoWhatsapp($cfg);
$rotulos = ['open'=>'Conectado','close'=>'Desconectado','connecting'=>'Aguardando conexão','sem_config'=>'Não configurado','sem_instancia'=>'Instância ainda não criada','erro'=>'Erro ao consultar'];
$pageTitle = 'Conexão WhatsApp'; require __DIR__ . '/../../includes/layout_header.php';
?>
<div class="secao" style="max-width:850px"><p>Estado: <strong><?= e($rotulos[$estado['estado']] ?? $estado['estado']) ?></strong></p><?php if ($estado['erro']): ?><p class="text-danger"><?= e($estado['erro']) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
<?php foreach (['api_url'=>'Endereço da Evolution API','instancia'=>'Nome da instância','webhook_base'=>'Endereço do painel visto pela Evolution'] as $campo=>$rotulo): ?><label class="form-label"><?= e($rotulo) ?></label><input class="form-control mb-3" name="<?= e($campo) ?>" value="<?= e($cfg[$campo]) ?>"><?php endforeach; ?>
<label class="form-label">Chave da API (em branco mantém)</label><input type="password" name="api_chave" class="form-control mb-3" autocomplete="new-password"><button class="btn btn-brand">Salvar configuração</button></form>
<form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button name="acao" value="conectar" class="btn btn-outline-secondary">Conectar / mostrar QR Code</button></form>
<?php if (is_string($qrcode) && str_starts_with($qrcode, 'data:image/png;base64,')): ?><img class="mt-3" src="<?= e($qrcode) ?>" alt="QR Code para conectar a conta" style="max-width:300px;width:100%"><?php endif; ?>
<p class="text-muted mt-3">A conexão é feita em WhatsApp → Aparelhos conectados. O painel permite apenas visualizar o histórico registrado.</p></div>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
