<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Emissao da resposta HTTP, incluindo os cabecalhos de seguranca aplicados
 * a toda a aplicacao.
 */
final class Resposta
{
    public static function cabecalhosSeguranca(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('X-XSS-Protection: 0');
        header_remove('X-Powered-By');

        // Politica de conteudo: nenhum script inline e permitido, o que
        // neutraliza a exploracao de XSS refletido (item 8.5.d).
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self'; "
            . "script-src 'self'; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "object-src 'none'"
        );

        if (SESSAO_COOKIE_SEGURO) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function html(string $conteudo, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=UTF-8');
        echo $conteudo;
    }

    /** @param array<string, mixed>|list<mixed> $dados */
    public static function json(array $dados, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function redirecionar(string $caminho, int $codigo = 302): void
    {
        // Impede open redirect: destino externo, protocolo-relativo ou com
        // barra invertida e descartado em favor da raiz da aplicacao.
        $externo = str_starts_with($caminho, 'http')
            || str_starts_with($caminho, '//')
            || str_starts_with($caminho, '\\');

        if ($externo && !str_starts_with($caminho, APP_URL)) {
            $caminho = '';
        }

        if (str_starts_with($caminho, APP_URL)) {
            $caminho = substr($caminho, strlen(APP_URL));
        }

        // Relativo a raiz de proposito: preserva o host pelo qual o visitante
        // chegou e, com ele, o cookie de sessao.
        $destino = URL_BASE . '/' . ltrim($caminho, '/');

        http_response_code($codigo);
        header('Location: ' . $destino);
        exit;
    }

    public static function arquivo(string $nomeArquivo, string $conteudo, string $tipoMime): void
    {
        header('Content-Type: ' . $tipoMime . '; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($conteudo));
        header('Cache-Control: no-store');
        echo $conteudo;
        exit;
    }
}
