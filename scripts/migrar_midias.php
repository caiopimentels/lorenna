<?php
// Migração aditiva: não remove mensagens nem arquivos existentes.
require_once __DIR__ . '/../includes/core.php';
$pdo = getDB();
$regra = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_whatsapp_mensagem_conversa'")->fetchColumn();
if ($regra === 'CASCADE') {
    $pdo->exec('ALTER TABLE whatsapp_mensagens DROP FOREIGN KEY fk_whatsapp_mensagem_conversa, ADD CONSTRAINT fk_whatsapp_mensagem_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id) ON DELETE RESTRICT');
}
$pdo->exec(file_get_contents(__DIR__ . '/../sql/midias.sql'));

// Tenta recuperar também mídias anteriores que ainda existam na Evolution.
$pdo->exec("INSERT IGNORE INTO whatsapp_midias (mensagem_id, evento)
    SELECT m.id, JSON_OBJECT('key', JSON_OBJECT('id', m.id_externo,
        'remoteJid', CONCAT(c.numero, '@s.whatsapp.net'), 'fromMe', IF(m.autor='atendente', TRUE, FALSE)))
    FROM whatsapp_mensagens m JOIN whatsapp_conversas c ON c.id=m.conversa_id
    LEFT JOIN whatsapp_midias a ON a.mensagem_id=m.id
    WHERE a.mensagem_id IS NULL AND m.tipo IN ('audio','imagem') AND m.id_externo IS NOT NULL");
