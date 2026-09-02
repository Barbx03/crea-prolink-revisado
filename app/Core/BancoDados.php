<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Conexao unica com o MariaDB via PDO.
 *
 * Toda consulta da aplicacao passa por aqui usando exclusivamente prepared
 * statements com parametros vinculados, que e a protecao contra SQL Injection
 * exigida no item 8.5.c do Termo de Referencia. Nao existe na base de codigo
 * nenhuma concatenacao de valor de entrada em SQL.
 */
final class BancoDados
{
    private static ?PDO $conexao = null;

    public static function conexao(): PDO
    {
        if (self::$conexao instanceof PDO) {
            return self::$conexao;
        }

        $dsn = DB_SOCKET !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', DB_SOCKET, DB_NOME, DB_CHARSET)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORTA, DB_NOME, DB_CHARSET);

        try {
            self::$conexao = new PDO($dsn, DB_USUARIO, DB_SENHA, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Conjunto de caracteres e fuso definidos na própria sessão, e não
            // por atributo específico do driver, mantendo o código portável
            // entre as versões de PHP homologadas (itens 8.3.1.g e 8.3.1.h).
            self::$conexao->exec(sprintf(
                "SET NAMES %s COLLATE %s_unicode_ci, time_zone = '-04:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
                DB_CHARSET,
                DB_CHARSET
            ));
        } catch (PDOException $e) {
            Registro::critico('Falha na conexão com o banco de dados', ['erro' => $e->getMessage()]);

            throw new RuntimeException(
                APP_DEBUG
                    ? 'Falha na conexão com o banco de dados: ' . $e->getMessage()
                    : 'Serviço temporariamente indisponível.',
                0,
                $e
            );
        }

        return self::$conexao;
    }

    /**
     * @param array<string, mixed> $parametros
     */
    public static function executar(string $sql, array $parametros = []): PDOStatement
    {
        // Com prepared statements reais (sem emulação), o PDO não aceita o
        // mesmo parâmetro nomeado repetido na consulta. Como repetir um
        // parâmetro deixa o SQL mais legível, a duplicação é desfeita aqui:
        // cada repetição ganha um nome próprio, vinculado ao mesmo valor.
        if ($parametros !== [] && !array_is_list($parametros)) {
            [$sql, $parametros] = self::expandirRepetidos($sql, $parametros);
        }

        $consulta = self::conexao()->prepare($sql);

        foreach ($parametros as $nome => $valor) {
            $chave = is_int($nome) ? $nome + 1 : ':' . ltrim((string) $nome, ':');
            $consulta->bindValue($chave, $valor, self::tipo($valor));
        }

        $consulta->execute();

        return $consulta;
    }

    private static function tipo(mixed $valor): int
    {
        return match (true) {
            is_bool($valor) => PDO::PARAM_BOOL,
            is_int($valor)  => PDO::PARAM_INT,
            is_null($valor) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    /**
     * Reescreve a consulta dando nome único a cada ocorrência repetida de um
     * parâmetro nomeado. Literais entre aspas são preservados intactos, para
     * que um dois-pontos dentro de texto não seja confundido com parâmetro.
     *
     * @param array<string, mixed> $parametros
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function expandirRepetidos(string $sql, array $parametros): array
    {
        $contagem   = [];
        $expandidos = [];

        // Separa a consulta em trechos de código e trechos entre aspas
        $partes = preg_split(
            "/('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/",
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($partes === false) {
            return [$sql, $parametros];
        }

        foreach ($partes as $indice => $parte) {
            // Trechos capturados (índices ímpares) são literais: não mexer
            if ($indice % 2 === 1) {
                continue;
            }

            $partes[$indice] = preg_replace_callback(
                '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
                static function (array $captura) use (&$contagem, &$expandidos, $parametros): string {
                    $nome = $captura[1];

                    if (!array_key_exists($nome, $parametros)) {
                        return $captura[0];
                    }

                    $contagem[$nome] = ($contagem[$nome] ?? 0) + 1;

                    if ($contagem[$nome] === 1) {
                        $expandidos[$nome] = $parametros[$nome];

                        return $captura[0];
                    }

                    $novoNome = $nome . '_r' . $contagem[$nome];
                    $expandidos[$novoNome] = $parametros[$nome];

                    return ':' . $novoNome;
                },
                (string) $parte
            ) ?? $parte;
        }

        // Parâmetros informados e não usados na consulta são descartados, pois
        // o PDO recusa vínculos sem placeholder correspondente.
        return [implode('', $partes), $expandidos];
    }

    /**
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>|null
     */
    public static function buscarUm(string $sql, array $parametros = []): ?array
    {
        $linha = self::executar($sql, $parametros)->fetch();

        return $linha === false ? null : $linha;
    }

    /**
     * @param array<string, mixed> $parametros
     * @return list<array<string, mixed>>
     */
    public static function buscarTodos(string $sql, array $parametros = []): array
    {
        return self::executar($sql, $parametros)->fetchAll();
    }

    /**
     * @param array<string, mixed> $parametros
     */
    public static function valor(string $sql, array $parametros = []): mixed
    {
        $valor = self::executar($sql, $parametros)->fetchColumn();

        return $valor === false ? null : $valor;
    }

    public static function ultimoId(): int
    {
        return (int) self::conexao()->lastInsertId();
    }

    public static function iniciarTransacao(): void
    {
        if (!self::conexao()->inTransaction()) {
            self::conexao()->beginTransaction();
        }
    }

    public static function confirmarTransacao(): void
    {
        if (self::conexao()->inTransaction()) {
            self::conexao()->commit();
        }
    }

    public static function desfazerTransacao(): void
    {
        if (self::conexao()->inTransaction()) {
            self::conexao()->rollBack();
        }
    }

    /**
     * Executa um bloco dentro de uma transacao, desfazendo em caso de excecao.
     */
    public static function transacao(callable $bloco): mixed
    {
        self::iniciarTransacao();

        try {
            $resultado = $bloco();
            self::confirmarTransacao();

            return $resultado;
        } catch (\Throwable $e) {
            self::desfazerTransacao();

            throw $e;
        }
    }
}
