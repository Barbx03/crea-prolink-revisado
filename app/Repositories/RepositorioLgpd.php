<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Consentimentos, termos e requisicoes de direitos do titular (LGPD / RF01).
 */
final class RepositorioLgpd extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_solicitacoes_lgpd';
    }

    protected static function prefixo(): string
    {
        return 'slg';
    }

    public const TIPOS_SOLICITACAO = [
        'ACESSO',
        'CORRECAO',
        'PORTABILIDADE',
        'ANONIMIZACAO',
        'ELIMINACAO',
        'REVOGACAO_CONSENTIMENTO',
    ];

    public const FINALIDADES = [
        'TERMOS_USO'            => 'Aceite dos Termos de Uso',
        'POLITICA_PRIVACIDADE'  => 'Aceite da Politica de Privacidade',
        'DADOS_CREA'            => 'Consulta e exibição de dados do CREA-AM (registro, ARTs e CATs)',
        'PERFIL_PUBLICO'        => 'Exibicao do perfil profissional em buscas publicas',
        'COMUNICACOES'          => 'Recebimento de comunicacoes da plataforma por e-mail',
    ];

    // -------------------------------------------------------------------------
    // Termos
    // -------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function termoVigente(string $tipo): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM sis_termos
              WHERE ter_tipo = :tipo AND ter_status = :ativo AND ter_dt_vigencia <= CURDATE()
              ORDER BY ter_dt_vigencia DESC, ter_id DESC
              LIMIT 1',
            ['tipo' => $tipo, 'ativo' => STATUS_ATIVO]
        );
    }

    // -------------------------------------------------------------------------
    // Consentimentos
    // -------------------------------------------------------------------------

    public static function registrarConsentimento(
        int $usuarioId,
        string $finalidade,
        bool $concedido,
        ?int $termoId = null,
        string $ip = '',
        string $agente = ''
    ): void {
        BancoDados::executar(
            'INSERT INTO sis_consentimentos
                (cns_usu_id, cns_ter_id, cns_finalidade, cns_concedido,
                 cns_ip_origem, cns_user_agent, cns_log, cns_status)
             VALUES (:usuario, :termo, :finalidade, :concedido, :ip, :agente, :log, :ativo)',
            [
                'usuario'    => $usuarioId,
                'termo'      => $termoId,
                'finalidade' => $finalidade,
                'concedido'  => $concedido ? 'S' : 'N',
                'ip'         => $ip !== '' ? $ip : null,
                'agente'     => $agente !== '' ? substr($agente, 0, 255) : null,
                'log'        => $concedido ? 'Consentimento concedido' : 'Consentimento revogado',
                'ativo'      => STATUS_ATIVO,
            ]
        );

        Auditoria::registrar($concedido ? 'CONSENTIMENTO_CONCEDIDO' : 'CONSENTIMENTO_REVOGADO', [
            'usuario_id'  => $usuarioId,
            'entidade'    => 'sis_consentimentos',
            'entidade_id' => $usuarioId,
            'descricao'   => sprintf(
                '%s da finalidade %s',
                $concedido ? 'Concessao' : 'Revogacao',
                $finalidade
            ),
        ]);
    }

    /**
     * Situacao atual de cada finalidade: prevalece o evento mais recente.
     *
     * @return array<string, array{concedido: bool, dt_evento: string}>
     */
    public static function consentimentosAtuais(int $usuarioId): array
    {
        $linhas = BancoDados::buscarTodos(
            'SELECT c.cns_finalidade, c.cns_concedido, c.cns_dt_evento
               FROM sis_consentimentos c
               JOIN (
                    SELECT cns_finalidade, MAX(cns_id) AS ultimo
                      FROM sis_consentimentos
                     WHERE cns_usu_id = :usuario
                     GROUP BY cns_finalidade
               ) ultimos ON ultimos.ultimo = c.cns_id
              ORDER BY c.cns_finalidade',
            ['usuario' => $usuarioId]
        );

        $atuais = [];

        foreach ($linhas as $linha) {
            $atuais[$linha['cns_finalidade']] = [
                'concedido' => $linha['cns_concedido'] === 'S',
                'dt_evento' => (string) $linha['cns_dt_evento'],
            ];
        }

        return $atuais;
    }

    /** @return list<array<string, mixed>> */
    public static function historicoConsentimentos(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            'SELECT c.*, t.ter_tipo, t.ter_versao
               FROM sis_consentimentos c
               LEFT JOIN sis_termos t ON t.ter_id = c.cns_ter_id
              WHERE c.cns_usu_id = :usuario
              ORDER BY c.cns_dt_evento DESC, c.cns_id DESC',
            ['usuario' => $usuarioId]
        );
    }

    public static function temConsentimento(int $usuarioId, string $finalidade): bool
    {
        $atuais = self::consentimentosAtuais($usuarioId);

        return $atuais[$finalidade]['concedido'] ?? false;
    }

    // -------------------------------------------------------------------------
    // Requisicoes de direitos do titular
    // -------------------------------------------------------------------------

    public static function abrirSolicitacao(int $usuarioId, string $tipo, string $descricao): int
    {
        BancoDados::executar(
            'INSERT INTO pro_solicitacoes_lgpd
                (slg_usu_id, slg_tipo, slg_descricao, slg_log, slg_status)
             VALUES (:usuario, :tipo, :descricao, :log, :ativo)',
            [
                'usuario'   => $usuarioId,
                'tipo'      => $tipo,
                'descricao' => $descricao !== '' ? $descricao : null,
                'log'       => 'Requisição aberta pelo titular',
                'ativo'     => STATUS_ATIVO,
            ]
        );

        $id = BancoDados::ultimoId();

        Auditoria::registrar('SOLICITACAO_LGPD', [
            'usuario_id'  => $usuarioId,
            'entidade'    => 'pro_solicitacoes_lgpd',
            'entidade_id' => $id,
            'descricao'   => 'Requisição de direito do titular: ' . $tipo,
            'severidade'  => 'ALERTA',
        ]);

        return $id;
    }

    public static function responderSolicitacao(int $solicitacaoId, int $analistaId, string $situacao, string $resposta): void
    {
        BancoDados::executar(
            'UPDATE pro_solicitacoes_lgpd SET
                slg_situacao = :situacao,
                slg_resposta = :resposta,
                slg_usu_analista = :analista,
                slg_dt_conclusao = CASE WHEN :situacao IN (:atendida, :recusada) THEN NOW() ELSE NULL END,
                slg_log = :log
              WHERE slg_id = :id',
            [
                'situacao' => $situacao,
                'resposta' => $resposta !== '' ? $resposta : null,
                'analista' => $analistaId,
                'atendida' => 'ATENDIDA',
                'recusada' => 'RECUSADA',
                'log'      => 'Requisição tratada pela administracao',
                'id'       => $solicitacaoId,
            ]
        );

        Auditoria::registrar('SOLICITACAO_LGPD_TRATADA', [
            'entidade'    => 'pro_solicitacoes_lgpd',
            'entidade_id' => $solicitacaoId,
            'descricao'   => 'Requisição marcada como ' . $situacao,
            'severidade'  => 'ALERTA',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function solicitacoesDoUsuario(int $usuarioId): array
    {
        return BancoDados::buscarTodos(
            'SELECT * FROM pro_solicitacoes_lgpd
              WHERE slg_usu_id = :usuario AND slg_status <> :excluido
              ORDER BY slg_dt_registro DESC',
            ['usuario' => $usuarioId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listarSolicitacoes(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = ['s.slg_status <> :excluido'];
        $parametros = ['excluido' => STATUS_EXCLUIDO];

        if (!empty($filtros['situacao'])) {
            $condicoes[] = 's.slg_situacao = :situacao';
            $parametros['situacao'] = $filtros['situacao'];
        }

        if (!empty($filtros['tipo'])) {
            $condicoes[] = 's.slg_tipo = :tipo';
            $parametros['tipo'] = $filtros['tipo'];
        }

        $onde = ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor('SELECT COUNT(*) FROM pro_solicitacoes_lgpd s' . $onde, $parametros);

        $itens = BancoDados::buscarTodos(
            'SELECT s.*, u.usu_nome, u.usu_email, ua.usu_nome AS analista_nome
               FROM pro_solicitacoes_lgpd s
               JOIN sis_usuarios u ON u.usu_id = s.slg_usu_id
               LEFT JOIN sis_usuarios ua ON ua.usu_id = s.slg_usu_analista'
            . $onde
            . " ORDER BY FIELD(s.slg_situacao, 'ABERTA', 'EM_ANALISE', 'ATENDIDA', 'RECUSADA'), s.slg_dt_registro DESC"
            . static::paginacao($pagina, $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function solicitacaoCompleta(int $solicitacaoId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT s.*, u.usu_nome, u.usu_email
               FROM pro_solicitacoes_lgpd s
               JOIN sis_usuarios u ON u.usu_id = s.slg_usu_id
              WHERE s.slg_id = :id
              LIMIT 1',
            ['id' => $solicitacaoId]
        );
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(slg_situacao = 'ABERTA') AS abertas,
                SUM(slg_situacao = 'EM_ANALISE') AS em_analise,
                SUM(slg_situacao = 'ATENDIDA') AS atendidas,
                SUM(slg_situacao = 'RECUSADA') AS recusadas
             FROM pro_solicitacoes_lgpd WHERE slg_status <> 'X'"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }
}
