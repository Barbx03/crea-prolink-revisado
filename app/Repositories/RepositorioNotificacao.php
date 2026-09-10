<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Fila e historico de notificacoes (RF07).
 *
 * A notificacao e sempre gravada antes de qualquer tentativa de envio. Assim,
 * mesmo com SMTP indisponivel, o evento fica registrado, visivel ao usuario na
 * central de notificacoes e reenviavel pelo painel administrativo.
 */
final class RepositorioNotificacao extends Repositorio
{
    protected static function tabela(): string
    {
        return 'sis_notificacoes';
    }

    protected static function prefixo(): string
    {
        return 'not';
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function enfileirar(array $dados): int
    {
        BancoDados::executar(
            'INSERT INTO sis_notificacoes
                (not_usu_id, not_canal, not_evento, not_destinatario,
                 not_assunto, not_corpo, not_link, not_log, not_status)
             VALUES
                (:usuario, :canal, :evento, :destinatario,
                 :assunto, :corpo, :link, :log, :pendente)',
            [
                'usuario'      => $dados['usuario_id'] ?? null,
                'canal'        => $dados['canal'] ?? 'EMAIL',
                'evento'       => $dados['evento'],
                'destinatario' => $dados['destinatario'] ?? null,
                'assunto'      => substr((string) $dados['assunto'], 0, 190),
                'corpo'        => $dados['corpo'],
                'link'         => $dados['link'] ?? null,
                'log'          => 'Notificação registrada na fila',
                'pendente'     => STATUS_ATIVO,
            ]
        );

        return BancoDados::ultimoId();
    }

    public static function marcarEnviada(int $notificacaoId): void
    {
        BancoDados::executar(
            'UPDATE sis_notificacoes
                SET not_status = :enviada,
                    not_dt_envio = NOW(),
                    not_tentativas = not_tentativas + 1,
                    not_erro = NULL,
                    not_log = :log
              WHERE not_id = :id',
            ['enviada' => STATUS_ENVIADA, 'log' => 'Enviada por SMTP', 'id' => $notificacaoId]
        );
    }

    public static function marcarFalha(int $notificacaoId, string $erro): void
    {
        BancoDados::executar(
            'UPDATE sis_notificacoes
                SET not_status = :falha,
                    not_tentativas = not_tentativas + 1,
                    not_erro = :erro,
                    not_log = :log
              WHERE not_id = :id',
            [
                'falha' => STATUS_FALHA,
                'erro'  => substr($erro, 0, 500),
                'log'   => 'Falha no envio por SMTP',
                'id'    => $notificacaoId,
            ]
        );
    }

    /**
     * Notificacoes internas do usuario, exibidas na central de notificacoes.
     *
     * @return list<array<string, mixed>>
     */
    public static function doUsuario(int $usuarioId, int $limite = 50): array
    {
        return BancoDados::buscarTodos(
            'SELECT not_id, not_evento, not_assunto, not_corpo, not_link,
                    not_lida, not_canal, not_status, not_dt_registro
               FROM sis_notificacoes
              WHERE not_usu_id = :usuario AND not_status <> :excluida
              ORDER BY not_dt_registro DESC
              LIMIT ' . max(1, min($limite, 200)),
            ['usuario' => $usuarioId, 'excluida' => STATUS_EXCLUIDO]
        );
    }

    public static function contarNaoLidas(int $usuarioId): int
    {
        return (int) BancoDados::valor(
            "SELECT COUNT(*) FROM sis_notificacoes
              WHERE not_usu_id = :usuario AND not_lida = 'N' AND not_status <> 'X'",
            ['usuario' => $usuarioId]
        );
    }

    public static function marcarLida(int $notificacaoId, int $usuarioId): void
    {
        BancoDados::executar(
            "UPDATE sis_notificacoes SET not_lida = 'S'
              WHERE not_id = :id AND not_usu_id = :usuario",
            ['id' => $notificacaoId, 'usuario' => $usuarioId]
        );
    }

    public static function marcarTodasLidas(int $usuarioId): void
    {
        BancoDados::executar(
            "UPDATE sis_notificacoes SET not_lida = 'S'
              WHERE not_usu_id = :usuario AND not_lida = 'N'",
            ['usuario' => $usuarioId]
        );
    }

    /**
     * Notificacoes pendentes ou com falha, para reprocessamento.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendentes(int $limite = 50): array
    {
        return BancoDados::buscarTodos(
            "SELECT * FROM sis_notificacoes
              WHERE not_canal = 'EMAIL'
                AND not_status IN ('A', 'F')
                AND not_tentativas < 3
              ORDER BY not_dt_registro ASC
              LIMIT " . max(1, min($limite, 200))
        );
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listarParaAdministracao(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['situacao'])) {
            $condicoes[] = 'n.not_status = :situacao';
            $parametros['situacao'] = $filtros['situacao'];
        }

        if (!empty($filtros['evento'])) {
            $condicoes[] = 'n.not_evento = :evento';
            $parametros['evento'] = $filtros['evento'];
        }

        $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor('SELECT COUNT(*) FROM sis_notificacoes n' . $onde, $parametros);

        $itens = BancoDados::buscarTodos(
            'SELECT n.*, u.usu_nome
               FROM sis_notificacoes n
               LEFT JOIN sis_usuarios u ON u.usu_id = n.not_usu_id'
            . $onde
            . ' ORDER BY n.not_dt_registro DESC'
            . static::paginacao($pagina, $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(not_status = 'A') AS pendentes,
                SUM(not_status = 'E') AS enviadas,
                SUM(not_status = 'F') AS falhas
             FROM sis_notificacoes"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }
}
