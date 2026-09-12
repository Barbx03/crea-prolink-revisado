# CREA Pro-Link — Rastreamento de requisitos

Onde cada requisito do Anexo I — Termo de Referência foi implementado. Serve
para conferência item a item, sem precisar procurar no código.

---

## Requisitos funcionais mínimos (item 4)

### RF01 — Gestão de usuários, perfis e privacidade

| Exigência | Onde está | Como verificar |
|---|---|---|
| Cadastro de PF e PJ, registradas ou não | `ControladorAutenticacao::cadastrar()` | tela `/cadastrar` oferece os quatro tipos: profissional registrado, empresa registrada, PF sem registro, PJ sem registro |
| Autenticação | `Core\Autenticacao::entrar()` | `/entrar`; senha com `password_verify()` e bloqueio temporário após tentativas sucessivas |
| Recuperação de acesso | `ControladorAutenticacao::solicitarRecuperacao()` e `redefinirSenha()` | `/recuperar-acesso`; token de uso único com validade, gravado como hash em `sis_tokens` |
| Gerenciamento de perfil | `ControladorPerfil` | `/meu-perfil/editar`: perfil profissional, competências, dados cadastrais, senha e foto |
| Aceite dos Termos e da Política | `ControladorAutenticacao::cadastrar()` | aceite obrigatório no cadastro, gravado em `sis_consentimentos` com versão, data, IP e agente |
| Gerenciamento de consentimentos | `ControladorPrivacidade::salvarConsentimentos()` | `/privacidade`: concessão e revogação por finalidade, com histórico |
| Funcionalidades compatíveis com a LGPD | `ControladorPrivacidade`, `RepositorioLgpd` | acesso, correção, portabilidade, anonimização, eliminação, revogação e restrição de visibilidade |

### RF02 — Integração com a API oficial do CREA-AM

| Exigência | Onde está | Como verificar |
|---|---|---|
| Consumo obrigatório da API oficial | `Services\ClienteApiCreaHttp` | token no cabeçalho `Authorization`; toda chamada registrada em `sis_api_consultas` |
| Consulta de profissional por CPF | `ServicoIntegracaoCrea::consultarProfissional()` | botão de consulta no cadastro e em `/meu-perfil/registro-crea` |
| Consulta de empresa por CNPJ | `ServicoIntegracaoCrea::consultarEmpresa()` | mesma tela, para pessoa jurídica |
| Consulta de ARTs por RNP e número | `ServicoIntegracaoCrea::consultarArts()` | associação de ART ao portfólio |
| Consulta de CATs por RNP e número | `ServicoIntegracaoCrea::consultarCats()` | sincronização de CATs |
| Validação do profissional registrado | `ServicoIntegracaoCrea::validarRegistroProfissional()` | confere o CPF retornado com o consultado e exige o RNP |
| Vedação de base própria simulando a API | `ClienteApiCreaIndisponivel` | recusa a consulta com mensagem explícita; nenhum dado fictício de registro, ART ou CAT existe no projeto |

### RF03 — Portfólio profissional

| Exigência | Onde está | Como verificar |
|---|---|---|
| Associar ARTs ao portfólio | `ControladorPortfolio::associarArt()` | informa o número; a plataforma consulta a API pelo RNP do titular |
| Validação pela API oficial | `ServicoIntegracaoCrea::associarArt()` | grava `origem = 'API_CREA'`, `validada`, `dt_validacao` e o payload íntegro |
| Apresentar ARTs, CATs e informações autorizadas | `views/busca/perfil.twig` | perfil público exibe apenas o que o titular autorizou |
| Autorização do usuário sobre o que exibir | `RepositorioArt::definirVisibilidade()` | cada ART e CAT tem visibilidade individual |
| Competências ligadas a experiências | `RepositorioExperiencia::sincronizarCompetencias()` | experiência vinculada a ART validada é exibida como comprovada |

### RF04 — Gestão e compatibilização de demandas

| Exigência | Onde está | Como verificar |
|---|---|---|
| Cadastro de demanda | `ControladorDemanda::criar()` | `/demandas/nova` |
| Publicação | `ControladorDemanda::publicar()` | rascunho não aparece em busca nem recebe manifestação |
| Acompanhamento | `ControladorDemanda::minhas()` e `interessados()` | `/minhas-demandas` |
| Encerramento | `ControladorDemanda::encerrar()` | encerramento manual e automático por prazo vencido |
| Pesquisa e filtros | `RepositorioDemanda::pesquisar()` | `/demandas`: termo, área, UF, município, modalidade, competências, exigência de ART, prazo e ordenação |
| Compatibilização demanda × profissional | `ServicoCompatibilizacao::candidatosParaDemanda()` | `/demandas/{id}/correspondencias` |
| Compatibilização profissional × demanda | `ServicoCompatibilizacao::demandasParaProfissional()` | painel do profissional |
| Critérios objetivos e transparentes | `ServicoCompatibilizacao::avaliar()` | cinco critérios de peso declarado, exibidos com percentual obtido e justificativa |

### RF05 — Manifestação de interesse e comunicação

| Exigência | Onde está | Como verificar |
|---|---|---|
| Manifestação de interesse | `ControladorInteresse::manifestar()` | `/demandas/{id}/manifestar`; grava a aderência do momento |
| Comunicação inicial entre as partes | `ControladorMensagem` | `/mensagens`; conversa só abre em contexto legítimo |
| Empresa visualiza o perfil do interessado | `ControladorInteresse::visualizar()` | registra a visualização e leva ao perfil completo |
| Resposta à manifestação | `ControladorInteresse::responder()` | em negociação, selecionado ou recusado, com mensagem e notificação |

### RF06 — Administração da plataforma

| Exigência | Onde está | Como verificar |
|---|---|---|
| Gerenciamento de usuários | `Admin\ControladorUsuarios` | `/admin/usuarios`: filtros, ficha completa e histórico |
| Gerenciamento de conteúdos | `Admin\ControladorModeracao` | moderação de demandas e perfis |
| Permissões | `ControladorUsuarios::alterarPerfil()` | perfis registrados não são concedidos manualmente: dependem da API |
| Moderação | `Admin\ControladorModeracao` | fila por situação, com parecer obrigatório |
| Tratamento de denúncias | `ControladorModeracao::julgarDenuncia()` | julgamento com providência aplicada e partes notificadas |
| Bloqueios | `RepositorioUsuario::bloquear()` | bloqueio com motivo informado ao usuário |
| Indicadores gerenciais | `Admin\ControladorPainelAdmin::indicadores()` | `/admin/indicadores`: adoção, atividade, oferta × demanda e integração |
| Registros de auditoria | `Admin\ControladorAuditoria` | `/admin/auditoria`: filtros e exportação em CSV |
| Configuração de integrações | `Admin\ControladorConfiguracoes` | SMTP, API, matching e plataforma, com botões de teste |

### RF07 — Notificações

| Exigência | Onde está | Como verificar |
|---|---|---|
| Mecanismo de notificações por e-mail | `ServicoNotificacao` | fila em `sis_notificacoes`, envio por PHPMailer |
| Criação de cadastro | `ControladorAutenticacao::cadastrar()` | evento `CADASTRO_CRIADO` |
| Recuperação de senha | `ControladorAutenticacao::solicitarRecuperacao()` | evento `RECUPERACAO_SENHA` |
| Atualização de demandas | `ControladorDemanda::atualizar()` e `encerrar()` | eventos `DEMANDA_ATUALIZADA` e `DEMANDA_ENCERRADA`, aos interessados |
| Manifestação de interesse | `ControladorInteresse::manifestar()` | evento `INTERESSE_RECEBIDO`, ao autor da demanda |
| Demais eventos relevantes | `ServicoNotificacao::EVENTOS` | senha alterada, demanda publicada, interesse respondido, mensagem recebida, moderação, denúncia, requisição LGPD e registro validado |
| SMTP configurável por painel | `Admin\ControladorConfiguracoes` | grupo SMTP em `/admin/configuracoes`, com envio de teste |
| SMTP configurável por arquivo | `_config.php` e `.env` | variáveis `MAIL_*` |

---

## Requisitos não funcionais (item 5)

| Exigência | Como foi atendida |
|---|---|
| Proteção de dados | senhas com `password_hash()`; documentos mascarados na exibição; valores sensíveis omitidos de logs e da auditoria; identificadores mascarados no registro de consultas à API |
| Segregação de ambientes | `APP_AMBIENTE` distingue desenvolvimento, homologação e produção; em produção nenhum detalhe técnico de erro chega à tela |
| Princípio do menor privilégio | usuário próprio no banco, sem `root`; no contêiner, só `storage/` e `public/uploads/` pertencem ao usuário do servidor web; perfis de acesso declarados por rota |
| Registros mínimos de auditoria | `sis_auditoria` com usuário, ação, entidade, estado anterior e posterior, IP, agente, rota e severidade |
| Interface responsiva | Bootstrap 5 com layout em grade; verificada de 320 px a telas amplas |
| Acessibilidade digital | link de salto para o conteúdo, hierarquia de títulos, rótulos associados, `aria-live` nos avisos, foco visível, contraste acima de 4,5:1, tabelas com cabeçalho e legenda, informação nunca só por cor |
| Compatibilidade com navegadores e dispositivos | HTML5, CSS3 e JavaScript sem recurso experimental; funciona sem JavaScript |
| Desempenho | índices nas colunas de filtro; consultas agregadas em vez de repetidas em laço; OPcache ativo; paginação em todas as listagens |
| Arquitetura organizada e documentada | camadas separadas por diretório; documentação em `_arq/` |
| Código estruturado e reutilizável | `declare(strict_types=1)`, tipos em assinaturas e retornos, responsabilidade única, comportamento comum nas classes base |
| Portabilidade e interoperabilidade com padrões abertos | SQL padrão, JSON na portabilidade de dados, CSV na exportação de auditoria, SVG no MER, Docker no ambiente |

---

## Requisitos técnicos (item 8)

| Item | Exigência | Onde |
|---|---|---|
| 8.1.1.a | PHP 8.2 ou superior | `composer.json` exige `>=8.2`; imagem com 8.3 |
| 8.1.1.b | POO com persistência separada por repositório | `app/Repositories/` sobre `Core\Repositorio` |
| 8.1.1.c | Arquitetura MVC ou equivalente | `app/Controllers/`, `app/Repositories/` e `views/` |
| 8.1.1.d | Composer | `composer.json` e `composer.lock` |
| 8.1.2.a | MariaDB 10.11 ou superior | `docker-compose.yml` com `mariadb:10.11` |
| 8.1.2.b | utf8mb4 e utf8mb4_unicode_ci | declarado no banco, em cada tabela e na sessão de conexão |
| 8.1.3 | HTML5, CSS3, JavaScript, Bootstrap 5 e jQuery | `views/` e `public/assets/` |
| 8.2 | Camadas separadas, sem PHP no HTML | nenhum arquivo de `views/` contém PHP; Twig como mecanismo de templates |
| 8.3 | Estrutura MVC com `_arq/` e `_config.php` | ambos na raiz |
| 8.3.1 | Configuração centralizada com `.env` | `_config.php` cobre as alíneas *a* a *k* |
| 8.3.2.a | `estrutura.sql` | `_arq/estrutura.sql`, mais `_arq/dados-iniciais.sql` |
| 8.3.2.b | MER em PDF, PNG ou editável | `_arq/mer/` em SVG, PNG e PDF, gerados do banco |
| 8.3.2.c | README de instalação e atualização | `_arq/README.md` |
| 8.3.2.d | Documentação técnica da arquitetura | `_arq/ARQUITETURA.md` |
| 8.3.2.e | Relação de dependências com versões | `_arq/DEPENDENCIAS.md` |
| 8.3.2.f | Descrição da estrutura de diretórios | `_arq/ESTRUTURA-DIRETORIOS.md` |
| 8.4 | Integração obrigatória com a API oficial | `app/Services/`, com registro em `sis_api_consultas` |
| 8.5.a | Autenticação segura | `Core\Autenticacao` com sessão regenerada e bloqueio por tentativas |
| 8.5.b | `password_hash()` | `RepositorioUsuario::criar()` e `atualizarSenha()`, com reidratação do hash |
| 8.5.c | Proteção contra SQL Injection | prepared statements em `Core\BancoDados`; nenhuma concatenação de entrada em SQL |
| 8.5.d | Proteção contra XSS | escape automático do Twig e política de conteúdo sem script inline |
| 8.5.e | Proteção contra CSRF | `Core\Csrf`, validado pelo roteador em toda requisição que altera estado |
| 8.5.f | Controle de perfis de acesso | perfis declarados por rota em `app/rotas.php`, verificados antes do controlador |
| 8.5.g | Registro de auditoria | `Core\Auditoria` e tabela `sis_auditoria` |
| 8.6.a | Integridade referencial | 34 chaves estrangeiras em InnoDB |
| 8.6.b | Chaves primárias | em todas as 23 tabelas, nomeadas `pk_<prefixo>_id` |
| 8.6.c | Chaves estrangeiras | nomeadas `fk_*`, com ação declarada em cada uma |
| 8.6.d | Índices para otimização | nas colunas de filtro, mais *fulltext* em demandas |
| 8.6.e | Scripts completos de criação | `_arq/estrutura.sql` |
| 8.6.f | Prefixo de módulo nas tabelas | `sis_` e `pro_` |
| 8.6.g | Prefixo de três letras nos campos | `usu_`, `prf_`, `dem_`, `art_`, `cat_` |
| 8.6.h | Restrições nomeadas com `pk_` e `fk_` | em todo o schema, mais `uk_` e `idx_` |
| 8.6.i | Campos de controle | `xxx_dt_registro`, `xxx_log` e `xxx_status` em todas as tabelas |
| 8.6.j | Exclusão lógica com lixeira | `Core\Repositorio` e `/admin/lixeira` |
| 8.7 | Controle de versão com Git | histórico do repositório |
| 8.8.f | Ambiente automatizado em contêineres | `Dockerfile`, `docker-compose.yml` e `docker/php/entrada.sh` |
| 8.8 | Sem credencial nos arquivos de configuração | `.env.example` e `.env.docker.example` são modelos vazios; `.env` não é versionado |

---

## Cenários de demonstração (item 7)

| Cenário | Percurso | Verificado |
|---|---|---|
| 7.1 Profissional cria perfil e informa competência ligada a experiência | `/cadastrar` → `/meu-perfil/editar` → `/meu-perfil/experiencias/nova` | sim |
| 7.2 Empresa publica demanda com escopo, localização e requisitos | `/demandas/nova` | sim |
| 7.3 Sistema apresenta correspondências e explica os critérios | `/demandas/{id}/correspondencias` | sim |
| 7.4 Profissional manifesta interesse e a empresa visualiza o perfil | `/demandas/{id}/manifestar` → `/demandas/{id}/interessados` | sim |
| 7.5 Usuário corrige ou restringe dados e registra denúncia | `/privacidade` → `/denunciar/{entidade}/{id}` | sim |
| 7.6 Administrador visualiza trilha de auditoria e atua na moderação | `/admin/auditoria` → `/admin/moderacao/denuncias/{id}` | sim |
