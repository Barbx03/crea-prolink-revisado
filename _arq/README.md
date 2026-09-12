# CREA Pro-Link — Instalação, configuração e execução

Protótipo desenvolvido para o **Desafio CREA Pro-Link** (CREA-AM), conforme o
Anexo I — Termo de Referência para Desenvolvimento e Arquitetura de Software.

Plataforma de aproximação entre profissionais registrados no Sistema
Confea/Crea, empresas, instituições e contratantes de serviços técnicos em
engenharia, agronomia e geociências.

---

## 1. Requisitos mínimos

### Execução com Docker (recomendada)

| Item | Versão mínima |
|---|---|
| Docker Engine | 24.0 |
| Docker Compose | v2.20 |

Nada mais precisa estar instalado no host: PHP, MariaDB, Composer e o servidor
de e-mail de teste sobem em contêineres.

### Execução direta no host (alternativa)

| Item | Versão mínima | Observação |
|---|---|---|
| PHP | 8.2 | extensões `pdo_mysql`, `mbstring`, `intl`, `gd`, `curl`, `json`, `openssl` |
| MariaDB | 10.11 | `utf8mb4` / `utf8mb4_unicode_ci` |
| Composer | 2.5 | gerenciamento de dependências |
| Apache | 2.4 | com `mod_rewrite`; ou Nginx com regra equivalente |

---

## 2. Instalação com Docker

```bash
# 1. Clonar o repositório
git clone <endereco-do-repositorio> crea-prolink
cd crea-prolink

# 2. Criar o arquivo de ambiente a partir do modelo
cp .env.docker.example .env.docker

# 3. Editar .env.docker e definir, no mínimo:
#      DB_SENHA, DB_SENHA_ROOT
#      CREA_API_URL, CREA_API_TOKEN   (token individual da equipe)

# 4. Subir o ambiente
docker compose --env-file .env.docker up -d --build

# 5. Acompanhar a preparação do banco na primeira subida
docker compose logs -f app
```

Ao terminar, a aplicação responde em **http://localhost:8080**.

O contêiner da aplicação, na primeira execução:

1. gera o arquivo `.env` a partir de `.env.example`, com uma `APP_CHAVE` própria;
2. aguarda o MariaDB aceitar conexões;
3. aplica `_arq/estrutura.sql` e `_arq/dados-iniciais.sql` caso o banco ainda
   esteja vazio;
4. ajusta as permissões dos diretórios de escrita;
5. entrega o controle ao Apache.

### Serviços do ambiente

| Serviço | Endereço | Para que serve |
|---|---|---|
| Aplicação | http://localhost:8080 | a plataforma |
| Mailpit | http://localhost:8025 | caixa de entrada local: mostra as notificações do RF07 sem provedor externo |
| MariaDB | `127.0.0.1:3307` | inspeção do banco com cliente externo |

### Acesso administrativo inicial

| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | `admin@prolink.local` | `Admin@2026` |

**Troque esta senha no primeiro acesso.** Ela existe apenas para permitir a
entrada inicial no painel administrativo.

A carga inicial traz também dois contratantes de demonstração, úteis para
percorrer os cenários do item 7 do Termo de Referência:

| Perfil | E-mail | Senha |
|---|---|---|
| Construtora (PJ, sem registro no CREA) | `contratante@prolink.local` | `Senha@123` |
| Instituição de pesquisa (PJ, sem registro) | `instituicao@prolink.local` | `Senha@123` |

Não há profissional pré-cadastrado na carga inicial, e isso é deliberado: o
perfil de profissional registrado depende de validação na API oficial do
CREA-AM, e o item 8.4 do Termo de Referência veda a criação de base própria
para simular esses dados.

---

## 3. Instalação direta no host

```bash
# 1. Dependências PHP
composer install

# 2. Ambiente
cp .env.example .env
php -r 'echo "APP_CHAVE=", bin2hex(random_bytes(32)), PHP_EOL;'   # copie para o .env

# 3. Banco de dados
mariadb -u root -p < _arq/estrutura.sql
mariadb -u root -p < _arq/dados-iniciais.sql

# 4. Permissões de escrita
mkdir -p storage/logs storage/cache public/uploads
chmod -R 775 storage public/uploads

# 5. Servidor web: aponte a raiz de documentos para public/
#    Em desenvolvimento, o servidor embutido do PHP resolve:
php -S 127.0.0.1:8080 -t public public/index.php
```

Preencha no `.env` os parâmetros de banco (`DB_*`) e da API oficial
(`CREA_API_*`).

---

## 4. Configuração

### 4.1 Arquivo `.env`

Todas as informações sensíveis e específicas de ambiente ficam no `.env`, que
**não é versionado**. O repositório traz apenas `.env.example`, sem credencial
real, conforme o item 8.3.1.k do Termo de Referência.

| Variável | Para que serve |
|---|---|
| `APP_AMBIENTE` | `desenvolvimento`, `homologacao` ou `producao`. Em produção, erros nunca aparecem na tela |
| `APP_DEBUG` | exibição de detalhes técnicos de erro. Deixe `false` fora de desenvolvimento |
| `APP_URL` | endereço público da aplicação, usado nos links dos e-mails |
| `APP_CHAVE` | chave da instalação, gerada uma única vez |
| `DB_*` | conexão com o MariaDB |
| `CREA_API_URL` | URL base da API oficial do desafio |
| `CREA_API_TOKEN` | token de acesso individual da equipe |
| `CREA_API_DRIVER` | `http` consome a API oficial; `offline` recusa as consultas com mensagem explícita |
| `CREA_API_ROTA_*` | caminho de cada recurso, ajustável conforme a documentação recebida |
| `MAIL_*` | servidor SMTP das notificações |
| `SESSAO_COOKIE_SEGURO` | ativar somente atrás de HTTPS |
| `LOGIN_MAX_TENTATIVAS` | tentativas antes do bloqueio temporário |

### 4.2 Arquivo `_config.php`

Fica na raiz da aplicação e centraliza as configurações gerais (item 8.3.1):
URLs, caminhos físicos (`PATH_*`, incluindo `PATH_IMG`), parâmetros de banco e
de serviços externos, constantes globais, fuso horário (`America/Manaus`),
conjunto de caracteres (UTF-8) e configuração de e-mail. Ele **lê** do `.env`
e não guarda credencial alguma.

### 4.3 Painel administrativo

Depois de instalado, o administrador ajusta pelo painel, sem novo deploy:

- **SMTP** — servidor, porta, segurança, credenciais e remetente, com botão de
  envio de teste;
- **API do CREA-AM** — URL, token, tempo limite e cache, com botão de consulta
  de teste;
- **Pesos da compatibilização** — quanto cada critério pesa no cálculo de
  aderência;
- **Plataforma** — nome, e-mail de contato, itens por página e abertura de
  novos cadastros.

Valores sensíveis aparecem em branco na interface e só são gravados quando o
administrador digita um valor novo: o que está salvo nunca é exibido de volta.

---

## 5. Integração com a API oficial do CREA-AM

O consumo da API oficial é obrigatório (item 8.4) e é o único caminho pelo qual
dados de registro profissional, ARTs e CATs entram na plataforma. Não existe
tela, rota ou comando que permita digitá-los à mão.

Para habilitar:

1. obtenha o token individual da equipe em
   <https://desafio-prolink.crea-am.org.br>;
2. defina `CREA_API_URL`, `CREA_API_TOKEN` e `CREA_API_DRIVER=http` no `.env`
   (ou informe URL e token pelo painel administrativo);
3. confira os caminhos em `CREA_API_ROTA_*` conforme a documentação técnica
   recebida.

As quatro consultas previstas no Termo de Referência estão implementadas:

| Consulta | Parâmetro | Onde aparece na interface |
|---|---|---|
| Profissional registrado | CPF | cadastro e validação de registro |
| Empresa registrada | CNPJ | cadastro e validação de registro |
| ARTs do profissional | RNP e número da ART | associação de ART ao portfólio |
| CATs do profissional | RNP e número da CAT | sincronização de CATs |

Cada chamada é registrada em `sis_api_consultas` com recurso, duração,
resultado e mensagem, e pode ser auditada em **Administração → Integração
CREA-AM**. Identificadores são mascarados na trilha: nem CPF nem CNPJ completo
são gravados.

Enquanto a integração não estiver configurada, a aplicação **informa a
indisponibilidade** ao usuário. Ela nunca substitui a resposta da API por dado
inventado.

### Tolerância ao formato da resposta

A documentação técnica da API é entregue apenas às equipes habilitadas. Para
não acoplar a aplicação a um único formato, cada campo interno declara uma
lista de nomes aceitos em `app/Services/NormalizadorApiCrea.php` — comparados
sem distinção de acento, maiúsculas ou separadores. Ao receber a documentação,
basta conferir ou acrescentar um apelido nesse arquivo; nenhuma outra camada
muda. Campo ausente na resposta permanece nulo, nunca preenchido por suposição.

---

## 6. Execução dos cenários de demonstração

Os seis cenários do item 7 do Termo de Referência, na ordem:

1. **Profissional cria perfil e informa competência ligada a experiência**
   Cadastre-se como *Profissional registrado no CREA-AM* → o CPF é validado na
   API → **Meu perfil → Editar** para título, resumo e competências →
   **Adicionar experiência**, vinculando-a a uma ART validada (a experiência
   passa a ser exibida como *comprovada*).

2. **Empresa publica demanda com escopo, localização e requisitos**
   Entre como contratante → **Publicar demanda** → informe escopo, local,
   modalidade, competências exigidas (com peso e obrigatoriedade), experiência
   mínima, faixa de orçamento e prazo.

3. **Sistema apresenta correspondências e explica os critérios**
   Ao publicar, a plataforma abre **Correspondências**: perfis em ordem de
   aderência, com o peso de cada critério, o quanto foi obtido, as competências
   atendidas e as faltantes.

4. **Profissional manifesta interesse e a empresa visualiza o perfil**
   Na demanda, **Manifestar interesse** → o contratante recebe notificação e vê
   a manifestação em **Interessados**, onde abre o perfil completo e responde
   (em negociação, selecionado ou recusado).

5. **Usuário corrige ou restringe dados e registra denúncia**
   **Privacidade e meus dados**: visibilidade do perfil, o que exibir,
   consentimentos por finalidade, exportação dos dados e requisições de
   correção, anonimização ou eliminação. A denúncia parte do botão *Denunciar*
   presente em perfis, demandas e conversas.

6. **Administrador visualiza trilha de auditoria e atua na moderação**
   **Administração → Trilha de auditoria** (com filtros e exportação em CSV) e
   **Administração → Moderação**, onde a denúncia é julgada com parecer e
   providência, notificando as partes.

---

## 7. Atualização da solução

```bash
git pull
docker compose --env-file .env.docker up -d --build
```

O contêiner detecta que o banco já contém as tabelas e **preserva os dados**.
Alterações de estrutura devem ser aplicadas por script incremental próprio,
versionado em `_arq/`. O `estrutura.sql` usa `CREATE TABLE IF NOT EXISTS` e
pode ser reexecutado sem destruir dados.

Para recriar o ambiente do zero, incluindo o banco:

```bash
docker compose --env-file .env.docker down -v
docker compose --env-file .env.docker up -d --build
```

> `down -v` remove o volume do banco e apaga todos os dados.

---

## 8. Verificação da instalação

```bash
# Sintaxe de todos os arquivos PHP
composer lint

# Estrutura aplicada: 23 tabelas esperadas
docker compose exec mariadb mariadb -u root -p -N -e \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='crea_prolink'"

# Registros da carga inicial
docker compose exec mariadb mariadb -u root -p crea_prolink -e \
  "SELECT 'areas', COUNT(*) FROM pro_areas
   UNION ALL SELECT 'competencias', COUNT(*) FROM pro_competencias
   UNION ALL SELECT 'termos', COUNT(*) FROM sis_termos
   UNION ALL SELECT 'configuracoes', COUNT(*) FROM sis_configuracoes"
```

Resultado esperado: 23 tabelas, 3 áreas, 37 competências, 2 termos e 22
parâmetros de configuração.

---

## 9. Diagnóstico de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| `Serviço temporariamente indisponível` | banco inacessível | `docker compose logs mariadb`; conferir `DB_*` no `.env` |
| Página em branco | erro de PHP com `APP_DEBUG=false` | ver `storage/logs/php-erros.log` |
| CSS e imagens não carregam | raiz de documentos apontando para a pasta do projeto | apontar para `public/` |
| `A página expirou` ao enviar formulário | sessão expirada ou cookie bloqueado | recarregar a página; conferir `SESSAO_TEMPO_MINUTOS` |
| Notificações não saem | SMTP desativado ou mal configurado | **Administração → Configurações**, botão de teste; a fila em **Notificações** mostra o erro exato |
| `A integração não está configurada` | falta URL ou token da API | preencher `CREA_API_*` e usar `CREA_API_DRIVER=http` |
| `A API oficial recusou o token` | token inválido ou expirado | obter novo token na plataforma do desafio |
| Upload de foto recusado | arquivo acima de 2 MB ou fora de JPEG/PNG/WebP | reduzir ou converter a imagem |

Registros úteis:

- `storage/logs/aplicacao-AAAA-MM-DD.log` — eventos técnicos da aplicação;
- `storage/logs/php-erros.log` — erros do PHP;
- tabela `sis_auditoria` — trilha de auditoria das ações de negócio;
- tabela `sis_api_consultas` — histórico das chamadas à API oficial.

---

## 10. Documentação complementar

| Arquivo | Conteúdo |
|---|---|
| `_arq/ARQUITETURA.md` | arquitetura, camadas, decisões de projeto e regras de negócio |
| `_arq/ESTRUTURA-DIRETORIOS.md` | finalidade de cada diretório e arquivo |
| `_arq/DEPENDENCIAS.md` | bibliotecas de terceiros, versões e licenças |
| `_arq/REQUISITOS.md` | rastreamento de cada requisito do Termo de Referência até o código |
| `_arq/SEGURANCA.md` | controles de segurança e tratamento de dados pessoais |
| `_arq/estrutura.sql` | criação do banco, tabelas, índices e relacionamentos |
| `_arq/dados-iniciais.sql` | carga inicial |
| `_arq/mer/` | Modelo Entidade-Relacionamento em SVG, PNG e PDF |
