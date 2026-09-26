# Publicação no Easypanel

A aplicação roda em uma VPS da Hostinger em São Paulo, gerenciada pelo
Easypanel, no projeto `crea-prolink`. São dois serviços, comunicando-se pela
rede interna do Docker: `mariadb` (MariaDB 10.11) e `app`, construído a partir
do `Dockerfile` deste repositório (PHP 8.3 e Apache).

A hospedagem precisa ficar no Brasil: a API oficial do CREA-AM recusa, com
HTTP 403, consultas vindas de servidores no exterior. A primeira publicação,
no Railway (região dos Estados Unidos), foi desativada por esse motivo.

## 1. Banco

Crie um serviço MariaDB chamado `mariadb` com a imagem `mariadb:10.11`, banco
`crea_prolink`, usuário `prolink` e senhas aleatórias exclusivas. Não exponha
a porta do banco: a aplicação usa o endereço interno `crea-prolink_mariadb`.

## 2. Aplicação

Crie um serviço App chamado `app`:

- **Origem:** Git, `https://github.com/Barbx03/crea-prolink-revisado.git`,
  branch `main`, caminho `/`.
- **Build:** Dockerfile, arquivo `Dockerfile`.
- **Volume:** `uploads` montado em `/var/www/html/public/uploads`. Mantenha
  uma única réplica: as sessões PHP são locais ao contêiner.
- **Domínio:** porta de destino **80**, com HTTPS.

Variáveis de ambiente:

```dotenv
APP_AMBIENTE=producao
APP_DEBUG=false
APP_URL=https://<domínio da aplicação>
APP_CHAVE=<chave aleatória estável de 64 caracteres hexadecimais>
ADMIN_SENHA_INICIAL=<senha exclusiva com pelo menos 16 caracteres>
DB_HOST=crea-prolink_mariadb
DB_PORTA=3306
DB_NOME=crea_prolink
DB_USUARIO=prolink
DB_SENHA=<senha do usuário prolink>
SESSAO_COOKIE_SEGURO=true
CREA_API_DRIVER=http
CREA_API_URL=https://desafio-prolink.crea-am.org.br/api/v1
CREA_API_TOKEN=<token individual da equipe>
MAIL_ATIVO=false
```

Não substitua o comando de inicialização do Dockerfile: ele aguarda o banco,
aplica a estrutura e a carga inicial quando o banco está vazio e só então
inicia o Apache. Em `producao`, o contêiner exige `ADMIN_SENHA_INICIAL`
enquanto o administrador estiver com a senha pública original; contas de
demonstração com a senha original recebem senha aleatória e são bloqueadas.
Senhas já alteradas não são redefinidas nos próximos deploys.

## 3. Publicar alterações

O deploy é feito pelo botão **Deploy** do serviço `app` no Easypanel, que
busca a branch `main` e reconstrói a imagem. Para publicar a cada push, cadastre
o webhook de deploy do serviço (aba **Deployments**) nos webhooks do repositório
no GitHub.

## 4. Validação

- Verifique a página inicial, `/entrar`, `/demandas` e o login administrativo.
- Em **Configurações** do painel administrativo, use "Testar a API oficial".
  O registro das consultas fica em **Integração CREA-AM** (`/admin/auditoria/api`).
- Configure e teste SMTP no painel para envio real de e-mails.
- Configure backups do banco no serviço `mariadb` do Easypanel.
