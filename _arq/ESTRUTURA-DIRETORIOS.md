# CREA Pro-Link — Estrutura de diretórios

Atende ao item 8.3.2.f do Termo de Referência: descrição da estrutura de
diretórios do projeto e a finalidade de cada pasta principal.

---

## Árvore do projeto

```
crea-prolink/
├── _config.php                  Configuração principal (item 8.3.1)
├── .env.example                 Modelo de variáveis de ambiente, sem credenciais
├── .env.docker.example          Modelo de variáveis do ambiente Docker
├── .gitignore                   Exclui .env, vendor/ e conteúdo gerado
├── .dockerignore                Exclui do contexto de build o que não deve ir
├── composer.json                Dependências e autoload PSR-4
├── composer.lock                Versões exatas resolvidas
├── Dockerfile                   Imagem da aplicação (PHP 8.3 + Apache)
├── docker-compose.yml           Ambiente completo (app + banco + Mailpit)
├── README.md                    Apresentação do projeto
│
├── _arq/                        Arquivos de apoio e documentação (item 8.3.2)
│   ├── estrutura.sql            Criação de banco, tabelas, índices e chaves
│   ├── dados-iniciais.sql       Carga inicial
│   ├── README.md                Instalação, configuração, execução e atualização
│   ├── ARQUITETURA.md           Arquitetura, camadas e regras de negócio
│   ├── ESTRUTURA-DIRETORIOS.md  Este arquivo
│   ├── DEPENDENCIAS.md          Bibliotecas de terceiros, versões e licenças
│   ├── REQUISITOS.md            Rastreamento dos requisitos até o código
│   ├── SEGURANCA.md             Controles de segurança e dados pessoais
│   └── mer/                     Modelo Entidade-Relacionamento
│       ├── mer.svg              Vetorial, editável
│       ├── mer.png              Imagem
│       ├── mer.pdf              Documento
│       └── mer.md               Versão textual (diagrama Mermaid)
│
├── app/                         Código-fonte da aplicação
│   ├── rotas.php                Mapa de rotas: método, caminho, ação e perfis
│   │
│   ├── Core/                    Núcleo do MVC — infraestrutura sem regra de negócio
│   │   ├── Aplicacao.php        Ponto de entrada: sessão, cabeçalhos, exceções
│   │   ├── Roteador.php         Resolução de rota, CSRF e controle de perfil
│   │   ├── Requisicao.php       Entrada HTTP com leitura tipada
│   │   ├── Resposta.php         Saída HTTP e cabeçalhos de segurança
│   │   ├── Controlador.php      Base dos controladores
│   │   ├── Visao.php            Camada de apresentação sobre o Twig
│   │   ├── BancoDados.php       Conexão PDO e prepared statements
│   │   ├── Repositorio.php      Base dos repositórios: exclusão lógica e paginação
│   │   ├── Autenticacao.php     Autenticação e controle de perfis
│   │   ├── Sessao.php           Sessão endurecida e mensagens entre requisições
│   │   ├── Csrf.php             Token anti-CSRF
│   │   ├── Validador.php        Validação de consistência
│   │   ├── Formatador.php       Formatação e mascaramento para exibição
│   │   ├── Auditoria.php        Trilha de auditoria em banco
│   │   ├── Registro.php         Log técnico em arquivo
│   │   ├── Configuracao.php     Parâmetros editáveis pelo painel
│   │   ├── Ambiente.php         Leitura do .env
│   │   └── ExcecaoHttp.php      Exceção com código de status
│   │
│   ├── Controllers/             Camada de apresentação — sem SQL, sem HTML
│   │   ├── ControladorInicio.php
│   │   ├── ControladorPagina.php
│   │   ├── ControladorAutenticacao.php
│   │   ├── ControladorPainel.php
│   │   ├── ControladorBusca.php
│   │   ├── ControladorPerfil.php
│   │   ├── ControladorPortfolio.php
│   │   ├── ControladorExperiencia.php
│   │   ├── ControladorPrivacidade.php
│   │   ├── ControladorDemanda.php
│   │   ├── ControladorInteresse.php
│   │   ├── ControladorMensagem.php
│   │   ├── ControladorNotificacao.php
│   │   ├── ControladorDenuncia.php
│   │   ├── Admin/               Painel administrativo (RF06)
│   │   │   ├── ControladorPainelAdmin.php
│   │   │   ├── ControladorUsuarios.php
│   │   │   ├── ControladorModeracao.php
│   │   │   ├── ControladorAuditoria.php
│   │   │   ├── ControladorConfiguracoes.php
│   │   │   ├── ControladorNotificacoes.php
│   │   │   ├── ControladorLgpd.php
│   │   │   └── ControladorLixeira.php
│   │   └── Api/
│   │       └── ControladorApoio.php   Endpoints JSON de apoio à interface
│   │
│   ├── Repositories/            Acesso a dados — todo o SQL da aplicação
│   │   ├── RepositorioUsuario.php
│   │   ├── RepositorioPerfil.php
│   │   ├── RepositorioCompetencia.php
│   │   ├── RepositorioArt.php
│   │   ├── RepositorioCat.php
│   │   ├── RepositorioExperiencia.php
│   │   ├── RepositorioDemanda.php
│   │   ├── RepositorioBusca.php
│   │   ├── RepositorioInteresse.php
│   │   ├── RepositorioConversa.php
│   │   ├── RepositorioMensagem.php
│   │   ├── RepositorioDenuncia.php
│   │   ├── RepositorioNotificacao.php
│   │   ├── RepositorioAuditoria.php
│   │   └── RepositorioLgpd.php
│   │
│   └── Services/                Regras de negócio
│       ├── ClienteApiCrea.php               Contrato de acesso à API oficial
│       ├── ClienteApiCreaHttp.php           Transporte HTTP, token e registro
│       ├── ClienteApiCreaIndisponivel.php   Recusa explícita quando desligada
│       ├── NormalizadorApiCrea.php          Tradução do formato da resposta
│       ├── ExcecaoApiCrea.php               Falha de comunicação com a API
│       ├── ServicoIntegracaoCrea.php        Regras da integração (RF02/RF03)
│       ├── ServicoCompatibilizacao.php      Cálculo e explicação da aderência
│       ├── ServicoNotificacao.php           Fila e envio de notificações (RF07)
│       └── ServicoUpload.php                Recebimento de imagem de perfil
│
├── views/                       Templates Twig — nenhum PHP, conforme item 8.2
│   ├── layout/base.twig         Estrutura da página, navegação e rodapé
│   ├── partials/                Componentes reaproveitados
│   │   ├── aderencia.twig       Explicação dos critérios de compatibilização
│   │   ├── cartao-perfil.twig
│   │   ├── cartao-demanda.twig
│   │   ├── competencias-selecao.twig
│   │   ├── selo-registro.twig   Distinção entre registrado e não registrado
│   │   ├── paginacao.twig
│   │   ├── campo-erro.twig
│   │   └── lista-vazia.twig
│   ├── inicio.twig              Página inicial pública
│   ├── auth/                    Cadastro, acesso e recuperação
│   ├── painel/                  Painel do usuário, notificações e denúncia
│   ├── perfil/                  Perfil, portfólio, experiência e privacidade
│   ├── busca/                   Busca de profissionais e perfil público
│   ├── demandas/                Demandas, correspondências e interesses
│   ├── mensagens/               Caixa de entrada e conversa
│   ├── admin/                   Painel administrativo
│   ├── paginas/                 Páginas institucionais e documentos
│   ├── emails/base.twig         Template das notificações por e-mail
│   └── erros/erro.twig          Página de erro
│
├── public/                      Raiz de documentos do servidor web
│   ├── index.php                Front controller — único ponto de entrada
│   ├── .htaccess                Reescrita de URL e proteção de uploads
│   ├── robots.txt               Impede indexação das áreas autenticadas
│   ├── assets/
│   │   ├── css/app.css          Estilos próprios, sobre o Bootstrap
│   │   ├── js/app.js            Comportamentos da interface (jQuery)
│   │   ├── img/                 Imagens da aplicação (PATH_IMG)
│   │   └── vendor/              Bootstrap, jQuery e ícones servidos localmente
│   └── uploads/                 Imagens enviadas pelos usuários
│       └── .htaccess            Impede execução de qualquer arquivo aqui
│
├── storage/                     Conteúdo gerado em execução (fora de public/)
│   ├── logs/                    Logs da aplicação e do PHP
│   └── cache/                   Cache de templates compilados
│
├── docker/
│   ├── php/
│   │   ├── vhost.conf           Virtual host com raiz em public/
│   │   ├── php.ini              Ajustes de PHP, sessão e limites
│   │   └── entrada.sh           Preparação do ambiente na subida
│   └── mariadb/                 Reservado a scripts de banco do contêiner
│
├── tests/                       Verificações executáveis
│
└── vendor/                      Dependências do Composer (não versionado)
```

---

## Finalidade de cada pasta principal

| Pasta | Finalidade | Alcançável pelo navegador |
|---|---|---|
| `_arq/` | arquivos de apoio e documentação exigidos no item 8.3.2 | **não** |
| `app/` | todo o código-fonte da aplicação | **não** |
| `app/Core/` | infraestrutura do MVC, sem regra de negócio | **não** |
| `app/Controllers/` | camada de apresentação: coordena entrada, regra e saída | **não** |
| `app/Repositories/` | acesso a dados; concentra todo o SQL | **não** |
| `app/Services/` | regras de negócio e integrações | **não** |
| `views/` | templates Twig da interface | **não** |
| `public/` | raiz de documentos: front controller e recursos estáticos | **sim** |
| `public/uploads/` | imagens enviadas pelos usuários, sem execução | **sim** |
| `storage/` | logs e cache gerados em execução | **não** |
| `docker/` | configuração do ambiente de contêineres | **não** |
| `tests/` | verificações executáveis do projeto | **não** |
| `vendor/` | dependências instaladas pelo Composer | **não** |

Apenas `public/` é exposto. O `.htaccess` do projeto e o virtual host do
contêiner recusam explicitamente o acesso a `app/`, `views/`, `_arq/`,
`vendor/`, `storage/`, `docker/` e `tests/`, bem como aos arquivos `.env`,
`_config.php` e `composer.*`.

---

## Convenções de nomenclatura

**Idioma.** Classes, métodos, variáveis, rotas e tabelas em português, na mesma
língua do domínio e do Termo de Referência. Termos técnicos consagrados
permanecem na forma original: `PDO`, `Twig`, `token`, `hash`.

**Classes.** `PascalCase`, uma classe por arquivo, nome do arquivo igual ao da
classe. Prefixo indicando o papel: `Controlador*`, `Repositorio*`, `Servico*`,
`Cliente*`.

**Métodos e variáveis.** `camelCase`, verbo no infinitivo para ação
(`validarRegistro`), substantivo para consulta (`competencias`).

**Templates.** `kebab-case.twig`, agrupados por área. Parciais em `partials/`,
sempre incluídas com `only` para deixar explícito o que recebem.

**Banco de dados.** Conforme o item 8.6: tabela com prefixo de módulo
(`sis_`, `pro_`), campo com prefixo de três letras da tabela, chave primária
`<prefixo>_id`, restrições nomeadas com `pk_`, `fk_`, `uk_` e `idx_`, e campos
de controle `xxx_dt_registro`, `xxx_log` e `xxx_status`.
