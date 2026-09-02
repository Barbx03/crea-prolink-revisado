<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base dos repositorios (item 8.1.1.b do Termo de Referencia).
 *
 * Separa a camada de persistencia das regras de negocio e concentra o
 * comportamento comum: exclusao logica, restauracao e paginacao. Nenhum
 * controlador acessa PDO diretamente.
 */
abstract class Repositorio
{
    /** Nome da tabela, ex.: pro_demandas */
    abstract protected static function tabela(): string;

    /** Prefixo dos campos, ex.: dem */
    abstract protected static function prefixo(): string;

    protected static function chavePrimaria(): string
    {
        return static::prefixo() . '_id';
    }

    protected static function campoStatus(): string
    {
        return static::prefixo() . '_status';
    }

    protected static function campoLog(): string
    {
        return static::prefixo() . '_log';
    }

    /**
     * Busca por identificador, ignorando registros logicamente excluidos.
     *
     * @return array<string, mixed>|null
     */
    public static function porId(int $id, bool $incluirExcluidos = false): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :id',
            static::tabela(),
            static::chavePrimaria()
        );

        $parametros = ['id' => $id];

        if (!$incluirExcluidos) {
            $sql .= sprintf(' AND %s <> :excluido', static::campoStatus());
            $parametros['excluido'] = STATUS_EXCLUIDO;
        }

        return BancoDados::buscarUm($sql . ' LIMIT 1', $parametros);
    }

    /**
     * Exclusao logica (item 8.6.j): o registro recebe status 'X' e deixa de
     * aparecer nas consultas operacionais, permanecendo recuperavel pela
     * lixeira administrativa.
     */
    public static function excluirLogicamente(int $id, string $motivo = ''): bool
    {
        $anterior = static::porId($id);

        if ($anterior === null) {
            return false;
        }

        BancoDados::executar(
            sprintf(
                'UPDATE %s SET %s = :status, %s = :log WHERE %s = :id',
                static::tabela(),
                static::campoStatus(),
                static::campoLog(),
                static::chavePrimaria()
            ),
            [
                'status' => STATUS_EXCLUIDO,
                'log'    => substr('Exclusão lógica' . ($motivo !== '' ? ': ' . $motivo : ''), 0, 255),
                'id'     => $id,
            ]
        );

        Auditoria::registrar('EXCLUSAO_LOGICA', [
            'entidade'    => static::tabela(),
            'entidade_id' => $id,
            'descricao'   => $motivo !== '' ? $motivo : 'Registro marcado como excluido',
            'antes'       => ['status' => $anterior[static::campoStatus()]],
            'depois'      => ['status' => STATUS_EXCLUIDO],
            'severidade'  => 'ALERTA',
        ]);

        return true;
    }

    /**
     * Restauracao pela lixeira administrativa (item 8.6.j).
     */
    public static function restaurar(int $id): bool
    {
        $registro = static::porId($id, true);

        if ($registro === null || $registro[static::campoStatus()] !== STATUS_EXCLUIDO) {
            return false;
        }

        BancoDados::executar(
            sprintf(
                'UPDATE %s SET %s = :status, %s = :log WHERE %s = :id',
                static::tabela(),
                static::campoStatus(),
                static::campoLog(),
                static::chavePrimaria()
            ),
            [
                'status' => STATUS_ATIVO,
                'log'    => 'Restaurado pela lixeira administrativa',
                'id'     => $id,
            ]
        );

        Auditoria::registrar('RESTAURACAO', [
            'entidade'    => static::tabela(),
            'entidade_id' => $id,
            'descricao'   => 'Registro restaurado da lixeira',
            'antes'       => ['status' => STATUS_EXCLUIDO],
            'depois'      => ['status' => STATUS_ATIVO],
            'severidade'  => 'ALERTA',
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listarExcluidos(int $limite = 50): array
    {
        return BancoDados::buscarTodos(
            sprintf(
                'SELECT * FROM %s WHERE %s = :excluido ORDER BY %s DESC LIMIT %d',
                static::tabela(),
                static::campoStatus(),
                static::chavePrimaria(),
                max(1, min($limite, 200))
            ),
            ['excluido' => STATUS_EXCLUIDO]
        );
    }

    public static function contarAtivos(): int
    {
        return (int) BancoDados::valor(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s = :ativo',
                static::tabela(),
                static::campoStatus()
            ),
            ['ativo' => STATUS_ATIVO]
        );
    }

    /**
     * Monta a clausula LIMIT/OFFSET com valores sempre inteiros, ja que
     * placeholders nao sao aceitos nessa posicao pelo MariaDB.
     */
    protected static function paginacao(int $pagina, int $porPagina): string
    {
        $porPagina = max(1, min($porPagina, 100));
        $pagina    = max(1, $pagina);

        return sprintf(' LIMIT %d OFFSET %d', $porPagina, ($pagina - 1) * $porPagina);
    }
}
