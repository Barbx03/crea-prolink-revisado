<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;

/**
 * Consulta do registro de chamadas à API oficial do CREA-AM (RF02).
 *
 * Somente leitura: a gravação acontece em App\Services\ClienteApiCreaHttp,
 * junto da própria chamada.
 */
final class RepositorioApiConsulta
{
    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['recurso'])) {
            $condicoes[] = 'a.apc_recurso = :recurso';
            $parametros['recurso'] = $filtros['recurso'];
        }

        if (($filtros['sucesso'] ?? '') !== '') {
            $condicoes[] = 'a.apc_sucesso = :sucesso';
            $parametros['sucesso'] = $filtros['sucesso'] === 'S' ? 'S' : 'N';
        }

        $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor(
            'SELECT COUNT(*) FROM sis_api_consultas a' . $onde,
            $parametros
        );

        $porPagina = max(1, min($porPagina, 200));
        $pagina    = max(1, $pagina);

        $itens = BancoDados::buscarTodos(
            'SELECT a.*, u.usu_nome
               FROM sis_api_consultas a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.apc_usu_id'
            . $onde
            . ' ORDER BY a.apc_dt_registro DESC'
            . sprintf(' LIMIT %d OFFSET %d', $porPagina, ($pagina - 1) * $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Resumo por recurso consultado, com duração média e última chamada.
     *
     * @return list<array<string, mixed>>
     */
    public static function resumoPorRecurso(): array
    {
        return BancoDados::buscarTodos(
            "SELECT apc_recurso,
                    COUNT(*) AS total,
                    SUM(apc_sucesso = 'S') AS sucessos,
                    SUM(apc_sucesso = 'N') AS falhas,
                    COALESCE(ROUND(AVG(apc_duracao_ms)), 0) AS duracao_media,
                    MAX(apc_dt_registro) AS ultima_consulta
               FROM sis_api_consultas
              GROUP BY apc_recurso
              ORDER BY total DESC"
        );
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(apc_sucesso = 'S') AS sucessos,
                SUM(apc_sucesso = 'N') AS falhas,
                SUM(DATE(apc_dt_registro) = CURDATE()) AS hoje,
                COALESCE(ROUND(AVG(apc_duracao_ms)), 0) AS duracao_media
             FROM sis_api_consultas"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }
}
