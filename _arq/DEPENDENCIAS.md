# CREA Pro-Link — Bibliotecas, frameworks e dependências

Atende ao item 8.3.2.e do Termo de Referência: relação das bibliotecas,
frameworks e dependências de terceiros utilizadas, com versões e licenças.

Todas as licenças relacionadas são compatíveis com uso institucional (item
8.1.3, parágrafo final): permitem uso, modificação e redistribuição em
software próprio, sem obrigação de abertura do código da aplicação.

---

## 1. Plataforma de execução

| Componente | Versão exigida | Versão do ambiente entregue |
|---|---|---|
| PHP | 8.2 ou superior (item 8.1.1.a) | 8.3 (imagem `php:8.3-apache`) |
| MariaDB | 10.11 ou superior (item 8.1.2.a) | 10.11 (imagem `mariadb:10.11`) |
| Apache HTTP Server | — | 2.4, com `mod_rewrite`, `headers`, `expires` e `deflate` |
| Composer | — | 2.x (item 8.1.1.d) |

### Extensões PHP requeridas

| Extensão | Para que é usada |
|---|---|
| `pdo` e `pdo_mysql` | acesso ao MariaDB por prepared statements |
| `mbstring` | tratamento de texto em UTF-8 |
| `json` | serialização na auditoria, no payload da API e na portabilidade |
| `curl` | consumo da API oficial do CREA-AM |
| `intl` | comparação e formatação sensíveis a idioma |
| `gd` | verificação e tratamento das imagens de perfil |
| `openssl` | transporte seguro e geração de bytes aleatórios |
| `session` | sessão do usuário |
| `filter` | validação de e-mail, URL e endereço IP |

---

## 2. Dependências PHP (Composer)

### Diretas

| Pacote | Versão instalada | Licença | Para que serve |
|---|---|---|---|
| `twig/twig` | 3.28.0 | BSD-3-Clause | mecanismo de templates (item 8.2), com escape automático na saída |
| `vlucas/phpdotenv` | 5.7.0 | BSD-3-Clause | leitura das variáveis de ambiente do `.env` (item 8.3.1.j) |
| `phpmailer/phpmailer` | 6.12.0 | LGPL-2.1-only | envio das notificações por SMTP (RF07) |

### Transitivas

| Pacote | Versão | Licença | Origem |
|---|---|---|---|
| `symfony/deprecation-contracts` | 3.7.1 | MIT | Twig |
| `symfony/polyfill-ctype` | 1.37.0 | MIT | phpdotenv |
| `symfony/polyfill-mbstring` | 1.38.2 | MIT | Twig |
| `symfony/polyfill-php80` | 1.37.0 | MIT | phpdotenv |
| `graham-campbell/result-type` | 1.2.0 | MIT | phpdotenv |
| `phpoption/phpoption` | 1.10.0 | Apache-2.0 | phpdotenv |

Nove pacotes no total, três deles escolhas diretas. As versões exatas estão
fixadas em `composer.lock`, de modo que a instalação é reprodutível.

> **Sobre a LGPL do PHPMailer.** A LGPL-2.1 permite uso em software
> proprietário ou de licença distinta desde que a própria biblioteca, se
> modificada, tenha suas alterações publicadas. O PHPMailer é utilizado aqui
> sem qualquer modificação, consumido como dependência via Composer. Caso o
> CREA-AM prefira evitar a LGPL, a substituição é localizada: o envio de
> e-mail está encapsulado em `ServicoNotificacao::montarMailer()`.

---

## 3. Bibliotecas de interface

Exigidas no item 8.1.3 e **servidas localmente**, em
`public/assets/vendor/`. A política de conteúdo da aplicação só admite
recursos da própria origem, o que neutraliza a exploração de XSS refletido;
além disso, o ambiente funciona sem acesso à internet.

| Biblioteca | Versão | Licença | Arquivo |
|---|---|---|---|
| Bootstrap | 5.3.3 | MIT | `vendor/bootstrap.min.css`, `vendor/bootstrap.bundle.min.js` |
| jQuery | 3.7.1 | MIT | `vendor/jquery.min.js` |
| Bootstrap Icons | 1.11.3 | MIT | `vendor/bootstrap-icons.css` e `vendor/fonts/` |

Nenhum outro recurso externo é carregado: não há CDN, fonte remota, mapa,
rastreador ou script de terceiros. Todo o CSS e o JavaScript próprios estão em
`public/assets/css/app.css` e `public/assets/js/app.js`.

### Progressividade

A interface funciona integralmente sem JavaScript. O script adiciona apenas
conveniências: contador de caracteres, filtro na lista de competências,
consulta prévia à API oficial durante o cadastro, alternância de campos
conforme o tipo de cadastro, confirmação de ações destrutivas e rolagem
automática da conversa. Formulários e navegação são HTML nativo.

---

## 4. Ferramentas do ambiente de execução

| Ferramenta | Versão | Licença | Papel |
|---|---|---|---|
| Docker Engine | 24+ | Apache-2.0 | contêineres (item 8.8.f) |
| Docker Compose | v2.20+ | Apache-2.0 | orquestração do ambiente |
| Mailpit | mais recente | MIT | servidor SMTP local, para conferir as notificações do RF07 sem provedor externo |

O Mailpit não é dependência da aplicação: é comodidade do ambiente de
avaliação. Em produção, o SMTP institucional é informado no `.env` ou no painel
administrativo.

---

## 5. Verificação e reprodutibilidade

```bash
# Instalação exatamente nas versões travadas
composer install

# Verificação de sintaxe de todos os arquivos PHP
composer lint

# Relação de licenças
composer licenses --no-dev

# Auditoria de vulnerabilidades conhecidas
composer audit
```

---

## 6. O que não foi utilizado, e por quê

| Alternativa | Por que não |
|---|---|
| Laravel, Symfony ou CodeIgniter | o Termo de Referência exige `_config.php` na raiz, pasta `_arq/` e nomenclatura própria de tabelas; um framework de convenções fortes exigiria contorná-las em vários pontos |
| ORM (Eloquent, Doctrine) | o item 8.1.1.b pede separação por repositório ou DAO; SQL explícito mantém as consultas auditáveis, o que importa em solução avaliada tabela por tabela |
| React, Vue ou Angular | o item 8.1.3 pede HTML5, CSS3, JavaScript, Bootstrap 5 e jQuery; uma interface renderizada no cliente também prejudicaria acessibilidade e indexação |
| Recursos via CDN | a política de conteúdo restringe a origem própria, e o ambiente precisa funcionar sem acesso externo |
| Biblioteca de gráficos | os indicadores gerenciais usam tabelas com barras em CSS, que são acessíveis a leitor de tela e não acrescentam dependência |
