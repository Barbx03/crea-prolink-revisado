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

    # Ambiente lê diretamente as variáveis do contêiner. Não interpolar
    # credenciais em sed: senhas podem conter /, &, aspas ou quebras de linha.
fi

# --- Espera o banco aceitar conexões ----------------------------------------
# O cliente MariaDB 11 exige TLS por padrão e o servidor 10.11 desta composição
# não o oferece. A conversa acontece dentro da rede privada do Compose, nunca
# exposta ao host, então a verificação é dispensada de propósito.
SEM_TLS="--skip-ssl"
mariadb --help 2>/dev/null | grep -q -- '--skip-ssl' || SEM_TLS="--ssl=0"

CLIENTE=mariadb
command -v mariadb > /dev/null 2>&1 || CLIENTE=mysql
export MYSQL_PWD="${DB_SENHA}"
banco() {
    "$CLIENTE" "$SEM_TLS" -h "${DB_HOST:-mariadb}" -P "${DB_PORTA:-3306}" \
        -u "${DB_USUARIO:-prolink}" -D "${DB_NOME:-crea_prolink}" "$@"
}

TENTATIVAS=40
echo "[prolink] aguardando o banco em ${DB_HOST:-mariadb}..."

until banco -e "SELECT 1" > /dev/null 2>&1; do
    TENTATIVAS=$((TENTATIVAS - 1))

    if [ "$TENTATIVAS" -le 0 ]; then
        echo "[prolink] o banco não respondeu em tempo. Verifique o serviço mariadb." >&2
        exit 1
    fi

    sleep 2
done

echo "[prolink] banco disponível."

# --- Estrutura e carga inicial ---------------------------------------------
TABELAS=$(banco -N -B \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")

if [ "${TABELAS:-0}" -lt 20 ]; then
    # Os scripts nomeiam crea_prolink ao criar e selecionar o banco, o que
    # serve à instalação manual. Aqui o banco já existe, criado pelo serviço
    # do MariaDB com o nome de DB_NOME, e o usuário da aplicação não tem
    # privilégio para criar outro: as duas linhas saem e o cliente entra
    # diretamente no banco configurado, seja qual for o nome.
    aplicar() {
        sed -e '/^CREATE DATABASE /,/;$/d' -e '/^USE /d' "$1" \
            | banco
    }

    echo "[prolink] aplicando estrutura do banco (_arq/estrutura.sql)..."
    aplicar /var/www/html/_arq/estrutura.sql

    echo "[prolink] aplicando carga inicial (_arq/dados-iniciais.sql)..."
    aplicar /var/www/html/_arq/dados-iniciais.sql

    echo "[prolink] banco preparado em '${DB_NOME:-crea_prolink}'."
else
    echo "[prolink] banco já contém ${TABELAS} tabelas: estrutura preservada."
fi

unset MYSQL_PWD

# Impede que as senhas públicas da carga de demonstração entrem em produção.
php /var/www/html/docker/php/preparar-producao.php

# --- Diretórios de escrita -------------------------------------------------
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/public/uploads
chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads

echo "[prolink] aplicação disponível em ${APP_URL:-http://localhost:8080}"

# Alguns ambientes de contêiner reativam o MPM event ao montar o serviço.
# mod_php requer exclusivamente o prefork; normalize antes de subir o Apache.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf

exec "$@"
