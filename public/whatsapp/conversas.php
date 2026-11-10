<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirPermissao('atender_whatsapp', 'Seu perfil não pode atender o WhatsApp.');

// Visualização das conversas, sem envio de mensagens.
$pdo = getDB();
$cfg = whatsappConfig($pdo);
$id = (int) ($_GET['id'] ?? 0);
$busca = trim((string) ($_GET['busca'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit('Somente leitura.'); }

// Lista de conversas.
$sql = "SELECT c.*, NULL AS aluno_nome,
               (SELECT texto FROM whatsapp_mensagens m WHERE m.conversa_id = c.id AND m.autor <> 'sistema' ORDER BY m.id DESC LIMIT 1) AS ultima
        FROM whatsapp_conversas c WHERE 1 = 1";
$params = [];
if ($busca !== '') {
    $sql .= ' AND (c.nome LIKE ? OR c.numero LIKE ?)';
    array_push($params, '%' . $busca . '%', '%' . (preg_replace('/\D/', '', $busca) ?: $busca) . '%');
}
$stmt = $pdo->prepare($sql . ' ORDER BY c.ultima_mensagem_em DESC LIMIT 200');
$stmt->execute($params);
$conversas = $stmt->fetchAll();

$conversa = null;
$mensagens = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT c.*, NULL AS aluno_nome FROM whatsapp_conversas c WHERE c.id = ?');
    $stmt->execute([$id]);
    $conversa = $stmt->fetch() ?: null;
    if ($conversa) {
        $pdo->prepare('UPDATE whatsapp_conversas SET nao_lidas = 0 WHERE id = ?')->execute([$id]);
        $stmt = $pdo->prepare('SELECT * FROM (SELECT * FROM whatsapp_mensagens WHERE conversa_id = ? AND id < ? ORDER BY id DESC LIMIT 300) x ORDER BY id');
        $stmt->execute([$id, max(1, (int) ($_GET['antes'] ?? PHP_INT_MAX))]);
        $mensagens = $stmt->fetchAll();
        $midias = $pdo->prepare('SELECT a.* FROM whatsapp_midias a JOIN whatsapp_mensagens m ON m.id=a.mensagem_id WHERE m.conversa_id=?');
        $midias->execute([$id]);
        $arquivos = array_column($midias->fetchAll(), null, 'mensagem_id');
    }
}
$nomeConversa = fn (array $c) => $c['aluno_nome'] ?: ($c['nome'] ?: numeroWhatsappFormatado($c['numero']));

ob_start();
?>
<div id="wa-lista">
    <?php if (!$conversas): ?><div class="text-muted small p-3">Nenhuma conversa.</div><?php endif; ?>
    <?php foreach ($conversas as $c): ?>
        <a href="?id=<?= (int) $c['id'] ?>&busca=<?= e(urlencode($busca)) ?>" class="wa-item <?= (int) $c['id'] === $id ? 'ativo' : '' ?>">
            <div class="d-flex justify-content-between gap-2">
                <strong class="text-truncate"><?= e($nomeConversa($c)) ?></strong>
                <span class="small text-muted text-nowrap"><?= $c['ultima_mensagem_em'] ? (date('Y-m-d', strtotime($c['ultima_mensagem_em'])) === date('Y-m-d') ? date('H:i', strtotime($c['ultima_mensagem_em'])) : formatDate($c['ultima_mensagem_em'])) : '' ?></span>
            </div>
            <div class="d-flex justify-content-between gap-2 align-items-center">
                <span class="small text-muted text-truncate"><?= e(mb_strimwidth((string) $c['ultima'], 0, 60, '…')) ?></span>
                <span class="text-nowrap">
                    <?php if ($c['nao_lidas'] && (int) $c['id'] !== $id): ?><span class="badge rounded-pill text-bg-success"><?= (int) $c['nao_lidas'] ?></span><?php endif; ?>
                </span>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php
$htmlLista = ob_get_clean();

ob_start();
?>
<div id="wa-mensagens" data-ultimo="<?= $mensagens ? (int) end($mensagens)['id'] : 0 ?>">
    <?php if (isset($_GET['antes'])): ?><a class="btn btn-sm btn-light" href="?id=<?= $id ?>">Voltar às mensagens recentes</a><?php endif; ?>
    <?php if (count($mensagens) === 300): ?><a class="btn btn-sm btn-light" href="?id=<?= $id ?>&antes=<?= (int) $mensagens[0]['id'] ?>">Ver mensagens anteriores</a><?php endif; ?>
    <?php foreach ($mensagens as $m): ?>
        <?php if ($m['autor'] === 'sistema'): ?>
            <div class="text-center small text-muted my-2"><i class="bi bi-info-circle"></i> <?= e((string) $m['texto']) ?> · <?= formatDateTime($m['criado_em']) ?></div>
        <?php else: $nosso = $m['autor'] !== 'cliente'; ?>
            <div class="d-flex <?= $nosso ? 'justify-content-end' : '' ?> mb-2">
                <div class="wa-balao <?= $nosso ? 'nosso' : '' ?>">
                    <div class="small text-muted mb-1">
                        <?= match ($m['autor']) { 'cliente' => e($conversa['nome'] ?: 'Contato'), default => 'Conta conectada' } ?>
                        · <?= formatDateTime($m['criado_em']) ?>
                    </div>
                    <div style="white-space: pre-line;"><?= e((string) $m['texto']) ?></div>
                    <?php if (in_array($m['tipo'], ['audio', 'imagem'], true)): $a = $arquivos[$m['id']] ?? null; ?>
                        <?php if ($a && $a['caminho']): ?>
                            <?php if ($m['tipo'] === 'imagem'): ?>
                                <a href="/whatsapp/midia.php?id=<?= (int) $m['id'] ?>" target="_blank" rel="noopener"><img loading="lazy" style="max-width:100%;max-height:320px" src="/whatsapp/midia.php?id=<?= (int) $m['id'] ?>" alt="Imagem da conversa"></a>
                            <?php else: ?>
                                <audio controls preload="none" style="max-width:100%" src="/whatsapp/midia.php?id=<?= (int) $m['id'] ?>"></audio>
                            <?php endif; ?>
                            <div><a href="/whatsapp/midia.php?id=<?= (int) $m['id'] ?>&baixar=1">Baixar arquivo preservado</a></div>
                        <?php else: ?><div class="small text-muted">Arquivo aguardando download.</div><?php endif; ?>
                        <?php if ($m['tipo'] === 'audio'): ?>
                            <div class="small mt-2"><strong>Transcrição por IA</strong><div style="white-space:pre-line"><?= $a && $a['transcricao'] !== null ? e($a['transcricao'] ?: '[Nenhuma fala identificada]') : 'Aguardando transcrição.' ?></div></div>
                        <?php endif; ?>
                        <?php if ($a && $a['erro']): ?><div class="small text-warning"><?= e($a['erro']) ?></div><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($m['erro']): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Não enviada: <?= e($m['erro']) ?></div><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php
$htmlMensagens = ob_get_clean();

if (isset($_GET['parcial'])) {
    responderJson(['lista' => $htmlLista, 'mensagens' => $htmlMensagens]);
}

$pageTitle = 'WhatsApp';
$pageBreadcrumb = 'Lorenna';
$activeMenu = 'whatsapp';
$pageAction = usuarioTemPermissao('configurar_whatsapp') ? '<a href="/whatsapp/configuracao.php" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> Configurar</a>' : '';
require __DIR__ . '/../../includes/layout_header.php';
?>
<style>
    .wa-tela { display: grid; grid-template-columns: 340px 1fr; gap: 16px; }
    @media (max-width: 900px) { .wa-tela { grid-template-columns: 1fr; } }
    .wa-coluna { background: var(--bs-body-bg, #fff); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 10px; }
    #wa-lista { max-height: 65vh; overflow-y: auto; }
    .wa-item { display: block; padding: 10px 12px; border-bottom: 1px solid var(--bs-border-color, #eee); color: inherit; text-decoration: none; }
    .wa-item:hover, .wa-item.ativo { background: rgba(0, 0, 0, .04); }
    #wa-mensagens { height: 55vh; overflow-y: auto; padding: 12px; background: rgba(0, 0, 0, .015); }
    .wa-balao { max-width: 78%; padding: 8px 12px; border-radius: 10px; background: #fff; border: 1px solid #e5e7eb; }
    .wa-balao.nosso { background: #e8f5e9; }
</style>

<?php if (!whatsappConfigurado($cfg)): ?>
    <div class="alert alert-warning">O WhatsApp ainda não está configurado. <?= usuarioTemPermissao('configurar_whatsapp') ? '<a href="/whatsapp/configuracao.php">Configurar agora</a>.' : 'Peça a um administrador para configurar.' ?></div>
<?php endif; ?>

<div class="wa-tela">
    <div class="wa-coluna">
        <form method="get" class="p-2 border-bottom d-flex gap-2">
            <input type="search" name="busca" class="form-control form-control-sm" placeholder="Nome ou número" value="<?= e($busca) ?>" onsearch="this.form.submit()">
        </form>
        <div id="wa-lista-caixa"><?= $htmlLista ?></div>
    </div>

    <div class="wa-coluna">
        <?php if (!$conversa): ?>
            <div class="text-muted text-center p-5"><i class="bi bi-whatsapp fs-1 d-block mb-2"></i>Escolha uma conversa.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom">
                <div class="flex-grow-1 lh-sm">
                    <div class="fw-semibold"><?= e($nomeConversa($conversa)) ?></div>
                    <div class="small text-muted"><?= e(numeroWhatsappFormatado($conversa['numero'])) ?>
                        </div>
                </div>
            </div>
            <div id="wa-mensagens-caixa"><?= $htmlMensagens ?></div>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const rolarFim = () => { const m = document.getElementById('wa-mensagens'); if (m) m.scrollTop = m.scrollHeight; };
    rolarFim();
    // Atualiza a lista e a conversa a cada 6 s (sem recarregar a página).
    setInterval(async () => {
        try {
            const r = await (await fetch(location.pathname + location.search + (location.search ? '&' : '?') + 'parcial=1')).json();
            document.getElementById('wa-lista-caixa').innerHTML = r.lista;
            const caixa = document.getElementById('wa-mensagens-caixa');
            const atual = document.getElementById('wa-mensagens');
            if (caixa && atual) {
                const novo = new DOMParser().parseFromString(r.mensagens, 'text/html').getElementById('wa-mensagens');
                if (novo && novo.innerHTML !== atual.innerHTML) {
                    const noFim = atual.scrollHeight - atual.scrollTop - atual.clientHeight < 40;
                    caixa.innerHTML = r.mensagens;
                    if (noFim) rolarFim();
                }
            }
        } catch (e) { /* tenta de novo no próximo ciclo */ }
    }, 6000);
})();
</script>
<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
