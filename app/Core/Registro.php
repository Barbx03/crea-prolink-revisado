<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Registro de log em arquivo, segregado por dia.
 *
 * Complementa a trilha de auditoria em banco (App\Core\Auditoria): aqui ficam
 * eventos tecnicos da aplicacao; lah, os eventos de negocio rastreaveis.
 */
final class Registro
{
    public const INFO    = 'INFO';
    public const ALERTA  = 'ALERTA';
    public const ERRO    = 'ERRO';
    public const CRITICO = 'CRITICO';

    /**
     * @param array<string, mixed> $contexto
     */
    public static function escrever(string $nivel, string $mensagem, array $contexto = []): void
    {
        $arquivo = PATH_LOGS . '/aplicacao-' . date('Y-m-d') . '.log';

        $linha = sprintf(
            "[%s] %s: %s%s%s",
            date('Y-m-d H:i:s'),
            $nivel,
            $mensagem,
            $contexto === [] ? '' : ' | ' . json_encode(self::mascarar($contexto), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        @file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $contexto */
    public static function info(string $mensagem, array $contexto = []): void
    {
        self::escrever(self::INFO, $mensagem, $contexto);
    }

    /** @param array<string, mixed> $contexto */
    public static function alerta(string $mensagem, array $contexto = []): void
    {
        self::escrever(self::ALERTA, $mensagem, $contexto);
    }

    /** @param array<string, mixed> $contexto */
    public static function erro(string $mensagem, array $contexto = []): void
    {
        self::escrever(self::ERRO, $mensagem, $contexto);
    }

    /** @param array<string, mixed> $contexto */
    public static function critico(string $mensagem, array $contexto = []): void
    {
        self::escrever(self::CRITICO, $mensagem, $contexto);
    }

    /**
     * Remove do log qualquer valor sensivel que tenha escapado do chamador.
     *
     * @param array<string, mixed> $contexto
     * @return array<string, mixed>
     */
    private static function mascarar(array $contexto): array
    {
        $proibidos = ['senha', 'password', 'token', 'secret', 'authorization', 'cpf', 'cnpj'];

        foreach ($contexto as $chave => $valor) {
            foreach ($proibidos as $proibido) {
                if (stripos((string) $chave, $proibido) !== false) {
                    $contexto[$chave] = '***';
                    continue 2;
                }
            }

            if (is_array($valor)) {
                $contexto[$chave] = self::mascarar($valor);
            }
        }

        return $contexto;
    }
}
