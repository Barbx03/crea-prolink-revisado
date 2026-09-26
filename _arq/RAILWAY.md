# Publicação no Railway

A aplicação usa o Dockerfile existente, PHP 8.3 e MariaDB 10.11. São dois
serviços no mesmo projeto e ambiente, comunicando-se pela rede privada. O
Railway detecta automaticamente o `Dockerfile` na raiz. Banco, volumes e
variáveis são recursos do projeto no Railway e não são criados pelo Dockerfile.

## 1. Banco

Crie um serviço chamado `mariadb` com a imagem `mariadb:10.11` e um volume
persistente em `/var/lib/mysql`. Configure:

```dotenv
MARIADB_DATABASE=crea_prolink
MARIADB_USER=prolink
MARIADB_PASSWORD=<senha aleatória exclusiva>
MARIADB_ROOT_PASSWORD=<outra senha aleatória exclusiva>
```

Não publique uma porta TCP para o banco. A aplicação usa o domínio privado
do serviço. Aguarde o banco ficar disponível antes de publicar a aplicação.

## 2. Aplicação

Crie o serviço a partir deste repositório ou envie o código com `railway up`.
Monte um volume persistente em `/var/www/html/public/uploads` e mantenha
uma única réplica: as sessões PHP são locais ao contêiner. Um redeploy pode
exigir que usuários façam login novamente; os uploads ficam no volume.

Configure as variáveis no Railway (as expressões abaixo são referências
nativas às variáveis do serviço `mariadb`):

```dotenv
PORT=80
APP_AMBIENTE=producao
APP_DEBUG=false
APP_URL=https://<dominio-publico-da-aplicacao>
APP_CHAVE=<chave aleatória estável de 64 caracteres hexadecimais>
ADMIN_SENHA_INICIAL=<senha exclusiva com pelo menos 16 caracteres>
DB_HOST=${{mariadb.RAILWAY_PRIVATE_DOMAIN}}
DB_PORTA=3306
DB_NOME=${{mariadb.MARIADB_DATABASE}}
DB_USUARIO=${{mariadb.MARIADB_USER}}
DB_SENHA=${{mariadb.MARIADB_PASSWORD}}
SESSAO_COOKIE_SEGURO=true
CREA_API_DRIVER=http
CREA_API_URL=https://desafio-prolink.crea-am.org.br/api/v1
CREA_API_TOKEN=<token individual da equipe, quando disponível>
MAIL_ATIVO=false
```

Gere um domínio público no Railway, com porta de destino **80**, atualize
`APP_URL` e aplique as alterações. Não substitua o comando de inicialização
do Dockerfile: ele prepara o banco antes de iniciar o Apache.

Na primeira execução, a estrutura e a carga inicial são aplicadas. Em
`producao`, o contêiner exige `ADMIN_SENHA_INICIAL` enquanto o administrador
estiver com a senha pública original. A conta `admin@prolink.local` recebe
a senha configurada; contas de demonstração que ainda tenham a senha
original recebem senha aleatória e são bloqueadas. Senhas já alteradas
não são redefinidas nos próximos deploys. Guarde a senha inicial em um
gerenciador de senhas e remova a variável após confirmar o primeiro acesso.

## 3. Validação

- Verifique a página inicial, `/entrar`, `/demandas` e o login administrativo.
- Verifique um upload e sua permanência após um redeploy.
- Configure o token CREA pelo ambiente ou painel para habilitar as consultas
  profissionais; sem ele, a aplicação informa indisponibilidade.
- Configure e teste SMTP no painel para envio real de e-mails.
- Ative backups dos volumes e do banco no Railway.

Os logs locais da aplicação não sobrevivem a um redeploy. Os logs de
inicialização e do Apache ficam disponíveis nos logs do serviço Railway.

Referências: [Dockerfiles](https://docs.railway.com/builds/dockerfiles),
[volumes](https://docs.railway.com/volumes) e
[preços](https://docs.railway.com/pricing).
