<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Manifestacoes de interesse em demandas (RF05).
 */
final class RepositorioInteresse extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_interesses';
    }

    protected static function prefixo(): string
    {
        return 'int';
    }

    public static function jaManifestou(int $demandaId, int $usuarioId): bool
    {
        return (int) BancoDados::valor(
            "SELECT COUNT(*) FROM pro_interesses
              WHERE int_dem_id = :demanda AND int_usu_id = :usuario
                AND int_status = 'A' AND int_situacao <> 'RETIRADO'",
            ['demanda' => $demandaId, 'usuario' => $usuarioId]
        ) > 0;
    }

    /** @return array<string, mixed>|null */
    public static function doUsuarioNaDemanda(int $demandaId, int $usuarioId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM pro_interesses
              WHERE int_dem_id = :demanda AND int_usu_id = :usuario AND int_status = :ativo
              LIMIT 1',
            ['demanda' => $demandaId, 'usuario' => $usuarioId, 'ativo' => STATUS_ATIVO]
        );
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function manifestar(int $demandaId, int $usuarioId, array $dados): int
    {
        $id = BancoDados::transacao(static function () use ($demandaId, $usuarioId, $dados): int {
            BancoDados::executar(
                'INSERT INTO pro_interesses
                    (int_dem_id, int_usu_id, int_mensagem, int_valor_proposto,
                     int_prazo_proposto, int_aderencia, int_situacao, int_log, int_status)
                 VALUES
                    (:demanda, :usuario, :mensagem, :valor, :prazo, :aderencia, :situacao, :log, :ativo)
                 ON DUPLICATE KEY UPDATE
                    int_mensagem = VALUES(int_mensagem),
                    int_valor_proposto = VALUES(int_valor_proposto),
                    int_prazo_proposto = VALUES(int_prazo_proposto),
                    int_aderencia = VALUES(int_aderencia),
                    int_situacao = VALUES(int_situacao),
                    int_status = VALUES(int_status),
                    int_log = :logAtualizacao',
                [
                    'demanda'        => $demandaId,
                    'usuario'        => $usuarioId,
                    'mensagem'       => $dados['mensagem'] ?? null,
                    'valor'          => $dados['valor_proposto'] ?? null,
                    'prazo'          => $dados['prazo_proposto'] ?? null,
                    'aderencia'      => $dados['aderencia'] ?? null,
                    'situacao'       => 'ENVIADO',
                    'log'            => 'Interesse manifestado',
                    'logAtualizacao' => 'Interesse reenviado após retirada',
                    'ativo'          => STATUS_ATIVO,
                ]
            );

            $registro = self::doUsuarioNaDemanda($demandaId, $usuarioId);

            return (int) ($registro['int_id'] ?? 0);
        });

        RepositorioDemanda::recontarInteresses($demandaId);

        Auditoria::registrar('INTERESSE_MANIFESTADO', [
            'entidade'    => 'pro_interesses',
            'entidade_id' => $id,
            'descricao'   => sprintf('Interesse manifestado na demanda %d', $demandaId),
        ]);

        return $id;
    }

    public static function retirar(int $interesseId, string $motivo = ''): void
    {
        $interesse = self::porId($interesseId);

        BancoDados::executar(
            "UPDATE pro_interesses
                SET int_situacao = 'RETIRADO', int_log = :log
              WHERE int_id = :id",
            ['log' => substr('Interesse retirado. ' . $motivo, 0, 255), 'id' => $interesseId]
        );

        if ($interesse !== null) {
            RepositorioDemanda::recontarInteresses((int) $interesse['int_dem_id']);
        }

        Auditoria::registrar('INTERESSE_RETIRADO', [
            'entidade'    => 'pro_interesses',
            'entidade_id' => $interesseId,
            'descricao'   => 'Manifestação de interesse retirada pelo profissional',
        ]);
    }

    public static function marcarVisualizado(int $interesseId): void
    {
        BancoDados::executar(
            "UPDATE pro_interesses
                SET int_situacao = CASE WHEN int_situacao = 'ENVIADO' THEN 'VISUALIZADO' ELSE int_situacao END,
                    int_dt_visualizacao = COALESCE(int_dt_visualizacao, NOW()),
                    int_log = 'Perfil do interessado visualizado pelo autor da demanda'
              WHERE int_id = :id",
            ['id' => $interesseId]
        );
    }

    public static function responder(int $interesseId, string $situacao, string $resposta): void
    {
        BancoDados::executar(
            'UPDATE pro_interesses
                SET int_situacao = :situacao,
                    int_resposta = :resposta,
                    int_dt_resposta = NOW(),
                    int_log = :log
              WHERE int_id = :id',
            [
                'situacao' => $situacao,
                'resposta' => $resposta !== '' ? $resposta : null,
                'log'      => 'Resposta do autor da demanda: ' . $situacao,
                'id'       => $interesseId,
            ]
        );

        Auditoria::registrar('INTERESSE_RESPONDIDO', [
            'entidade'    => 'pro_interesses',
            'entidade_id' => $interesseId,
            'descricao'   => 'Manifestação respondida com situação ' . $situacao,
        ]);
    }

    /**
     * Interessados em uma demanda, com dados do perfil para o autor avaliar.
     *
     * @return list<array<string, mixed>>
     */
    public static function daDemanda(int $demandaId): array
    {
        $itens = BancoDados::buscarTodos(
            "SELECT i.*, u.usu_nome, u.usu_perfil, u.usu_registrado_crea, u.usu_rnp,
                    u.usu_email, u.usu_telefone,
                    p.prf_id, p.prf_titulo, p.prf_resumo, p.prf_uf, p.prf_cidade,
                    p.prf_anos_experiencia, p.prf_disponibilidade, p.prf_foto,
                    p.prf_exibe_contato,
                    (SELECT COUNT(*) FROM pro_arts a
                      WHERE a.art_prf_id = p.prf_id AND a.art_status = 'A') AS total_arts
               FROM pro_interesses i
               JOIN sis_usuarios u ON u.usu_id = i.int_usu_id
               LEFT JOIN pro_perfis p ON p.prf_usu_id = u.usu_id AND p.prf_status = 'A'
              WHERE i.int_dem_id = :demanda AND i.int_status = :ativo
              ORDER BY FIELD(i.int_situacao, 'SELECIONADO', 'EM_NEGOCIACAO', 'VISUALIZADO', 'ENVIADO', 'RECUSADO', 'RETIRADO'),
                       i.int_aderencia DESC, i.int_dt_registro ASC",
            ['demanda' => $demandaId, 'ativo' => STATUS_ATIVO]
        );

        foreach ($itens as $indice => $item) {
            if (!empty($item['prf_id'])) {
                $itens[$indice]['competencias'] = array_slice(
                    RepositorioPerfil::competencias((int) $item['prf_id']),
                    0,
                    8
                );
            }
        }

        return $itens;
    }

    /**
     * Manifestacoes feitas pelo usuario, para o painel dele.
     *
     * @return list<array<string, mixed>>
     */
    public static function doUsuario(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            'SELECT i.*, d.dem_titulo, d.dem_situacao, d.dem_uf, d.dem_cidade,
                    d.dem_dt_limite, d.dem_modalidade, d.dem_id,
                    ua.usu_nome AS autor_nome
               FROM pro_interesses i
               JOIN pro_demandas d ON d.dem_id = i.int_dem_id
               JOIN sis_usuarios ua ON ua.usu_id = d.dem_usu_id
              WHERE i.int_usu_id = :usuario AND i.int_status = :ativo
              ORDER BY i.int_dt_registro DESC',
            ['usuario' => $usuarioId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return array<string, mixed>|null */
    public static function completoPorId(int $interesseId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT i.*, d.dem_titulo, d.dem_usu_id AS autor_id, d.dem_id,
                    u.usu_nome AS interessado_nome, u.usu_email AS interessado_email
               FROM pro_interesses i
               JOIN pro_demandas d ON d.dem_id = i.int_dem_id
               JOIN sis_usuarios u ON u.usu_id = i.int_usu_id
              WHERE i.int_id = :id AND i.int_status <> :excluido
              LIMIT 1',
            ['id' => $interesseId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(int_situacao = 'ENVIADO') AS enviados,
                SUM(int_situacao = 'VISUALIZADO') AS visualizados,
                SUM(int_situacao = 'EM_NEGOCIACAO') AS em_negociacao,
                SUM(int_situacao = 'SELECIONADO') AS selecionados,
                SUM(int_situacao = 'RECUSADO') AS recusados,
                SUM(DATE(int_dt_registro) = CURDATE()) AS hoje
             FROM pro_interesses WHERE int_status = 'A'"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }
}
