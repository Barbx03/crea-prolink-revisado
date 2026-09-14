<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;

/**
 * Pesquisa publica de profissionais e empresas (perfil "Publico" do item 3).
 *
 * Respeita as escolhas de visibilidade do titular: perfis ocultos nunca
 * aparecem, e perfis restritos a usuarios autenticados sao filtrados conforme
 * o visitante esteja ou nao logado.
 */
final class RepositorioBusca
{
    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function profissionais(array $filtros, int $pagina, int $porPagina, bool $autenticado = false): array
    {
        $condicoes = [
            'p.prf_status = :ativo',
            'u.usu_status = :ativoUsuario',
            "p.prf_moderacao = 'APROVADO'",
            "p.prf_visibilidade <> 'OCULTO'",
        ];

        $parametros = [
            'ativo'        => STATUS_ATIVO,
            'ativoUsuario' => STATUS_ATIVO,
        ];

        if (!$autenticado) {
            $condicoes[] = "p.prf_visibilidade = 'PUBLICO'";
        }

        if (!empty($filtros['termo'])) {
            $condicoes[] = '(u.usu_nome LIKE :termo OR p.prf_titulo LIKE :termo OR p.prf_resumo LIKE :termo)';
            $parametros['termo'] = '%' . $filtros['termo'] . '%';
        }

        if (!empty($filtros['area_id'])) {
            $condicoes[] = 'p.prf_are_id = :area';
            $parametros['area'] = (int) $filtros['area_id'];
        }

        if (!empty($filtros['uf'])) {
            $condicoes[] = '(p.prf_uf = :uf' . (!empty($filtros['incluir_remoto']) ? " OR p.prf_atende_remoto = 'S')" : ')');
            $parametros['uf'] = $filtros['uf'];
        }

        if (!empty($filtros['cidade'])) {
            $condicoes[] = 'p.prf_cidade LIKE :cidade';
            $parametros['cidade'] = '%' . $filtros['cidade'] . '%';
        }

        // A busca lista quem oferece serviço técnico. Contratantes sem registro
        // publicam demandas, mas não figuram como prestadores.
        if (!empty($filtros['perfil'])) {
            $condicoes[] = 'u.usu_perfil = :perfil';
            $parametros['perfil'] = $filtros['perfil'];
        } else {
            $condicoes[] = "u.usu_perfil IN ('PROFISSIONAL', 'EMPRESA')";
        }

        if (!empty($filtros['somente_registrados'])) {
            $condicoes[] = "u.usu_registrado_crea = 'S'";
        }

        if (!empty($filtros['disponibilidade'])) {
            $condicoes[] = 'p.prf_disponibilidade = :disponibilidade';
            $parametros['disponibilidade'] = $filtros['disponibilidade'];
        }

        if (!empty($filtros['experiencia_min'])) {
            $condicoes[] = 'COALESCE(p.prf_anos_experiencia, 0) >= :experiencia';
            $parametros['experiencia'] = (int) $filtros['experiencia_min'];
        }

        if (!empty($filtros['com_acervo'])) {
            $condicoes[] = "EXISTS (
                SELECT 1 FROM pro_arts a
                 WHERE a.art_prf_id = p.prf_id AND a.art_status = 'A' AND a.art_visivel = 'S'
            )";
        }

        if (!empty($filtros['competencias']) && is_array($filtros['competencias'])) {
            $ids = RepositorioCompetencia::filtrarValidos($filtros['competencias']);

            if ($ids !== []) {
                $lista = implode(',', $ids);
                $condicoes[] = "EXISTS (
                    SELECT 1 FROM pro_perfil_competencias pc
                     WHERE pc.pcp_prf_id = p.prf_id
                       AND pc.pcp_status = 'A'
                       AND pc.pcp_cmp_id IN ({$lista})
                )";
            }
        }

        $onde = ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor(
            'SELECT COUNT(*) FROM pro_perfis p JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id' . $onde,
            $parametros
        );

        $ordem = match ($filtros['ordem'] ?? 'relevancia') {
            'nome'        => ' ORDER BY u.usu_nome ASC',
            'experiencia' => ' ORDER BY COALESCE(p.prf_anos_experiencia, 0) DESC, u.usu_nome ASC',
            'acervo'      => ' ORDER BY total_arts DESC, u.usu_nome ASC',
            'recentes'    => ' ORDER BY p.prf_dt_registro DESC',
            default       => " ORDER BY (u.usu_registrado_crea = 'S') DESC, total_arts DESC, COALESCE(p.prf_anos_experiencia, 0) DESC, u.usu_nome ASC",
        };

        $itens = BancoDados::buscarTodos(
            "SELECT p.prf_id, p.prf_titulo, p.prf_resumo, p.prf_uf, p.prf_cidade,
                    p.prf_disponibilidade, p.prf_anos_experiencia, p.prf_atende_remoto,
                    p.prf_foto, p.prf_exibe_valor_hora, p.prf_valor_hora, p.prf_exibe_rnp,
                    u.usu_id, u.usu_nome, u.usu_perfil, u.usu_registrado_crea, u.usu_rnp,
                    a.are_nome,
                    (SELECT COUNT(*) FROM pro_arts ar
                      WHERE ar.art_prf_id = p.prf_id AND ar.art_status = 'A' AND ar.art_visivel = 'S') AS total_arts,
                    (SELECT COUNT(*) FROM pro_experiencias ex
                      WHERE ex.exp_prf_id = p.prf_id AND ex.exp_status = 'A' AND ex.exp_visivel = 'S') AS total_experiencias
               FROM pro_perfis p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
               LEFT JOIN pro_areas a ON a.are_id = p.prf_are_id"
            . $onde . $ordem . self::limite($pagina, $porPagina),
            $parametros
        );

        foreach ($itens as $indice => $item) {
            $itens[$indice]['competencias'] = array_slice(
                RepositorioPerfil::competencias((int) $item['prf_id']),
                0,
                6
            );
        }

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Perfis em destaque na pagina inicial: registrados, com acervo e ativos.
     *
     * @return list<array<string, mixed>>
     */
    public static function destaques(int $limite = 6): array
    {
        return BancoDados::buscarTodos(
            "SELECT p.prf_id, p.prf_titulo, p.prf_uf, p.prf_cidade, p.prf_foto,
                    p.prf_anos_experiencia, u.usu_nome, u.usu_registrado_crea, a.are_nome,
                    (SELECT COUNT(*) FROM pro_arts ar
                      WHERE ar.art_prf_id = p.prf_id AND ar.art_status = 'A' AND ar.art_visivel = 'S') AS total_arts
               FROM pro_perfis p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
               LEFT JOIN pro_areas a ON a.are_id = p.prf_are_id
              WHERE p.prf_status = 'A'
                AND u.usu_status = 'A'
                AND p.prf_visibilidade = 'PUBLICO'
                AND p.prf_moderacao = 'APROVADO'
                AND p.prf_titulo IS NOT NULL
                AND u.usu_perfil IN ('PROFISSIONAL', 'EMPRESA')
              ORDER BY (u.usu_registrado_crea = 'S') DESC, total_arts DESC, p.prf_dt_alteracao DESC
              LIMIT " . max(1, min($limite, 24))
        );
    }

    /**
     * Numeros gerais exibidos na pagina inicial.
     *
     * @return array<string, int>
     */
    public static function numerosPlataforma(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                (SELECT COUNT(*) FROM pro_perfis p JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
                  WHERE p.prf_status = 'A' AND u.usu_status = 'A' AND p.prf_visibilidade = 'PUBLICO') AS perfis,
                (SELECT COUNT(*) FROM sis_usuarios WHERE usu_registrado_crea = 'S' AND usu_status = 'A') AS registrados,
                (SELECT COUNT(*) FROM pro_demandas
                  WHERE dem_status = 'A' AND dem_situacao = 'PUBLICADA') AS demandas,
                (SELECT COUNT(*) FROM pro_arts WHERE art_status = 'A' AND art_validada = 'S') AS arts,
                (SELECT COUNT(*) FROM pro_competencias WHERE cmp_status = 'A') AS competencias,
                (SELECT COUNT(*) FROM pro_interesses WHERE int_status = 'A') AS interesses"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }

    private static function limite(int $pagina, int $porPagina): string
    {
        $porPagina = max(1, min($porPagina, 100));
        $pagina    = max(1, $pagina);

        return sprintf(' LIMIT %d OFFSET %d', $porPagina, ($pagina - 1) * $porPagina);
    }
}
