-- =============================================================================
-- CREA Pro-Link | Carga inicial (item 8.8.c do Termo de Referência)
--
-- Contém apenas dados proprios da aplicação: catálogos controlados, termos,
-- parâmetros de configuração, um usuário administrador e contratantes de
-- demonstração (pessoas não registradas no CREA-AM).
--
-- IMPORTANTE: não há aqui nenhum dado de profissional registrado, empresa
-- registrada, ART ou CAT. Esses dados vem exclusivamente da API oficial do
-- desafio, conforme item 8.4 do Termo de Referência, que veda a criação de
-- base própria para simulá-los. Profissionais e empresas registrados são
-- cadastrados pela própria interface, com validação em tempo real na API.
-- =============================================================================

SET NAMES utf8mb4;
USE `crea_prolink`;

-- -----------------------------------------------------------------------------
-- Áreas de atuação do Sistema Confea/Crea
-- -----------------------------------------------------------------------------
INSERT INTO `pro_areas` (`are_id`, `are_nome`, `are_descricao`) VALUES
  (1, 'Engenharia',  'Modalidades de engenharia abrangidas pelo Sistema Confea/Crea'),
  (2, 'Agronomia',   'Agronomia, engenharia agronômica e áreas correlatas'),
  (3, 'Geociências', 'Geologia, engenharia de minas e áreas correlatas')
ON DUPLICATE KEY UPDATE `are_descricao` = VALUES(`are_descricao`);

-- -----------------------------------------------------------------------------
-- Catálogo de competências técnicas
-- -----------------------------------------------------------------------------
INSERT INTO `pro_competencias` (`cmp_id`, `cmp_are_id`, `cmp_nome`, `cmp_descricao`) VALUES
  -- Engenharia
  ( 1, 1, 'Projeto estrutural em concreto armado',   'Dimensionamento e detalhamento de estruturas de concreto'),
  ( 2, 1, 'Projeto estrutural metálico',             'Estruturas em aço e sistemas mistos'),
  ( 3, 1, 'Projeto estrutural em madeira',           'Estruturas de madeira e sistemas híbridos'),
  ( 4, 1, 'Execução e fiscalização de obras civis',  'Acompanhamento e fiscalização de execução'),
  ( 5, 1, 'Orçamento e planejamento de obras',       'Composição de custos, cronogramas e curva S'),
  ( 6, 1, 'Laudo e perícia de edificações',          'Vistorias, laudos técnicos e perícias'),
  ( 7, 1, 'Instalações elétricas prediais',          'Projeto e execução de instalações elétricas de baixa tensão'),
  ( 8, 1, 'Instalações elétricas industriais',       'Sistemas de média tensão, subestações e acionamentos'),
  ( 9, 1, 'Sistemas fotovoltaicos',                  'Projeto e homologação de geração distribuída'),
  (10, 1, 'Instalações hidrossanitárias',            'Água fria, água quente, esgoto e águas pluviais'),
  (11, 1, 'Saneamento e tratamento de efluentes',    'Sistemas de abastecimento, esgotamento e ETEs'),
  (12, 1, 'Drenagem urbana',                         'Microdrenagem e macrodrenagem'),
  (13, 1, 'Geotecnia e fundações',                   'Investigação geotécnica, contenções e fundações'),
  (14, 1, 'Pavimentação e terraplenagem',            'Projeto e execução de pavimentos'),
  (15, 1, 'Topografia e geoprocessamento',           'Levantamentos topográficos, georreferenciamento e SIG'),
  (16, 1, 'Climatização e refrigeração (AVAC-R)',    'Projeto e manutenção de sistemas de climatização'),
  (17, 1, 'Segurança do trabalho',                   'PGR, PCMSO, laudos de insalubridade e periculosidade'),
  (18, 1, 'Prevenção e combate a incêndio',          'Projetos de PPCI e sistemas de detecção'),
  (19, 1, 'Automação e controle industrial',          'PLCs, SCADA e instrumentação'),
  (20, 1, 'Manutenção industrial',                   'Planos de manutenção preventiva e preditiva'),
  (21, 1, 'Licenciamento e estudos ambientais',      'EIA/RIMA, PCA, PRAD e licenciamento'),
  (22, 1, 'Gestão de resíduos sólidos',              'PGRS, PGRCC e logística reversa'),
  (23, 1, 'Engenharia de produção e processos',      'Otimização de processos e produtividade'),
  (24, 1, 'BIM e modelagem 3D',                      'Modelagem, coordenação e compatibilização BIM'),
  (25, 1, 'Avaliação de imóveis',                    'Laudos de avaliação conforme NBR 14653'),
  -- Agronomia
  (26, 2, 'Projeto de irrigação',                    'Dimensionamento de sistemas de irrigação'),
  (27, 2, 'Manejo e fertilidade do solo',            'Análise, correção e recomendação de adubação'),
  (28, 2, 'Assistência técnica em culturas',         'Acompanhamento agronômico de lavouras'),
  (29, 2, 'Regularização ambiental rural',           'CAR, PRA e adequação de propriedades rurais'),
  (30, 2, 'Projetos de crédito rural',               'Elaboração e acompanhamento de projetos de financiamento'),
  (31, 2, 'Manejo florestal sustentável',            'Planos de manejo e inventário florestal'),
  (32, 2, 'Piscicultura e aquicultura',              'Projeto e manejo de sistemas aquícolas'),
  -- Geociências
  (33, 3, 'Mapeamento geológico',                    'Levantamento e mapeamento geológico'),
  (34, 3, 'Hidrogeologia e poços tubulares',         'Locação, projeto e outorga de poços'),
  (35, 3, 'Pesquisa mineral',                        'Prospecção e pesquisa de bens minerais'),
  (36, 3, 'Plano de aproveitamento econômico',       'PAE e projetos de lavra'),
  (37, 3, 'Geotecnia aplicada à mineração',          'Estabilidade de taludes e barragens de rejeito')
ON DUPLICATE KEY UPDATE `cmp_descricao` = VALUES(`cmp_descricao`);

-- -----------------------------------------------------------------------------
-- Termos de Uso e Política de Privacidade (versão inicial)
-- -----------------------------------------------------------------------------
INSERT INTO `sis_termos` (`ter_id`, `ter_tipo`, `ter_versao`, `ter_titulo`, `ter_dt_vigencia`, `ter_conteudo`) VALUES
  (1, 'TERMOS_USO', '1.0', 'Termos de Uso da plataforma CREA Pro-Link', '2026-01-01',
   '<h5>1. Objeto</h5><p>O CREA Pro-Link é uma plataforma digital destinada a aproximar profissionais registrados no Sistema Confea/Crea, empresas, instituições e demais interessados na contratação ou oferta de serviços técnicos especializados em engenharia, agronomia e geociências.</p><h5>2. Cadastro</h5><p>O usuário declara que as informações fornecidas são verdadeiras e se responsabiliza por mantê-las atualizadas. Informações de registro profissional, ARTs e CATs são validadas junto à base oficial do CREA-AM e não podem ser alteradas manualmente pelo usuário.</p><h5>3. Responsabilidades</h5><p>A plataforma atua exclusivamente como meio de aproximação. A contratação, execução e pagamento dos serviços técnicos ocorrem diretamente entre as partes, que respondem integralmente por suas obrigações contratuais, técnicas e legais.</p><h5>4. Conduta</h5><p>É vedada a publicação de conteúdo falso, ofensivo, discriminatório, que exponha dados pessoais de terceiros sem autorização ou que caracterize exercício ilegal da profissão. O descumprimento sujeita o usuário a moderação, suspensão ou bloqueio da conta.</p><h5>5. Moderação</h5><p>Perfis, demandas, experiências e mensagens podem ser moderados a partir de denúncias ou verificação de rotina. Toda ação de moderação é registrada em trilha de auditoria.</p><h5>6. Vigência</h5><p>Estes termos vigem por prazo indeterminado. Alterações materiais serão comunicadas e exigirão novo aceite.</p>'),
  (2, 'POLITICA_PRIVACIDADE', '1.0', 'Política de Privacidade e Proteção de Dados', '2026-01-01',
   '<h5>1. Controlador</h5><p>O tratamento de dados pessoais nesta plataforma observa a Lei n. 13.709/2018 (LGPD).</p><h5>2. Dados tratados</h5><p>São tratados dados de identificação (nome, e-mail, telefone, CPF ou CNPJ), dados profissionais (RNP, competências, experiências, ARTs e CATs obtidas da base oficial do CREA-AM), dados de localização aproximada (município e UF) e registros de acesso (endereço IP, data e hora, agente de usuário).</p><h5>3. Finalidades</h5><p>Os dados são utilizados para autenticar o usuário, compor o perfil profissional, viabilizar a compatibilização entre demandas e profissionais, permitir a comunicação entre as partes, cumprir obrigações legais e regulatórias e garantir a segurança da plataforma.</p><h5>4. Base legal</h5><p>O tratamento fundamenta-se no consentimento do titular, na execução de contrato, no cumprimento de obrigação legal e no exercício regular de direitos.</p><h5>5. Compartilhamento</h5><p>Dados do perfil são exibidos publicamente apenas conforme as opções de visibilidade escolhidas pelo próprio titular. Dados de contato e documentos permanecem ocultos por padrão. Não há compartilhamento com terceiros para finalidade publicitária.</p><h5>6. Direitos do titular</h5><p>O titular pode, a qualquer momento, solicitar confirmação de tratamento, acesso, correção, portabilidade, anonimização ou eliminação de seus dados, bem como revogar consentimentos, pelo painel de privacidade da própria plataforma.</p><h5>7. Retenção</h5><p>Registros de auditoria e consentimento são preservados pelo prazo necessário ao cumprimento de obrigações legais. A exclusão de conta é realizada por exclusão lógica, preservando a rastreabilidade exigida, com anonimização dos dados pessoais quando solicitada.</p><h5>8. Segurança</h5><p>São adotados controle de acesso por perfil, armazenamento de senhas com função de hash, proteção contra injeção de SQL, XSS e CSRF, transporte cifrado e registro de auditoria das operações sensíveis.</p>')
ON DUPLICATE KEY UPDATE `ter_titulo` = VALUES(`ter_titulo`);

-- -----------------------------------------------------------------------------
-- Parâmetros de configuração (editáveis no painel administrativo)
-- Valores sensíveis ficam vazios: devem ser preenchidos pelo painel ou .ENV
-- -----------------------------------------------------------------------------
INSERT INTO `sis_configuracoes` (`cfg_grupo`, `cfg_chave`, `cfg_valor`, `cfg_tipo`, `cfg_sensivel`, `cfg_descricao`) VALUES
  ('PLATAFORMA', 'nome_plataforma',      'CREA Pro-Link',            'TEXTO',    'N', 'Nome exibido na interface'),
  ('PLATAFORMA', 'email_contato',        'contato@prolink.local',    'TEXTO',    'N', 'E-mail institucional de contato'),
  ('PLATAFORMA', 'itens_por_pagina',     '12',                       'INTEIRO',  'N', 'Quantidade de itens por página nas listagens'),
  ('PLATAFORMA', 'cadastro_aberto',      '1',                        'BOOLEANO', 'N', 'Permite novos cadastros na plataforma'),
  ('SMTP',       'smtp_ativo',           '0',                        'BOOLEANO', 'N', 'Quando desativado, as notificações ficam registradas na fila sem envio'),
  ('SMTP',       'smtp_host',            '',                         'TEXTO',    'N', 'Servidor SMTP'),
  ('SMTP',       'smtp_porta',           '587',                      'INTEIRO',  'N', 'Porta do servidor SMTP'),
  ('SMTP',       'smtp_seguranca',       'tls',                      'TEXTO',    'N', 'tls, ssl ou vazio'),
  ('SMTP',       'smtp_usuario',         '',                         'TEXTO',    'N', 'Usuário de autenticação SMTP'),
  ('SMTP',       'smtp_senha',           '',                         'SENHA',    'S', 'Senha de autenticação SMTP'),
  ('SMTP',       'smtp_remetente_email', 'nao-responda@prolink.local','TEXTO',   'N', 'E-mail remetente'),
  ('SMTP',       'smtp_remetente_nome',  'CREA Pro-Link',            'TEXTO',    'N', 'Nome do remetente'),
  ('API_CREA',   'api_base_url',         '',                         'TEXTO',    'N', 'URL base da API oficial do desafio (preferir .ENV)'),
  ('API_CREA',   'api_token',            '',                         'SENHA',    'S', 'Token de acesso individual (preferir .ENV)'),
  ('API_CREA',   'api_timeout',          '15',                       'INTEIRO',  'N', 'Timeout das chamadas em segundos'),
  ('API_CREA',   'api_cache_minutos',    '60',                       'INTEIRO',  'N', 'Tempo de cache das respostas de consulta'),
  ('MATCHING',   'peso_competencias',    '45',                       'INTEIRO',  'N', 'Peso das competências no score de aderência'),
  ('MATCHING',   'peso_localizacao',     '20',                       'INTEIRO',  'N', 'Peso da localização no score de aderência'),
  ('MATCHING',   'peso_acervo',          '20',                       'INTEIRO',  'N', 'Peso do acervo técnico (ART/CAT) no score'),
  ('MATCHING',   'peso_experiencia',     '10',                       'INTEIRO',  'N', 'Peso do tempo de experiência no score'),
  ('MATCHING',   'peso_disponibilidade', '5',                        'INTEIRO',  'N', 'Peso da disponibilidade declarada no score'),
  ('MATCHING',   'score_minimo',         '20',                       'INTEIRO',  'N', 'Score mínimo para aparecer entre as correspondências')
ON DUPLICATE KEY UPDATE `cfg_descricao` = VALUES(`cfg_descricao`);

-- -----------------------------------------------------------------------------
-- Usuário administrador inicial
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
-- Contratantes de demonstração (terceiros não registrados no CREA-AM)
-- Senha de ambos: Senha@123
-- -----------------------------------------------------------------------------
INSERT INTO `sis_usuarios`
  (`usu_id`, `usu_nome`, `usu_email`, `usu_senha`, `usu_tipo_pessoa`, `usu_perfil`,
   `usu_cnpj`, `usu_cpf`, `usu_email_verificado`, `usu_telefone`, `usu_uf`, `usu_cidade`, `usu_log`, `usu_status`)
VALUES
  (2, 'Construtora Rio Negro Ltda', 'contratante@prolink.local',
   '$2y$12$zZb9XOhr537/DiQ3erPZKusTsoKG3JPzNINd.tzQDJ7L/ZpJguWni',
   'PJ', 'TERCEIRO', '12345678000199', NULL, 'S', '(92) 3000-0000', 'AM', 'Manaus',
   'Contratante de demonstração', 'A'),
  (3, 'Instituto Amazônia Sustentável', 'instituição@prolink.local',
   '$2y$12$zZb9XOhr537/DiQ3erPZKusTsoKG3JPzNINd.tzQDJ7L/ZpJguWni',
   'PJ', 'TERCEIRO', '98765432000155', NULL, 'S', '(92) 3111-1111', 'AM', 'Manaus',
   'Contratante de demonstração', 'A')
ON DUPLICATE KEY UPDATE `usu_nome` = VALUES(`usu_nome`);

-- -----------------------------------------------------------------------------
-- Demandas de demonstração (cenário 7.2 do Termo de Referência)
-- -----------------------------------------------------------------------------
INSERT INTO `pro_demandas`
  (`dem_id`, `dem_usu_id`, `dem_titulo`, `dem_escopo`, `dem_are_id`, `dem_uf`, `dem_cidade`,
   `dem_aceita_remoto`, `dem_modalidade`, `dem_exige_art`, `dem_exige_registro`,
   `dem_experiencia_min`, `dem_orcamento_min`, `dem_orcamento_max`, `dem_prazo_execucao`,
   `dem_dt_limite`, `dem_situacao`, `dem_dt_publicacao`, `dem_log`)
VALUES
  (1, 2, 'Projeto estrutural de edifício residencial de 12 pavimentos',
   'Elaboração de projeto estrutural completo em concreto armado para edifício residencial de 12 pavimentos, com dois subsolos, no bairro Ponta Negra. Escopo: concepção estrutural, dimensionamento, detalhamento de armaduras, memorial de cálculo, quantitativos e compatibilização com os projetos de arquitetura e instalações. Sondagem SPT disponível. Entrega em formato editável e PDF assinado, com ART registrada.',
   1, 'AM', 'Manaus', 'N', 'PROJETO', 'S', 'S', 5, 80000.00, 140000.00,
   '90 dias corridos', DATE_ADD(CURDATE(), INTERVAL 25 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstração'),
  (2, 3, 'Laudo técnico e adequação de sistema de tratamento de efluentes',
   'Avaliação do sistema de tratamento de efluentes de unidade de pesquisa na zona rural de Iranduba, com emissão de laudo técnico, diagnóstico de conformidade ambiental e projeto de adequação. Inclui coleta de amostras, análise dos resultados, dimensionamento da adequação e acompanhamento do processo de licenciamento junto ao órgão ambiental.',
   1, 'AM', 'Iranduba', 'N', 'LAUDO', 'N', 'S', 3, 25000.00, 45000.00,
   '60 dias corridos', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstração'),
  (3, 2, 'Consultoria em coordenação BIM para carteira de obras',
   'Consultoria para implantação de fluxo de coordenação BIM em carteira de quatro obras em andamento, contemplando definição de padrão de modelagem, plano de execução BIM, rotinas de compatibilização e capacitação da equipe técnica interna. Atendimento híbrido, com reuniões presenciais mensais em Manaus.',
   1, 'AM', 'Manaus', 'S', 'CONSULTORIA', 'N', 'S', 4, 40000.00, 70000.00,
   '6 meses', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'PUBLICADA', NOW(),
   'Demanda de demonstração')
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
