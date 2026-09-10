<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Mensagens trocadas dentro de uma conversa (RF05).
 */
final class RepositorioMensagem extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_mensagens';
    }

    protected static function prefixo(): string
    {
        return 'msg';
    }

    public static function enviar(int $conversaId, int $autorId, string $conteudo): int
    {
        return BancoDados::transacao(static function () use ($conversaId, $autorId, $conteudo): int {
            BancoDados::executar(
                'INSERT INTO pro_mensagens (msg_cnv_id, msg_usu_id, msg_conteudo, msg_log, msg_status)
                 VALUES (:conversa, :autor, :conteudo, :log, :ativo)',
                [
                    'conversa' => $conversaId,
                    'autor'    => $autorId,
                    'conteudo' => $conteudo,
                    'log'      => 'Mensagem enviada pela plataforma',
                    'ativo'    => STATUS_ATIVO,
                ]
            );

            $id = BancoDados::ultimoId();

            BancoDados::executar(
                'UPDATE pro_conversas SET cnv_dt_ultima_msg = NOW() WHERE cnv_id = :id',
                ['id' => $conversaId]
            );

            return $id;
        });
    }

    /** @return list<array<string, mixed>> */
    public static function daConversa(int $conversaId): array
    {
        return BancoDados::buscarTodos(
            'SELECT m.*, u.usu_nome, u.usu_perfil
               FROM pro_mensagens m
               JOIN sis_usuarios u ON u.usu_id = m.msg_usu_id
              WHERE m.msg_cnv_id = :conversa AND m.msg_status = :ativo
              ORDER BY m.msg_id ASC',
            ['conversa' => $conversaId, 'ativo' => STATUS_ATIVO]
        );
    }

    /**
     * Marca como lidas as mensagens que o usuario recebeu nesta conversa.
     */
    public static function marcarLidas(int $conversaId, int $usuarioId): void
    {
        BancoDados::executar(
            'UPDATE pro_mensagens
                SET msg_dt_leitura = NOW()
              WHERE msg_cnv_id = :conversa
                AND msg_usu_id <> :usuario
                AND msg_dt_leitura IS NULL
                AND msg_status = :ativo',
            ['conversa' => $conversaId, 'usuario' => $usuarioId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return array<string, mixed>|null */
    public static function comContexto(int $mensagemId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT m.*, c.cnv_usu_origem, c.cnv_usu_destino, c.cnv_id, u.usu_nome
               FROM pro_mensagens m
               JOIN pro_conversas c ON c.cnv_id = m.msg_cnv_id
               JOIN sis_usuarios u ON u.usu_id = m.msg_usu_id
              WHERE m.msg_id = :id
              LIMIT 1',
            ['id' => $mensagemId]
        );
    }

    public static function definirModeracao(int $mensagemId, string $situacao): void
    {
        BancoDados::executar(
            'UPDATE pro_mensagens SET msg_moderacao = :situacao, msg_log = :log WHERE msg_id = :id',
            ['situacao' => $situacao, 'log' => 'Moderacao: ' . $situacao, 'id' => $mensagemId]
        );
    }
}
