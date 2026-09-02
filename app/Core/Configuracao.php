<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Parametros de runtime armazenados em sis_configuracoes.
 *
 * Permite ao administrador ajustar SMTP, pesos do matching e dados da
 * plataforma pelo painel, sem novo deploy (RF06 e RF07). Quando a chave nao
 * existe no banco, cai no valor vindo do .env / _config.php.
 */
final class Configuracao
{
    /** @var array<string, array<string, string|null>>|null */
    private static ?array $cache = null;

    public static function texto(string $grupo, string $chave, string $padrao = ''): string
    {
        $valor = self::bruto($grupo, $chave);

        return ($valor === null || $valor === '') ? $padrao : $valor;
    }

    public static function inteiro(string $grupo, string $chave, int $padrao = 0): int
    {
        $valor = self::bruto($grupo, $chave);

        return ($valor === null || $valor === '') ? $padrao : (int) $valor;
    }

    public static function booleano(string $grupo, string $chave, bool $padrao = false): bool
    {
        $valor = self::bruto($grupo, $chave);

        if ($valor === null || $valor === '') {
            return $padrao;
        }

        return in_array(strtolower($valor), ['1', 'true', 'sim', 'on'], true);
    }

    /** @return array<string, string|null> */
    public static function grupo(string $grupo): array
    {
        self::carregar();

        return self::$cache[$grupo] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public static function listarGrupo(string $grupo): array
    {
        return BancoDados::buscarTodos(
            'SELECT cfg_id, cfg_grupo, cfg_chave, cfg_valor, cfg_tipo, cfg_descricao, cfg_sensivel
               FROM sis_configuracoes
              WHERE cfg_grupo = :grupo AND cfg_status = :ativo
              ORDER BY cfg_id',
            ['grupo' => $grupo, 'ativo' => STATUS_ATIVO]
        );
    }

    public static function definir(string $grupo, string $chave, ?string $valor): void
    {
        BancoDados::executar(
            'UPDATE sis_configuracoes
                SET cfg_valor = :valor, cfg_log = :log
              WHERE cfg_grupo = :grupo AND cfg_chave = :chave',
            [
                'valor' => $valor,
                'log'   => 'Alterado pelo painel administrativo',
                'grupo' => $grupo,
                'chave' => $chave,
            ]
        );

        self::$cache = null;
    }

    public static function limparCache(): void
    {
        self::$cache = null;
    }

    private static function bruto(string $grupo, string $chave): ?string
    {
        self::carregar();

        return self::$cache[$grupo][$chave] ?? null;
    }

    private static function carregar(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $linhas = BancoDados::buscarTodos(
                'SELECT cfg_grupo, cfg_chave, cfg_valor
                   FROM sis_configuracoes
                  WHERE cfg_status = :ativo',
                ['ativo' => STATUS_ATIVO]
            );

            foreach ($linhas as $linha) {
                self::$cache[$linha['cfg_grupo']][$linha['cfg_chave']] = $linha['cfg_valor'];
            }
        } catch (\Throwable $e) {
            Registro::alerta('Não foi possível carregar sis_configurações; usando valores do ambiente', [
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
