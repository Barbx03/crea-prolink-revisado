<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Experiencias profissionais publicadas, com ou sem vinculo a ART/CAT (RF03).
 *
 * A experiencia so e marcada como comprovada quando associada a uma ART ou CAT
 * previamente validada pela API oficial. Isso mantem a distincao entre o que o
 * profissional declara e o que a base institucional confirma.
 */
final class RepositorioExperiencia extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_experiencias';
    }

    protected static function prefixo(): string
    {
        return 'exp';
    }

    /** @return list<array<string, mixed>> */
    public static function doPerfil(int $perfilId, bool $somenteVisiveis = false): array
    {
        $sql = 'SELECT e.*, a.art_numero, c.cat_numero
                  FROM pro_experiencias e
                  LEFT JOIN pro_arts a ON a.art_id = e.exp_art_id
                  LEFT JOIN pro_cats c ON c.cat_id = e.exp_cat_id
                 WHERE e.exp_prf_id = :perfil AND e.exp_status = :ativo';

        if ($somenteVisiveis) {
            $sql .= " AND e.exp_visivel = 'S' AND e.exp_moderacao = 'APROVADO'";
        }

        $experiencias = BancoDados::buscarTodos(
            $sql . ' ORDER BY e.exp_atual DESC, e.exp_dt_inicio DESC, e.exp_id DESC',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );

        foreach ($experiencias as $indice => $experiencia) {
            $experiencias[$indice]['competencias'] = self::competencias((int) $experiencia['exp_id']);
        }

        return $experiencias;
    }

    /** @return list<array<string, mixed>> */
    public static function doUsuario(int $usuarioId): array
    {
        $perfil = RepositorioPerfil::porUsuario($usuarioId, true);

        return $perfil === null ? [] : self::doPerfil((int) $perfil['prf_id']);
    }

    /** @return array<string, mixed>|null */
    public static function comDono(int $experienciaId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT e.*, p.prf_usu_id, p.prf_id
               FROM pro_experiencias e
               JOIN pro_perfis p ON p.prf_id = e.exp_prf_id
              WHERE e.exp_id = :id AND e.exp_status <> :excluido
              LIMIT 1',
            ['id' => $experienciaId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /**
     * @param array<string, mixed> $dados
     * @param list<int> $competenciaIds
     */
    public static function criar(int $perfilId, array $dados, array $competenciaIds): int
    {
        return BancoDados::transacao(static function () use ($perfilId, $dados, $competenciaIds): int {
            BancoDados::executar(
                'INSERT INTO pro_experiencias
                    (exp_prf_id, exp_titulo, exp_descricao, exp_organizacao, exp_papel,
                     exp_municipio, exp_uf, exp_dt_inicio, exp_dt_fim, exp_atual,
                     exp_art_id, exp_cat_id, exp_comprovada, exp_visivel, exp_log, exp_status)
                 VALUES
                    (:perfil, :titulo, :descricao, :organizacao, :papel,
                     :municipio, :uf, :dt_inicio, :dt_fim, :atual,
                     :art, :cat, :comprovada, :visivel, :log, :ativo)',
                [
                    'perfil'      => $perfilId,
                    'titulo'      => $dados['titulo'],
                    'descricao'   => $dados['descricao'] ?? null,
                    'organizacao' => $dados['organizacao'] ?? null,
                    'papel'       => $dados['papel'] ?? null,
                    'municipio'   => $dados['municipio'] ?? null,
                    'uf'          => $dados['uf'] ?? null,
                    'dt_inicio'   => $dados['dt_inicio'] ?? null,
                    'dt_fim'      => $dados['atual'] === 'S' ? null : ($dados['dt_fim'] ?? null),
                    'atual'       => $dados['atual'] ?? 'N',
                    'art'         => $dados['art_id'] ?? null,
                    'cat'         => $dados['cat_id'] ?? null,
                    'comprovada'  => (!empty($dados['art_id']) || !empty($dados['cat_id'])) ? 'S' : 'N',
                    'visivel'     => $dados['visivel'] ?? 'S',
                    'log'         => 'Experiência publicada pelo titular',
                    'ativo'       => STATUS_ATIVO,
                ]
            );

            $id = BancoDados::ultimoId();

            self::sincronizarCompetencias($id, $competenciaIds);

            Auditoria::registrar('CADASTRO', [
                'entidade'    => 'pro_experiencias',
                'entidade_id' => $id,
                'descricao'   => 'Experiência publicada: ' . $dados['titulo'],
            ]);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $dados
     * @param list<int> $competenciaIds
     */
    public static function atualizar(int $experienciaId, array $dados, array $competenciaIds): void
    {
        $anterior = self::porId($experienciaId);

        BancoDados::transacao(static function () use ($experienciaId, $dados, $competenciaIds): void {
            BancoDados::executar(
                'UPDATE pro_experiencias SET
                    exp_titulo = :titulo,
                    exp_descricao = :descricao,
                    exp_organizacao = :organizacao,
                    exp_papel = :papel,
                    exp_municipio = :municipio,
                    exp_uf = :uf,
                    exp_dt_inicio = :dt_inicio,
                    exp_dt_fim = :dt_fim,
                    exp_atual = :atual,
                    exp_art_id = :art,
                    exp_cat_id = :cat,
                    exp_comprovada = :comprovada,
                    exp_visivel = :visivel,
                    exp_log = :log
                  WHERE exp_id = :id',
                [
                    'titulo'      => $dados['titulo'],
                    'descricao'   => $dados['descricao'] ?? null,
                    'organizacao' => $dados['organizacao'] ?? null,
                    'papel'       => $dados['papel'] ?? null,
                    'municipio'   => $dados['municipio'] ?? null,
                    'uf'          => $dados['uf'] ?? null,
                    'dt_inicio'   => $dados['dt_inicio'] ?? null,
                    'dt_fim'      => $dados['atual'] === 'S' ? null : ($dados['dt_fim'] ?? null),
                    'atual'       => $dados['atual'] ?? 'N',
                    'art'         => $dados['art_id'] ?? null,
                    'cat'         => $dados['cat_id'] ?? null,
                    'comprovada'  => (!empty($dados['art_id']) || !empty($dados['cat_id'])) ? 'S' : 'N',
                    'visivel'     => $dados['visivel'] ?? 'S',
                    'log'         => 'Experiência atualizada pelo titular',
                    'id'          => $experienciaId,
                ]
            );

            self::sincronizarCompetencias($experienciaId, $competenciaIds);
        });

        if ($anterior !== null) {
            Auditoria::alteracao('pro_experiencias', $experienciaId, $anterior, [
                'exp_titulo'    => $dados['titulo'],
                'exp_descricao' => $dados['descricao'] ?? null,
                'exp_art_id'    => $dados['art_id'] ?? null,
                'exp_cat_id'    => $dados['cat_id'] ?? null,
                'exp_visivel'   => $dados['visivel'] ?? 'S',
            ], 'Atualização de experiência profissional');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function competencias(int $experienciaId): array
    {
        return BancoDados::buscarTodos(
            'SELECT ec.ecp_cmp_id, c.cmp_nome, a.are_nome
               FROM pro_experiencia_competencias ec
               JOIN pro_competencias c ON c.cmp_id = ec.ecp_cmp_id
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
              WHERE ec.ecp_exp_id = :experiencia AND ec.ecp_status = :ativo
              ORDER BY c.cmp_nome',
            ['experiencia' => $experienciaId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<int> */
    public static function idsCompetencias(int $experienciaId): array
    {
        return array_map(
            static fn (array $linha): int => (int) $linha['ecp_cmp_id'],
            self::competencias($experienciaId)
        );
    }

    /** @param list<int> $competenciaIds */
    private static function sincronizarCompetencias(int $experienciaId, array $competenciaIds): void
    {
        BancoDados::executar(
            'UPDATE pro_experiencia_competencias
                SET ecp_status = :excluido, ecp_log = :log
              WHERE ecp_exp_id = :experiencia AND ecp_status = :ativo',
            [
                'excluido'    => STATUS_EXCLUIDO,
                'log'         => 'Substituida na atualização da experiência',
                'experiencia' => $experienciaId,
                'ativo'       => STATUS_ATIVO,
            ]
        );

        foreach ($competenciaIds as $competenciaId) {
            BancoDados::executar(
                'INSERT INTO pro_experiencia_competencias (ecp_exp_id, ecp_cmp_id, ecp_log, ecp_status)
                 VALUES (:experiencia, :competencia, :log, :ativo)
                 ON DUPLICATE KEY UPDATE ecp_status = VALUES(ecp_status), ecp_log = VALUES(ecp_log)',
                [
                    'experiencia' => $experienciaId,
                    'competencia' => $competenciaId,
                    'log'         => 'Competência vinculada a experiência',
                    'ativo'       => STATUS_ATIVO,
                ]
            );
        }
    }

    public static function definirModeracao(int $experienciaId, string $situacao): void
    {
        BancoDados::executar(
            'UPDATE pro_experiencias SET exp_moderacao = :situacao, exp_log = :log WHERE exp_id = :id',
            ['situacao' => $situacao, 'log' => 'Moderacao: ' . $situacao, 'id' => $experienciaId]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'pro_experiencias',
            'entidade_id' => $experienciaId,
            'descricao'   => 'Experiência marcada como ' . $situacao,
            'severidade'  => 'ALERTA',
        ]);
    }

    /**
     * Anos de experiencia estimados a partir das experiencias publicadas,
     * usado como criterio objetivo no matching quando o profissional nao
     * declarou o tempo total.
     */
    public static function anosEstimados(int $perfilId): int
    {
        $meses = (int) BancoDados::valor(
            'SELECT COALESCE(SUM(
                        TIMESTAMPDIFF(
                            MONTH,
                            exp_dt_inicio,
                            COALESCE(exp_dt_fim, CURDATE())
                        )
                    ), 0)
               FROM pro_experiencias
              WHERE exp_prf_id = :perfil
                AND exp_status = :ativo
                AND exp_dt_inicio IS NOT NULL',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );

        return (int) floor($meses / 12);
    }
}
