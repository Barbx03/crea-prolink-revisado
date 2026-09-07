<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Persistencia das CATs consultadas na API oficial (RF03).
 *
 * Como nas ARTs, a origem dos dados e exclusivamente a API do CREA-AM.
 */
final class RepositorioCat extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_cats';
    }

    protected static function prefixo(): string
    {
        return 'cat';
    }

    /** @return list<array<string, mixed>> */
    public static function doPerfil(int $perfilId, bool $somenteVisiveis = false): array
    {
        $sql = 'SELECT * FROM pro_cats WHERE cat_prf_id = :perfil AND cat_status = :ativo';

        if ($somenteVisiveis) {
            $sql .= " AND cat_visivel = 'S'";
        }

        return BancoDados::buscarTodos(
            $sql . ' ORDER BY cat_dt_emissao DESC, cat_id DESC',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function doUsuario(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            'SELECT c.* FROM pro_cats c
               JOIN pro_perfis p ON p.prf_id = c.cat_prf_id
              WHERE p.prf_usu_id = :usuario AND c.cat_status = :ativo
              ORDER BY c.cat_dt_emissao DESC',
            ['usuario' => $usuarioId, 'ativo' => STATUS_ATIVO]
        );
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function registrarValidada(int $perfilId, array $dados, string $payloadOriginal): int
    {
        BancoDados::executar(
            'INSERT INTO pro_cats
                (cat_prf_id, cat_numero, cat_rnp, cat_tipo, cat_objeto,
                 cat_dt_emissao, cat_dt_validade, cat_situacao, cat_origem,
                 cat_validada, cat_dt_validacao, cat_payload, cat_visivel, cat_log, cat_status)
             VALUES
                (:perfil, :numero, :rnp, :tipo, :objeto,
                 :dt_emissao, :dt_validade, :situacao, :origem,
                 :validada, NOW(), :payload, :visivel, :log, :ativo)
             ON DUPLICATE KEY UPDATE
                cat_tipo = VALUES(cat_tipo),
                cat_objeto = VALUES(cat_objeto),
                cat_dt_emissao = VALUES(cat_dt_emissao),
                cat_dt_validade = VALUES(cat_dt_validade),
                cat_situacao = VALUES(cat_situacao),
                cat_validada = VALUES(cat_validada),
                cat_dt_validacao = NOW(),
                cat_payload = VALUES(cat_payload),
                cat_status = VALUES(cat_status),
                cat_log = :logAtualizacao',
            [
                'perfil'         => $perfilId,
                'numero'         => $dados['numero'],
                'rnp'            => $dados['rnp'],
                'tipo'           => $dados['tipo'] ?? null,
                'objeto'         => $dados['objeto'] ?? null,
                'dt_emissao'     => $dados['dt_emissao'] ?? null,
                'dt_validade'    => $dados['dt_validade'] ?? null,
                'situacao'       => $dados['situacao'] ?? null,
                'origem'         => 'API_CREA',
                'validada'       => 'S',
                'payload'        => $payloadOriginal,
                'visivel'        => 'S',
                'log'            => 'CAT obtida da API oficial',
                'logAtualizacao' => 'CAT revalidada na API oficial',
                'ativo'          => STATUS_ATIVO,
            ]
        );

        $id = BancoDados::ultimoId();

        Auditoria::registrar('CAT_ASSOCIADA', [
            'entidade'    => 'pro_cats',
            'entidade_id' => $id,
            'descricao'   => sprintf('CAT %s obtida da API oficial', $dados['numero']),
        ]);

        return $id;
    }

    public static function definirVisibilidade(int $catId, bool $visivel): void
    {
        BancoDados::executar(
            'UPDATE pro_cats SET cat_visivel = :visivel, cat_log = :log WHERE cat_id = :id',
            [
                'visivel' => $visivel ? 'S' : 'N',
                'log'     => 'Visibilidade da CAT alterada pelo titular',
                'id'      => $catId,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public static function comDono(int $catId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT c.*, p.prf_usu_id, p.prf_id
               FROM pro_cats c
               JOIN pro_perfis p ON p.prf_id = c.cat_prf_id
              WHERE c.cat_id = :id AND c.cat_status <> :excluido
              LIMIT 1',
            ['id' => $catId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    public static function contarDoPerfil(int $perfilId): int
    {
        return (int) BancoDados::valor(
            'SELECT COUNT(*) FROM pro_cats WHERE cat_prf_id = :perfil AND cat_status = :ativo',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }
}
