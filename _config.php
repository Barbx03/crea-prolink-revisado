<?php

/**
 * =============================================================================
 * CREA Pro-Link | Arquivo de configuracao principal
 *
 * Atende ao item 8.3.1 do Termo de Referencia. Centraliza as configuracoes
 * gerais da aplicacao, lendo de variaveis de ambiente (.env) tudo o que e
 * sensivel ou especifico de cada ambiente.
 *
 * Nenhuma credencial deve ser escrita diretamente neste arquivo.
 * =============================================================================
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// a) Fuso horario e conjunto de caracteres (itens 8.3.1.g e 8.3.1.h)
// -----------------------------------------------------------------------------
date_default_timezone_set('America/Manaus');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil');

// -----------------------------------------------------------------------------
// b) Caminhos fisicos dos diretorios (itens 8.3.1.b e 8.3.1.c)
// -----------------------------------------------------------------------------
define('PATH_RAIZ',    __DIR__);
define('PATH_APP',     PATH_RAIZ . '/app');
define('PATH_VIEWS',   PATH_RAIZ . '/views');
define('PATH_PUBLIC',  PATH_RAIZ . '/public');
define('PATH_ARQ',     PATH_RAIZ . '/_arq');
define('PATH_STORAGE', PATH_RAIZ . '/storage');
define('PATH_LOGS',    PATH_STORAGE . '/logs');
define('PATH_CACHE',   PATH_STORAGE . '/cache');
define('PATH_IMG',     PATH_PUBLIC . '/assets/img');
define('PATH_UPLOAD',  PATH_PUBLIC . '/uploads');
define('PATH_VENDOR',  PATH_RAIZ . '/vendor');

// -----------------------------------------------------------------------------
// c) Autoload do Composer
// -----------------------------------------------------------------------------
if (!is_file(PATH_VENDOR . '/autoload.php')) {
    http_response_code(500);
    exit('Dependencias nao instaladas. Execute: composer install');
}
require PATH_VENDOR . '/autoload.php';

// -----------------------------------------------------------------------------
// d) Variaveis de ambiente (item 8.3.1.j)
// -----------------------------------------------------------------------------
App\Core\Ambiente::carregar(PATH_RAIZ);

use App\Core\Ambiente as Env;

// -----------------------------------------------------------------------------
// e) URLs da aplicacao (item 8.3.1.a)
// -----------------------------------------------------------------------------
define('APP_URL',    rtrim(Env::texto('APP_URL', 'http://localhost:8080'), '/'));
define('URL_ASSETS', APP_URL . '/assets');
define('URL_IMG',    URL_ASSETS . '/img');
define('URL_UPLOAD', APP_URL . '/uploads');

// -----------------------------------------------------------------------------
// f) Configuracoes de ambiente (item 8.3.1.f)
// -----------------------------------------------------------------------------
define('APP_NOME',      Env::texto('APP_NOME', 'CREA Pro-Link'));
define('APP_AMBIENTE',  Env::texto('APP_AMBIENTE', 'producao'));
define('APP_DEBUG',     Env::booleano('APP_DEBUG', false));
define('APP_CHAVE',     Env::texto('APP_CHAVE', ''));
define('APP_VERSAO',    '1.0.0');

// Exibicao de erros por ambiente: producao nunca vaza detalhes ao usuario
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', PATH_LOGS . '/php-erros.log');

// -----------------------------------------------------------------------------
// g) Parametros de conexao com o banco de dados (item 8.3.1.d)
// -----------------------------------------------------------------------------
define('DB_HOST',    Env::texto('DB_HOST', '127.0.0.1'));
define('DB_PORTA',   Env::inteiro('DB_PORTA', 3306));
define('DB_NOME',    Env::texto('DB_NOME', 'crea_prolink'));
define('DB_USUARIO', Env::texto('DB_USUARIO', 'root'));
define('DB_SENHA',   Env::texto('DB_SENHA', ''));
define('DB_CHARSET', Env::texto('DB_CHARSET', 'utf8mb4'));
define('DB_SOCKET',  Env::texto('DB_SOCKET', ''));

// -----------------------------------------------------------------------------
// h) API oficial do desafio CREA-AM (itens 8.3.1.d e 8.4)
// -----------------------------------------------------------------------------
define('CREA_API_URL',     rtrim(Env::texto('CREA_API_URL', ''), '/'));
define('CREA_API_TOKEN',   Env::texto('CREA_API_TOKEN', ''));
define('CREA_API_TIMEOUT', Env::inteiro('CREA_API_TIMEOUT', 15));
define('CREA_API_DRIVER',  Env::texto('CREA_API_DRIVER', 'http'));

// -----------------------------------------------------------------------------
// i) Envio de e-mails via SMTP (item 8.3.1.i)
//    Estes valores sao o padrao; o painel administrativo pode sobrescreve-los
//    em sis_configuracoes (grupo SMTP), conforme RF07.
// -----------------------------------------------------------------------------
define('MAIL_ATIVO',            Env::booleano('MAIL_ATIVO', false));
define('MAIL_HOST',             Env::texto('MAIL_HOST', ''));
define('MAIL_PORTA',            Env::inteiro('MAIL_PORTA', 587));
define('MAIL_SEGURANCA',        Env::texto('MAIL_SEGURANCA', 'tls'));
define('MAIL_USUARIO',          Env::texto('MAIL_USUARIO', ''));
define('MAIL_SENHA',            Env::texto('MAIL_SENHA', ''));
define('MAIL_REMETENTE_EMAIL',  Env::texto('MAIL_REMETENTE_EMAIL', 'nao-responda@prolink.local'));
define('MAIL_REMETENTE_NOME',   Env::texto('MAIL_REMETENTE_NOME', APP_NOME));

// -----------------------------------------------------------------------------
// j) Sessao, autenticacao e demais parametros globais (item 8.3.1.e)
// -----------------------------------------------------------------------------
define('SESSAO_NOME',            Env::texto('SESSAO_NOME', 'prolink_sessao'));
define('SESSAO_TEMPO_MINUTOS',   Env::inteiro('SESSAO_TEMPO_MINUTOS', 120));
define('SESSAO_COOKIE_SEGURO',   Env::booleano('SESSAO_COOKIE_SEGURO', false));
define('LOGIN_MAX_TENTATIVAS',   Env::inteiro('LOGIN_MAX_TENTATIVAS', 5));
define('LOGIN_BLOQUEIO_MINUTOS', Env::inteiro('LOGIN_BLOQUEIO_MINUTOS', 15));
define('TOKEN_VALIDADE_MINUTOS', Env::inteiro('TOKEN_VALIDADE_MINUTOS', 60));

// Status de registro - exclusao logica (item 8.6.j)
define('STATUS_ATIVO',     'A');
define('STATUS_PENDENTE',  'P');
define('STATUS_BLOQUEADO', 'B');
define('STATUS_EXCLUIDO',  'X');
define('STATUS_ENVIADA',   'E');
define('STATUS_FALHA',     'F');

// Perfis de acesso (item 8.5.f)
define('PERFIL_ADMIN',        'ADMIN');
define('PERFIL_PROFISSIONAL', 'PROFISSIONAL');
define('PERFIL_EMPRESA',      'EMPRESA');
define('PERFIL_TERCEIRO',     'TERCEIRO');

// Limites de upload
define('UPLOAD_MAX_BYTES',     2 * 1024 * 1024);
define('UPLOAD_MIME_PERMITIDOS', ['image/jpeg', 'image/png', 'image/webp']);

// -----------------------------------------------------------------------------
// k) Garantia dos diretorios de escrita
// -----------------------------------------------------------------------------
foreach ([PATH_LOGS, PATH_CACHE, PATH_UPLOAD] as $diretorio) {
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0775, true);
    }
}
