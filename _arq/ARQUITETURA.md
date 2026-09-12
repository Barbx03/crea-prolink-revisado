# CREA Pro-Link — Documentação de arquitetura

Atende ao item 8.3.2.d do Termo de Referência: informações necessárias ao
entendimento da arquitetura, estrutura de diretórios, componentes, módulos,
integrações e principais regras de negócio implementadas.

---

## 1. Visão geral

Aplicação web em PHP 8.2+, arquitetura MVC própria, banco MariaDB, camada de
apresentação em Twig e interface em Bootstrap 5 com jQuery.

```
                       ┌──────────────────────────────────┐
   Navegador  ────────▶│  public/index.php                │
                       │  front controller único          │
                       └───────────────┬──────────────────┘
                                       │
                       ┌───────────────▼──────────────────┐
                       │  App\Core\Aplicacao              │
                       │  sessão · cabeçalhos · exceções  │
                       └───────────────┬──────────────────┘
                                       │
                       ┌───────────────▼──────────────────┐
                       │  App\Core\Roteador               │
                       │  CSRF · perfil de acesso         │
                       └───────────────┬──────────────────┘
                                       │
     ┌─────────────────────────────────▼─────────────────────────────────┐
     │  Apresentação  —  app/Controllers/                                │
     │  sem SQL, sem HTML: coordena entrada, regra e saída               │
     └───────┬───────────────────────────────────────────┬───────────────┘
             │                                           │
   ┌─────────▼──────────┐                     ┌──────────▼─────────────┐
   │ Regras de negócio  │                     │  Apresentação          │
   │ app/Services/      │                     │  App\Core\Visao (Twig) │
   │ compatibilização   │                     │  views/*.twig          │
   │ integração CREA-AM │                     │  escape automático     │
   │ notificações       │                     └────────────────────────┘
   └─────────┬──────────┘
             │
   ┌─────────▼────────────────────────────────────────────────────────────┐
   │  Acesso a dados  —  app/Repositories/ sobre App\Core\Repositorio     │
   │  prepared statements sempre · exclusão lógica · auditoria            │
   └─────────┬────────────────────────────────────────────────────────────┘
             │
   ┌─────────▼──────────┐        ┌────────────────────────────────────────┐
   │  MariaDB 10.11     │        │  API oficial do CREA-AM (REST)         │
   │  23 tabelas        │        │  profissionais · empresas · ARTs · CATs│
   └────────────────────┘        └────────────────────────────────────────┘
```

As três camadas exigidas no item 8.2 estão separadas fisicamente em
diretórios distintos, e a separação é verificável: **nenhum arquivo de
`views/` contém PHP** e **nenhum arquivo de `app/Controllers/` contém SQL**.

---

## 2. Componentes do núcleo (`app/Core/`)

| Componente | Responsabilidade |
|---|---|
| `Aplicacao` | ponto de entrada: inicia a sessão, aplica cabeçalhos de segurança, despacha a rota e converte qualquer exceção em resposta adequada |
| `Roteador` | resolve a rota e, **antes de instanciar o controlador**, valida o token CSRF e o perfil de acesso |
| `Requisicao` | encapsula a entrada, com leitura tipada e remoção de caracteres de controle |
| `Resposta` | emite a resposta e os cabeçalhos de segurança, incluindo a política de conteúdo |
| `Controlador` | base dos controladores: renderização, redirecionamento, paginação e verificação de propriedade do registro |
| `Visao` | camada de apresentação sobre o Twig, com funções e filtros próprios |
| `BancoDados` | conexão PDO única e execução de consultas exclusivamente por prepared statements |
| `Repositorio` | base dos repositórios: busca por identificador, exclusão lógica, restauração e paginação |
| `Autenticacao` | autenticação, sessão do usuário e controle de perfis |
| `Sessao` | sessão endurecida, expiração por inatividade e mensagens entre requisições |
| `Csrf` | token por sessão, comparado em tempo constante |
| `Validador` | validação de consistência acumulando erros por campo |
| `Formatador` | formatação e mascaramento de valores para exibição |
| `Auditoria` | trilha de auditoria em banco, com omissão de valores sensíveis |
| `Registro` | log técnico em arquivo, com mascaramento de dados sensíveis |
| `Configuracao` | parâmetros de runtime editáveis pelo administrador |
| `Ambiente` | leitura tipada do `.env` |
| `ExcecaoHttp` | exceção que carrega o código de status a devolver |

### Decisões de projeto do núcleo

**Roteador como ponto de aplicação das travas.** A validação de CSRF e de
perfil acontece no roteador, não nos controladores. Um controlador novo é
protegido por declarar sua rota; nenhuma proteção depende de o programador se
lembrar de chamá-la.

**Parâmetros nomeados repetidos.** Com prepared statements reais, o PDO não
aceita o mesmo parâmetro nomeado duas vezes na consulta. Como repetir o
parâmetro deixa o SQL mais legível, `BancoDados::executar()` desfaz a
duplicação automaticamente: cada repetição recebe um nome próprio ligado ao
mesmo valor, preservando literais entre aspas.

**Exclusão lógica na base dos repositórios.** `Repositorio::excluirLogicamente()`
e `Repositorio::restaurar()` implementam o item 8.6.j uma única vez, para todas
as entidades, já registrando a operação na trilha de auditoria.

**Auditoria que nunca derruba a operação.** Falha ao gravar a trilha vai para o
log em arquivo e não interrompe o fluxo do usuário. A operação principal não
pode ser perdida por um problema de registro.

---

## 3. Módulos do banco de dados

O schema segue a nomenclatura do item 8.6: prefixo do módulo, prefixo de três
letras nos campos, chaves nomeadas com `pk_`, `fk_`, `uk_` e `idx_`, e campos
de controle `xxx_dt_registro`, `xxx_log` e `xxx_status`.

### Módulo `sis_` — sistema, segurança e LGPD

| Tabela | Conteúdo |
|---|---|
| `sis_usuarios` | identidade e autenticação de todos os perfis |
| `sis_tokens` | tokens de uso único de recuperação de acesso |
| `sis_termos` | versões dos Termos de Uso e da Política de Privacidade |
| `sis_consentimentos` | histórico de aceite e revogação por finalidade |
| `sis_auditoria` | trilha de auditoria, tratada como *append only* |
| `sis_configuracoes` | parâmetros ajustáveis pelo painel |
| `sis_notificacoes` | fila e histórico das notificações |
| `sis_api_consultas` | registro das chamadas à API oficial |

### Módulo `pro_` — Pro-Link

| Tabela | Conteúdo |
|---|---|
| `pro_areas` | áreas de atuação do Sistema Confea/Crea |
| `pro_competencias` | catálogo controlado de competências técnicas |
| `pro_perfis` | perfil público, com controles de visibilidade |
| `pro_perfil_competencias` | competências declaradas, com nível |
| `pro_arts` | ARTs validadas na API oficial |
| `pro_cats` | CATs obtidas da API oficial |
| `pro_experiencias` | experiências, com ou sem vínculo a ART/CAT |
| `pro_experiencia_competencias` | competência exercida em cada experiência |
| `pro_demandas` | demandas por serviços técnicos |
| `pro_demanda_competencias` | requisitos de competência, com peso e obrigatoriedade |
| `pro_interesses` | manifestações de interesse |
| `pro_conversas` / `pro_mensagens` | comunicação inicial entre as partes |
| `pro_denuncias` | denúncias e tratamento pela moderação |
| `pro_solicitacoes_lgpd` | requisições de direitos do titular |

O Modelo Entidade-Relacionamento está em `_arq/mer/` (SVG, PNG e PDF) e é
**gerado a partir do banco**, de modo que não pode divergir do schema.

---

## 4. Regras de negócio implementadas

### 4.1 Distinção entre registrado e não registrado

Exigida no item 1 do Termo de Referência e sustentada em três níveis:

1. **Dados** — `sis_usuarios.usu_registrado_crea` só assume `'S'` após retorno
   positivo da API oficial, e `usu_dt_validacao` guarda quando isso aconteceu.
2. **Regra** — o administrador **não pode** conceder manualmente o perfil de
   profissional ou empresa registrada; a tela recusa a operação e explica o
   motivo. O caminho é a validação pelo próprio titular.
3. **Interface** — o selo de registro aparece em toda listagem, cartão e ficha,
   com dois estados textualmente distintos e não apenas por cor.

### 4.2 Origem dos dados do acervo técnico

ARTs e CATs entram na plataforma **exclusivamente** pelo retorno da API
oficial. As tabelas guardam `origem = 'API_CREA'`, `validada`, `dt_validacao` e
o `payload` integral da resposta, para rastreabilidade. Não existe formulário,
rota ou comando de inserção manual.

Ao associar uma ART, a titularidade é verificada duas vezes: a consulta usa o
RNP do próprio usuário autenticado, e a ART retornada precisa pertencer a esse
RNP. Divergência é recusada e registrada na auditoria.

### 4.3 Experiência declarada e experiência comprovada

`pro_experiencias.exp_comprovada` recebe `'S'` somente quando a experiência
está vinculada a uma ART ou CAT **validada e do próprio perfil**. A verificação
acontece no servidor, em `ControladorExperiencia::dadosFormulario()`: um
identificador de ART enviado pelo cliente que não pertença ao perfil é
descartado. A interface distingue os dois casos com rótulos explícitos.

### 4.4 Compatibilização entre demandas e perfis

O item 8.4 exige critérios objetivos e transparentes, e o cenário 7.3 pede que
o sistema explique os principais critérios. `ServicoCompatibilizacao` devolve,
além da nota, a lista de critérios avaliados com peso, percentual obtido e
justificativa em linguagem comum.

**Requisitos eliminatórios**, verificados antes da pontuação:

- registro ativo no CREA-AM, quando a demanda o exige;
- acervo comprovado por ART ou CAT, quando a demanda o exige.

Não atendido um requisito, o perfil sai da lista e o impedimento é exibido em
texto — nunca uma exclusão silenciosa.

**Critérios pontuados**, com pesos configuráveis no painel:

| Critério | Peso padrão | Como é medido |
|---|---|---|
| Competências técnicas | 45 | proporção do peso atendido; competência obrigatória pesa o dobro |
| Localização | 20 | mesmo município 100%, mesma UF 65%, remoto aceito 50%, fora 10% |
| Acervo validado | 20 | escada por quantidade de ARTs e CATs: 0, 1, 2, 3-4, 5+ |
| Tempo de experiência | 10 | anos declarados ou apurados das experiências, frente ao mínimo |
| Disponibilidade | 5 | disponível 100%, parcial 60%, indisponível 10% |

A nota final é a soma ponderada normalizada para 0-100. A avaliação roda nas
duas direções: perfis compatíveis com uma demanda e demandas compatíveis com um
perfil. O score do momento da manifestação fica gravado em
`pro_interesses.int_aderencia`, preservando o critério aplicado à época.

### 4.5 Visibilidade e minimização de dados

O perfil pode ser público, restrito a autenticados ou oculto, e cada item
sensível tem controle próprio: contato, documento, RNP e valor-hora, todos
ocultos por padrão. ARTs, CATs e experiências têm visibilidade individual.

A restrição é aplicada na consulta, não na tela: `RepositorioBusca` filtra por
visibilidade e situação de moderação no próprio SQL, de modo que um perfil
oculto não aparece nem em resultado de busca nem em contagem. Documentos, quando
autorizados, aparecem mascarados.

Revogar a finalidade *exibição pública do perfil* oculta o perfil
imediatamente e desativa o recebimento de contatos, no mesmo passo.

### 4.6 Comunicação com contexto legítimo

Uma conversa só pode ser iniciada em duas situações: dentro de uma demanda em
que ambas as partes estão envolvidas — o autor fala com quem manifestou
interesse, e o interessado com o autor — ou com um perfil que autorizou receber
contato. Fora disso, a abertura é recusada. Isso evita que a plataforma se torne
canal de mensagens não solicitadas.

Conversas são privadas: **nem o administrador as lê**. A moderação de mensagem
acontece por denúncia, que traz o conteúdo específico denunciado.

### 4.7 Encerramento automático de demandas

Demandas publicadas com prazo vencido são encerradas automaticamente na entrada
do painel, dispensando agendador externo no protótipo. Em produção, a mesma
rotina pode ser chamada por tarefa agendada.

### 4.8 Notificação registrada antes do envio

Todo evento relevante é gravado em `sis_notificacoes` **antes** de qualquer
tentativa de envio. Com SMTP indisponível ou desligado, o evento continua
existindo, visível ao usuário na central de notificações e reenviável pelo
painel. O envio por e-mail é uma consequência do registro, não um pré-requisito.

---

## 5. Integração com a API oficial (RF02)

```
ControladorPortfolio ─▶ ServicoIntegracaoCrea ─▶ ClienteApiCrea (interface)
                              │                        │
                              │                        ├── ClienteApiCreaHttp
                              │                        │     transporte, token,
                              │                        │     registro da consulta
                              │                        │
                              │                        └── ClienteApiCreaIndisponivel
                              │                              recusa explícita
                              │
                              ├─▶ NormalizadorApiCrea    tradução do formato
                              ├─▶ RepositorioArt/Cat     persistência local
                              └─▶ Auditoria              rastreabilidade
```

A separação em três peças tem uma razão prática: o **transporte** muda com a
infraestrutura, o **formato** muda com a documentação, e as **regras** não
mudam. Trocar a URL ou o token não toca em código; ajustar o nome de um campo
mexe em um único arquivo; nenhuma das duas coisas afeta controladores ou
repositórios.

`ClienteApiCreaIndisponivel` existe para permitir o desenvolvimento local de
telas que não dependem da integração. Ele **não devolve dado algum**: recusa a
consulta com mensagem explícita. A criação de base própria para simular os
dados da API oficial é vedada pelo item 8.4, e este projeto não a faz em ponto
nenhum.

---

## 6. Fluxo de uma requisição

1. `public/index.php` carrega `_config.php`, que define caminhos, constantes,
   fuso horário e conjunto de caracteres, e carrega o autoload do Composer.
2. `Aplicacao::executar()` inicia a sessão, aplica os cabeçalhos de segurança e
   captura a requisição.
3. `Autenticacao::revalidar()` derruba a sessão se a conta foi bloqueada ou
   excluída durante o uso.
4. `Roteador::despachar()` casa o caminho, valida o token CSRF nas requisições
   que alteram estado e confere o perfil de acesso.
5. O controlador valida a entrada com `Validador`, chama serviços e
   repositórios, e renderiza a view ou redireciona.
6. Repositórios executam prepared statements e registram as operações
   relevantes na trilha de auditoria.
7. O Twig renderiza a view com escape automático de tudo o que é impresso.
8. Exceções sobem até `Aplicacao`, que devolve a página de erro apropriada —
   sem detalhe técnico fora de desenvolvimento — ou JSON, quando a requisição
   for de API.

---

## 7. Escolhas técnicas e seus motivos

**MVC próprio em vez de framework completo.** O Termo de Referência exige
`_config.php` na raiz, a pasta `_arq/`, nomenclatura de tabelas com prefixo de
módulo e campos com prefixo de três letras. Um framework de convenções fortes
brigaria com cada um desses pontos. O núcleo autoral tem 18 classes com
responsabilidade única, é auditável em uma leitura e adere ao pedido sem
adaptação forçada.

**Twig como mecanismo de templates.** Atende ao item 8.2, que veda PHP dentro
das páginas HTML, e o escape automático elimina a classe mais comum de XSS.

**Repositórios em vez de ORM.** O item 8.1.1.b pede separação da persistência
por repositório, DAO ou padrão equivalente. Repositórios com SQL explícito
mantêm as consultas legíveis e auditáveis, o que importa em uma solução que
será avaliada tabela por tabela.

**Bootstrap 5 e jQuery.** Exigidos no item 8.1.3. A interface funciona
integralmente sem JavaScript: o script apenas melhora o uso — contadores de
caracteres, filtro de competências, consulta prévia à API e confirmações.

**Assets servidos localmente.** A política de conteúdo da aplicação só admite
recursos da própria origem, o que neutraliza a exploração de XSS refletido. Por
isso Bootstrap, jQuery e os ícones ficam em `public/assets/vendor/`, e não em
CDN. O ambiente também funciona sem acesso externo.

---

## 8. Preparação para evolução institucional

| Ponto de extensão | Como está preparado |
|---|---|
| Troca do provedor da API | `ClienteApiCrea` é interface; basta uma nova implementação |
| Mudança no formato da resposta | apelidos de campo concentrados em `NormalizadorApiCrea` |
| Novos critérios de compatibilização | um método por critério em `ServicoCompatibilizacao`, com peso em banco |
| Novos canais de notificação | `sis_notificacoes.not_canal` já modela o canal |
| Novas entidades moderáveis | `pro_denuncias` guarda entidade e identificador genéricos |
| Novos perfis de acesso | listas de perfis declaradas por rota em `app/rotas.php` |
| Autenticação institucional | `Autenticacao::estabelecerSessao()` é o único ponto de entrada da sessão |
| Migração para API pública | controladores já separam JSON de HTML pela requisição |

---

## 9. Limites conhecidos do protótipo

Registrados por honestidade técnica, com o encaminhamento de cada um:

1. **Fila de notificações processada de forma síncrona.** O envio ocorre na
   requisição, com reprocessamento manual pelo painel. Em produção, chamar
   `ServicoNotificacao::processarFila()` por tarefa agendada.
2. **Encerramento de demandas disparado por acesso.** Sem agendador, a rotina
   roda quando alguém entra no painel. Mesma solução: tarefa agendada.
3. **Cache de respostas da API não implementado.** O parâmetro
   `api_cache_minutos` existe em `sis_configuracoes` e está previsto, mas cada
   consulta hoje vai à API.
4. **Anexos em demandas e mensagens.** Apenas imagem de perfil é aceita. Um
   repositório de arquivos exigiria antivírus e política de retenção.
5. **Verificação de e-mail não obrigatória.** A estrutura existe
   (`sis_tokens`, `usu_email_verificado`), mas o acesso não é bloqueado por
   falta de verificação, para não travar a demonstração.
6. **Sem autenticação em dois fatores.** A coluna `usu_2fa_ativo` foi prevista
   no modelo inicial e removida do schema entregue para não sugerir recurso
   inexistente.
