<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Denuncias de conteudo e seu tratamento pela moderacao (RF06).
 */
final class RepositorioDenuncia extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_denuncias';
    }

    protected static function prefixo(): string
    {
        return 'den';
    }

    public const ENTIDADES = ['PERFIL', 'DEMANDA', 'EXPERIENCIA', 'MENSAGEM', 'USUARIO'];

    public const MOTIVOS = [
        'CONTEUDO_INADEQUADO',
        'INFORMACAO_FALSA',
        'SPAM',
        'DADO_PESSOAL_INDEVIDO',
        'EXERCICIO_ILEGAL',
        'OUTRO',
    ];

    public const PROVIDENCIAS = [
        'NENHUMA',
        'CONTEUDO_OCULTADO',
        'CONTEUDO_REMOVIDO',
        'USUARIO_ADVERTIDO',
        'USUARIO_BLOQUEADO',
    ];

    public static function registrar(
        ?int $denuncianteId,
        string $entidade,
        int $entidadeId,
        string $motivo,
        string $descricao
    ): int {
        BancoDados::executar(
            'INSERT INTO pro_denuncias
                (den_usu_id, den_entidade, den_entidade_id, den_motivo, den_descricao, den_log, den_status)
             VALUES (:usuario, :entidade, :entidade_id, :motivo, :descricao, :log, :ativo)',
            [
                'usuario'     => $denuncianteId,
                'entidade'    => $entidade,
                'entidade_id' => $entidadeId,
                'motivo'      => $motivo,
                'descricao'   => $descricao !== '' ? $descricao : null,
                'log'         => 'Denúncia registrada pelo usuário',
                'ativo'       => STATUS_ATIVO,
            ]
        );

        $id = BancoDados::ultimoId();

        Auditoria::registrar('DENUNCIA_REGISTRADA', [
            'entidade'    => 'pro_denuncias',
            'entidade_id' => $id,
            'descricao'   => sprintf('Denúncia de %s #%d por %s', $entidade, $entidadeId, $motivo),
            'severidade'  => 'ALERTA',
        ]);

        return $id;
    }

    public static function analisar(int $denunciaId, int $analistaId, string $situacao, string $parecer, string $providencia): void
    {
        BancoDados::executar(
            'UPDATE pro_denuncias SET
                den_situacao = :situacao,
                den_usu_analista = :analista,
                den_parecer = :parecer,
                den_providencia = :providencia,
                den_dt_analise = NOW(),
                den_log = :log
              WHERE den_id = :id',
            [
                'situacao'    => $situacao,
                'analista'    => $analistaId,
                'parecer'     => $parecer !== '' ? $parecer : null,
                'providencia' => $providencia,
                'log'         => 'Denúncia analisada pela moderação',
                'id'          => $denunciaId,
            ]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'pro_denuncias',
            'entidade_id' => $denunciaId,
            'descricao'   => sprintf('Denúncia julgada %s com providência %s', $situacao, $providencia),
            'severidade'  => 'ALERTA',
        ]);
    }

    public static function assumir(int $denunciaId, int $analistaId): void
    {
        BancoDados::executar(
            "UPDATE pro_denuncias
                SET den_situacao = 'EM_ANALISE', den_usu_analista = :analista, den_log = :log
              WHERE den_id = :id AND den_situacao = 'ABERTA'",
            ['analista' => $analistaId, 'log' => 'Denúncia em análise', 'id' => $denunciaId]
        );
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listar(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = ['d.den_status <> :excluido'];
        $parametros = ['excluido' => STATUS_EXCLUIDO];

        if (!empty($filtros['situacao'])) {
            $condicoes[] = 'd.den_situacao = :situacao';
            $parametros['situacao'] = $filtros['situacao'];
        }

        if (!empty($filtros['entidade'])) {
            $condicoes[] = 'd.den_entidade = :entidade';
            $parametros['entidade'] = $filtros['entidade'];
        }

        if (!empty($filtros['motivo'])) {
            $condicoes[] = 'd.den_motivo = :motivo';
            $parametros['motivo'] = $filtros['motivo'];
        }

        $onde = ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor('SELECT COUNT(*) FROM pro_denuncias d' . $onde, $parametros);

        $itens = BancoDados::buscarTodos(
            'SELECT d.*, ud.usu_nome AS denunciante_nome, ua.usu_nome AS analista_nome
               FROM pro_denuncias d
               LEFT JOIN sis_usuarios ud ON ud.usu_id = d.den_usu_id
               LEFT JOIN sis_usuarios ua ON ua.usu_id = d.den_usu_analista'
            . $onde
            . " ORDER BY FIELD(d.den_situacao, 'ABERTA', 'EM_ANALISE', 'PROCEDENTE', 'IMPROCEDENTE'), d.den_dt_registro DESC"
            . static::paginacao($pagina, $porPagina),
            $parametros
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Resumo do conteudo denunciado, para o moderador julgar sem sair da tela.
     *
     * @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null}
     */
    public static function alvo(string $entidade, int $entidadeId): array
    {
        return match ($entidade) {
            'PERFIL' => self::resumirPerfil($entidadeId),
            'DEMANDA' => self::resumirDemanda($entidadeId),
            'EXPERIENCIA' => self::resumirExperiencia($entidadeId),
            'MENSAGEM' => self::resumirMensagem($entidadeId),
            'USUARIO' => self::resumirUsuario($entidadeId),
            default => ['titulo' => 'Conteúdo não identificado', 'detalhe' => '', 'link' => null, 'usuario_id' => null],
        };
    }

    /** @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null} */
    private static function resumirPerfil(int $id): array
    {
        $perfil = RepositorioPerfil::completoPorId($id);

        if ($perfil === null) {
            return ['titulo' => 'Perfil removido', 'detalhe' => '', 'link' => null, 'usuario_id' => null];
        }

        return [
            'titulo'     => (string) $perfil['usu_nome'],
            'detalhe'    => (string) ($perfil['prf_titulo'] ?? '') . ' | ' . (string) ($perfil['prf_resumo'] ?? ''),
            'link'       => '/profissionais/' . $id,
            'usuario_id' => (int) $perfil['prf_usu_id'],
        ];
    }

    /** @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null} */
    private static function resumirDemanda(int $id): array
    {
        $demanda = RepositorioDemanda::completaPorId($id);

        if ($demanda === null) {
            return ['titulo' => 'Demanda removida', 'detalhe' => '', 'link' => null, 'usuario_id' => null];
        }

        return [
            'titulo'     => (string) $demanda['dem_titulo'],
            'detalhe'    => (string) $demanda['dem_escopo'],
            'link'       => '/demandas/' . $id,
            'usuario_id' => (int) $demanda['dem_usu_id'],
        ];
    }

    /** @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null} */
    private static function resumirExperiencia(int $id): array
    {
        $experiencia = RepositorioExperiencia::comDono($id);

        if ($experiencia === null) {
            return ['titulo' => 'Experiência removida', 'detalhe' => '', 'link' => null, 'usuario_id' => null];
        }

        return [
            'titulo'     => (string) $experiencia['exp_titulo'],
            'detalhe'    => (string) ($experiencia['exp_descricao'] ?? ''),
            'link'       => '/profissionais/' . (int) $experiencia['prf_id'],
            'usuario_id' => (int) $experiencia['prf_usu_id'],
        ];
    }

    /** @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null} */
    private static function resumirMensagem(int $id): array
    {
        $mensagem = RepositorioMensagem::comContexto($id);

        if ($mensagem === null) {
            return ['titulo' => 'Mensagem removida', 'detalhe' => '', 'link' => null, 'usuario_id' => null];
        }

        return [
            'titulo'     => 'Mensagem de ' . (string) $mensagem['usu_nome'],
            'detalhe'    => (string) $mensagem['msg_conteudo'],
            'link'       => null,
            'usuario_id' => (int) $mensagem['msg_usu_id'],
        ];
    }

    /** @return array{titulo: string, detalhe: string, link: string|null, usuario_id: int|null} */
    private static function resumirUsuario(int $id): array
    {
        $usuario = RepositorioUsuario::porId($id, true);

        if ($usuario === null) {
            return ['titulo' => 'Usuário removido', 'detalhe' => '', 'link' => null, 'usuario_id' => null];
        }

        return [
            'titulo'     => (string) $usuario['usu_nome'],
            'detalhe'    => (string) $usuario['usu_email'] . ' | ' . (string) $usuario['usu_perfil'],
            'link'       => null,
            'usuario_id' => $id,
        ];
    }

    /**
     * Outras denuncias que recaem sobre o mesmo usuario, considerando
     * denuncias diretas a conta, ao perfil e as demandas dele.
     *
     * @return list<array<string, mixed>>
     */
    public static function outrasDoUsuario(int $usuarioId, int $ignorarDenuncia): array
    {
        return BancoDados::buscarTodos(
            'SELECT d.den_id, d.den_entidade, d.den_motivo, d.den_situacao,
                    d.den_providencia, d.den_dt_registro
               FROM pro_denuncias d
              WHERE d.den_id <> :ignorar
                AND d.den_status <> :excluido
                AND (
                    (d.den_entidade = :usuario_entidade AND d.den_entidade_id = :usuario)
                    OR EXISTS (
                        SELECT 1 FROM pro_perfis p
                         WHERE p.prf_usu_id = :usuario
                           AND d.den_entidade = :perfil_entidade
                           AND d.den_entidade_id = p.prf_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM pro_demandas dm
                         WHERE dm.dem_usu_id = :usuario
                           AND d.den_entidade = :demanda_entidade
                           AND d.den_entidade_id = dm.dem_id
                    )
                )
              ORDER BY d.den_dt_registro DESC
              LIMIT 20',
            [
                'ignorar'          => $ignorarDenuncia,
                'excluido'         => STATUS_EXCLUIDO,
                'usuario'          => $usuarioId,
                'usuario_entidade' => 'USUARIO',
                'perfil_entidade'  => 'PERFIL',
                'demanda_entidade' => 'DEMANDA',
            ]
        );
    }

    /** @return array<string, int> */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(den_situacao = 'ABERTA') AS abertas,
                SUM(den_situacao = 'EM_ANALISE') AS em_analise,
                SUM(den_situacao = 'PROCEDENTE') AS procedentes,
                SUM(den_situacao = 'IMPROCEDENTE') AS improcedentes
             FROM pro_denuncias WHERE den_status <> 'X'"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }
}
