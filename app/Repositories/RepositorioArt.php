<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Persistencia das ARTs do portfolio (RF03).
 *
 * Registros nesta tabela existem apenas como copia local de dados retornados
 * pela API oficial do CREA-AM, para exibicao e rastreabilidade. A aplicacao
 * nao permite criar ART manualmente: o unico caminho de entrada e o retorno
 * validado da API (item 8.4).
 */
final class RepositorioArt extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_arts';
    }

    protected static function prefixo(): string
    {
        return 'art';
    }

    /** @return list<array<string, mixed>> */
    public static function doPerfil(int $perfilId, bool $somenteVisiveis = false): array
    {
        $sql = 'SELECT * FROM pro_arts WHERE art_prf_id = :perfil AND art_status = :ativo';

        if ($somenteVisiveis) {
            $sql .= " AND art_visivel = 'S'";
        }

        return BancoDados::buscarTodos(
            $sql . ' ORDER BY art_destaque DESC, art_dt_inicio DESC, art_id DESC',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function doUsuario(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            'SELECT a.* FROM pro_arts a
               JOIN pro_perfis p ON p.prf_id = a.art_prf_id
              WHERE p.prf_usu_id = :usuario AND a.art_status = :ativo
              ORDER BY a.art_dt_inicio DESC',
            ['usuario' => $usuarioId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return array<string, mixed>|null */
    public static function porNumero(int $perfilId, string $numero): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM pro_arts
              WHERE art_prf_id = :perfil AND art_numero = :numero LIMIT 1',
            ['perfil' => $perfilId, 'numero' => $numero]
        );
    }

    /**
     * Grava a ART retornada pela API oficial, atualizando se ja existir.
     *
     * @param array<string, mixed> $dados Campos ja normalizados pelo servico de integracao
     */
    public static function registrarValidada(int $perfilId, array $dados, string $payloadOriginal): int
    {
        BancoDados::executar(
            'INSERT INTO pro_arts
                (art_prf_id, art_numero, art_rnp, art_tipo, art_objeto, art_contratante,
                 art_valor_contrato, art_municipio, art_uf, art_dt_inicio, art_dt_fim,
                 art_situacao, art_origem, art_validada, art_dt_validacao, art_payload,
                 art_visivel, art_log, art_status)
             VALUES
                (:perfil, :numero, :rnp, :tipo, :objeto, :contratante,
                 :valor, :municipio, :uf, :dt_inicio, :dt_fim,
                 :situacao, :origem, :validada, NOW(), :payload,
                 :visivel, :log, :ativo)
             ON DUPLICATE KEY UPDATE
                art_tipo = VALUES(art_tipo),
                art_objeto = VALUES(art_objeto),
                art_contratante = VALUES(art_contratante),
                art_valor_contrato = VALUES(art_valor_contrato),
                art_municipio = VALUES(art_municipio),
                art_uf = VALUES(art_uf),
                art_dt_inicio = VALUES(art_dt_inicio),
                art_dt_fim = VALUES(art_dt_fim),
                art_situacao = VALUES(art_situacao),
                art_validada = VALUES(art_validada),
                art_dt_validacao = NOW(),
                art_payload = VALUES(art_payload),
                art_status = VALUES(art_status),
                art_log = :logAtualizacao',
            [
                'perfil'          => $perfilId,
                'numero'          => $dados['numero'],
                'rnp'             => $dados['rnp'],
                'tipo'            => $dados['tipo'] ?? null,
                'objeto'          => $dados['objeto'] ?? null,
                'contratante'     => $dados['contratante'] ?? null,
                'valor'           => $dados['valor_contrato'] ?? null,
                'municipio'       => $dados['municipio'] ?? null,
                'uf'              => $dados['uf'] ?? null,
                'dt_inicio'       => $dados['dt_inicio'] ?? null,
                'dt_fim'          => $dados['dt_fim'] ?? null,
                'situacao'        => $dados['situacao'] ?? null,
                'origem'          => 'API_CREA',
                'validada'        => 'S',
                'payload'         => $payloadOriginal,
                'visivel'         => 'S',
                'log'             => 'Associada ao portfólio após validação na API oficial',
                'logAtualizacao'  => 'Revalidada na API oficial',
                'ativo'           => STATUS_ATIVO,
            ]
        );

        $id = BancoDados::ultimoId();

        if ($id === 0) {
            $existente = self::porNumero($perfilId, (string) $dados['numero']);
            $id = (int) ($existente['art_id'] ?? 0);
        }

        Auditoria::registrar('ART_ASSOCIADA', [
            'entidade'    => 'pro_arts',
            'entidade_id' => $id,
            'descricao'   => sprintf('ART %s validada na API oficial e associada ao portfólio', $dados['numero']),
        ]);

        return $id;
    }

    public static function definirVisibilidade(int $artId, bool $visivel): void
    {
        BancoDados::executar(
            'UPDATE pro_arts SET art_visivel = :visivel, art_log = :log WHERE art_id = :id',
            [
                'visivel' => $visivel ? 'S' : 'N',
                'log'     => $visivel ? 'Exibicao autorizada pelo titular' : 'Exibicao restringida pelo titular',
                'id'      => $artId,
            ]
        );

        Auditoria::registrar('ATUALIZACAO', [
            'entidade'    => 'pro_arts',
            'entidade_id' => $artId,
            'descricao'   => $visivel
                ? 'Titular autorizou a exibição pública da ART'
                : 'Titular restringiu a exibição pública da ART',
        ]);
    }

    public static function definirDestaque(int $artId, bool $destaque): void
    {
        BancoDados::executar(
            'UPDATE pro_arts SET art_destaque = :destaque, art_log = :log WHERE art_id = :id',
            [
                'destaque' => $destaque ? 'S' : 'N',
                'log'      => 'Destaque no portfólio alterado',
                'id'       => $artId,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public static function comDono(int $artId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT a.*, p.prf_usu_id, p.prf_id
               FROM pro_arts a
               JOIN pro_perfis p ON p.prf_id = a.art_prf_id
              WHERE a.art_id = :id AND a.art_status <> :excluido
              LIMIT 1',
            ['id' => $artId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    public static function contarDoPerfil(int $perfilId): int
    {
        return (int) BancoDados::valor(
            'SELECT COUNT(*) FROM pro_arts WHERE art_prf_id = :perfil AND art_status = :ativo',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }
}
