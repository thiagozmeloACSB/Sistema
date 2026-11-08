<?php
if (!defined('PRO_ROOT')) { http_response_code(403); exit; }
// ═══════════════════════════════════════════════════════════
// Prospecção Opa — esquema do banco (criado sozinho no 1º acesso; sem dependências)
// Todas as tabelas têm o prefixo pro_ para conviver com outras tabelas do mesmo banco.
// ═══════════════════════════════════════════════════════════
if (!defined('PRO_SCHEMA_V')) define('PRO_SCHEMA_V', '3');

function proSchema(PDO $db): void {
    $tabelas = [
    "CREATE TABLE IF NOT EXISTS pro_config (
        chave VARCHAR(80) NOT NULL PRIMARY KEY,
        valor MEDIUMTEXT NULL,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Uma campanha = um produto/serviço vendido para um ramo de negócio.
    "CREATE TABLE IF NOT EXISTS pro_campanhas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(120) NOT NULL,
        ativa TINYINT(1) NOT NULL DEFAULT 1,
        produto VARCHAR(190) NOT NULL,
        ramo VARCHAR(120) NOT NULL,
        persona VARCHAR(80) NULL,
        pitch TEXT NULL,
        dor_comum TEXT NULL,
        oferta VARCHAR(255) NULL,
        link VARCHAR(255) NULL,
        tom VARCHAR(255) NULL,
        contexto TEXT NULL,
        busca_sugestoes TEXT NULL,
        segmentos TEXT NULL,
        limite_dia INT NOT NULL DEFAULT 25,
        ia_objetivo VARCHAR(10) NOT NULL DEFAULT 'link',
        ia_precos TEXT NULL,
        ia_proibido TEXT NULL,
        ia_extra TEXT NULL,
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS pro_prospects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campanha_id INT NOT NULL,
        nome VARCHAR(190) NOT NULL,
        segmento VARCHAR(80) NULL,
        cidade VARCHAR(90) NULL, bairro VARCHAR(90) NULL, uf VARCHAR(4) NULL,
        endereco VARCHAR(255) NULL,
        telefone VARCHAR(20) NULL, instagram VARCHAR(120) NULL, site VARCHAR(255) NULL,
        nota DECIMAL(2,1) NULL, avaliacoes INT NULL,
        status VARCHAR(15) NOT NULL DEFAULT 'novo',
        mensagem TEXT NULL, obs TEXT NULL, origem VARCHAR(30) NULL,
        busca VARCHAR(160) NULL,
        google_place_id VARCHAR(120) NULL, maps_url VARCHAR(255) NULL,
        criado_em DATETIME NOT NULL, atualizado_em DATETIME NULL, contatado_em DATETIME NULL,
        ia_ativa TINYINT(1) NOT NULL DEFAULT 0,
        ia_pausada TINYINT(1) NOT NULL DEFAULT 0,
        ia_ultima_em DATETIME NULL,
        ia_aviso_em DATETIME NULL,
        ia_nao_lidas INT NOT NULL DEFAULT 0,
        KEY idx_pp_camp (campanha_id, status),
        KEY idx_pp_tel (telefone),
        KEY idx_pp_cidade (cidade),
        KEY idx_pp_gpid (google_place_id),
        KEY idx_pp_busca (busca),
        KEY idx_pp_contatado (contatado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS pro_vd_mensagens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        prospect_id INT NOT NULL,
        autor VARCHAR(8) NOT NULL,
        texto TEXT NOT NULL,
        wamid VARCHAR(160) NULL,
        criado_em DATETIME NOT NULL,
        KEY idx_vd_p (prospect_id, id),
        KEY idx_vd_w (wamid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS pro_wa_fila (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wamid VARCHAR(160) NOT NULL,
        phone_number_id VARCHAR(40) NOT NULL DEFAULT '',
        from_e164 VARCHAR(30) NOT NULL,
        tipo VARCHAR(30) NOT NULL DEFAULT 'unknown',
        payload MEDIUMTEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pendente',
        tentativas INT NOT NULL DEFAULT 0,
        erro VARCHAR(255) NULL,
        recebido_em DATETIME NOT NULL,
        processado_em DATETIME NULL,
        UNIQUE KEY uq_fila_wamid (wamid),
        KEY idx_fila_status (status, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Lista permanente: quem pediu para sair nunca mais é contatado (mesmo se o contato for apagado).
    "CREATE TABLE IF NOT EXISTS pro_bloqueados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telefone VARCHAR(20) NOT NULL,
        tipo VARCHAR(15) NOT NULL DEFAULT 'nao_contatar',
        nome VARCHAR(190) NULL,
        motivo VARCHAR(190) NULL,
        criado_em DATETIME NOT NULL,
        UNIQUE KEY uq_bloq_tel (telefone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // v3: consumo da API do Claude (uma linha por chamada) — base para repassar o custo das mensagens
    "CREATE TABLE IF NOT EXISTS pro_ia_uso (
        id INT AUTO_INCREMENT PRIMARY KEY,
        criado_em DATETIME NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'outro',
        prospect_id INT NULL,
        campanha_id INT NULL,
        modelo VARCHAR(60) NULL,
        tokens_in INT NOT NULL DEFAULT 0,
        tokens_out INT NOT NULL DEFAULT 0,
        cache_leitura INT NOT NULL DEFAULT 0,
        cache_escrita INT NOT NULL DEFAULT 0,
        KEY idx_uso_data (criado_em),
        KEY idx_uso_pros (prospect_id),
        KEY idx_uso_camp (campanha_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tabelas as $sql) $db->exec($sql);
    // v2: Instagram Direct, pesquisa do contato e canal da mensagem (idempotente: coluna que já existe só dá erro ignorado)
    foreach ([
        "ALTER TABLE pro_prospects ADD COLUMN ia_canal VARCHAR(10) NOT NULL DEFAULT 'whatsapp'",
        "ALTER TABLE pro_prospects ADD COLUMN ig_id VARCHAR(40) NULL",
        "ALTER TABLE pro_prospects ADD COLUMN ig_cliente_em DATETIME NULL",
        "ALTER TABLE pro_prospects ADD KEY idx_pp_ig (ig_id)",
        "ALTER TABLE pro_prospects ADD COLUMN pesquisa MEDIUMTEXT NULL",
        "ALTER TABLE pro_prospects ADD COLUMN pesquisa_em DATETIME NULL",
        "ALTER TABLE pro_vd_mensagens ADD COLUMN canal VARCHAR(10) NULL",
    ] as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }
    $db->prepare("INSERT INTO pro_config (chave, valor, atualizado_em) VALUES ('schema_v', ?, ?)
                  ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = VALUES(atualizado_em)")
       ->execute([PRO_SCHEMA_V, date('Y-m-d H:i:s')]);
}
