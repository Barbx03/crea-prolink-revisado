<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gestao da sessao PHP com cookie endurecido e expiracao por inatividade.
 */
final class Sessao
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(SESSAO_NOME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => SESSAO_COOKIE_SEGURO,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();

        self::aplicarExpiracao();
    }

    public static function obter(string $chave, mixed $padrao = null): mixed
    {
        return $_SESSION[$chave] ?? $padrao;
    }

    public static function definir(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public static function existe(string $chave): bool
    {
        return isset($_SESSION[$chave]);
    }

    public static function remover(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    /**
     * Regenera o identificador da sessao, preservando o conteudo.
     * Chamado apos login e apos troca de senha, contra fixacao de sessao.
     */
    public static function regenerar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destruir(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $parametros['path'],
                'domain'   => $parametros['domain'],
                'secure'   => $parametros['secure'],
                'httponly' => $parametros['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * Mensagens de uso unico entre requisicoes (padrao flash).
     */
    public static function alerta(string $tipo, string $mensagem): void
    {
        $_SESSION['_alertas'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    }

    public static function sucesso(string $mensagem): void
    {
        self::alerta('success', $mensagem);
    }

    public static function erro(string $mensagem): void
    {
        self::alerta('danger', $mensagem);
    }

    public static function aviso(string $mensagem): void
    {
        self::alerta('warning', $mensagem);
    }

    public static function informacao(string $mensagem): void
    {
        self::alerta('info', $mensagem);
    }

    /**
     * @return list<array{tipo: string, mensagem: string}>
     */
    public static function consumirAlertas(): array
    {
        $alertas = $_SESSION['_alertas'] ?? [];
        unset($_SESSION['_alertas']);

        return $alertas;
    }

    /**
     * Guarda os dados de um formulario recusado para repopular a tela.
     *
     * @param array<string, mixed> $dados
     */
    public static function guardarFormulario(array $dados, array $erros = []): void
    {
        unset($dados['senha'], $dados['senha_confirmacao'], $dados['_token']);

        $_SESSION['_formulario'] = $dados;
        $_SESSION['_erros']      = $erros;
    }

    /** @return array<string, mixed> */
    public static function consumirFormulario(): array
    {
        $dados = $_SESSION['_formulario'] ?? [];
        unset($_SESSION['_formulario']);

        return $dados;
    }

    /** @return array<string, string> */
    public static function consumirErros(): array
    {
        $erros = $_SESSION['_erros'] ?? [];
        unset($_SESSION['_erros']);

        return $erros;
    }

    private static function aplicarExpiracao(): void
    {
        $limite = SESSAO_TEMPO_MINUTOS * 60;
        $agora  = time();

        if (isset($_SESSION['_ultima_atividade']) && ($agora - (int) $_SESSION['_ultima_atividade']) > $limite) {
            self::destruir();
            self::iniciar();
            self::aviso('Sua sessão expirou por inatividade. Entre novamente.');

            return;
        }

        $_SESSION['_ultima_atividade'] = $agora;
    }
}
