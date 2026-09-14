-- =============================================================================
-- CREA Pro-Link | Estrutura do banco de dados
-- MariaDB 10.11+ | utf8mb4 / utf8mb4_unicode_ci
--
-- Convencoes (item 8.6 do Termo de Referencia):
--   - Tabelas: <prefixo_modulo>_<entidade>, minusculas, separadas por _
--       sis_ = modulo de sistema | pro_ = modulo Pro-Link
--   - Campos: prefixo de tres letras da tabela + nome do atributo
--   - PK: <prefixo>_id  | constraints nomeadas: pk_ / fk_ / uk_ / idx_
--   - Controle: xxx_dt_registro, xxx_log, xxx_status CHAR(1) DEFAULT 'A'
--   - Exclusao logica: xxx_status = 'X' (registro nunca sai fisicamente da base)
--
-- Este script e idempotente (CREATE ... IF NOT EXISTS): pode ser reexecutado
-- sem destruir dados. Para recriar o ambiente do zero, remova o volume do
-- container MariaDB conforme instrucoes do _arq/README.md.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '-04:00';

CREATE DATABASE IF NOT EXISTS `crea_prolink`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `crea_prolink`;

-- =============================================================================
-- MODULO SIS - Sistema, usuarios, seguranca, auditoria e LGPD
-- =============================================================================

-- -----------------------------------------------------------------------------
-- sis_usuarios : identidade e autenticacao de todos os perfis
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_usuarios` (
  `usu_id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usu_nome`             VARCHAR(150)  NOT NULL,
  `usu_email`            VARCHAR(190)  NOT NULL,
  `usu_senha`            VARCHAR(255)  NOT NULL COMMENT 'password_hash() - PASSWORD_DEFAULT',
  `usu_tipo_pessoa`      CHAR(2)       NOT NULL DEFAULT 'PF' COMMENT 'PF | PJ',
  `usu_perfil`           VARCHAR(20)   NOT NULL COMMENT 'ADMIN | PROFISSIONAL | EMPRESA | TERCEIRO',
  `usu_cpf`              VARCHAR(11)   NULL,
  `usu_cnpj`             VARCHAR(14)   NULL,
  `usu_rnp`              VARCHAR(20)   NULL COMMENT 'Registro Nacional do Profissional (Confea/Crea)',
  `usu_registrado_crea`  CHAR(1)       NOT NULL DEFAULT 'N' COMMENT 'S = validado na API oficial do CREA-AM',
  `usu_dt_validacao`     DATETIME      NULL COMMENT 'Momento da validacao junto a API oficial',
  `usu_telefone`         VARCHAR(20)   NULL,
  `usu_uf`               CHAR(2)       NULL,
  `usu_cidade`           VARCHAR(120)  NULL,
  `usu_email_verificado` CHAR(1)       NOT NULL DEFAULT 'N',
  `usu_falhas_login`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `usu_bloqueado_ate`    DATETIME      NULL COMMENT 'Bloqueio temporario por tentativas sucessivas',
  `usu_dt_ultimo_acesso` DATETIME      NULL,
  `usu_motivo_bloqueio`  VARCHAR(255)  NULL COMMENT 'Bloqueio administrativo (usu_status = B)',
  `usu_dt_registro`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `usu_dt_alteracao`     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  `usu_log`              VARCHAR(255)  NULL COMMENT 'Ultima acao registrada sobre o registro',
  `usu_status`           CHAR(1)       NOT NULL DEFAULT 'A' COMMENT 'A=Ativo P=Pendente B=Bloqueado X=Excluido',
  CONSTRAINT `pk_usu_id` PRIMARY KEY (`usu_id`),
  CONSTRAINT `uk_usu_email` UNIQUE KEY (`usu_email`),
  KEY `idx_usu_cpf`     (`usu_cpf`),
  KEY `idx_usu_cnpj`    (`usu_cnpj`),
  KEY `idx_usu_rnp`     (`usu_rnp`),
  KEY `idx_usu_perfil`  (`usu_perfil`, `usu_status`),
  KEY `idx_usu_status`  (`usu_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuarios da plataforma - todos os perfis (RF01)';

-- -----------------------------------------------------------------------------
-- sis_tokens : recuperacao de senha e verificacao de e-mail (uso unico)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_tokens` (
  `tok_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tok_usu_id`      INT UNSIGNED NOT NULL,
  `tok_tipo`        VARCHAR(30)  NOT NULL COMMENT 'RECUPERACAO_SENHA | VERIFICACAO_EMAIL',
  `tok_hash`        CHAR(64)     NOT NULL COMMENT 'hash do token enviado ao usuario',
  `tok_dt_expira`   DATETIME     NOT NULL,
  `tok_dt_uso`      DATETIME     NULL,
  `tok_ip_origem`   VARCHAR(45)  NULL,
  `tok_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tok_log`         VARCHAR(255) NULL,
  `tok_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_tok_id` PRIMARY KEY (`tok_id`),
  CONSTRAINT `uk_tok_hash` UNIQUE KEY (`tok_hash`),
  CONSTRAINT `fk_tok_usu_id` FOREIGN KEY (`tok_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_tok_busca` (`tok_tipo`, `tok_status`, `tok_dt_expira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tokens de uso unico para recuperacao de acesso (RF01)';

-- -----------------------------------------------------------------------------
-- sis_termos : versionamento dos Termos de Uso e Politica de Privacidade
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_termos` (
  `ter_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ter_tipo`        VARCHAR(30)  NOT NULL COMMENT 'TERMOS_USO | POLITICA_PRIVACIDADE',
  `ter_versao`      VARCHAR(20)  NOT NULL,
  `ter_titulo`      VARCHAR(150) NOT NULL,
  `ter_conteudo`    MEDIUMTEXT   NOT NULL,
  `ter_dt_vigencia` DATE         NOT NULL,
  `ter_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ter_log`         VARCHAR(255) NULL,
  `ter_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_ter_id` PRIMARY KEY (`ter_id`),
  CONSTRAINT `uk_ter_tipo_versao` UNIQUE KEY (`ter_tipo`, `ter_versao`),
  KEY `idx_ter_vigencia` (`ter_tipo`, `ter_status`, `ter_dt_vigencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Versoes dos termos apresentados ao usuario (LGPD)';

-- -----------------------------------------------------------------------------
-- sis_consentimentos : trilha de aceite e revogacao (LGPD art. 8 e 18)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_consentimentos` (
  `cns_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cns_usu_id`       INT UNSIGNED NOT NULL,
  `cns_ter_id`       INT UNSIGNED NULL COMMENT 'Versao do termo aceito, quando aplicavel',
  `cns_finalidade`   VARCHAR(50)  NOT NULL COMMENT 'TERMOS_USO | POLITICA_PRIVACIDADE | DADOS_CREA | COMUNICACOES | PERFIL_PUBLICO',
  `cns_concedido`    CHAR(1)      NOT NULL DEFAULT 'S' COMMENT 'S = concedido | N = revogado',
  `cns_dt_evento`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cns_ip_origem`    VARCHAR(45)  NULL,
  `cns_user_agent`   VARCHAR(255) NULL,
  `cns_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cns_log`          VARCHAR(255) NULL,
  `cns_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_cns_id` PRIMARY KEY (`cns_id`),
  CONSTRAINT `fk_cns_usu_id` FOREIGN KEY (`cns_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cns_ter_id` FOREIGN KEY (`cns_ter_id`)
    REFERENCES `sis_termos` (`ter_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_cns_usu_finalidade` (`cns_usu_id`, `cns_finalidade`, `cns_dt_evento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historico de consentimentos - append only (LGPD)';

-- -----------------------------------------------------------------------------
-- sis_auditoria : trilha de auditoria da aplicacao (item 8.5.g)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_auditoria` (
  `aud_id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `aud_usu_id`       INT UNSIGNED NULL COMMENT 'NULL para acoes anonimas',
  `aud_acao`         VARCHAR(60)  NOT NULL COMMENT 'LOGIN | LOGIN_FALHA | CADASTRO | ATUALIZACAO | EXCLUSAO_LOGICA | RESTAURACAO | MODERACAO | CONSULTA_API | EXPORTACAO_DADOS',
  `aud_entidade`     VARCHAR(60)  NULL COMMENT 'Tabela ou agregado afetado',
  `aud_entidade_id`  VARCHAR(40)  NULL,
  `aud_descricao`    VARCHAR(500) NULL,
  `aud_dados_antes`  JSON         NULL,
  `aud_dados_depois` JSON         NULL,
  `aud_ip_origem`    VARCHAR(45)  NULL,
  `aud_user_agent`   VARCHAR(255) NULL,
  `aud_rota`         VARCHAR(190) NULL,
  `aud_severidade`   VARCHAR(10)  NOT NULL DEFAULT 'INFO' COMMENT 'INFO | ALERTA | CRITICO',
  `aud_dt_registro`  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `aud_log`          VARCHAR(255) NULL,
  `aud_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_aud_id` PRIMARY KEY (`aud_id`),
  CONSTRAINT `fk_aud_usu_id` FOREIGN KEY (`aud_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_aud_usu`      (`aud_usu_id`, `aud_dt_registro`),
  KEY `idx_aud_acao`     (`aud_acao`, `aud_dt_registro`),
  KEY `idx_aud_entidade` (`aud_entidade`, `aud_entidade_id`),
  KEY `idx_aud_data`     (`aud_dt_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Trilha de auditoria - append only (RF06)';

-- -----------------------------------------------------------------------------
-- sis_configuracoes : parametros gerenciaveis pelo painel (SMTP, matching, API)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_configuracoes` (
  `cfg_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cfg_grupo`        VARCHAR(40)  NOT NULL COMMENT 'SMTP | API_CREA | MATCHING | PLATAFORMA',
  `cfg_chave`        VARCHAR(60)  NOT NULL,
  `cfg_valor`        TEXT         NULL,
  `cfg_tipo`         VARCHAR(20)  NOT NULL DEFAULT 'TEXTO' COMMENT 'TEXTO | INTEIRO | BOOLEANO | SENHA',
  `cfg_descricao`    VARCHAR(255) NULL,
  `cfg_sensivel`     CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'S = valor mascarado na interface',
  `cfg_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cfg_dt_alteracao` DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `cfg_log`          VARCHAR(255) NULL,
  `cfg_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_cfg_id` PRIMARY KEY (`cfg_id`),
  CONSTRAINT `uk_cfg_grupo_chave` UNIQUE KEY (`cfg_grupo`, `cfg_chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuracoes de runtime editaveis pelo administrador (RF07)';

-- -----------------------------------------------------------------------------
-- sis_notificacoes : fila e historico de notificacoes (RF07)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_notificacoes` (
  `not_id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `not_usu_id`       INT UNSIGNED NULL,
  `not_canal`        VARCHAR(20)  NOT NULL DEFAULT 'EMAIL' COMMENT 'EMAIL | INTERNA',
  `not_evento`       VARCHAR(60)  NOT NULL COMMENT 'CADASTRO_CRIADO | RECUPERACAO_SENHA | DEMANDA_PUBLICADA | DEMANDA_ATUALIZADA | INTERESSE_RECEBIDO | INTERESSE_RESPONDIDO | MENSAGEM_RECEBIDA | MODERACAO | DENUNCIA_RECEBIDA',
  `not_destinatario` VARCHAR(190) NULL,
  `not_assunto`      VARCHAR(190) NOT NULL,
  `not_corpo`        MEDIUMTEXT   NOT NULL,
  `not_link`         VARCHAR(255) NULL,
  `not_lida`         CHAR(1)      NOT NULL DEFAULT 'N',
  `not_tentativas`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `not_erro`         VARCHAR(500) NULL,
  `not_dt_envio`     DATETIME     NULL,
  `not_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `not_log`          VARCHAR(255) NULL,
  `not_status`       CHAR(1)      NOT NULL DEFAULT 'A' COMMENT 'A=Pendente E=Enviada F=Falha X=Excluida',
  CONSTRAINT `pk_not_id` PRIMARY KEY (`not_id`),
  CONSTRAINT `fk_not_usu_id` FOREIGN KEY (`not_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_not_fila` (`not_status`, `not_canal`, `not_dt_registro`),
  KEY `idx_not_usu`  (`not_usu_id`, `not_lida`, `not_dt_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notificacoes por e-mail e internas (RF07)';

-- -----------------------------------------------------------------------------
-- sis_api_consultas : rastreabilidade das chamadas a API oficial do CREA-AM
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sis_api_consultas` (
  `apc_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `apc_usu_id`      INT UNSIGNED NULL,
  `apc_recurso`     VARCHAR(40)  NOT NULL COMMENT 'PROFISSIONAL | EMPRESA | ART | CAT',
  `apc_parametros`  VARCHAR(255) NULL COMMENT 'Identificadores mascarados',
  `apc_endpoint`    VARCHAR(255) NULL,
  `apc_http_status` SMALLINT UNSIGNED NULL,
  `apc_sucesso`     CHAR(1)      NOT NULL DEFAULT 'N',
  `apc_duracao_ms`  INT UNSIGNED NULL,
  `apc_mensagem`    VARCHAR(500) NULL,
  `apc_dt_registro` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `apc_log`         VARCHAR(255) NULL,
  `apc_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_apc_id` PRIMARY KEY (`apc_id`),
  CONSTRAINT `fk_apc_usu_id` FOREIGN KEY (`apc_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_apc_recurso` (`apc_recurso`, `apc_dt_registro`),
  KEY `idx_apc_sucesso` (`apc_sucesso`, `apc_dt_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log das integracoes com a API oficial do desafio (RF02)';

-- =============================================================================
-- MODULO PRO - Perfis, portfolio, demandas e relacionamento
-- =============================================================================

-- -----------------------------------------------------------------------------
-- pro_areas : areas de atuacao (engenharia, agronomia, geociencias)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_areas` (
  `are_id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `are_nome`        VARCHAR(120) NOT NULL,
  `are_descricao`   VARCHAR(255) NULL,
  `are_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `are_log`         VARCHAR(255) NULL,
  `are_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_are_id` PRIMARY KEY (`are_id`),
  CONSTRAINT `uk_are_nome` UNIQUE KEY (`are_nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Areas de atuacao do Sistema Confea/Crea';

-- -----------------------------------------------------------------------------
-- pro_competencias : catalogo controlado de competencias tecnicas
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_competencias` (
  `cmp_id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cmp_are_id`      SMALLINT UNSIGNED NOT NULL,
  `cmp_nome`        VARCHAR(150) NOT NULL,
  `cmp_descricao`   VARCHAR(255) NULL,
  `cmp_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cmp_log`         VARCHAR(255) NULL,
  `cmp_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_cmp_id` PRIMARY KEY (`cmp_id`),
  CONSTRAINT `uk_cmp_area_nome` UNIQUE KEY (`cmp_are_id`, `cmp_nome`),
  CONSTRAINT `fk_cmp_are_id` FOREIGN KEY (`cmp_are_id`)
    REFERENCES `pro_areas` (`are_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY `idx_cmp_nome` (`cmp_nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Competencias tecnicas selecionaveis (RF03/RF04)';

-- -----------------------------------------------------------------------------
-- pro_perfis : perfil publico do profissional ou da empresa
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_perfis` (
  `prf_id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prf_usu_id`           INT UNSIGNED NOT NULL,
  `prf_titulo`           VARCHAR(150) NULL COMMENT 'Ex.: Engenheiro Civil - Estruturas',
  `prf_resumo`           TEXT         NULL,
  `prf_are_id`           SMALLINT UNSIGNED NULL COMMENT 'Area principal',
  `prf_uf`               CHAR(2)      NULL,
  `prf_cidade`           VARCHAR(120) NULL,
  `prf_raio_atuacao_km`  SMALLINT UNSIGNED NULL,
  `prf_atende_remoto`    CHAR(1)      NOT NULL DEFAULT 'N',
  `prf_disponibilidade`  VARCHAR(20)  NOT NULL DEFAULT 'DISPONIVEL' COMMENT 'DISPONIVEL | PARCIAL | INDISPONIVEL',
  `prf_anos_experiencia` TINYINT UNSIGNED NULL,
  `prf_valor_hora`       DECIMAL(10,2) NULL,
  `prf_site`             VARCHAR(190) NULL,
  `prf_linkedin`         VARCHAR(190) NULL,
  `prf_foto`             VARCHAR(255) NULL,
  `prf_visibilidade`     VARCHAR(20)  NOT NULL DEFAULT 'PUBLICO' COMMENT 'PUBLICO | AUTENTICADO | OCULTO',
  `prf_exibe_contato`    CHAR(1)      NOT NULL DEFAULT 'N',
  `prf_exibe_documento`  CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'Exibir CPF/CNPJ mascarado',
  `prf_exibe_rnp`        CHAR(1)      NOT NULL DEFAULT 'S',
  `prf_exibe_valor_hora` CHAR(1)      NOT NULL DEFAULT 'N',
  `prf_aceita_contato`   CHAR(1)      NOT NULL DEFAULT 'S',
  `prf_moderacao`        VARCHAR(20)  NOT NULL DEFAULT 'APROVADO' COMMENT 'APROVADO | EM_ANALISE | REPROVADO',
  `prf_moderacao_motivo` VARCHAR(255) NULL,
  `prf_dt_registro`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `prf_dt_alteracao`     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `prf_log`              VARCHAR(255) NULL,
  `prf_status`           CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_prf_id` PRIMARY KEY (`prf_id`),
  CONSTRAINT `uk_prf_usu_id` UNIQUE KEY (`prf_usu_id`),
  CONSTRAINT `fk_prf_usu_id` FOREIGN KEY (`prf_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prf_are_id` FOREIGN KEY (`prf_are_id`)
    REFERENCES `pro_areas` (`are_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_prf_local`           (`prf_uf`, `prf_cidade`),
  KEY `idx_prf_visibilidade`    (`prf_visibilidade`, `prf_moderacao`, `prf_status`),
  KEY `idx_prf_disponibilidade` (`prf_disponibilidade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Perfil publico de profissionais e empresas (RF01/RF03)';

-- -----------------------------------------------------------------------------
-- pro_perfil_competencias : competencias declaradas pelo perfil
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_perfil_competencias` (
  `pcp_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pcp_prf_id`      INT UNSIGNED NOT NULL,
  `pcp_cmp_id`      SMALLINT UNSIGNED NOT NULL,
  `pcp_nivel`       VARCHAR(20)  NOT NULL DEFAULT 'INTERMEDIARIO' COMMENT 'BASICO | INTERMEDIARIO | AVANCADO | ESPECIALISTA',
  `pcp_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pcp_log`         VARCHAR(255) NULL,
  `pcp_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_pcp_id` PRIMARY KEY (`pcp_id`),
  CONSTRAINT `uk_pcp_prf_cmp` UNIQUE KEY (`pcp_prf_id`, `pcp_cmp_id`),
  CONSTRAINT `fk_pcp_prf_id` FOREIGN KEY (`pcp_prf_id`)
    REFERENCES `pro_perfis` (`prf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pcp_cmp_id` FOREIGN KEY (`pcp_cmp_id`)
    REFERENCES `pro_competencias` (`cmp_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY `idx_pcp_cmp` (`pcp_cmp_id`, `pcp_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vinculo perfil x competencia (RF03)';

-- -----------------------------------------------------------------------------
-- pro_arts : ARTs validadas na API oficial e associadas ao portfolio
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_arts` (
  `art_id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `art_prf_id`         INT UNSIGNED NOT NULL,
  `art_numero`         VARCHAR(40)  NOT NULL COMMENT 'Numero da ART conforme API oficial',
  `art_rnp`            VARCHAR(20)  NOT NULL,
  `art_tipo`           VARCHAR(60)  NULL,
  `art_objeto`         TEXT         NULL COMMENT 'Objeto/descricao retornado pela API',
  `art_contratante`    VARCHAR(190) NULL,
  `art_valor_contrato` DECIMAL(14,2) NULL,
  `art_municipio`      VARCHAR(120) NULL,
  `art_uf`             CHAR(2)      NULL,
  `art_dt_inicio`      DATE         NULL,
  `art_dt_fim`         DATE         NULL,
  `art_situacao`       VARCHAR(40)  NULL COMMENT 'Situacao informada pela API oficial',
  `art_origem`         VARCHAR(20)  NOT NULL DEFAULT 'API_CREA' COMMENT 'API_CREA e a unica origem valida para ART',
  `art_validada`       CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'S = confirmada pela API oficial',
  `art_dt_validacao`   DATETIME     NULL,
  `art_payload`        JSON         NULL COMMENT 'Resposta integral da API para rastreabilidade',
  `art_visivel`        CHAR(1)      NOT NULL DEFAULT 'S' COMMENT 'Autorizacao do titular para exibicao (LGPD)',
  `art_destaque`       CHAR(1)      NOT NULL DEFAULT 'N',
  `art_dt_registro`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `art_dt_alteracao`   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `art_log`            VARCHAR(255) NULL,
  `art_status`         CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_art_id` PRIMARY KEY (`art_id`),
  CONSTRAINT `uk_art_prf_numero` UNIQUE KEY (`art_prf_id`, `art_numero`),
  CONSTRAINT `fk_art_prf_id` FOREIGN KEY (`art_prf_id`)
    REFERENCES `pro_perfis` (`prf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_art_rnp`     (`art_rnp`),
  KEY `idx_art_visivel` (`art_visivel`, `art_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Anotacoes de Responsabilidade Tecnica do portfolio (RF02/RF03)';

-- -----------------------------------------------------------------------------
-- pro_cats : Certidoes de Acervo Tecnico consultadas na API oficial
-- -----------------------------------------------------------------------------
-- -----------------------------------------------------------------------------
-- Modalidades do profissional, conforme a base oficial do CREA-AM.
-- Vêm na consulta do registro e não são declaráveis pelo titular: são a
-- habilitação que o Conselho reconhece, e por isso valem como dado verificado.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_modalidades` (
  `mod_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mod_prf_id`        INT UNSIGNED NOT NULL,
  `mod_codigo`        VARCHAR(20)  NOT NULL COMMENT 'Sigla da modalidade na API oficial',
  `mod_nome`          VARCHAR(190) NOT NULL,
  `mod_origem`        VARCHAR(20)  NOT NULL DEFAULT 'API_CREA' COMMENT 'API_CREA e a unica origem valida',
  `mod_dt_validacao`  DATETIME     NULL,
  `mod_dt_registro`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `mod_dt_alteracao`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `mod_log`           VARCHAR(255) NULL,
  `mod_status`        CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_mod_id` PRIMARY KEY (`mod_id`),
  CONSTRAINT `uk_mod_prf_codigo` UNIQUE KEY (`mod_prf_id`, `mod_codigo`),
  CONSTRAINT `fk_mod_prf_id` FOREIGN KEY (`mod_prf_id`)
    REFERENCES `pro_perfis` (`prf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_mod_status` (`mod_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Modalidades habilitadas do profissional, obtidas da API oficial';

-- -----------------------------------------------------------------------------
-- Atividades de uma ART, na Tabela de Obras e Serviços (TOS) do Confea.
-- É o que o profissional efetivamente executou, com código oficial: uma
-- competência comprovada, em vez de declarada.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_art_atividades` (
  `ata_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ata_art_id`        INT UNSIGNED NOT NULL,
  `ata_tos_codigo`    VARCHAR(40)  NOT NULL COMMENT 'Codigo na Tabela de Obras e Servicos',
  `ata_descricao`     TEXT         NULL,
  `ata_grupo`         VARCHAR(190) NULL,
  `ata_subgrupo`      VARCHAR(190) NULL,
  `ata_obra_servico`  VARCHAR(190) NULL,
  `ata_complementar`  VARCHAR(190) NULL,
  `ata_atividade`     VARCHAR(190) NULL COMMENT 'Natureza da participacao (execucao, projeto...)',
  `ata_dt_registro`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ata_dt_alteracao`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `ata_log`           VARCHAR(255) NULL,
  `ata_status`        CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_ata_id` PRIMARY KEY (`ata_id`),
  CONSTRAINT `uk_ata_art_codigo` UNIQUE KEY (`ata_art_id`, `ata_tos_codigo`),
  CONSTRAINT `fk_ata_art_id` FOREIGN KEY (`ata_art_id`)
    REFERENCES `pro_arts` (`art_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_ata_tos`    (`ata_tos_codigo`),
  KEY `idx_ata_status` (`ata_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Atividades TOS de cada ART, obtidas da API oficial';

CREATE TABLE IF NOT EXISTS `pro_cats` (
  `cat_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cat_prf_id`       INT UNSIGNED NOT NULL,
  `cat_numero`       VARCHAR(40)  NOT NULL,
  `cat_rnp`          VARCHAR(20)  NOT NULL,
  `cat_tipo`         VARCHAR(60)  NULL,
  `cat_objeto`       TEXT         NULL,
  `cat_dt_emissao`   DATE         NULL,
  `cat_dt_validade`  DATE         NULL,
  `cat_situacao`     VARCHAR(40)  NULL,
  `cat_origem`       VARCHAR(20)  NOT NULL DEFAULT 'API_CREA',
  `cat_validada`     CHAR(1)      NOT NULL DEFAULT 'N',
  `cat_dt_validacao` DATETIME     NULL,
  `cat_payload`      JSON         NULL,
  `cat_visivel`      CHAR(1)      NOT NULL DEFAULT 'S',
  `cat_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cat_dt_alteracao` DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `cat_log`          VARCHAR(255) NULL,
  `cat_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_cat_id` PRIMARY KEY (`cat_id`),
  CONSTRAINT `uk_cat_prf_numero` UNIQUE KEY (`cat_prf_id`, `cat_numero`),
  CONSTRAINT `fk_cat_prf_id` FOREIGN KEY (`cat_prf_id`)
    REFERENCES `pro_perfis` (`prf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_cat_rnp` (`cat_rnp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Certidoes de Acervo Tecnico (RF02/RF03)';

-- -----------------------------------------------------------------------------
-- pro_experiencias : experiencia declarada, com ou sem vinculo a ART/CAT
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_experiencias` (
  `exp_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exp_prf_id`       INT UNSIGNED NOT NULL,
  `exp_titulo`       VARCHAR(190) NOT NULL,
  `exp_descricao`    TEXT         NULL,
  `exp_organizacao`  VARCHAR(190) NULL,
  `exp_papel`        VARCHAR(120) NULL,
  `exp_municipio`    VARCHAR(120) NULL,
  `exp_uf`           CHAR(2)      NULL,
  `exp_dt_inicio`    DATE         NULL,
  `exp_dt_fim`       DATE         NULL,
  `exp_atual`        CHAR(1)      NOT NULL DEFAULT 'N',
  `exp_art_id`       INT UNSIGNED NULL COMMENT 'Comprovacao por ART validada',
  `exp_cat_id`       INT UNSIGNED NULL COMMENT 'Comprovacao por CAT validada',
  `exp_comprovada`   CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'S quando vinculada a ART/CAT validada',
  `exp_visivel`      CHAR(1)      NOT NULL DEFAULT 'S',
  `exp_moderacao`    VARCHAR(20)  NOT NULL DEFAULT 'APROVADO' COMMENT 'APROVADO | EM_ANALISE | REPROVADO',
  `exp_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `exp_dt_alteracao` DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `exp_log`          VARCHAR(255) NULL,
  `exp_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_exp_id` PRIMARY KEY (`exp_id`),
  CONSTRAINT `fk_exp_prf_id` FOREIGN KEY (`exp_prf_id`)
    REFERENCES `pro_perfis` (`prf_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_exp_art_id` FOREIGN KEY (`exp_art_id`)
    REFERENCES `pro_arts` (`art_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exp_cat_id` FOREIGN KEY (`exp_cat_id`)
    REFERENCES `pro_cats` (`cat_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_exp_prf`        (`exp_prf_id`, `exp_status`),
  KEY `idx_exp_comprovada` (`exp_comprovada`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Experiencias profissionais publicadas (RF03)';

-- -----------------------------------------------------------------------------
-- pro_experiencia_competencias : competencia ligada a experiencia (cenario 7.1)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_experiencia_competencias` (
  `ecp_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ecp_exp_id`      INT UNSIGNED NOT NULL,
  `ecp_cmp_id`      SMALLINT UNSIGNED NOT NULL,
  `ecp_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ecp_log`         VARCHAR(255) NULL,
  `ecp_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_ecp_id` PRIMARY KEY (`ecp_id`),
  CONSTRAINT `uk_ecp_exp_cmp` UNIQUE KEY (`ecp_exp_id`, `ecp_cmp_id`),
  CONSTRAINT `fk_ecp_exp_id` FOREIGN KEY (`ecp_exp_id`)
    REFERENCES `pro_experiencias` (`exp_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ecp_cmp_id` FOREIGN KEY (`ecp_cmp_id`)
    REFERENCES `pro_competencias` (`cmp_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Competencia comprovada por uma experiencia (cenario 7.1)';

-- -----------------------------------------------------------------------------
-- pro_demandas : oportunidades de servico tecnico publicadas
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_demandas` (
  `dem_id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dem_usu_id`              INT UNSIGNED NOT NULL COMMENT 'Autor: empresa, instituicao ou terceiro',
  `dem_titulo`              VARCHAR(190) NOT NULL,
  `dem_escopo`              TEXT         NOT NULL,
  `dem_are_id`              SMALLINT UNSIGNED NULL,
  `dem_uf`                  CHAR(2)      NULL,
  `dem_cidade`              VARCHAR(120) NULL,
  `dem_aceita_remoto`       CHAR(1)      NOT NULL DEFAULT 'N',
  `dem_modalidade`          VARCHAR(20)  NOT NULL DEFAULT 'PROJETO' COMMENT 'PROJETO | CONSULTORIA | LAUDO | EXECUCAO | FISCALIZACAO | OUTROS',
  `dem_exige_art`           CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'Exige acervo comprovado por ART/CAT',
  `dem_exige_registro`      CHAR(1)      NOT NULL DEFAULT 'S' COMMENT 'Exige registro ativo no CREA-AM',
  `dem_experiencia_min`     TINYINT UNSIGNED NULL COMMENT 'Anos minimos de experiencia',
  `dem_orcamento_min`       DECIMAL(14,2) NULL,
  `dem_orcamento_max`       DECIMAL(14,2) NULL,
  `dem_prazo_execucao`      VARCHAR(120) NULL,
  `dem_dt_limite`           DATE         NULL COMMENT 'Prazo para manifestacao de interesse',
  `dem_situacao`            VARCHAR(20)  NOT NULL DEFAULT 'RASCUNHO' COMMENT 'RASCUNHO | PUBLICADA | EM_ANALISE | ENCERRADA | CANCELADA',
  `dem_dt_publicacao`       DATETIME     NULL,
  `dem_dt_encerramento`     DATETIME     NULL,
  `dem_motivo_encerramento` VARCHAR(255) NULL,
  `dem_visibilidade`        VARCHAR(20)  NOT NULL DEFAULT 'PUBLICO' COMMENT 'PUBLICO | AUTENTICADO',
  `dem_moderacao`           VARCHAR(20)  NOT NULL DEFAULT 'APROVADO' COMMENT 'APROVADO | EM_ANALISE | REPROVADO',
  `dem_moderacao_motivo`    VARCHAR(255) NULL,
  `dem_total_interesses`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `dem_dt_registro`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dem_dt_alteracao`        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `dem_log`                 VARCHAR(255) NULL,
  `dem_status`              CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_dem_id` PRIMARY KEY (`dem_id`),
  CONSTRAINT `fk_dem_usu_id` FOREIGN KEY (`dem_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dem_are_id` FOREIGN KEY (`dem_are_id`)
    REFERENCES `pro_areas` (`are_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_dem_situacao` (`dem_situacao`, `dem_moderacao`, `dem_status`),
  KEY `idx_dem_local`    (`dem_uf`, `dem_cidade`),
  KEY `idx_dem_autor`    (`dem_usu_id`, `dem_status`),
  KEY `idx_dem_prazo`    (`dem_dt_limite`),
  FULLTEXT KEY `ftx_dem_texto` (`dem_titulo`, `dem_escopo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Demandas por servicos tecnicos (RF04)';

-- -----------------------------------------------------------------------------
-- pro_demanda_competencias : competencias requeridas pela demanda
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_demanda_competencias` (
  `dmc_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dmc_dem_id`      INT UNSIGNED NOT NULL,
  `dmc_cmp_id`      SMALLINT UNSIGNED NOT NULL,
  `dmc_obrigatoria` CHAR(1)      NOT NULL DEFAULT 'S' COMMENT 'S = requisito eliminatorio no matching',
  `dmc_peso`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Peso relativo (1-5) no calculo de aderencia',
  `dmc_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dmc_log`         VARCHAR(255) NULL,
  `dmc_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_dmc_id` PRIMARY KEY (`dmc_id`),
  CONSTRAINT `uk_dmc_dem_cmp` UNIQUE KEY (`dmc_dem_id`, `dmc_cmp_id`),
  CONSTRAINT `fk_dmc_dem_id` FOREIGN KEY (`dmc_dem_id`)
    REFERENCES `pro_demandas` (`dem_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dmc_cmp_id` FOREIGN KEY (`dmc_cmp_id`)
    REFERENCES `pro_competencias` (`cmp_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY `idx_dmc_cmp` (`dmc_cmp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Requisitos de competencia da demanda (RF04)';

-- -----------------------------------------------------------------------------
-- pro_interesses : manifestacao de interesse na demanda
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_interesses` (
  `int_id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `int_dem_id`          INT UNSIGNED NOT NULL,
  `int_usu_id`          INT UNSIGNED NOT NULL COMMENT 'Profissional ou empresa interessada',
  `int_mensagem`        TEXT         NULL,
  `int_valor_proposto`  DECIMAL(14,2) NULL,
  `int_prazo_proposto`  VARCHAR(120) NULL,
  `int_aderencia`       DECIMAL(5,2) NULL COMMENT 'Score de compatibilizacao no momento da manifestacao',
  `int_situacao`        VARCHAR(20)  NOT NULL DEFAULT 'ENVIADO' COMMENT 'ENVIADO | VISUALIZADO | EM_NEGOCIACAO | SELECIONADO | RECUSADO | RETIRADO',
  `int_dt_visualizacao` DATETIME     NULL,
  `int_resposta`        TEXT         NULL,
  `int_dt_resposta`     DATETIME     NULL,
  `int_dt_registro`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `int_dt_alteracao`    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `int_log`             VARCHAR(255) NULL,
  `int_status`          CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_int_id` PRIMARY KEY (`int_id`),
  CONSTRAINT `uk_int_dem_usu` UNIQUE KEY (`int_dem_id`, `int_usu_id`),
  CONSTRAINT `fk_int_dem_id` FOREIGN KEY (`int_dem_id`)
    REFERENCES `pro_demandas` (`dem_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_int_usu_id` FOREIGN KEY (`int_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_int_situacao` (`int_situacao`, `int_status`),
  KEY `idx_int_usu`      (`int_usu_id`, `int_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Manifestacoes de interesse em demandas (RF05)';

-- -----------------------------------------------------------------------------
-- pro_conversas : canal de comunicacao inicial entre as partes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_conversas` (
  `cnv_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cnv_dem_id`        INT UNSIGNED NULL,
  `cnv_int_id`        INT UNSIGNED NULL,
  `cnv_usu_origem`    INT UNSIGNED NOT NULL,
  `cnv_usu_destino`   INT UNSIGNED NOT NULL,
  `cnv_assunto`       VARCHAR(190) NULL,
  `cnv_dt_ultima_msg` DATETIME     NULL,
  `cnv_bloqueada`     CHAR(1)      NOT NULL DEFAULT 'N' COMMENT 'S = bloqueada por moderacao',
  `cnv_dt_registro`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cnv_log`           VARCHAR(255) NULL,
  `cnv_status`        CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_cnv_id` PRIMARY KEY (`cnv_id`),
  CONSTRAINT `fk_cnv_dem_id` FOREIGN KEY (`cnv_dem_id`)
    REFERENCES `pro_demandas` (`dem_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cnv_int_id` FOREIGN KEY (`cnv_int_id`)
    REFERENCES `pro_interesses` (`int_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cnv_usu_origem` FOREIGN KEY (`cnv_usu_origem`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cnv_usu_destino` FOREIGN KEY (`cnv_usu_destino`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_cnv_participantes` (`cnv_usu_origem`, `cnv_usu_destino`),
  KEY `idx_cnv_destino`       (`cnv_usu_destino`, `cnv_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conversas entre contratantes e profissionais (RF05)';

-- -----------------------------------------------------------------------------
-- pro_mensagens : mensagens trocadas dentro de uma conversa
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_mensagens` (
  `msg_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `msg_cnv_id`      INT UNSIGNED NOT NULL,
  `msg_usu_id`      INT UNSIGNED NOT NULL COMMENT 'Autor da mensagem',
  `msg_conteudo`    TEXT         NOT NULL,
  `msg_dt_leitura`  DATETIME     NULL,
  `msg_moderacao`   VARCHAR(20)  NOT NULL DEFAULT 'APROVADO' COMMENT 'APROVADO | EM_ANALISE | REPROVADO',
  `msg_dt_registro` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `msg_log`         VARCHAR(255) NULL,
  `msg_status`      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_msg_id` PRIMARY KEY (`msg_id`),
  CONSTRAINT `fk_msg_cnv_id` FOREIGN KEY (`msg_cnv_id`)
    REFERENCES `pro_conversas` (`cnv_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_msg_usu_id` FOREIGN KEY (`msg_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  KEY `idx_msg_conversa` (`msg_cnv_id`, `msg_dt_registro`),
  KEY `idx_msg_leitura`  (`msg_cnv_id`, `msg_dt_leitura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mensagens da comunicacao inicial (RF05)';

-- -----------------------------------------------------------------------------
-- pro_denuncias : denuncias de conteudo para moderacao
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_denuncias` (
  `den_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `den_usu_id`       INT UNSIGNED NULL COMMENT 'Denunciante (NULL se anonimo)',
  `den_entidade`     VARCHAR(40)  NOT NULL COMMENT 'PERFIL | DEMANDA | EXPERIENCIA | MENSAGEM | USUARIO',
  `den_entidade_id`  INT UNSIGNED NOT NULL,
  `den_motivo`       VARCHAR(40)  NOT NULL COMMENT 'CONTEUDO_INADEQUADO | INFORMACAO_FALSA | SPAM | DADO_PESSOAL_INDEVIDO | EXERCICIO_ILEGAL | OUTRO',
  `den_descricao`    TEXT         NULL,
  `den_situacao`     VARCHAR(20)  NOT NULL DEFAULT 'ABERTA' COMMENT 'ABERTA | EM_ANALISE | PROCEDENTE | IMPROCEDENTE',
  `den_usu_analista` INT UNSIGNED NULL,
  `den_parecer`      TEXT         NULL,
  `den_providencia`  VARCHAR(40)  NULL COMMENT 'NENHUMA | CONTEUDO_OCULTADO | CONTEUDO_REMOVIDO | USUARIO_ADVERTIDO | USUARIO_BLOQUEADO',
  `den_dt_analise`   DATETIME     NULL,
  `den_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `den_dt_alteracao` DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `den_log`          VARCHAR(255) NULL,
  `den_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_den_id` PRIMARY KEY (`den_id`),
  CONSTRAINT `fk_den_usu_id` FOREIGN KEY (`den_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_den_usu_analista` FOREIGN KEY (`den_usu_analista`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_den_situacao` (`den_situacao`, `den_dt_registro`),
  KEY `idx_den_entidade` (`den_entidade`, `den_entidade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Denuncias e tratamento pela moderacao (RF06)';

-- -----------------------------------------------------------------------------
-- pro_solicitacoes_lgpd : direitos do titular (LGPD art. 18)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pro_solicitacoes_lgpd` (
  `slg_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slg_usu_id`       INT UNSIGNED NOT NULL,
  `slg_tipo`         VARCHAR(30)  NOT NULL COMMENT 'ACESSO | CORRECAO | PORTABILIDADE | ANONIMIZACAO | ELIMINACAO | REVOGACAO_CONSENTIMENTO',
  `slg_descricao`    TEXT         NULL,
  `slg_situacao`     VARCHAR(20)  NOT NULL DEFAULT 'ABERTA' COMMENT 'ABERTA | EM_ANALISE | ATENDIDA | RECUSADA',
  `slg_resposta`     TEXT         NULL,
  `slg_usu_analista` INT UNSIGNED NULL,
  `slg_dt_conclusao` DATETIME     NULL,
  `slg_dt_registro`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `slg_dt_alteracao` DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `slg_log`          VARCHAR(255) NULL,
  `slg_status`       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT `pk_slg_id` PRIMARY KEY (`slg_id`),
  CONSTRAINT `fk_slg_usu_id` FOREIGN KEY (`slg_usu_id`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_slg_usu_analista` FOREIGN KEY (`slg_usu_analista`)
    REFERENCES `sis_usuarios` (`usu_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_slg_situacao` (`slg_situacao`, `slg_dt_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Requisicoes de direitos do titular (LGPD / RF01)';
