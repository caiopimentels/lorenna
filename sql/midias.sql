CREATE TABLE IF NOT EXISTS whatsapp_midias (
 mensagem_id INT PRIMARY KEY,
 evento LONGTEXT NOT NULL,
 caminho VARCHAR(100) NULL,
 mime VARCHAR(100) NULL,
 sha256 CHAR(64) NULL,
 transcricao LONGTEXT NULL,
 tentativas INT NOT NULL DEFAULT 0,
 tentado_em DATETIME NULL,
 erro VARCHAR(255) NULL,
 CONSTRAINT fk_midia_mensagem FOREIGN KEY (mensagem_id) REFERENCES whatsapp_mensagens(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE OR REPLACE TRIGGER preservar_mensagens BEFORE DELETE ON whatsapp_mensagens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historico permanente: exclusao proibida';
CREATE OR REPLACE TRIGGER preservar_conversas BEFORE DELETE ON whatsapp_conversas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historico permanente: exclusao proibida';
CREATE OR REPLACE TRIGGER preservar_midias BEFORE DELETE ON whatsapp_midias FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Midias permanentes: exclusao proibida';
CREATE OR REPLACE TRIGGER preservar_texto BEFORE UPDATE ON whatsapp_mensagens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mensagem original imutavel';
