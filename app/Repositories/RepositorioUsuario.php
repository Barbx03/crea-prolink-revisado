<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auditoria;
use App\Core\BancoDados;
use App\Core\Formatador;
use App\Core\Repositorio;

/**
 * Persistencia de sis_usuarios (RF01).
 */
final class RepositorioUsuario extends Repositorio
{
    protected static function tabela(): string
    {
        return 'sis_usuarios';
    }

    protected static function prefixo(): string
    {
        return 'usu';
    }

    /** @return array<string, mixed>|null */
    public static function porEmail(string $email): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM sis_usuarios WHERE usu_email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email))]
        );
    }

    /** @return array<string, mixed>|null */
    public static function porCpf(string $cpf): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM sis_usuarios WHERE usu_cpf = :cpf AND usu_status <> :excluido LIMIT 1',
            ['cpf' => Formatador::somenteDigitos($cpf), 'excluido' => STATUS_EXCLUIDO]
        );
    }

    /** @return array<string, mixed>|null */
    public static function porCnpj(string $cnpj): ?array
    {
        return BancoDados::buscarUm(
            'SELECT * FROM sis_usuarios WHERE usu_cnpj = :cnpj AND usu_status <> :excluido LIMIT 1',
            ['cnpj' => Formatador::somenteDigitos($cnpj), 'excluido' => STATUS_EXCLUIDO]
        );
    }

    public static function emailEmUso(string $email, int $ignorarId = 0): bool
    {
        return (int) BancoDados::valor(
            'SELECT COUNT(*) FROM sis_usuarios WHERE usu_email = :email AND usu_id <> :id',
            ['email' => mb_strtolower(trim($email)), 'id' => $ignorarId]
        ) > 0;
    }

    public static function documentoEmUso(?string $cpf, ?string $cnpj, int $ignorarId = 0): bool
    {
        $cpf  = $cpf !== null ? Formatador::somenteDigitos($cpf) : '';
        $cnpj = $cnpj !== null ? Formatador::somenteDigitos($cnpj) : '';

        if ($cpf === '' && $cnpj === '') {
            return false;
        }

        return (int) BancoDados::valor(
            'SELECT COUNT(*) FROM sis_usuarios
              WHERE usu_id <> :id
                AND usu_status <> :excluido
                AND ((:cpf <> :vazio AND usu_cpf = :cpf) OR (:cnpj <> :vazio AND usu_cnpj = :cnpj))',
            [
                'id'       => $ignorarId,
                'excluido' => STATUS_EXCLUIDO,
                'cpf'      => $cpf,
                'cnpj'     => $cnpj,
                'vazio'    => '',
            ]
        ) > 0;
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function criar(array $dados): int
    {
        BancoDados::executar(
            'INSERT INTO sis_usuarios
                (usu_nome, usu_email, usu_senha, usu_tipo_pessoa, usu_perfil,
                 usu_cpf, usu_cnpj, usu_rnp, usu_registrado_crea, usu_dt_validacao,
                 usu_telefone, usu_uf, usu_cidade, usu_email_verificado, usu_log, usu_status)
             VALUES
                (:nome, :email, :senha, :tipo_pessoa, :perfil,
                 :cpf, :cnpj, :rnp, :registrado, :dt_validacao,
                 :telefone, :uf, :cidade, :email_verificado, :log, :status)',
            [
                'nome'             => $dados['nome'],
                'email'            => mb_strtolower(trim((string) $dados['email'])),
                'senha'            => password_hash((string) $dados['senha'], PASSWORD_DEFAULT),
                'tipo_pessoa'      => $dados['tipo_pessoa'],
                'perfil'           => $dados['perfil'],
                'cpf'              => $dados['cpf'] ?? null,
                'cnpj'             => $dados['cnpj'] ?? null,
                'rnp'              => $dados['rnp'] ?? null,
                'registrado'       => $dados['registrado_crea'] ?? 'N',
                'dt_validacao'     => $dados['dt_validacao'] ?? null,
                'telefone'         => $dados['telefone'] ?? null,
                'uf'               => $dados['uf'] ?? null,
                'cidade'           => $dados['cidade'] ?? null,
                'email_verificado' => $dados['email_verificado'] ?? 'N',
                'log'              => 'Cadastro realizado pela própria interface',
                'status'           => $dados['status'] ?? STATUS_ATIVO,
            ]
        );

        return BancoDados::ultimoId();
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function atualizarDadosPessoais(int $id, array $dados): void
    {
        $anterior = self::porId($id);

        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_nome = :nome, usu_telefone = :telefone,
                    usu_uf = :uf, usu_cidade = :cidade, usu_log = :log
              WHERE usu_id = :id',
            [
                'nome'     => $dados['nome'],
                'telefone' => $dados['telefone'] ?? null,
                'uf'       => $dados['uf'] ?? null,
                'cidade'   => $dados['cidade'] ?? null,
                'log'      => 'Dados pessoais atualizados pelo titular',
                'id'       => $id,
            ]
        );

        if ($anterior !== null) {
            Auditoria::alteracao('sis_usuarios', $id, $anterior, [
                'usu_nome'     => $dados['nome'],
                'usu_telefone' => $dados['telefone'] ?? null,
                'usu_uf'       => $dados['uf'] ?? null,
                'usu_cidade'   => $dados['cidade'] ?? null,
            ], 'Atualização de dados pessoais');
        }
    }

    public static function atualizarEmail(int $id, string $email): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_email = :email, usu_email_verificado = :nao, usu_log = :log
              WHERE usu_id = :id',
            [
                'email' => mb_strtolower(trim($email)),
                'nao'   => 'N',
                'log'   => 'E-mail alterado pelo titular; verificacao pendente',
                'id'    => $id,
            ]
        );

        Auditoria::registrar('ATUALIZACAO', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Endereço de e-mail alterado',
            'severidade'  => 'ALERTA',
        ]);
    }

    public static function atualizarSenha(int $id, string $senha): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_senha = :senha, usu_falhas_login = 0, usu_bloqueado_ate = NULL, usu_log = :log
              WHERE usu_id = :id',
            [
                'senha' => password_hash($senha, PASSWORD_DEFAULT),
                'log'   => 'Senha alterada',
                'id'    => $id,
            ]
        );

        Auditoria::registrar('SENHA_ALTERADA', [
            'usuario_id'  => $id,
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Senha de acesso alterada',
            'severidade'  => 'ALERTA',
        ]);
    }

    /**
     * Marca o vinculo do usuario com o registro validado na API oficial.
     */
    public static function confirmarRegistroCrea(int $id, ?string $rnp, string $perfil): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_registrado_crea = :sim, usu_rnp = :rnp, usu_perfil = :perfil,
                    usu_dt_validacao = NOW(), usu_log = :log
              WHERE usu_id = :id',
            [
                'sim'    => 'S',
                'rnp'    => $rnp,
                'perfil' => $perfil,
                'log'    => 'Registro confirmado na API oficial do CREA-AM',
                'id'     => $id,
            ]
        );

        Auditoria::registrar('VALIDACAO_CREA', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Registro profissional confirmado junto a API oficial',
        ]);
    }

    public static function verificarEmail(int $id): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_email_verificado = :sim, usu_status = :ativo, usu_log = :log
              WHERE usu_id = :id',
            ['sim' => 'S', 'ativo' => STATUS_ATIVO, 'log' => 'E-mail verificado', 'id' => $id]
        );
    }

    public static function registrarFalhaLogin(int $id): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_falhas_login = usu_falhas_login + 1,
                    usu_bloqueado_ate = CASE
                        WHEN usu_falhas_login + 1 >= :maximo
                        THEN DATE_ADD(NOW(), INTERVAL :minutos MINUTE)
                        ELSE usu_bloqueado_ate
                    END,
                    usu_log = :log
              WHERE usu_id = :id',
            [
                'maximo'  => LOGIN_MAX_TENTATIVAS,
                'minutos' => LOGIN_BLOQUEIO_MINUTOS,
                'log'     => 'Tentativa de acesso malsucedida',
                'id'      => $id,
            ]
        );
    }

    public static function registrarAcessoBemSucedido(int $id): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_falhas_login = 0, usu_bloqueado_ate = NULL,
                    usu_dt_ultimo_acesso = NOW(), usu_log = :log
              WHERE usu_id = :id',
            ['log' => 'Acesso realizado', 'id' => $id]
        );
    }

    public static function bloquear(int $id, string $motivo): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_status = :bloqueado, usu_motivo_bloqueio = :motivo, usu_log = :log
              WHERE usu_id = :id',
            [
                'bloqueado' => STATUS_BLOQUEADO,
                'motivo'    => substr($motivo, 0, 255),
                'log'       => 'Bloqueio administrativo',
                'id'        => $id,
            ]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Usuário bloqueado: ' . $motivo,
            'severidade'  => 'CRITICO',
        ]);
    }

    public static function desbloquear(int $id): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_status = :ativo, usu_motivo_bloqueio = NULL,
                    usu_falhas_login = 0, usu_bloqueado_ate = NULL, usu_log = :log
              WHERE usu_id = :id',
            ['ativo' => STATUS_ATIVO, 'log' => 'Desbloqueio administrativo', 'id' => $id]
        );

        Auditoria::registrar('MODERACAO', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Usuário desbloqueado',
            'severidade'  => 'ALERTA',
        ]);
    }

    /**
     * Anonimizacao a pedido do titular (LGPD art. 18, VI).
     *
     * Preserva o identificador e a trilha de auditoria, substituindo os dados
     * pessoais por valores irreversiveis.
     */
    public static function anonimizar(int $id): void
    {
        BancoDados::executar(
            'UPDATE sis_usuarios
                SET usu_nome = :nome,
                    usu_email = :email,
                    usu_senha = :senha,
                    usu_cpf = NULL, usu_cnpj = NULL, usu_rnp = NULL,
                    usu_telefone = NULL, usu_cidade = NULL,
                    usu_registrado_crea = :nao,
                    usu_status = :excluido,
                    usu_log = :log
              WHERE usu_id = :id',
            [
                'nome'     => 'Titular anonimizado',
                'email'    => 'anonimizado+' . $id . '@invalido.local',
                'senha'    => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'nao'      => 'N',
                'excluido' => STATUS_EXCLUIDO,
                'log'      => 'Dados anonimizados a pedido do titular (LGPD art. 18)',
                'id'       => $id,
            ]
        );

        Auditoria::registrar('ANONIMIZACAO', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => 'Dados pessoais anonimizados a pedido do titular',
            'severidade'  => 'CRITICO',
        ]);
    }

    /**
     * Listagem administrativa com filtros.
     *
     * @param array<string, mixed> $filtros
     * @return array{itens: list<array<string, mixed>>, total: int}
     */
    public static function listarParaAdministracao(array $filtros, int $pagina, int $porPagina): array
    {
        $condicoes  = [];
        $parametros = [];

        if (!empty($filtros['termo'])) {
            $condicoes[] = '(u.usu_nome LIKE :termo OR u.usu_email LIKE :termo)';
            $parametros['termo'] = '%' . $filtros['termo'] . '%';
        }

        if (!empty($filtros['perfil'])) {
            $condicoes[] = 'u.usu_perfil = :perfil';
            $parametros['perfil'] = $filtros['perfil'];
        }

        if (!empty($filtros['situacao'])) {
            $condicoes[] = 'u.usu_status = :situacao';
            $parametros['situacao'] = $filtros['situacao'];
        } else {
            $condicoes[] = 'u.usu_status <> :excluido';
            $parametros['excluido'] = STATUS_EXCLUIDO;
        }

        if (($filtros['registrado'] ?? '') !== '') {
            $condicoes[] = 'u.usu_registrado_crea = :registrado';
            $parametros['registrado'] = $filtros['registrado'];
        }

        $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

        $total = (int) BancoDados::valor(
            'SELECT COUNT(*) FROM sis_usuarios u' . $onde,
            $parametros
        );

        $itens = BancoDados::buscarTodos(
            'SELECT u.*, p.prf_id, p.prf_moderacao
               FROM sis_usuarios u
               LEFT JOIN pro_perfis p ON p.prf_usu_id = u.usu_id AND p.prf_status <> :excluidoPerfil'
            . $onde
            . ' ORDER BY u.usu_dt_registro DESC'
            . static::paginacao($pagina, $porPagina),
            $parametros + ['excluidoPerfil' => STATUS_EXCLUIDO]
        );

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * @return array<string, int>
     */
    public static function indicadores(): array
    {
        $linha = BancoDados::buscarUm(
            "SELECT
                COUNT(*) AS total,
                SUM(usu_status = 'A') AS ativos,
                SUM(usu_status = 'B') AS bloqueados,
                SUM(usu_status = 'X') AS excluidos,
                SUM(usu_registrado_crea = 'S') AS registrados,
                SUM(usu_perfil = 'PROFISSIONAL') AS profissionais,
                SUM(usu_perfil = 'EMPRESA') AS empresas,
                SUM(usu_perfil = 'TERCEIRO') AS terceiros,
                SUM(DATE(usu_dt_registro) = CURDATE()) AS hoje,
                SUM(usu_dt_registro >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS ultimos_30_dias
             FROM sis_usuarios"
        ) ?? [];

        return array_map(static fn ($valor) => (int) $valor, $linha);
    }

    /**
     * Altera o perfil de acesso.
     *
     * A regra de quem pode receber cada perfil e do controlador; aqui fica
     * apenas a persistencia e o registro da alteracao.
     */
    public static function alterarPerfil(int $id, string $perfil): void
    {
        $anterior = self::porId($id, true);

        BancoDados::executar(
            'UPDATE sis_usuarios SET usu_perfil = :perfil, usu_log = :log WHERE usu_id = :id',
            [
                'perfil' => $perfil,
                'log'    => 'Perfil de acesso alterado pela administração',
                'id'     => $id,
            ]
        );

        Auditoria::registrar('ATUALIZACAO', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $id,
            'descricao'   => sprintf(
                'Perfil de acesso alterado de %s para %s',
                (string) ($anterior['usu_perfil'] ?? '?'),
                $perfil
            ),
            'antes'       => ['usu_perfil' => $anterior['usu_perfil'] ?? null],
            'depois'      => ['usu_perfil' => $perfil],
            'severidade'  => 'CRITICO',
        ]);
    }

    /**
     * Cadastros por mes nos ultimos doze meses, para os indicadores gerenciais.
     *
     * @return list<array<string, mixed>>
     */
    public static function cadastrosPorMes(): array
    {
        return BancoDados::buscarTodos(
            "SELECT DATE_FORMAT(usu_dt_registro, '%Y-%m') AS mes,
                    COUNT(*) AS total,
                    SUM(usu_perfil = 'PROFISSIONAL') AS profissionais,
                    SUM(usu_perfil = 'EMPRESA') AS empresas,
                    SUM(usu_perfil = 'TERCEIRO') AS terceiros
               FROM sis_usuarios
              WHERE usu_dt_registro >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
              GROUP BY mes
              ORDER BY mes"
        );
    }

    /**
     * Administradores ativos, destinatarios das notificacoes de moderacao.
     *
     * @return list<array<string, mixed>>
     */
    public static function administradoresAtivos(int $limite = 20): array
    {
        return BancoDados::buscarTodos(
            'SELECT usu_id, usu_nome, usu_email
               FROM sis_usuarios
              WHERE usu_perfil = :admin AND usu_status = :ativo
              ORDER BY usu_id
              LIMIT ' . max(1, min($limite, 100)),
            ['admin' => PERFIL_ADMIN, 'ativo' => STATUS_ATIVO]
        );
    }

    /**
     * Dados completos do titular para exportacao (LGPD art. 18, II e V).
     *
     * @return array<string, mixed>
     */
    public static function dadosParaPortabilidade(int $id): array
    {
        $usuario = self::porId($id, true) ?? [];
        unset($usuario['usu_senha']);

        return [
            'gerado_em'      => date('c'),
            'identificacao'  => $usuario,
            'perfil'         => RepositorioPerfil::porUsuario($id, true) ?? [],
            'competencias'   => RepositorioPerfil::competenciasDoUsuario($id),
            'experiencias'   => RepositorioExperiencia::doUsuario($id),
            'arts'           => RepositorioArt::doUsuario($id),
            'cats'           => RepositorioCat::doUsuario($id),
            'demandas'       => RepositorioDemanda::doAutor($id),
            'interesses'     => RepositorioInteresse::doUsuario($id),
            'consentimentos' => BancoDados::buscarTodos(
                'SELECT cns_finalidade, cns_concedido, cns_dt_evento, cns_ip_origem
                   FROM sis_consentimentos WHERE cns_usu_id = :id ORDER BY cns_dt_evento',
                ['id' => $id]
            ),
            'solicitacoes_lgpd' => BancoDados::buscarTodos(
                'SELECT slg_tipo, slg_situacao, slg_descricao, slg_resposta, slg_dt_registro, slg_dt_conclusao
                   FROM pro_solicitacoes_lgpd WHERE slg_usu_id = :id ORDER BY slg_dt_registro',
                ['id' => $id]
            ),
        ];
    }
}
