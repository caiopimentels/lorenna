-- Registro de conversas e configuração de conexão.
CREATE TABLE whatsapp_config (
 id INT PRIMARY KEY DEFAULT 1,
 api_url VARCHAR(255) DEFAULT NULL,
 api_chave TEXT DEFAULT NULL,
 instancia VARCHAR(60) DEFAULT NULL,
 webhook_token CHAR(40) DEFAULT NULL,
 webhook_base VARCHAR(255) DEFAULT NULL,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO whatsapp_config (id) VALUES (1);

CREATE TABLE whatsapp_conversas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL UNIQUE,
    nome VARCHAR(150) DEFAULT NULL,
    nao_lidas INT NOT NULL DEFAULT 0,
    ultima_mensagem_em DATETIME DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_conversas_ultima (ultima_mensagem_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- autor: mensagem recebida, enviada pela conta conectada ou registro do sistema.
-- id_externo: id da mensagem no WhatsApp (não grava a mesma duas vezes).
CREATE TABLE whatsapp_mensagens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversa_id INT NOT NULL,
    autor ENUM('cliente', 'atendente', 'sistema') NOT NULL,
    usuario_id INT DEFAULT NULL,
    autor_nome VARCHAR(150) DEFAULT NULL,
    texto TEXT DEFAULT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'texto',
    id_externo VARCHAR(100) DEFAULT NULL UNIQUE,
    erro VARCHAR(255) DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_mensagens (conversa_id, id),
    CONSTRAINT fk_whatsapp_mensagem_conversa FOREIGN KEY (conversa_id) REFERENCES whatsapp_conversas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
 id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
 senha VARCHAR(255) NOT NULL, ativo TINYINT NOT NULL DEFAULT 1, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE email_config (
 id INT PRIMARY KEY, smtp_host VARCHAR(254) NOT NULL DEFAULT '', smtp_porta INT NOT NULL DEFAULT 587,
 smtp_seguranca VARCHAR(10) NOT NULL DEFAULT 'tls', smtp_usuario VARCHAR(254) NOT NULL DEFAULT '',
 smtp_senha TEXT, smtp_remetente_email VARCHAR(254) NOT NULL DEFAULT '', smtp_remetente_nome VARCHAR(254) NOT NULL DEFAULT 'Lorenna',
 destino VARCHAR(254) NOT NULL DEFAULT '', alertas_ativos TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO email_config (id) VALUES (1);
CREATE TABLE whatsapp_monitor (
 id INT PRIMARY KEY, estado VARCHAR(30) NOT NULL DEFAULT 'sem_config', conectado_antes TINYINT NOT NULL DEFAULT 0,
 desconectado_em DATETIME NULL, verificado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO whatsapp_monitor (id) VALUES (1);
CREATE TABLE email_alertas (
 id INT AUTO_INCREMENT PRIMARY KEY, estado VARCHAR(30) NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, enviado_em DATETIME NULL, tentado_em DATETIME NULL,
 tentativas INT NOT NULL DEFAULT 0, erro VARCHAR(500) NULL, KEY pendentes (enviado_em, tentado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE login_tentativas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, ip CHAR(64) NOT NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY tentativas (ip, criado_em), KEY limpeza (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
