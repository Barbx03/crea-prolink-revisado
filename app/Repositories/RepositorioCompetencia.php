<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Catalogo de areas de atuacao e competencias tecnicas.
 */
final class RepositorioCompetencia extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_competencias';
    }

    protected static function prefixo(): string
    {
        return 'cmp';
    }

    /** @return list<array<string, mixed>> */
    public static function areas(): array
    {
        return BancoDados::buscarTodos(
            'SELECT are_id, are_nome, are_descricao
               FROM pro_areas WHERE are_status = :ativo ORDER BY are_nome',
            ['ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function todas(): array
    {
        return BancoDados::buscarTodos(
            'SELECT c.cmp_id, c.cmp_nome, c.cmp_descricao, c.cmp_are_id, a.are_nome
               FROM pro_competencias c
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
              WHERE c.cmp_status = :ativo AND a.are_status = :ativo
              ORDER BY a.are_nome, c.cmp_nome',
            ['ativo' => STATUS_ATIVO]
        );
    }

    /**
     * Competencias organizadas por area, para montar os grupos do formulario.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function agrupadasPorArea(): array
    {
        $agrupadas = [];

        foreach (self::todas() as $competencia) {
            $agrupadas[$competencia['are_nome']][] = $competencia;
        }

        return $agrupadas;
    }

    /** @return list<array<string, mixed>> */
    public static function porArea(int $areaId): array
    {
        return BancoDados::buscarTodos(
            'SELECT cmp_id, cmp_nome FROM pro_competencias
              WHERE cmp_are_id = :area AND cmp_status = :ativo ORDER BY cmp_nome',
            ['area' => $areaId, 'ativo' => STATUS_ATIVO]
        );
    }

    /**
     * Filtra os identificadores recebidos, mantendo apenas competencias que
     * existem e estao ativas. Impede que o cliente envie ids arbitrarios.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public static function filtrarValidos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $linhas = BancoDados::buscarTodos(
            "SELECT cmp_id FROM pro_competencias
              WHERE cmp_status = 'A' AND cmp_id IN ({$marcadores})",
            $ids
        );

        return array_map(static fn (array $linha): int => (int) $linha['cmp_id'], $linhas);
    }

    /** @return list<array<string, mixed>> */
    public static function maisDemandadas(int $limite = 10): array
    {
        return BancoDados::buscarTodos(
            'SELECT c.cmp_nome, a.are_nome, COUNT(*) AS total
               FROM pro_demanda_competencias dc
               JOIN pro_competencias c ON c.cmp_id = dc.dmc_cmp_id
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
               JOIN pro_demandas d ON d.dem_id = dc.dmc_dem_id
              WHERE dc.dmc_status = :ativo AND d.dem_status = :ativo
              GROUP BY c.cmp_id, c.cmp_nome, a.are_nome
              ORDER BY total DESC, c.cmp_nome
              LIMIT ' . max(1, min($limite, 50)),
            ['ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function maisOfertadas(int $limite = 10): array
    {
        return BancoDados::buscarTodos(
            'SELECT c.cmp_nome, a.are_nome, COUNT(*) AS total
               FROM pro_perfil_competencias pc
               JOIN pro_competencias c ON c.cmp_id = pc.pcp_cmp_id
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
               JOIN pro_perfis p ON p.prf_id = pc.pcp_prf_id
              WHERE pc.pcp_status = :ativo AND p.prf_status = :ativo
              GROUP BY c.cmp_id, c.cmp_nome, a.are_nome
              ORDER BY total DESC, c.cmp_nome
              LIMIT ' . max(1, min($limite, 50)),
            ['ativo' => STATUS_ATIVO]
        );
    }
}
