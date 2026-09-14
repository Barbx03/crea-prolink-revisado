<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Persistencia das modalidades do profissional (RF02).
 *
 * Modalidade e a habilitacao que o Conselho reconhece, e chega somente na
 * consulta do registro a API oficial. Como as ARTs e as CATs, nao existe
 * caminho manual de entrada: o conjunto e substituido a cada validacao, para
 * que uma modalidade retirada na origem nao sobreviva aqui.
 */
final class RepositorioModalidade extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_modalidades';
    }

    protected static function prefixo(): string
    {
        return 'mod';
    }

    /**
     * @param list<array{codigo: string, nome: string}> $modalidades
     */
    public static function sincronizar(int $perfilId, array $modalidades): void
    {
        $codigos = [];

        foreach ($modalidades as $modalidade) {
            $codigo = trim((string) ($modalidade['codigo'] ?? ''));
            $nome   = trim((string) ($modalidade['nome'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $codigos[] = $codigo;

            BancoDados::executar(
                'INSERT INTO pro_modalidades
                    (mod_prf_id, mod_codigo, mod_nome, mod_origem, mod_dt_validacao, mod_log, mod_status)
                 VALUES
                    (:perfil, :codigo, :nome, :origem, NOW(), :log, :ativo)
                 ON DUPLICATE KEY UPDATE
                    mod_nome = VALUES(mod_nome),
                    mod_dt_validacao = NOW(),
                    mod_status = VALUES(mod_status)',
                [
                    'perfil' => $perfilId,
                    'codigo' => $codigo,
                    'nome'   => $nome !== '' ? $nome : $codigo,
                    'origem' => 'API_CREA',
                    'log'    => 'Obtida na validação do registro junto à API oficial',
                    'ativo'  => STATUS_ATIVO,
                ]
            );
        }

        if ($codigos === []) {
            BancoDados::executar(
                'UPDATE pro_modalidades SET mod_status = :excluido
                  WHERE mod_prf_id = :perfil AND mod_status = :ativo',
                ['excluido' => STATUS_EXCLUIDO, 'perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
            );

            return;
        }

        $marcadores = implode(',', array_fill(0, count($codigos), '?'));

        BancoDados::executar(
            "UPDATE pro_modalidades SET mod_status = ?
              WHERE mod_prf_id = ? AND mod_status = ? AND mod_codigo NOT IN ($marcadores)",
            array_merge([STATUS_EXCLUIDO, $perfilId, STATUS_ATIVO], $codigos)
        );
    }

    /** @return list<array<string, mixed>> */
    public static function doPerfil(int $perfilId): array
    {
        return BancoDados::buscarTodos(
            'SELECT * FROM pro_modalidades
              WHERE mod_prf_id = :perfil AND mod_status = :ativo
              ORDER BY mod_nome',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }
}
