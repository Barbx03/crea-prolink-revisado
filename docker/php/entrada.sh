#!/bin/sh
# =============================================================================
# CREA Pro-Link | Inicialização do contêiner
#
# Aguarda o banco, aplica a estrutura e a carga inicial quando ainda não
# existirem, e só então entrega o controle ao Apache. Isso torna o ambiente
# reproduzível com um único comando, como pede o item 8.8.
# =============================================================================

set -e

echo "[prolink] iniciando ambiente..."

# --- Arquivo de ambiente ----------------------------------------------------
if [ ! -f /var/www/html/.env ]; then
    echo "[prolink] .env ausente: criando a partir de .env.example"
    cp /var/www/html/.env.example /var/www/html/.env

    # Gera uma chave própria para esta instalação
    CHAVE=$(php -r 'echo bin2hex(random_bytes(32));')
    sed -i "s/^APP_CHAVE=.*/APP_CHAVE=${CHAVE}/" /var/www/html/.env

    # Aplica ao .env os valores vindos do ambiente do contêiner
    [ -n "${DB_HOST}" ]    && sed -i "s/^DB_HOST=.*/DB_HOST=${DB_HOST}/" /var/www/html/.env
    [ -n "${DB_NOME}" ]    && sed -i "s/^DB_NOME=.*/DB_NOME=${DB_NOME}/" /var/www/html/.env
    [ -n "${DB_USUARIO}" ] && sed -i "s/^DB_USUARIO=.*/DB_USUARIO=${DB_USUARIO}/" /var/www/html/.env
    [ -n "${DB_SENHA}" ]   && sed -i "s/^DB_SENHA=.*/DB_SENHA=${DB_SENHA}/" /var/www/html/.env
    [ -n "${APP_URL}" ]    && sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" /var/www/html/.env
fi

# --- Espera o banco aceitar conexões ---------------------------------------
TENTATIVAS=40
echo "[prolink] aguardando o banco em ${DB_HOST:-mariadb}..."

until mariadb -h "${DB_HOST:-mariadb}" -u "${DB_USUARIO:-prolink}" -p"${DB_SENHA}" -e "SELECT 1" > /dev/null 2>&1 \
   || mysql -h "${DB_HOST:-mariadb}" -u "${DB_USUARIO:-prolink}" -p"${DB_SENHA}" -e "SELECT 1" > /dev/null 2>&1; do
    TENTATIVAS=$((TENTATIVAS - 1))

    if [ "$TENTATIVAS" -le 0 ]; then
        echo "[prolink] o banco não respondeu em tempo. Verifique o serviço mariadb." >&2
        exit 1
    fi

    sleep 2
done

echo "[prolink] banco disponível."

# --- Estrutura e carga inicial ---------------------------------------------
CLIENTE=mariadb
command -v mariadb > /dev/null 2>&1 || CLIENTE=mysql

TABELAS=$($CLIENTE -h "${DB_HOST:-mariadb}" -u "${DB_USUARIO:-prolink}" -p"${DB_SENHA}" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${DB_NOME:-crea_prolink}'" 2>/dev/null || echo 0)

if [ "${TABELAS:-0}" -lt 20 ]; then
    echo "[prolink] aplicando estrutura do banco (_arq/estrutura.sql)..."
    $CLIENTE -h "${DB_HOST:-mariadb}" -u "${DB_USUARIO:-prolink}" -p"${DB_SENHA}" < /var/www/html/_arq/estrutura.sql

    echo "[prolink] aplicando carga inicial (_arq/dados-iniciais.sql)..."
    $CLIENTE -h "${DB_HOST:-mariadb}" -u "${DB_USUARIO:-prolink}" -p"${DB_SENHA}" < /var/www/html/_arq/dados-iniciais.sql

    echo "[prolink] banco preparado."
else
    echo "[prolink] banco já contém ${TABELAS} tabelas: estrutura preservada."
fi

# --- Diretórios de escrita -------------------------------------------------
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/public/uploads
chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads

echo "[prolink] aplicação disponível em ${APP_URL:-http://localhost:8080}"
echo "[prolink] acesso administrativo inicial: admin@prolink.local / Admin@2026"

exec "$@"
