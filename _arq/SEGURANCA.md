# CREA Pro-Link — Segurança e tratamento de dados pessoais

Detalha como cada controle exigido no item 8.5 do Termo de Referência foi
implementado, e como a plataforma trata dados pessoais à luz da Lei
n. 13.709/2018.

---

## 1. Autenticação (item 8.5.a)

| Controle | Implementação |
|---|---|
| Verificação de senha | `password_verify()` sobre o hash gravado; nenhuma comparação de texto |
| Resposta uniforme | e-mail inexistente e senha incorreta produzem a mesma mensagem; para e-mail inexistente, um `password_verify()` contra hash inválido iguala o tempo de resposta e evita distinguir contas pela latência |
| Bloqueio por tentativas | após `LOGIN_MAX_TENTATIVAS` (padrão 5), bloqueio temporário de `LOGIN_BLOQUEIO_MINUTOS` (padrão 15), contado no banco |
| Fixação de sessão | `session_regenerate_id(true)` e renovação do token CSRF a cada autenticação |
| Revalidação contínua | a cada requisição, a sessão é confrontada com o banco: conta bloqueada ou excluída durante o uso perde o acesso na hora |
| Expiração por inatividade | `SESSAO_TEMPO_MINUTOS` (padrão 120), verificada no servidor |
| Cookie de sessão | `HttpOnly`, `SameSite=Lax`, `Secure` quando `SESSAO_COOKIE_SEGURO=true`, com `use_strict_mode` e `use_only_cookies` |
| Encerramento | destrói a sessão no servidor e expira o cookie no cliente |

## 2. Armazenamento de senhas (item 8.5.b)

`password_hash()` com `PASSWORD_DEFAULT` — bcrypt com custo 12 no PHP 8.3,
acompanhando a evolução do padrão da linguagem sem alteração de código.

`password_needs_rehash()` é verificado a cada autenticação bem-sucedida: quando
o algoritmo ou o custo padrão mudam, o hash é reescrito com a senha que o
usuário acabou de digitar, sem pedir nada a ele.

Senha nunca aparece em log, na trilha de auditoria, no repopular de formulário
ou na exportação de dados: `Core\Auditoria` e `Core\Registro` mascaram campos
cujo nome contenha `senha`, `password`, `token`, `secret`, `authorization`,
`cpf` ou `cnpj`, e `Sessao::guardarFormulario()` descarta os campos de senha
antes de gravar.

## 3. Proteção contra SQL Injection (item 8.5.c)

Todo acesso ao banco passa por `Core\BancoDados`, que usa exclusivamente
prepared statements com parâmetros vinculados e tipados. `ATTR_EMULATE_PREPARES`
está desligado, de modo que a preparação acontece no servidor de banco.

**Não existe na base de código nenhuma concatenação de entrada do usuário em
SQL.** Onde o SQL precisa variar, a variação é estrutural e vem de conjunto
fechado:

- listas de identificadores em cláusula `IN` são filtradas antes contra o banco
  (`RepositorioCompetencia::filtrarValidos()`), de modo que só inteiros
  existentes entram na consulta;
- `LIMIT` e `OFFSET`, que não aceitam placeholder no MariaDB, recebem inteiros
  validados por faixa em `Repositorio::paginacao()`;
- ordenação vem de `match` com valores fixos, nunca do texto recebido.

Verificação: `grep -rn '\$_GET\|\$_POST' app/` retorna apenas
`Core\Requisicao`, o único ponto que lê a entrada bruta.

## 4. Proteção contra XSS (item 8.5.d)

**Escape na saída.** O Twig escapa automaticamente toda variável impressa. O
único ponto com `|raw` é o conteúdo dos Termos de Uso e da Política de
Privacidade, que são documentos institucionais gravados pela administração, não
conteúdo de usuário.

**Política de conteúdo.** `Content-Security-Policy` com `default-src 'self'`,
sem `unsafe-inline` para script. Não há atributo `onclick` nem `<script>`
inline em nenhuma view: todo o comportamento está em `public/assets/js/app.js`.
Assim, mesmo que um payload chegue à página, não há como executá-lo.

**Limpeza na entrada.** `Core\Requisicao` remove bytes nulos e caracteres de
controle de tudo o que chega, o que impede a quebra de contexto por caractere
invisível.

**Cabeçalhos complementares.** `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy` restritiva e `Strict-Transport-Security` quando em HTTPS.

**Upload.** O tipo da imagem é determinado pelo conteúdo (`getimagesize()`), não
pela extensão nem pelo cabeçalho enviado, o nome final é gerado pela aplicação,
e o diretório de uploads tem execução desligada no Apache.

## 5. Proteção contra CSRF (item 8.5.e)

Token de 32 bytes por sessão, comparado com `hash_equals()` em tempo constante.

A validação acontece **no roteador**, antes de o controlador existir, para toda
requisição `POST`, `PUT`, `PATCH` ou `DELETE`. Uma rota nova está protegida por
ser declarada; nenhuma proteção depende de o programador se lembrar dela.
Requisição sem token válido é recusada com HTTP 419, registrada na auditoria
com severidade de alerta.

O token é renovado na autenticação e no encerramento de sessão. O cookie de
sessão com `SameSite=Lax` acrescenta uma segunda barreira.

## 6. Controle de perfis de acesso (item 8.5.f)

Quatro perfis: `ADMIN`, `PROFISSIONAL`, `EMPRESA` e `TERCEIRO`. Cada rota
declara quem pode acessá-la em `app/rotas.php`, e a verificação também ocorre no
roteador.

**Autorização horizontal.** Além do perfil, o acesso ao registro individual é
verificado: `Controlador::exigirPropriedade()` compara o dono do registro com o
usuário autenticado. Tentativa de acesso a registro de terceiro é recusada e
registrada na auditoria.

**Limites do próprio administrador.** O administrador não pode conceder
manualmente o perfil de profissional ou empresa registrada — isso depende da
API oficial —, não pode remover o próprio perfil administrativo, não pode
excluir a própria conta, e **não lê conversas privadas**: a moderação de
mensagem acontece por denúncia, que traz o conteúdo específico denunciado.

## 7. Registro de auditoria (item 8.5.g)

`sis_auditoria` é tratada como *append only*: a aplicação insere, nunca altera
nem remove. Cada evento registra usuário (nulo quando anônimo), ação, entidade
e identificador afetados, descrição, estado anterior e posterior em JSON, IP,
agente do usuário, rota, severidade e data com milissegundos.

`Auditoria::alteracao()` compara os estados e grava **apenas os campos que
mudaram**, o que mantém a trilha legível.

Falha ao gravar a auditoria não interrompe a operação do usuário: o incidente
vai para o log em arquivo. A operação principal não pode ser perdida por um
problema de registro.

Ações registradas: autenticação e tentativa malsucedida, cadastro, alteração,
alteração de senha, exclusão lógica, restauração, moderação, consulta à API,
validação de registro, associação de ART, exportação de dados, exportação da
trilha, concessão e revogação de consentimento, requisição de titular,
anonimização, token CSRF inválido e acesso negado.

O painel oferece consulta com filtros por ação, usuário, entidade, registro,
severidade, período e texto livre, além de exportação em CSV — que também é
auditada.

---

## 8. Tratamento de dados pessoais (LGPD)

### 8.1 Minimização

Somente o necessário é coletado. Documento, contato, RNP e valor-hora ficam
**ocultos por padrão** e só aparecem se o titular autorizar. Autorizado o
documento, ainda assim é exibido mascarado. A trilha de consultas à API registra
identificadores mascarados, nunca CPF ou CNPJ completos.

### 8.2 Consentimento por finalidade

Cinco finalidades separadas, cada uma com autorização própria:

| Finalidade | O que autoriza |
|---|---|
| `TERMOS_USO` | aceite dos Termos de Uso |
| `POLITICA_PRIVACIDADE` | aceite da Política de Privacidade |
| `DADOS_CREA` | consulta e exibição de registro, ARTs e CATs |
| `PERFIL_PUBLICO` | exibição do perfil em buscas públicas |
| `COMUNICACOES` | recebimento de e-mails da plataforma |

Cada evento de concessão ou revogação é gravado com versão do termo, data, hora,
IP e agente. O histórico é imutável, e a situação atual é o evento mais recente
de cada finalidade.

A revogação tem efeito imediato e concreto: sem `DADOS_CREA`, a consulta à API
é recusada; sem `PERFIL_PUBLICO`, o perfil é ocultado e o recebimento de
contatos desativado no mesmo passo; sem `COMUNICACOES`, o evento continua
registrado na central de notificações, mas nenhum e-mail é enviado.

### 8.3 Direitos do titular (art. 18)

| Direito | Como é exercido |
|---|---|
| Confirmação e acesso | painel de privacidade mostra dados, consentimentos e atividade recente da conta |
| Correção | edição direta do que é editável, e requisição formal com protocolo para o que depende de análise |
| Anonimização, bloqueio ou eliminação | requisição com protocolo; o administrador executa a anonimização de forma auditada |
| Portabilidade | exportação em JSON de cadastro, perfil, competências, experiências, acervo, demandas, manifestações, consentimentos e requisições |
| Informação sobre compartilhamento | Política de Privacidade versionada |
| Revogação do consentimento | painel de privacidade, com efeito imediato |

Toda requisição recebe protocolo, resposta registrada e notificação ao titular.

### 8.4 Anonimização

`RepositorioUsuario::anonimizar()` substitui nome, e-mail e senha por valores
irreversíveis, remove documento, RNP, telefone e cidade, e marca a conta como
excluída. O identificador numérico permanece, para que a trilha de auditoria
exigida por lei continue coerente — sem identificar a pessoa.

Antes de anonimizar, o titular é notificado no último endereço válido, e o
perfil é ocultado das buscas.

### 8.5 Exclusão lógica e retenção

Nenhum registro é removido fisicamente. Conforme o item 8.6.j, a exclusão marca
`xxx_status = 'X'`, o registro sai das telas operacionais e permanece
recuperável pela lixeira administrativa. Isso preserva integridade histórica,
rastreabilidade e a possibilidade de recuperação — e é o que permite atender ao
direito de eliminação sem destruir a auditoria.

---

## 9. Segregação de ambientes

| Aspecto | Desenvolvimento | Produção |
|---|---|---|
| `APP_AMBIENTE` | `desenvolvimento` | `producao` |
| Erros na tela | detalhados, com rastro | mensagem genérica; detalhe apenas no log |
| Faixa de identificação | exibida no topo de toda página | ausente |
| Cookie `Secure` | desligado | ligado, atrás de HTTPS |
| HSTS | ausente | presente |
| Cache de templates | desligado | ligado |
| Credenciais | `.env` local | `.env` do ambiente, fora do controle de versão |

## 10. Menor privilégio

- o banco é acessado por usuário próprio da aplicação, nunca `root`;
- no contêiner, apenas `storage/` e `public/uploads/` pertencem ao usuário do
  servidor web; o restante é somente leitura para ele;
- o diretório de uploads tem interpretador desligado e listagem bloqueada;
- o virtual host recusa explicitamente `app/`, `views/`, `_arq/`, `vendor/`,
  `storage/`, `docker/` e `tests/`, bem como `.env`, `_config.php` e
  `composer.*`;
- o MariaDB fica publicado apenas em `127.0.0.1`, não na rede;
- `expose_php` e a assinatura do servidor estão desligados.

---

## 11. Verificação dos controles

```bash
# Nenhuma leitura de entrada bruta fora de Core\Requisicao
grep -rn '\$_GET\|\$_POST\|\$_REQUEST' app/ | grep -v 'Core/Requisicao.php'

# Nenhuma view contém PHP
grep -rln '<?php\|<?=' views/

# Nenhum controlador contém SQL
grep -rlnE '\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b' app/Controllers/

# Toda rota que altera estado exige CSRF (validação centralizada)
grep -n 'Csrf::valido' app/Core/Roteador.php

# Senhas sempre por password_hash()
grep -rn 'password_hash' app/

# Nenhum script inline nas views
grep -rn 'onclick=\|onchange=\|<script>' views/
```

As quatro primeiras verificações devem retornar vazio, exceto a primeira, que
retorna apenas o próprio arquivo excluído do filtro.
