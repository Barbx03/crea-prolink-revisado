<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BancoDados;

/**
 * Consulta da trilha de auditoria (RF06, cenario 7.6).
 *
 * Somente leitura: a gravacao acontece em App\Core\Auditoria e a tabela nunca
 * e alterada pela aplicacao.
 */
final class RepositorioAuditoria
{
    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function consultar(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['acao'])) {
            $condicoes[] = 'a.aud_acao = :acao';
            $parametros['acao'] = $filtros['acao'];
        }

        if (!empty($filtros['usuario_id'])) {
            $condicoes[] = 'a.aud_usu_id = :usuario';
            $parametros['usuario'] = (int) $filtros['usuario_id'];
        }

        if (!empty($filtros['entidade'])) {
            $condicoes[] = 'a.aud_entidade = :entidade';
            $parametros['entidade'] = $filtros['entidade'];
        }

        if (!empty($filtros['entidade_id'])) {
            $condicoes[] = 'a.aud_entidade_id = :entidade_id';
            $parametros['entidade_id'] = (string) $filtros['entidade_id'];
        }

        if (!empty($filtros['severidade'])) {
            $condicoes[] = 'a.aud_severidade = :severidade';
            $parametros['severidade'] = $filtros['severidade'];
        }

        if (!empty($filtros['data_inicio'])) {
            $condicoes[] = 'a.aud_dt_registro >= :inicio';
            $parametros['inicio'] = $filtros['data_inicio'] . ' 00:00:00';
        }

        if (!empty($filtros['data_fim'])) {
            $condicoes[] = 'a.aud_dt_registro <= :fim';
            $parametros['fim'] = $filtros['data_fim'] . ' 23:59:59';
        }

        if (!empty($filtros['termo'])) {
            $condicoes[] = '(a.aud_descrição LIKE :termo OR a.aud_rota LIKE :termo OR a.aud_ip_origem LIKE :termo)';
            $parametros['termo'] = '%' . $filtros['termo'] . '%';
        }

        $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor('SELECT COUNT(*) FROM sis_auditoria a' . $onde, $parametros);

        $porPagina = max(1, min($porPagina, 200));
        $pagina    = max(1, $pagina);

        $itens = BancoDados::buscarTodos(
            'SELECT a.*, u.usu_nome, u.usu_email, u.usu_perfil
               FROM sis_auditoria a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id'
            . $onde
            . ' ORDER BY a.aud_dt_registro DESC, a.aud_id DESC'
            . sprintf(' LIMIT %d OFFSET %d', $porPagina, ($pagina - 1) * $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Trilha de um registro especifico, usada nas telas de detalhe.
     *
     * @return list<array<string, mixed>>
     */
    public static function doRegistro(string $entidade, int|string $entidadeId, int $limite = 30): array
    {
        return BancoDados::buscarTodos(
            'SELECT a.*, u.usu_nome
               FROM sis_auditoria a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id
              WHERE a.aud_entidade = :entidade AND a.aud_entidade_id = :id
              ORDER BY a.aud_dt_registro DESC
              LIMIT ' . max(1, min($limite, 100)),
            ['entidade' => $entidade, 'id' => (string) $entidadeId]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function doUsuario(int $usuarioId, int $limite = 30): array
    {
        return BancoDados::buscarTodos(
            'SELECT aud_acao, aud_entidade, aud_entidade_id, aud_descricao,
                    aud_ip_origem, aud_severidade, aud_dt_registro
               FROM sis_auditoria
              WHERE aud_usu_id = :usuario
              ORDER BY aud_dt_registro DESC
              LIMIT ' . max(1, min($limite, 100)),
            ['usuario' => $usuarioId]
        );
    }

    /** @return list<string> */
    public static function acoesRegistradas(): array
    {
        $linhas = BancoDados::buscarTodos(
            'SELECT DISTINCT aud_acao FROM sis_auditoria ORDER BY aud_acao'
        );

        return array_map(static fn (array $linha): string => (string) $linha['aud_acao'], $linhas);
    }

    /** @return list<array<string, mixed>> */
    public static function resumoPorAcao(int $dias = 30): array
    {
        return BancoDados::buscarTodos(
            'SELECT aud_acao, COUNT(*) AS total,
                    MAX(aud_dt_registro) AS ultimo_evento
               FROM sis_auditoria
              WHERE aud_dt_registro >= DATE_SUB(NOW(), INTERVAL :dias DAY)
              GROUP BY aud_acao
              ORDER BY total DESC',
            ['dias' => max(1, min($dias, 365))]
        );
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(aud_severidade = 'CRITICO') AS criticos,
                SUM(aud_severidade = 'ALERTA') AS alertas,
                SUM(DATE(aud_dt_registro) = CURDATE()) AS hoje,
                SUM(aud_acao = 'LOGIN_FALHA' AND aud_dt_registro >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS falhas_login_24h
             FROM sis_auditoria"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }

    /**
     * Exportacao da trilha em CSV, para prestacao de contas.
     *
     * @param array<string, mixed> $filtros
     */
    public static function exportarCsv(array $filtros): string
    {
        $resultado = self::consultar($filtros, 1, 200);

        $saida = fopen('php://temp', 'r+');

        if ($saida === false) {
            return '';
        }

        fputcsv($saida, [
            'ID', 'Data e hora', 'Usuario', 'E-mail', 'Acao', 'Entidade',
            'Registro', 'Descricao', 'IP', 'Rota', 'Severidade',
        ], ';', '"', '');

        foreach ($resultado['itens'] as $linha) {
            fputcsv($saida, [
                $linha['aud_id'],
                $linha['aud_dt_registro'],
                $linha['usu_nome'] ?? 'Anonimo',
                $linha['usu_email'] ?? '',
                $linha['aud_acao'],
                $linha['aud_entidade'] ?? '',
                $linha['aud_entidade_id'] ?? '',
                $linha['aud_descricao'] ?? '',
                $linha['aud_ip_origem'] ?? '',
                $linha['aud_rota'] ?? '',
                $linha['aud_severidade'],
            ], ';', '"', '');
        }

        rewind($saida);
        $conteudo = (string) stream_get_contents($saida);
        fclose($saida);

        // BOM para abrir corretamente no Excel em portugues
        return "\xEF\xBB\xBF" . $conteudo;
    }
}
