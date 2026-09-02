<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Protecao contra Cross-Site Request Forgery (item 8.5.e).
 *
 * Um token por sessao, comparado em tempo constante. Toda requisicao com
 * metodo POST, PUT, PATCH ou DELETE e barrada pelo roteador quando o token
 * enviado nao corresponde ao da sessao.
 */
final class Csrf
{
    private const CHAVE = '_csrf_token';

    public static function token(): string
    {
        if (!Sessao::existe(self::CHAVE)) {
            Sessao::definir(self::CHAVE, bin2hex(random_bytes(32)));
        }

        return (string) Sessao::obter(self::CHAVE);
    }

    public static function campo(): string
    {
        return sprintf('<input type="hidden" name="_token" value="%s">', htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8'));
    }

    public static function valido(?string $tokenRecebido): bool
    {
        if ($tokenRecebido === null || $tokenRecebido === '') {
            return false;
        }

        $tokenSessao = Sessao::obter(self::CHAVE);

        if (!is_string($tokenSessao) || $tokenSessao === '') {
            return false;
        }

        return hash_equals($tokenSessao, $tokenRecebido);
    }

    /**
     * Renova o token, usado apos operacoes sensiveis como login e logout.
     */
    public static function renovar(): void
    {
        Sessao::definir(self::CHAVE, bin2hex(random_bytes(32)));
    }
}
