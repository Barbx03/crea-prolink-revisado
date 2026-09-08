<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Persistencia e pesquisa de demandas por servicos tecnicos (RF04).
 */
final class RepositorioDemanda extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_demandas';
    }

    protected static function prefixo(): string
    {
        return 'dem';
    }

    /** @return array<string, mixed>|null */
    public static function completaPorId(int $demandaId): ?array
    {
        $demanda = BancoDados::buscarUm(
            'SELECT d.*, u.usu_nome AS autor_nome, u.usu_email AS autor_email,
                    u.usu_perfil AS autor_perfil, u.usu_tipo_pessoa AS autor_tipo,
                    u.usu_registrado_crea AS autor_registrado, u.usu_cnpj AS autor_cnpj,
                    a.are_nome
               FROM pro_demandas d
               JOIN sis_usuarios u ON u.usu_id = d.dem_usu_id
               LEFT JOIN pro_areas a ON a.are_id = d.dem_are_id
              WHERE d.dem_id = :id AND d.dem_status <> :excluido
              LIMIT 1',
            ['id' => $demandaId, 'excluido' => STATUS_EXCLUIDO]
        );

        if ($demanda === null) {
            return null;
        }

        $demanda['competencias'] = self::competencias($demandaId);

        return $demanda;
    }

    /** @return list<array<string, mixed>> */
    public static function competencias(int $demandaId): array
    {
        return BancoDados::buscarTodos(
            'SELECT dc.dmc_cmp_id, dc.dmc_obrigatoria, dc.dmc_peso,
                    c.cmp_nome, a.are_nome
               FROM pro_demanda_competencias dc
               JOIN pro_competencias c ON c.cmp_id = dc.dmc_cmp_id
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
              WHERE dc.dmc_dem_id = :demanda AND dc.dmc_status = :ativo
              ORDER BY dc.dmc_obrigatoria DESC, dc.dmc_peso DESC, c.cmp_nome',
            ['demanda' => $demandaId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<int> */
    public static function idsCompetencias(int $demandaId): array
    {
        return array_map(
            static fn (array $linha): int => (int) $linha['dmc_cmp_id'],
            self::competencias($demandaId)
        );
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<int, array{obrigatoria: string, peso: int}> $competencias
     */
    public static function criar(int $autorId, array $dados, array $competencias): int
    {
        return BancoDados::transacao(static function () use ($autorId, $dados, $competencias): int {
            $publicada = ($dados['situacao'] ?? 'RASCUNHO') === 'PUBLICADA';

            BancoDados::executar(
                'INSERT INTO pro_demandas
                    (dem_usu_id, dem_titulo, dem_escopo, dem_are_id, dem_uf, dem_cidade,
                     dem_aceita_remoto, dem_modalidade, dem_exige_art, dem_exige_registro,
                     dem_experiencia_min, dem_orcamento_min, dem_orcamento_max,
                     dem_prazo_execucao, dem_dt_limite, dem_situacao, dem_dt_publicacao,
                     dem_visibilidade, dem_log, dem_status)
                 VALUES
                    (:autor, :titulo, :escopo, :area, :uf, :cidade,
                     :remoto, :modalidade, :exige_art, :exige_registro,
                     :experiencia_min, :orcamento_min, :orcamento_max,
                     :prazo, :dt_limite, :situacao, :dt_publicacao,
                     :visibilidade, :log, :ativo)',
                [
                    'autor'           => $autorId,
                    'titulo'          => $dados['titulo'],
                    'escopo'          => $dados['escopo'],
                    'area'            => $dados['area_id'] ?? null,
                    'uf'              => $dados['uf'] ?? null,
                    'cidade'          => $dados['cidade'] ?? null,
                    'remoto'          => $dados['aceita_remoto'] ?? 'N',
                    'modalidade'      => $dados['modalidade'] ?? 'PROJETO',
                    'exige_art'       => $dados['exige_art'] ?? 'N',
                    'exige_registro'  => $dados['exige_registro'] ?? 'S',
                    'experiencia_min' => $dados['experiencia_min'] ?? null,
                    'orcamento_min'   => $dados['orcamento_min'] ?? null,
                    'orcamento_max'   => $dados['orcamento_max'] ?? null,
                    'prazo'           => $dados['prazo_execucao'] ?? null,
                    'dt_limite'       => $dados['dt_limite'] ?? null,
                    'situacao'        => $dados['situacao'] ?? 'RASCUNHO',
                    'dt_publicacao'   => $publicada ? date('Y-m-d H:i:s') : null,
                    'visibilidade'    => $dados['visibilidade'] ?? 'PUBLICO',
                    'log'             => 'Demanda cadastrada',
                    'ativo'           => STATUS_ATIVO,
                ]
            );

            $id = BancoDados::ultimoId();

            self::sincronizarCompetencias($id, $competencias);

            Auditoria::registrar('CADASTRO', [
                'entidade'    => 'pro_demandas',
                'entidade_id' => $id,
                'descricao'   => 'Demanda cadastrada: ' . $dados['titulo'],
            ]);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<int, array{obrigatoria: string, peso: int}> $competencias
     */
    public static function atualizar(int $demandaId, array $dados, array $competencias): void
    {
        $anterior = self::porId($demandaId);

        BancoDados::transacao(static function () use ($demandaId, $dados, $competencias, $anterior): void {
            $eraRascunho = ($anterior['dem_situacao'] ?? '') === 'RASCUNHO';
            $viraPublica = ($dados['situacao'] ?? '') === 'PUBLICADA';

            BancoDados::executar(
                'UPDATE pro_demandas SET
                    dem_titulo = :titulo,
                    dem_escopo = :escopo,
                    dem_are_id = :area,
                    dem_uf = :uf,
                    dem_cidade = :cidade,
                    dem_aceita_remoto = :remoto,
                    dem_modalidade = :modalidade,
                    dem_exige_art = :exige_art,
                    dem_exige_registro = :exige_registro,
                    dem_experiencia_min = :experiencia_min,
                    dem_orcamento_min = :orcamento_min,
                    dem_orcamento_max = :orcamento_max,
                    dem_prazo_execucao = :prazo,
                    dem_dt_limite = :dt_limite,
                    dem_situacao = :situacao,
                    dem_visibilidade = :visibilidade,
                    dem_dt_publicacao = CASE
                        WHEN :publicar = 1 AND dem_dt_publicacao IS NULL THEN NOW()
                        ELSE dem_dt_publicacao
                    END,
                    dem_log = :log
                  WHERE dem_id = :id',
                [
                    'titulo'          => $dados['titulo'],
                    'escopo'          => $dados['escopo'],
                    'area'            => $dados['area_id'] ?? null,
                    'uf'              => $dados['uf'] ?? null,
                    'cidade'          => $dados['cidade'] ?? null,
                    'remoto'          => $dados['aceita_remoto'] ?? 'N',
                    'modalidade'      => $dados['modalidade'] ?? 'PROJETO',
                    'exige_art'       => $dados['exige_art'] ?? 'N',
                    'exige_registro'  => $dados['exige_registro'] ?? 'S',
                    'experiencia_min' => $dados['experiencia_min'] ?? null,
                    'orcamento_min'   => $dados['orcamento_min'] ?? null,
                    'orcamento_max'   => $dados['orcamento_max'] ?? null,
                    'prazo'           => $dados['prazo_execucao'] ?? null,
                    'dt_limite'       => $dados['dt_limite'] ?? null,
                    'situacao'        => $dados['situacao'] ?? 'RASCUNHO',
                    'visibilidade'    => $dados['visibilidade'] ?? 'PUBLICO',
                    'publicar'        => ($eraRascunho && $viraPublica) ? 1 : 0,
                    'log'             => 'Demanda atualizada',
                    'id'              => $demandaId,
                ]
            );

            self::sincronizarCompetencias($demandaId, $competencias);
        });

        if ($anterior !== null) {
            Auditoria::alteracao('pro_demandas', $demandaId, $anterior, [
                'dem_titulo'    => $dados['titulo'],
                'dem_escopo'    => $dados['escopo'],
                'dem_situacao'  => $dados['situacao'] ?? 'RASCUNHO',
                'dem_dt_limite' => $dados['dt_limite'] ?? null,
                'dem_uf'        => $dados['uf'] ?? null,
                'dem_cidade'    => $dados['cidade'] ?? null,
            ], 'Atualização de demanda');
        }
    }

    /** @param array<int, array{obrigatoria: string, peso: int}> $competencias */
    private static function sincronizarCompetencias(int $demandaId, array $competencias): void
    {
        BancoDados::executar(
            'UPDATE pro_demanda_competencias
                SET dmc_status = :excluido, dmc_log = :log
              WHERE dmc_dem_id = :demanda AND dmc_status = :ativo',
            [
                'excluido' => STATUS_EXCLUIDO,
                'log'      => 'Substituida na atualização da demanda',
                'demanda'  => $demandaId,
                'ativo'    => STATUS_ATIVO,
            ]
        );

        foreach ($competencias as $competenciaId => $configuracao) {
            BancoDados::executar(
                'INSERT INTO pro_demanda_competencias
                    (dmc_dem_id, dmc_cmp_id, dmc_obrigatoria, dmc_peso, dmc_log, dmc_status)
                 VALUES (:demanda, :competencia, :obrigatoria, :peso, :log, :ativo)
                 ON DUPLICATE KEY UPDATE
                    dmc_obrigatoria = VALUES(dmc_obrigatoria),
                    dmc_peso = VALUES(dmc_peso),
                    dmc_status = VALUES(dmc_status),
                    dmc_log = VALUES(dmc_log)',
                [
                    'demanda'     => $demandaId,
                    'competencia' => $competenciaId,
                    'obrigatoria' => $configuracao['obrigatoria'],
                    'peso'        => max(1, min(5, $configuracao['peso'])),
                    'log'         => 'Requisito da demanda',
                    'ativo'       => STATUS_ATIVO,
                ]
            );
        }
    }

    public static function publicar(int $demandaId): void
    {
        BancoDados::executar(
            "UPDATE pro_demandas
                SET dem_situacao = 'PUBLICADA',
                    dem_dt_publicacao = COALESCE(dem_dt_publicacao, NOW()),
                    dem_log = :log
              WHERE dem_id = :id",
            ['log' => 'Demanda publicada', 'id' => $demandaId]
        );

        Auditoria::registrar('DEMANDA_PUBLICADA', [
            'entidade'    => 'pro_demandas',
            'entidade_id' => $demandaId,
            'descricao'   => 'Demanda publicada e disponível para manifestações',
        ]);
    }

    public static function encerrar(int $demandaId, string $motivo, string $situacao = 'ENCERRADA'): void
    {
        BancoDados::executar(
            'UPDATE pro_demandas
                SET dem_situacao = :situacao,
                    dem_dt_encerramento = NOW(),
                    dem_motivo_encerramento = :motivo,
                    dem_log = :log
              WHERE dem_id = :id',
            [
                'situacao' => $situacao,
                'motivo'   => substr($motivo, 0, 255),
                'log'      => 'Demanda ' . strtolower($situacao),
                'id'       => $demandaId,
            ]
        );

        Auditoria::registrar('DEMANDA_ENCERRADA', [
            'entidade'    => 'pro_demandas',
            'entidade_id' => $demandaId,
            'descricao'   => sprintf('Demanda %s: %s', strtolower($situacao), $motivo),
        ]);
    }

    public static function definirModeracao(int $demandaId, string $situacao, string $motivo = ''): void
    {
        BancoDados::executar(
            'UPDATE pro_demandas
                SET dem_moderacao = :situacao, dem_moderacao_motivo = :motivo, dem_log = :log
              WHERE dem_id = :id',
            [
                'situacao' => $situacao,
                'motivo'   => $motivo !== '' ? substr($motivo, 0, 255) : null,
                'log'      => 'Moderacao: ' . $situacao,
                'id'       => $demandaId,
            ]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'pro_demandas',
            'entidade_id' => $demandaId,
            'descricao'   => 'Demanda marcada como ' . $situacao . ($motivo !== '' ? ': ' . $motivo : ''),
            'severidade'  => 'ALERTA',
        ]);
    }

    public static function recontarInteresses(int $demandaId): void
    {
        BancoDados::executar(
            "UPDATE pro_demandas d
                SET d.dem_total_interesses = (
                        SELECT COUNT(*) FROM pro_interesses i
                         WHERE i.int_dem_id = d.dem_id
                           AND i.int_status = 'A'
                           AND i.int_situacao <> 'RETIRADO'
                    )
              WHERE d.dem_id = :id",
            ['id' => $demandaId]
        );
    }

    /**
     * Pesquisa publica de demandas com filtros (RF04).
     *
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function pesquisar(array $filtros, int $pagina, int $porPagina, bool $autenticado = false): array
    {
        $condicoes = [
            'd.dem_status = :ativo',
            "d.dem_moderacao = 'APROVADO'",
            "d.dem_situacao = 'PUBLICADA'",
        ];
        $parametros = ['ativo' => STATUS_ATIVO];

        if (!$autenticado) {
            $condicoes[] = "d.dem_visibilidade = 'PUBLICO'";
        }

        if (!empty($filtros['termo'])) {
            $condicoes[] = '(d.dem_titulo LIKE :termo OR d.dem_escopo LIKE :termo)';
            $parametros['termo'] = '%' . $filtros['termo'] . '%';
        }

        if (!empty($filtros['area_id'])) {
            $condicoes[] = 'd.dem_are_id = :area';
            $parametros['area'] = (int) $filtros['area_id'];
        }

        if (!empty($filtros['uf'])) {
            $condicoes[] = '(d.dem_uf = :uf' . (!empty($filtros['incluir_remoto']) ? " OR d.dem_aceita_remoto = 'S')" : ')');
            $parametros['uf'] = $filtros['uf'];
        }

        if (!empty($filtros['cidade'])) {
            $condicoes[] = 'd.dem_cidade LIKE :cidade';
            $parametros['cidade'] = '%' . $filtros['cidade'] . '%';
        }

        if (!empty($filtros['modalidade'])) {
            $condicoes[] = 'd.dem_modalidade = :modalidade';
            $parametros['modalidade'] = $filtros['modalidade'];
        }

        if (!empty($filtros['exige_art'])) {
            $condicoes[] = "d.dem_exige_art = 'S'";
        }

        if (!empty($filtros['somente_abertas'])) {
            $condicoes[] = '(d.dem_dt_limite IS NULL OR d.dem_dt_limite >= CURDATE())';
        }

        if (!empty($filtros['competencias']) && is_array($filtros['competencias'])) {
            $ids = RepositorioCompetencia::filtrarValidos($filtros['competencias']);

            if ($ids !== []) {
                $lista = implode(',', $ids);
                $condicoes[] = "EXISTS (
                    SELECT 1 FROM pro_demanda_competencias dc
                     WHERE dc.dmc_dem_id = d.dem_id
                       AND dc.dmc_status = 'A'
                       AND dc.dmc_cmp_id IN ({$lista})
                )";
            }
        }

        $onde = ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor(
            'SELECT COUNT(*) FROM pro_demandas d' . $onde,
            $parametros
        );

        $ordem = match ($filtros['ordem'] ?? 'recentes') {
            'prazo'      => ' ORDER BY d.dem_dt_limite IS NULL, d.dem_dt_limite ASC',
            'orcamento'  => ' ORDER BY d.dem_orcamento_max DESC',
            'interesses' => ' ORDER BY d.dem_total_interesses DESC, d.dem_dt_publicacao DESC',
            default      => ' ORDER BY d.dem_dt_publicacao DESC, d.dem_id DESC',
        };

        $itens = BancoDados::buscarTodos(
            'SELECT d.dem_id, d.dem_titulo, d.dem_escopo, d.dem_uf, d.dem_cidade,
                    d.dem_modalidade, d.dem_aceita_remoto, d.dem_exige_art,
                    d.dem_orcamento_min, d.dem_orcamento_max, d.dem_dt_limite,
                    d.dem_dt_publicacao, d.dem_total_interesses, d.dem_experiencia_min,
                    u.usu_nome AS autor_nome, u.usu_registrado_crea AS autor_registrado,
                    a.are_nome
               FROM pro_demandas d
               JOIN sis_usuarios u ON u.usu_id = d.dem_usu_id
               LEFT JOIN pro_areas a ON a.are_id = d.dem_are_id'
            . $onde . $ordem . static::paginacao($pagina, $porPagina),
            $parametros
        );

        foreach ($itens as $indice => $item) {
            $itens[$indice]['competencias'] = self::competencias((int) $item['dem_id']);
        }

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Demandas do autor, incluindo rascunhos e encerradas.
     *
     * @return list<array<string, mixed>>
     */
    public static function doAutor(int $autorId, ?string $situacao = null): array
    {
        $sql = 'SELECT d.*, a.are_nome
                  FROM pro_demandas d
                  LEFT JOIN pro_areas a ON a.are_id = d.dem_are_id
                 WHERE d.dem_usu_id = :autor AND d.dem_status = :ativo';

        $parametros = ['autor' => $autorId, 'ativo' => STATUS_ATIVO];

        if ($situacao !== null && $situacao !== '') {
            $sql .= ' AND d.dem_situacao = :situacao';
            $parametros['situacao'] = $situacao;
        }

        return BancoDados::buscarTodos(
            $sql . ' ORDER BY d.dem_dt_registro DESC',
            $parametros
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recentes(int $limite = 6): array
    {
        return BancoDados::buscarTodos(
            "SELECT d.dem_id, d.dem_titulo, d.dem_escopo, d.dem_uf, d.dem_cidade,
                    d.dem_modalidade, d.dem_dt_limite, d.dem_dt_publicacao,
                    d.dem_orcamento_min, d.dem_orcamento_max, d.dem_total_interesses,
                    u.usu_nome AS autor_nome, a.are_nome
               FROM pro_demandas d
               JOIN sis_usuarios u ON u.usu_id = d.dem_usu_id
               LEFT JOIN pro_areas a ON a.are_id = d.dem_are_id
              WHERE d.dem_status = 'A'
                AND d.dem_situacao = 'PUBLICADA'
                AND d.dem_moderacao = 'APROVADO'
                AND d.dem_visibilidade = 'PUBLICO'
              ORDER BY d.dem_dt_publicacao DESC
              LIMIT " . max(1, min($limite, 24))
        );
    }

    /**
     * Encerra automaticamente demandas cujo prazo de manifestacao expirou.
     * Chamado no acesso ao painel, dispensando agendador externo no prototipo.
     */
    public static function encerrarVencidas(): int
    {
        $consulta = BancoDados::executar(
            "UPDATE pro_demandas
                SET dem_situacao = 'ENCERRADA',
                    dem_dt_encerramento = NOW(),
                    dem_motivo_encerramento = 'Prazo para manifestação de interesse expirado',
                    dem_log = 'Encerramento automático por prazo'
              WHERE dem_situacao = 'PUBLICADA'
                AND dem_status = 'A'
                AND dem_dt_limite IS NOT NULL
                AND dem_dt_limite < CURDATE()"
        );

        return $consulta->rowCount();
    }

    /**
     * Demandas por mês nos últimos doze meses, para os indicadores gerenciais.
     *
     * @return list<array<string, mixed>>
     */
    public static function porMes(): array
    {
        return BancoDados::buscarTodos(
            "SELECT DATE_FORMAT(dem_dt_registro, '%Y-%m') AS mes,
                    COUNT(*) AS total,
                    SUM(dem_situacao = 'PUBLICADA') AS publicadas,
                    SUM(dem_situacao = 'ENCERRADA') AS encerradas
               FROM pro_demandas
              WHERE dem_dt_registro >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              GROUP BY mes
              ORDER BY mes"
        );
    }

    /**
     * Identificadores das demandas abertas, exceto as do próprio usuário.
     * Usado pela compatibilização para avaliar oportunidades de um perfil.
     *
     * @return list<int>
     */
    public static function idsAbertasExcetoAutor(int $usuarioId, int $limite = 120): array
    {
        $linhas = BancoDados::buscarTodos(
            "SELECT dem_id
               FROM pro_demandas
              WHERE dem_status = 'A'
                AND dem_situacao = 'PUBLICADA'
                AND dem_moderacao = 'APROVADO'
                AND dem_usu_id <> :usuario
                AND (dem_dt_limite IS NULL OR dem_dt_limite >= CURDATE())
              ORDER BY dem_dt_publicacao DESC
              LIMIT " . max(1, min($limite, 500)),
            ['usuario' => $usuarioId]
        );

        return array_map(static fn (array $linha): int => (int) $linha['dem_id'], $linhas);
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(dem_situacao = 'PUBLICADA') AS publicadas,
                SUM(dem_situacao = 'RASCUNHO') AS rascunhos,
                SUM(dem_situacao = 'ENCERRADA') AS encerradas,
                SUM(dem_moderacao = 'EM_ANALISE') AS em_moderacao,
                SUM(dem_status = 'X') AS excluidas,
                SUM(DATE(dem_dt_registro) = CURDATE()) AS hoje
             FROM pro_demandas"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }

    /**
     * Listagem administrativa, sem os filtros de visibilidade publica.
     *
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listarParaAdministracao(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['termo'])) {
            $condicoes[] = 'd.dem_titulo LIKE :termo';
            $parametros['termo'] = '%' . $filtros['termo'] . '%';
        }

        if (!empty($filtros['situacao'])) {
            $condicoes[] = 'd.dem_situacao = :situacao';
            $parametros['situacao'] = $filtros['situacao'];
        }

        if (!empty($filtros['moderacao'])) {
            $condicoes[] = 'd.dem_moderacao = :moderacao';
            $parametros['moderacao'] = $filtros['moderacao'];
        }

        if (empty($filtros['incluir_excluidas'])) {
            $condicoes[] = 'd.dem_status <> :excluido';
            $parametros['excluido'] = STATUS_EXCLUIDO;
        }

        $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor('SELECT COUNT(*) FROM pro_demandas d' . $onde, $parametros);

        $itens = BancoDados::buscarTodos(
            'SELECT d.*, u.usu_nome AS autor_nome, u.usu_email AS autor_email
               FROM pro_demandas d
               JOIN sis_usuarios u ON u.usu_id = d.dem_usu_id'
            . $onde
            . ' ORDER BY d.dem_dt_registro DESC'
            . static::paginacao($pagina, $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }
}
