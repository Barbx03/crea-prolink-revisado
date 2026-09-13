<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

/**
 * Leitura tipada das variaveis de ambiente definidas no arquivo .env.
 *
 * Centraliza o acesso ao ambiente para que nenhuma outra camada precise
 * conhecer a origem do valor (item 8.3.1.j do Termo de Referencia).
 */
final class Ambiente
{
    private static bool $carregado = false;

    public static function carregar(string $caminhoRaiz): void
    {
        if (self::$carregado) {
            return;
        }

        if (is_file($caminhoRaiz . '/.env')) {
            Dotenv::createImmutable($caminhoRaiz)->safeLoad();
        }

        self::$carregado = true;
    }

    public static function texto(string $chave, string $padrao = ''): string
    {
        $valor = $_ENV[$chave] ?? $_SERVER[$chave] ?? getenv($chave);

        if ($valor === false || $valor === null || $valor === '') {
            return $padrao;
        }

        return trim((string) $valor, " \t\n\r\0\x0B\"'");
    }

    public static function inteiro(string $chave, int $padrao = 0): int
    {
        $valor = self::texto($chave);

        return $valor === '' ? $padrao : (int) $valor;
    }

    public static function booleano(string $chave, bool $padrao = false): bool
    {
        $valor = strtolower(self::texto($chave));

        return match ($valor) {
            ''                                  => $padrao,
            '1', 'true', 'on', 'sim', 'yes'     => true,
            default                             => false,
        };
    }
}
