-- =============================================================================
-- CREA Pro-Link | Carga inicial (item 8.8.c do Termo de Referencia)
--
-- Contem apenas dados proprios da aplicacao: catalogos controlados, termos,
-- parametros de configuracao, um usuario administrador e contratantes de
-- demonstracao (pessoas nao registradas no CREA-AM).
--
-- IMPORTANTE: nao ha aqui nenhum dado de profissional registrado, empresa
-- registrada, ART ou CAT. Esses dados vem exclusivamente da API oficial do
-- desafio, conforme item 8.4 do Termo de Referencia, que veda a criacao de
-- base propria para simula-los. Profissionais e empresas registrados sao
-- cadastrados pela propria interface, com validacao em tempo real na API.
-- =============================================================================

SET NAMES utf8mb4;
USE `crea_prolink`;

-- -----------------------------------------------------------------------------
-- Areas de atuacao do Sistema Confea/Crea
-- -----------------------------------------------------------------------------
INSERT INTO `pro_areas` (`are_id`, `are_nome`, `are_descricao`) VALUES
  (1, 'Engenharia',  'Modalidades de engenharia abrangidas pelo Sistema Confea/Crea'),
  (2, 'Agronomia',   'Agronomia, engenharia agronomica e areas correlatas'),
  (3, 'Geociencias', 'Geologia, engenharia de minas e areas correlatas')
ON DUPLICATE KEY UPDATE `are_descricao` = VALUES(`are_descricao`);

-- -----------------------------------------------------------------------------
-- Catalogo de competencias tecnicas
-- -----------------------------------------------------------------------------
INSERT INTO `pro_competencias` (`cmp_id`, `cmp_are_id`, `cmp_nome`, `cmp_descricao`) VALUES
  -- Engenharia
  ( 1, 1, 'Projeto estrutural em concreto armado',   'Dimensionamento e detalhamento de estruturas de concreto'),
  ( 2, 1, 'Projeto estrutural metalico',             'Estruturas em aco e sistemas mistos'),
  ( 3, 1, 'Projeto estrutural em madeira',           'Estruturas de madeira e sistemas hibridos'),
  ( 4, 1, 'Execucao e fiscalizacao de obras civis',  'Acompanhamento e fiscalizacao de execucao'),
  ( 5, 1, 'Orcamento e planejamento de obras',       'Composicao de custos, cronogramas e curva S'),
  ( 6, 1, 'Laudo e pericia de edificacoes',          'Vistorias, laudos tecnicos e pericias'),
  ( 7, 1, 'Instalacoes eletricas prediais',          'Projeto e execucao de instalacoes eletricas de baixa tensao'),
  ( 8, 1, 'Instalacoes eletricas industriais',       'Sistemas de media tensao, subestacoes e acionamentos'),
  ( 9, 1, 'Sistemas fotovoltaicos',                  'Projeto e homologacao de geracao distribuida'),
  (10, 1, 'Instalacoes hidrossanitarias',            'Agua fria, agua quente, esgoto e aguas pluviais'),
  (11, 1, 'Saneamento e tratamento de efluentes',    'Sistemas de abastecimento, esgotamento e ETEs'),
  (12, 1, 'Drenagem urbana',                         'Microdrenagem e macrodrenagem'),
  (13, 1, 'Geotecnia e fundacoes',                   'Investigacao geotecnica, contencoes e fundacoes'),
  (14, 1, 'Pavimentacao e terraplenagem',            'Projeto e execucao de pavimentos'),
  (15, 1, 'Topografia e geoprocessamento',           'Levantamentos topograficos, georreferenciamento e SIG'),
  (16, 1, 'Climatizacao e refrigeracao (AVAC-R)',    'Projeto e manutencao de sistemas de climatizacao'),
  (17, 1, 'Seguranca do trabalho',                   'PGR, PCMSO, laudos de insalubridade e periculosidade'),
  (18, 1, 'Prevencao e combate a incendio',          'Projetos de PPCI e sistemas de deteccao'),
  (19, 1, 'Automacao e controle industrial',          'PLCs, SCADA e instrumentacao'),
  (20, 1, 'Manutencao industrial',                   'Planos de manutencao preventiva e preditiva'),
  (21, 1, 'Licenciamento e estudos ambientais',      'EIA/RIMA, PCA, PRAD e licenciamento'),
  (22, 1, 'Gestao de residuos solidos',              'PGRS, PGRCC e logistica reversa'),
  (23, 1, 'Engenharia de producao e processos',      'Otimizacao de processos e produtividade'),
  (24, 1, 'BIM e modelagem 3D',                      'Modelagem, coordenacao e compatibilizacao BIM'),
  (25, 1, 'Avaliacao de imoveis',                    'Laudos de avaliacao conforme NBR 14653'),
  -- Agronomia
  (26, 2, 'Projeto de irrigacao',                    'Dimensionamento de sistemas de irrigacao'),
  (27, 2, 'Manejo e fertilidade do solo',            'Analise, correcao e recomendacao de adubacao'),
  (28, 2, 'Assistencia tecnica em culturas',         'Acompanhamento agronomico de lavouras'),
  (29, 2, 'Regularizacao ambiental rural',           'CAR, PRA e adequacao de propriedades rurais'),
  (30, 2, 'Projetos de credito rural',               'Elaboracao e acompanhamento de projetos de financiamento'),
  (31, 2, 'Manejo florestal sustentavel',            'Planos de manejo e inventario florestal'),
  (32, 2, 'Piscicultura e aquicultura',              'Projeto e manejo de sistemas aquicolas'),
  -- Geociencias
  (33, 3, 'Mapeamento geologico',                    'Levantamento e mapeamento geologico'),
  (34, 3, 'Hidrogeologia e pocos tubulares',         'Locacao, projeto e outorga de pocos'),
  (35, 3, 'Pesquisa mineral',                        'Prospeccao e pesquisa de bens minerais'),
  (36, 3, 'Plano de aproveitamento economico',       'PAE e projetos de lavra'),
  (37, 3, 'Geotecnia aplicada a mineracao',          'Estabilidade de taludes e barragens de rejeito')
ON DUPLICATE KEY UPDATE `cmp_descricao` = VALUES(`cmp_descricao`);

-- -----------------------------------------------------------------------------
-- Termos de Uso e Politica de Privacidade (versao inicial)
-- -----------------------------------------------------------------------------
INSERT INTO `sis_termos` (`ter_id`, `ter_tipo`, `ter_versao`, `ter_titulo`, `ter_dt_vigencia`, `ter_conteudo`) VALUES
  (1, 'TERMOS_USO', '1.0', 'Termos de Uso da plataforma CREA Pro-Link', '2026-01-01',
   '<h5>1. Objeto</h5><p>O CREA Pro-Link e uma plataforma digital destinada a aproximar profissionais registrados no Sistema Confea/Crea, empresas, instituicoes e demais interessados na contratacao ou oferta de servicos tecnicos especializados em engenharia, agronomia e geociencias.</p><h5>2. Cadastro</h5><p>O usuario declara que as informacoes fornecidas sao verdadeiras e se responsabiliza por mante-las atualizadas. Informacoes de registro profissional, ARTs e CATs sao validadas junto a base oficial do CREA-AM e nao podem ser alteradas manualmente pelo usuario.</p><h5>3. Responsabilidades</h5><p>A plataforma atua exclusivamente como meio de aproximacao. A contratacao, execucao e pagamento dos servicos tecnicos ocorrem diretamente entre as partes, que respondem integralmente por suas obrigacoes contratuais, tecnicas e legais.</p><h5>4. Conduta</h5><p>E vedada a publicacao de conteudo falso, ofensivo, discriminatorio, que exponha dados pessoais de terceiros sem autorizacao ou que caracterize exercicio ilegal da profissao. O descumprimento sujeita o usuario a moderacao, suspensao ou bloqueio da conta.</p><h5>5. Moderacao</h5><p>Perfis, demandas, experiencias e mensagens podem ser moderados a partir de denuncias ou verificacao de rotina. Toda acao de moderacao e registrada em trilha de auditoria.</p><h5>6. Vigencia</h5><p>Estes termos vigem por prazo indeterminado. Alteracoes materiais serao comunicadas e exigirao novo aceite.</p>'),
  (2, 'POLITICA_PRIVACIDADE', '1.0', 'Politica de Privacidade e Protecao de Dados', '2026-01-01',
   '<h5>1. Controlador</h5><p>O tratamento de dados pessoais nesta plataforma observa a Lei n. 13.709/2018 (LGPD).</p><h5>2. Dados tratados</h5><p>Sao tratados dados de identificacao (nome, e-mail, telefone, CPF ou CNPJ), dados profissionais (RNP, competencias, experiencias, ARTs e CATs obtidas da base oficial do CREA-AM), dados de localizacao aproximada (municipio e UF) e registros de acesso (endereco IP, data e hora, agente de usuario).</p><h5>3. Finalidades</h5><p>Os dados sao utilizados para autenticar o usuario, compor o perfil profissional, viabilizar a compatibilizacao entre demandas e profissionais, permitir a comunicacao entre as partes, cumprir obrigacoes legais e regulatorias e garantir a seguranca da plataforma.</p><h5>4. Base legal</h5><p>O tratamento fundamenta-se no consentimento do titular, na execucao de contrato, no cumprimento de obrigacao legal e no exercicio regular de direitos.</p><h5>5. Compartilhamento</h5><p>Dados do perfil sao exibidos publicamente apenas conforme as opcoes de visibilidade escolhidas pelo proprio titular. Dados de contato e documentos permanecem ocultos por padrao. Nao ha compartilhamento com terceiros para finalidade publicitaria.</p><h5>6. Direitos do titular</h5><p>O titular pode, a qualquer momento, solicitar confirmacao de tratamento, acesso, correcao, portabilidade, anonimizacao ou eliminacao de seus dados, bem como revogar consentimentos, pelo painel de privacidade da propria plataforma.</p><h5>7. Retencao</h5><p>Registros de auditoria e consentimento sao preservados pelo prazo necessario ao cumprimento de obrigacoes legais. A exclusao de conta e realizada por exclusao logica, preservando a rastreabilidade exigida, com anonimizacao dos dados pessoais quando solicitada.</p><h5>8. Seguranca</h5><p>Sao adotados controle de acesso por perfil, armazenamento de senhas com funcao de hash, protecao contra injecao de SQL, XSS e CSRF, transporte cifrado e registro de auditoria das operacoes sensiveis.</p>')
ON DUPLICATE KEY UPDATE `ter_titulo` = VALUES(`ter_titulo`);

-- -----------------------------------------------------------------------------
-- Parametros de configuracao (editaveis no painel administrativo)
-- Valores sensiveis ficam vazios: devem ser preenchidos pelo painel ou .ENV
-- -----------------------------------------------------------------------------
INSERT INTO `sis_configuracoes` (`cfg_grupo`, `cfg_chave`, `cfg_valor`, `cfg_tipo`, `cfg_sensivel`, `cfg_descricao`) VALUES
  ('PLATAFORMA', 'nome_plataforma',      'CREA Pro-Link',            'TEXTO',    'N', 'Nome exibido na interface'),
  ('PLATAFORMA', 'email_contato',        'contato@prolink.local',    'TEXTO',    'N', 'E-mail institucional de contato'),
  ('PLATAFORMA', 'itens_por_pagina',     '12',                       'INTEIRO',  'N', 'Quantidade de itens por pagina nas listagens'),
  ('PLATAFORMA', 'cadastro_aberto',      '1',                        'BOOLEANO', 'N', 'Permite novos cadastros na plataforma'),
  ('SMTP',       'smtp_ativo',           '0',                        'BOOLEANO', 'N', 'Quando desativado, as notificacoes ficam registradas na fila sem envio'),
  ('SMTP',       'smtp_host',            '',                         'TEXTO',    'N', 'Servidor SMTP'),
  ('SMTP',       'smtp_porta',           '587',                      'INTEIRO',  'N', 'Porta do servidor SMTP'),
  ('SMTP',       'smtp_seguranca',       'tls',                      'TEXTO',    'N', 'tls, ssl ou vazio'),
  ('SMTP',       'smtp_usuario',         '',                         'TEXTO',    'N', 'Usuario de autenticacao SMTP'),
  ('SMTP',       'smtp_senha',           '',                         'SENHA',    'S', 'Senha de autenticacao SMTP'),
  ('SMTP',       'smtp_remetente_email', 'nao-responda@prolink.local','TEXTO',   'N', 'E-mail remetente'),
  ('SMTP',       'smtp_remetente_nome',  'CREA Pro-Link',            'TEXTO',    'N', 'Nome do remetente'),
  ('API_CREA',   'api_base_url',         '',                         'TEXTO',    'N', 'URL base da API oficial do desafio (preferir .ENV)'),
  ('API_CREA',   'api_token',            '',                         'SENHA',    'S', 'Token de acesso individual (preferir .ENV)'),
  ('API_CREA',   'api_timeout',          '15',                       'INTEIRO',  'N', 'Timeout das chamadas em segundos'),
  ('API_CREA',   'api_cache_minutos',    '60',                       'INTEIRO',  'N', 'Tempo de cache das respostas de consulta'),
  ('MATCHING',   'peso_competencias',    '45',                       'INTEIRO',  'N', 'Peso das competencias no score de aderencia'),
  ('MATCHING',   'peso_localizacao',     '20',                       'INTEIRO',  'N', 'Peso da localizacao no score de aderencia'),
  ('MATCHING',   'peso_acervo',          '20',                       'INTEIRO',  'N', 'Peso do acervo tecnico (ART/CAT) no score'),
  ('MATCHING',   'peso_experiencia',     '10',                       'INTEIRO',  'N', 'Peso do tempo de experiencia no score'),
  ('MATCHING',   'peso_disponibilidade', '5',                        'INTEIRO',  'N', 'Peso da disponibilidade declarada no score'),
  ('MATCHING',   'score_minimo',         '20',                       'INTEIRO',  'N', 'Score minimo para aparecer entre as correspondencias')
ON DUPLICATE KEY UPDATE `cfg_descricao` = VALUES(`cfg_descricao`);

-- -----------------------------------------------------------------------------
-- Usuario administrador inicial
-- Senha: Admin@2026  (trocar no primeiro acesso)
-- -----------------------------------------------------------------------------
INSERT INTO `sis_usuarios`
  (`usu_id`, `usu_nome`, `usu_email`, `usu_senha`, `usu_tipo_pessoa`, `usu_perfil`,
   `usu_email_verificado`, `usu_uf`, `usu_cidade`, `usu_log`, `usu_status`)
VALUES
  (1, 'Administrador do Sistema', 'admin@prolink.local',
   '$2y$12$QcF25Xsc3Hw.mpMV4NcBWudRdXfLXP4LThQKQMRRsNtHqU7A1C6KS',
   'PF', 'ADMIN', 'S', 'AM', 'Manaus', 'Criado pela carga inicial', 'A')
ON DUPLICATE KEY UPDATE `usu_nome` = VALUES(`usu_nome`);

-- -----------------------------------------------------------------------------
-- Contratantes de demonstracao (terceiros nao registrados no CREA-AM)
-- Senha de ambos: Senha@123
-- -----------------------------------------------------------------------------
INSERT INTO `sis_usuarios`
  (`usu_id`, `usu_nome`, `usu_email`, `usu_senha`, `usu_tipo_pessoa`, `usu_perfil`,
   `usu_cnpj`, `usu_cpf`, `usu_email_verificado`, `usu_telefone`, `usu_uf`, `usu_cidade`, `usu_log`, `usu_status`)
VALUES
  (2, 'Construtora Rio Negro Ltda', 'contratante@prolink.local',
   '$2y$12$zZb9XOhr537/DiQ3erPZKusTsoKG3JPzNINd.tzQDJ7L/ZpJguWni',
   'PJ', 'TERCEIRO', '12345678000199', NULL, 'S', '(92) 3000-0000', 'AM', 'Manaus',
   'Contratante de demonstracao', 'A'),
  (3, 'Instituto Amazonia Sustentavel', 'instituicao@prolink.local',
   '$2y$12$zZb9XOhr537/DiQ3erPZKusTsoKG3JPzNINd.tzQDJ7L/ZpJguWni',
   'PJ', 'TERCEIRO', '98765432000155', NULL, 'S', '(92) 3111-1111', 'AM', 'Manaus',
   'Contratante de demonstracao', 'A')
ON DUPLICATE KEY UPDATE `usu_nome` = VALUES(`usu_nome`);

-- -----------------------------------------------------------------------------
-- Demandas de demonstracao (cenario 7.2 do Termo de Referencia)
-- -----------------------------------------------------------------------------
INSERT INTO `pro_demandas`
  (`dem_id`, `dem_usu_id`, `dem_titulo`, `dem_escopo`, `dem_are_id`, `dem_uf`, `dem_cidade`,
   `dem_aceita_remoto`, `dem_modalidade`, `dem_exige_art`, `dem_exige_registro`,
   `dem_experiencia_min`, `dem_orcamento_min`, `dem_orcamento_max`, `dem_prazo_execucao`,
   `dem_dt_limite`, `dem_situacao`, `dem_dt_publicacao`, `dem_log`)
VALUES
  (1, 2, 'Projeto estrutural de edificio residencial de 12 pavimentos',
   'Elaboracao de projeto estrutural completo em concreto armado para edificio residencial de 12 pavimentos, com dois subsolos, no bairro Ponta Negra. Escopo: concepcao estrutural, dimensionamento, detalhamento de armaduras, memorial de calculo, quantitativos e compatibilizacao com os projetos de arquitetura e instalacoes. Sondagem SPT disponivel. Entrega em formato editavel e PDF assinado, com ART registrada.',
   1, 'AM', 'Manaus', 'N', 'PROJETO', 'S', 'S', 5, 80000.00, 140000.00,
   '90 dias corridos', DATE_ADD(CURDATE(), INTERVAL 25 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstracao'),
  (2, 3, 'Laudo tecnico e adequacao de sistema de tratamento de efluentes',
   'Avaliacao do sistema de tratamento de efluentes de unidade de pesquisa na zona rural de Iranduba, com emissao de laudo tecnico, diagnostico de conformidade ambiental e projeto de adequacao. Inclui coleta de amostras, analise dos resultados, dimensionamento da adequacao e acompanhamento do processo de licenciamento junto ao orgao ambiental.',
   1, 'AM', 'Iranduba', 'N', 'LAUDO', 'N', 'S', 3, 25000.00, 45000.00,
   '60 dias corridos', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstracao'),
  (3, 2, 'Consultoria em coordenacao BIM para carteira de obras',
   'Consultoria para implantacao de fluxo de coordenacao BIM em carteira de quatro obras em andamento, contemplando definicao de padrao de modelagem, plano de execucao BIM, rotinas de compatibilizacao e capacitacao da equipe tecnica interna. Atendimento hibrido, com reunioes presenciais mensais em Manaus.',
   1, 'AM', 'Manaus', 'S', 'CONSULTORIA', 'N', 'S', 4, 40000.00, 70000.00,
   '6 meses', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstracao')
ON DUPLICATE KEY UPDATE `dem_titulo` = VALUES(`dem_titulo`);

INSERT INTO `pro_demanda_competencias` (`dmc_dem_id`, `dmc_cmp_id`, `dmc_obrigatoria`, `dmc_peso`) VALUES
  (1,  1, 'S', 5),
  (1, 13, 'S', 3),
  (1, 24, 'N', 2),
  (1,  5, 'N', 1),
  (2, 11, 'S', 5),
  (2, 21, 'S', 3),
  (2,  6, 'N', 2),
  (3, 24, 'S', 5),
  (3,  5, 'N', 3),
  (3, 23, 'N', 2)
ON DUPLICATE KEY UPDATE `dmc_peso` = VALUES(`dmc_peso`);
