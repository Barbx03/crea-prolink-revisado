<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Repositorio;

/**
 * Persistencia de pro_perfis e das competencias declaradas (RF01/RF03).
 */
final class RepositorioPerfil extends Repositorio
{
    protected static function tabela(): string
    {
        return 'pro_perfis';
    }

    protected static function prefixo(): string
    {
        return 'prf';
    }

    /** @return array<string, mixed>|null */
    public static function porUsuario(int $usuarioId, bool $incluirExcluidos = false): ?array
    {
        $sql = 'SELECT p.*, u.usu_nome, u.usu_email, u.usu_perfil, u.usu_telefone,
                       u.usu_cpf, u.usu_cnpj, u.usu_rnp, u.usu_registrado_crea,
                       u.usu_tipo_pessoa, u.usu_status AS situacao_usuario,
                       a.are_nome
                  FROM pro_perfis p
                  JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
                  LEFT JOIN pro_areas a ON a.are_id = p.prf_are_id
                 WHERE p.prf_usu_id = :usuario';

        if (!$incluirExcluidos) {
            $sql .= ' AND p.prf_status <> :excluido';
        }

        return BancoDados::buscarUm(
            $sql . ' LIMIT 1',
            $incluirExcluidos
                ? ['usuario' => $usuarioId]
                : ['usuario' => $usuarioId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /** @return array<string, mixed>|null */
    public static function completoPorId(int $perfilId): ?array
    {
        return BancoDados::buscarUm(
            'SELECT p.*, u.usu_nome, u.usu_email, u.usu_perfil, u.usu_telefone,
                    u.usu_cpf, u.usu_cnpj, u.usu_rnp, u.usu_registrado_crea,
                    u.usu_tipo_pessoa, u.usu_dt_registro AS usuario_desde,
                    u.usu_status AS situacao_usuario, a.are_nome
               FROM pro_perfis p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
               LEFT JOIN pro_areas a ON a.are_id = p.prf_are_id
              WHERE p.prf_id = :id AND p.prf_status <> :excluido
              LIMIT 1',
            ['id' => $perfilId, 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /**
     * Cria o perfil na primeira edicao, se ainda nao existir.
     */
    public static function garantirExistencia(int $usuarioId): int
    {
        $perfil = self::porUsuario($usuarioId, true);

        if ($perfil !== null) {
            return (int) $perfil['prf_id'];
        }

        BancoDados::executar(
            'INSERT INTO pro_perfis (prf_usu_id, prf_log, prf_status)
             VALUES (:usuario, :log, :ativo)',
            ['usuario' => $usuarioId, 'log' => 'Perfil criado', 'ativo' => STATUS_ATIVO]
        );

        $id = BancoDados::ultimoId();

        Auditoria::registrar('CADASTRO', [
            'entidade'    => 'pro_perfis',
            'entidade_id' => $id,
            'descricao'   => 'Perfil profissional criado',
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function salvar(int $perfilId, array $dados): void
    {
        $anterior = self::porId($perfilId);

        BancoDados::executar(
            'UPDATE pro_perfis SET
                prf_titulo = :titulo,
                prf_resumo = :resumo,
                prf_are_id = :area,
                prf_uf = :uf,
                prf_cidade = :cidade,
                prf_raio_atuacao_km = :raio,
                prf_atende_remoto = :remoto,
                prf_disponibilidade = :disponibilidade,
                prf_anos_experiencia = :anos,
                prf_valor_hora = :valor_hora,
                prf_site = :site,
                prf_linkedin = :linkedin,
                prf_log = :log
              WHERE prf_id = :id',
            [
                'titulo'          => $dados['titulo'] ?? null,
                'resumo'          => $dados['resumo'] ?? null,
                'area'            => $dados['area_id'] ?? null,
                'uf'              => $dados['uf'] ?? null,
                'cidade'          => $dados['cidade'] ?? null,
                'raio'            => $dados['raio_km'] ?? null,
                'remoto'          => $dados['atende_remoto'] ?? 'N',
                'disponibilidade' => $dados['disponibilidade'] ?? 'DISPONIVEL',
                'anos'            => $dados['anos_experiencia'] ?? null,
                'valor_hora'      => $dados['valor_hora'] ?? null,
                'site'            => $dados['site'] ?? null,
                'linkedin'        => $dados['linkedin'] ?? null,
                'log'             => 'Perfil atualizado pelo titular',
                'id'              => $perfilId,
            ]
        );

        if ($anterior !== null) {
            Auditoria::alteracao('pro_perfis', $perfilId, $anterior, [
                'prf_titulo'          => $dados['titulo'] ?? null,
                'prf_resumo'          => $dados['resumo'] ?? null,
                'prf_are_id'          => $dados['area_id'] ?? null,
                'prf_uf'              => $dados['uf'] ?? null,
                'prf_cidade'          => $dados['cidade'] ?? null,
                'prf_disponibilidade' => $dados['disponibilidade'] ?? 'DISPONIVEL',
            ], 'Atualização do perfil profissional');
        }
    }

    /**
     * Controles de visibilidade escolhidos pelo titular (LGPD).
     *
     * @param array<string, string> $dados
     */
    public static function salvarPrivacidade(int $perfilId, array $dados): void
    {
        $anterior = self::porId($perfilId);

        BancoDados::executar(
            'UPDATE pro_perfis SET
                prf_visibilidade = :visibilidade,
                prf_exibe_contato = :contato,
                prf_exibe_documento = :documento,
                prf_exibe_rnp = :rnp,
                prf_exibe_valor_hora = :valor_hora,
                prf_aceita_contato = :aceita_contato,
                prf_log = :log
              WHERE prf_id = :id',
            [
                'visibilidade'   => $dados['visibilidade'],
                'contato'        => $dados['exibe_contato'],
                'documento'      => $dados['exibe_documento'],
                'rnp'            => $dados['exibe_rnp'],
                'valor_hora'     => $dados['exibe_valor_hora'],
                'aceita_contato' => $dados['aceita_contato'],
                'log'            => 'Preferencias de privacidade atualizadas pelo titular',
                'id'             => $perfilId,
            ]
        );

        if ($anterior !== null) {
            Auditoria::alteracao('pro_perfis', $perfilId, $anterior, [
                'prf_visibilidade'     => $dados['visibilidade'],
                'prf_exibe_contato'    => $dados['exibe_contato'],
                'prf_exibe_documento'  => $dados['exibe_documento'],
                'prf_exibe_rnp'        => $dados['exibe_rnp'],
                'prf_exibe_valor_hora' => $dados['exibe_valor_hora'],
                'prf_aceita_contato'   => $dados['aceita_contato'],
            ], 'Alteracao das preferencias de privacidade');
        }
    }

    public static function atualizarFoto(int $perfilId, ?string $arquivo): void
    {
        BancoDados::executar(
            'UPDATE pro_perfis SET prf_foto = :foto, prf_log = :log WHERE prf_id = :id',
            ['foto' => $arquivo, 'log' => 'Foto do perfil atualizada', 'id' => $perfilId]
        );
    }

    public static function definirModeracao(int $perfilId, string $situacao, string $motivo = ''): void
    {
        BancoDados::executar(
            'UPDATE pro_perfis SET prf_moderacao = :situacao, prf_moderacao_motivo = :motivo, prf_log = :log
              WHERE prf_id = :id',
            [
                'situacao' => $situacao,
                'motivo'   => $motivo !== '' ? substr($motivo, 0, 255) : null,
                'log'      => 'Moderacao: ' . $situacao,
                'id'       => $perfilId,
            ]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'pro_perfis',
            'entidade_id' => $perfilId,
            'descricao'   => 'Perfil marcado como ' . $situacao . ($motivo !== '' ? ': ' . $motivo : ''),
            'severidade'  => 'ALERTA',
        ]);
    }

    // -------------------------------------------------------------------------
    // Competencias do perfil
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public static function competencias(int $perfilId): array
    {
        return BancoDados::buscarTodos(
            'SELECT pc.pcp_id, pc.pcp_cmp_id, pc.pcp_nivel,
                    c.cmp_nome, c.cmp_are_id, a.are_nome
               FROM pro_perfil_competencias pc
               JOIN pro_competencias c ON c.cmp_id = pc.pcp_cmp_id
               JOIN pro_areas a ON a.are_id = c.cmp_are_id
              WHERE pc.pcp_prf_id = :perfil AND pc.pcp_status = :ativo
              ORDER BY a.are_nome, c.cmp_nome',
            ['perfil' => $perfilId, 'ativo' => STATUS_ATIVO]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function competenciasDoUsuario(int $usuarioId): array
    {
        $perfil = self::porUsuario($usuarioId, true);

        return $perfil === null ? [] : self::competencias((int) $perfil['prf_id']);
    }

    /** @return list<int> */
    public static function idsCompetencias(int $perfilId): array
    {
        return array_map(
            static fn (array $linha): int => (int) $linha['pcp_cmp_id'],
            self::competencias($perfilId)
        );
    }

    /**
     * Substitui o conjunto de competencias do perfil.
     *
     * @param list<int> $competenciaIds
     * @param array<int, string> $niveis
     */
    public static function sincronizarCompetencias(int $perfilId, array $competenciaIds, array $niveis = []): void
    {
        BancoDados::transacao(static function () use ($perfilId, $competenciaIds, $niveis): void {
            // Exclusao logica das competencias removidas
            BancoDados::executar(
                'UPDATE pro_perfil_competencias
                    SET pcp_status = :excluido, pcp_log = :log
                  WHERE pcp_prf_id = :perfil AND pcp_status = :ativo',
                [
                    'excluido' => STATUS_EXCLUIDO,
                    'log'      => 'Substituido na atualização do perfil',
                    'perfil'   => $perfilId,
                    'ativo'    => STATUS_ATIVO,
                ]
            );

            foreach ($competenciaIds as $competenciaId) {
                $nivel = $niveis[$competenciaId] ?? 'INTERMEDIARIO';

                BancoDados::executar(
                    'INSERT INTO pro_perfil_competencias (pcp_prf_id, pcp_cmp_id, pcp_nivel, pcp_log, pcp_status)
                     VALUES (:perfil, :competencia, :nivel, :log, :ativo)
                     ON DUPLICATE KEY UPDATE
                        pcp_nivel = VALUES(pcp_nivel),
                        pcp_status = VALUES(pcp_status),
                        pcp_log = VALUES(pcp_log)',
                    [
                        'perfil'      => $perfilId,
                        'competencia' => $competenciaId,
                        'nivel'       => $nivel,
                        'log'         => 'Competência declarada pelo titular',
                        'ativo'       => STATUS_ATIVO,
                    ]
                );
            }
        });

        Auditoria::registrar('ATUALIZACAO', [
            'entidade'    => 'pro_perfil_competencias',
            'entidade_id' => $perfilId,
            'descricao'   => sprintf('Competências do perfil atualizadas (%d selecionadas)', count($competenciaIds)),
        ]);
    }

    /**
     * Perfis que exigem atencao da moderacao: com moderacao pendente ou
     * reprovada, ou pertencentes a conta bloqueada.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendentesDeModeracao(int $limite = 20): array
    {
        return BancoDados::buscarTodos(
            'SELECT p.prf_id, p.prf_titulo, p.prf_moderacao, p.prf_moderacao_motivo,
                    p.prf_visibilidade, p.prf_dt_alteracao, p.prf_status,
                    u.usu_id, u.usu_nome, u.usu_email, u.usu_perfil, u.usu_registrado_crea
               FROM pro_perfis p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
              WHERE p.prf_status <> :excluido
                AND (p.prf_moderacao <> :aprovado OR u.usu_status = :bloqueado)
              ORDER BY FIELD(p.prf_moderacao, :em_analise, :reprovado, :aprovado), p.prf_dt_alteracao DESC
              LIMIT ' . max(1, min($limite, 500)),
            [
                'excluido'   => STATUS_EXCLUIDO,
                'aprovado'   => 'APROVADO',
                'bloqueado'  => STATUS_BLOQUEADO,
                'em_analise' => 'EM_ANALISE',
                'reprovado'  => 'REPROVADO',
            ]
        );
    }

    public static function contarPendentesDeModeracao(): int
    {
        return (int) BancoDados::valor(
            "SELECT COUNT(*) FROM pro_perfis
              WHERE prf_moderacao = 'EM_ANALISE' AND prf_status = 'A'"
        );
    }

    /**
     * Percentual de completude do perfil, usado como orientacao na interface.
     */
    public static function completude(int $perfilId): int
    {
        $perfil = self::porId($perfilId);

        if ($perfil === null) {
            return 0;
        }

        $itens = [
            !empty($perfil['prf_titulo']),
            !empty($perfil['prf_resumo']) && mb_strlen((string) $perfil['prf_resumo']) >= 80,
            !empty($perfil['prf_are_id']),
            !empty($perfil['prf_uf']) && !empty($perfil['prf_cidade']),
            self::competencias($perfilId) !== [],
            RepositorioExperiencia::doPerfil($perfilId) !== [],
            RepositorioArt::doPerfil($perfilId) !== [],
            !empty($perfil['prf_foto']),
        ];

        $preenchidos = count(array_filter($itens));

        return (int) round(($preenchidos / count($itens)) * 100);
    }
}
