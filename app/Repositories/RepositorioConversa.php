<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Comunicacao inicial entre contratantes e profissionais (RF05).
 *
 * A conversa e sempre entre dois participantes e, quando originada de uma
 * demanda, guarda o vinculo com ela e com a manifestacao de interesse.
 */
final class RepositorioConversa extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_conversas';
    }

    protected static function prefixo(): string
    {
        return 'cnv';
    }

    /**
     * Recupera a conversa existente entre dois usuarios no contexto de uma
     * demanda, ou cria uma nova.
     */
    public static function abrir(int $usuarioOrigem, int $usuarioDestino, ?int $demandaId, ?int $interesseId, string $assunto): int
    {
        $existente = BancoDados::buscarUm(
            'SELECT cnv_id FROM pro_conversas
              WHERE cnv_status = :ativo
                AND ((cnv_usu_origem = :origem AND cnv_usu_destino = :destino)
                  OR (cnv_usu_origem = :destino AND cnv_usu_destino = :origem))
                AND ((:demanda IS NULL AND cnv_dem_id IS NULL) OR cnv_dem_id = :demanda)
              LIMIT 1',
            [
                'ativo'   => STATUS_ATIVO,
                'origem'  => $usuarioOrigem,
                'destino' => $usuarioDestino,
                'demanda' => $demandaId,
            ]
        );

        if ($existente !== null) {
            return (int) $existente['cnv_id'];
        }

        BancoDados::executar(
            'INSERT INTO pro_conversas
                (cnv_dem_id, cnv_int_id, cnv_usu_origem, cnv_usu_destino, cnv_assunto, cnv_log, cnv_status)
             VALUES (:demanda, :interesse, :origem, :destino, :assunto, :log, :ativo)',
            [
                'demanda'   => $demandaId,
                'interesse' => $interesseId,
                'origem'    => $usuarioOrigem,
                'destino'   => $usuarioDestino,
                'assunto'   => substr($assunto, 0, 190),
                'log'       => 'Conversa iniciada pela plataforma',
                'ativo'     => STATUS_ATIVO,
            ]
        );

        $id = BancoDados::ultimoId();

        Auditoria::registrar('CONVERSA_INICIADA', [
            'entidade'    => 'pro_conversas',
            'entidade_id' => $id,
            'descricao'   => 'Canal de comunicacao aberto entre as partes',
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public static function comParticipantes(int $conversaId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT c.*, 
                    uo.usu_nome AS origem_nome, uo.usu_email AS origem_email, uo.usu_perfil AS origem_perfil,
                    ud.usu_nome AS destino_nome, ud.usu_email AS destino_email, ud.usu_perfil AS destino_perfil,
                    d.dem_titulo, d.dem_id
               FROM pro_conversas c
               JOIN sis_usuarios uo ON uo.usu_id = c.cnv_usu_origem
               JOIN sis_usuarios ud ON ud.usu_id = c.cnv_usu_destino
               LEFT JOIN pro_demandas d ON d.dem_id = c.cnv_dem_id
              WHERE c.cnv_id = :id AND c.cnv_status <> :excluido
              LIMIT 1',
            ['id' => $conversaId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    public static function participa(int $conversaId, int $usuarioId): bool
    {
        return (int) BancoDados::valor(
            'SELECT COUNT(*) FROM pro_conversas
              WHERE cnv_id = :id AND (cnv_usu_origem = :usuario OR cnv_usu_destino = :usuario)',
            ['id' => $conversaId, 'usuario' => $usuarioId]
        ) > 0;
    }

    /**
     * Caixa de entrada do usuario, com a ultima mensagem e o total nao lido.
     *
     * @return list<array<string, mixed>>
     */
    public static function doUsuario(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            "SELECT c.cnv_id, c.cnv_assunto, c.cnv_dt_ultima_msg, c.cnv_bloqueada, c.cnv_dem_id,
                    d.dem_titulo,
                    CASE WHEN c.cnv_usu_origem = :usuario THEN ud.usu_nome ELSE uo.usu_nome END AS interlocutor_nome,
                    CASE WHEN c.cnv_usu_origem = :usuario THEN ud.usu_id ELSE uo.usu_id END AS interlocutor_id,
                    CASE WHEN c.cnv_usu_origem = :usuario THEN ud.usu_perfil ELSE uo.usu_perfil END AS interlocutor_perfil,
                    (SELECT msg_conteudo FROM pro_mensagens m
                      WHERE m.msg_cnv_id = c.cnv_id AND m.msg_status = 'A'
                      ORDER BY m.msg_id DESC LIMIT 1) AS ultima_mensagem,
                    (SELECT COUNT(*) FROM pro_mensagens m
                      WHERE m.msg_cnv_id = c.cnv_id
                        AND m.msg_status = 'A'
                        AND m.msg_usu_id <> :usuario
                        AND m.msg_dt_leitura IS NULL) AS nao_lidas
               FROM pro_conversas c
               JOIN sis_usuarios uo ON uo.usu_id = c.cnv_usu_origem
               JOIN sis_usuarios ud ON ud.usu_id = c.cnv_usu_destino
               LEFT JOIN pro_demandas d ON d.dem_id = c.cnv_dem_id
              WHERE c.cnv_status = 'A'
                AND (c.cnv_usu_origem = :usuario OR c.cnv_usu_destino = :usuario)
              ORDER BY COALESCE(c.cnv_dt_ultima_msg, c.cnv_dt_registro) DESC",
            ['usuario' => $usuarioId]
        );
    }

    public static function contarNaoLidas(int $usuarioId): int
    {
        return (int) BancoDados::valor(
            "SELECT COUNT(*)
               FROM pro_mensagens m
               JOIN pro_conversas c ON c.cnv_id = m.msg_cnv_id
              WHERE c.cnv_status = 'A'
                AND (c.cnv_usu_origem = :usuario OR c.cnv_usu_destino = :usuario)
                AND m.msg_usu_id <> :usuario
                AND m.msg_status = 'A'
                AND m.msg_dt_leitura IS NULL",
            ['usuario' => $usuarioId]
        );
    }

    public static function bloquear(int $conversaId, bool $bloquear): void
    {
        BancoDados::executar(
            'UPDATE pro_conversas SET cnv_bloqueada = :bloqueada, cnv_log = :log WHERE cnv_id = :id',
            [
                'bloqueada' => $bloquear ? 'S' : 'N',
                'log'       => $bloquear ? 'Conversa bloqueada pela moderação' : 'Conversa desbloqueada pela moderação',
                'id'        => $conversaId,
            ]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'pro_conversas',
            'entidade_id' => $conversaId,
            'descricao'   => $bloquear ? 'Conversa bloqueada' : 'Conversa desbloqueada',
            'severidade'  => 'ALERTA',
        ]);
    }
}
