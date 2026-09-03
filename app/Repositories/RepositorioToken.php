<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Tokens de uso único para recuperação de acesso e verificação de e-mail (RF01).
 *
 * O token entregue ao usuário nunca é armazenado: guarda-se apenas o seu hash,
 * de modo que o vazamento da tabela não permite reutilizar nenhum link.
 */
final class RepositorioToken extends Repositorio
{
    protected static function tabela(): string
    {
        return 'sis_tokens';
    }

    protected static function prefixo(): string
    {
        return 'tok';
    }

    public const RECUPERACAO_SENHA = 'RECUPERACAO_SENHA';
    public const VERIFICACAO_EMAIL = 'VERIFICACAO_EMAIL';

    /**
     * Gera o token, grava o hash e devolve o valor em claro, que só existe
     * nesta chamada e no link enviado ao usuário.
     */
    public static function gerar(int $usuarioId, string $tipo, int $validadeMinutos, string $ip = ''): string
    {
        $token = bin2hex(random_bytes(32));

        BancoDados::executar(
            'INSERT INTO sis_tokens
                (tok_usu_id, tok_tipo, tok_hash, tok_dt_expira, tok_ip_origem, tok_log, tok_status)
             VALUES
                (:usuario, :tipo, :hash, DATE_ADD(NOW(), INTERVAL :minutos MINUTE), :ip, :log, :ativo)',
            [
                'usuario' => $usuarioId,
                'tipo'    => $tipo,
                'hash'    => self::hash($token),
                'minutos' => $validadeMinutos,
                'ip'      => $ip !== '' ? $ip : null,
                'log'     => 'Token de uso único gerado',
                'ativo'   => STATUS_ATIVO,
            ]
        );

        return $token;
    }

    /**
     * Recupera o token pendente correspondente ao valor informado.
     *
     * @return array<string, mixed>|null
     */
    public static function pendente(string $token, string $tipo): ?array
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return BancoDados::buscarUm(
            'SELECT t.*, u.usu_email
               FROM sis_tokens t
               JOIN sis_usuarios u ON u.usu_id = t.tok_usu_id
              WHERE t.tok_hash = :hash
                AND t.tok_tipo = :tipo
                AND t.tok_status = :ativo
                AND t.tok_dt_uso IS NULL
                AND t.tok_dt_expira >= NOW()
                AND u.usu_status <> :excluido
              LIMIT 1',
            [
                'hash'     => self::hash($token),
                'tipo'     => $tipo,
                'ativo'    => STATUS_ATIVO,
                'excluido' => STATUS_EXCLUIDO,
            ]
        );
    }

    /**
     * Marca o token como usado.
     */
    public static function consumir(int $tokenId): void
    {
        BancoDados::executar(
            'UPDATE sis_tokens
                SET tok_dt_uso = NOW(), tok_status = :usado, tok_log = :log
              WHERE tok_id = :id',
            ['usado' => STATUS_EXCLUIDO, 'log' => 'Token utilizado', 'id' => $tokenId]
        );
    }

    /**
     * Invalida os demais tokens pendentes do mesmo tipo, para que uma
     * redefinição bem-sucedida encerre todos os links em circulação.
     */
    public static function invalidarPendentes(int $usuarioId, string $tipo): void
    {
        BancoDados::executar(
            'UPDATE sis_tokens
                SET tok_status = :cancelado, tok_log = :log
              WHERE tok_usu_id = :usuario AND tok_tipo = :tipo AND tok_status = :ativo',
            [
                'cancelado' => STATUS_EXCLUIDO,
                'log'       => 'Cancelado após uso bem-sucedido de outro token',
                'usuario'   => $usuarioId,
                'tipo'      => $tipo,
                'ativo'     => STATUS_ATIVO,
            ]
        );
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
