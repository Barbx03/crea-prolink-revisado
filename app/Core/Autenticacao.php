<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\RepositorioUsuario;

/**
 * Autenticacao e controle de perfis de acesso (itens 8.5.a, 8.5.b e 8.5.f).
 *
 * Senhas sao verificadas com password_verify() sobre o hash gerado por
 * password_hash(), e reidratadas quando o algoritmo padrao do PHP evolui.
 * Tentativas sucessivas malsucedidas bloqueiam o acesso temporariamente.
 */
final class Autenticacao
{
    private const CHAVE_USUARIO = '_usuario';

    /** @var array<string, mixed>|null */
    private static ?array $memoria = null;

    /**
     * @return array{ok: bool, mensagem: string, usuario?: array<string, mixed>}
     */
    public static function entrar(string $email, string $senha): array
    {
        $usuario = RepositorioUsuario::porEmail($email);

        // Mensagem generica: nao revela se o e-mail existe na base
        $recusa = ['ok' => false, 'mensagem' => 'E-mail ou senha incorretos.'];

        if ($usuario === null) {
            // Custo de hash artificial, para nao vazar a existencia do e-mail
            // pela diferenca de tempo de resposta
            password_verify($senha, '$2y$12$invalidoinvalidoinvalidoinvalidoinvalidoinvalidoinvalidoinval');

            Auditoria::registrar('LOGIN_FALHA', [
                'descricao'  => 'Tentativa de acesso com e-mail não cadastrado',
                'severidade' => 'ALERTA',
            ]);

            return $recusa;
        }

        if ($usuario['usu_status'] === STATUS_EXCLUIDO) {
            return $recusa;
        }

        if ($usuario['usu_status'] === STATUS_BLOQUEADO) {
            Auditoria::registrar('LOGIN_FALHA', [
                'usuario_id' => (int) $usuario['usu_id'],
                'descricao'  => 'Tentativa de acesso de conta bloqueada pela administracao',
                'severidade' => 'ALERTA',
            ]);

            return [
                'ok'        => false,
                'mensagem'  => 'Esta conta está bloqueada. Motivo: '
                    . ($usuario['usu_motivo_bloqueio'] ?: 'não informado')
                    . '. Procure a administração da plataforma.',
            ];
        }

        if ($usuario['usu_bloqueado_ate'] !== null && strtotime((string) $usuario['usu_bloqueado_ate']) > time()) {
            $minutos = max(1, (int) ceil((strtotime((string) $usuario['usu_bloqueado_ate']) - time()) / 60));

            return [
                'ok'       => false,
                'mensagem' => "Acesso temporariamente bloqueado por tentativas sucessivas. Tente novamente em {$minutos} minuto(s).",
            ];
        }

        if (!password_verify($senha, (string) $usuario['usu_senha'])) {
            RepositorioUsuario::registrarFalhaLogin((int) $usuario['usu_id']);

            Auditoria::registrar('LOGIN_FALHA', [
                'usuario_id' => (int) $usuario['usu_id'],
                'descricao'  => 'Senha incorreta',
                'severidade' => 'ALERTA',
            ]);

            return $recusa;
        }

        // Rehash quando o algoritmo padrao ou o custo mudou
        if (password_needs_rehash((string) $usuario['usu_senha'], PASSWORD_DEFAULT)) {
            RepositorioUsuario::atualizarSenha((int) $usuario['usu_id'], $senha);
        }

        RepositorioUsuario::registrarAcessoBemSucedido((int) $usuario['usu_id']);

        self::estabelecerSessao($usuario);

        Auditoria::registrar('LOGIN', [
            'usuario_id' => (int) $usuario['usu_id'],
            'entidade'   => 'sis_usuarios',
            'entidade_id'=> (int) $usuario['usu_id'],
            'descricao'  => 'Acesso realizado com sucesso',
        ]);

        return ['ok' => true, 'mensagem' => 'Acesso realizado.', 'usuario' => $usuario];
    }

    /** @param array<string, mixed> $usuario */
    public static function estabelecerSessao(array $usuario): void
    {
        Sessao::regenerar();
        Csrf::renovar();

        Sessao::definir(self::CHAVE_USUARIO, [
            'id'              => (int) $usuario['usu_id'],
            'nome'            => (string) $usuario['usu_nome'],
            'email'           => (string) $usuario['usu_email'],
            'perfil'          => (string) $usuario['usu_perfil'],
            'tipo_pessoa'     => (string) $usuario['usu_tipo_pessoa'],
            'registrado_crea' => (string) $usuario['usu_registrado_crea'],
            'rnp'             => $usuario['usu_rnp'] ?? null,
        ]);

        self::$memoria = null;
    }

    public static function sair(): void
    {
        $id = self::id();

        if ($id > 0) {
            Auditoria::registrar('LOGOUT', [
                'usuario_id' => $id,
                'descricao'  => 'Encerramento de sessão',
            ]);
        }

        Sessao::destruir();
        self::$memoria = null;
    }

    public static function autenticado(): bool
    {
        return Sessao::existe(self::CHAVE_USUARIO);
    }

    /** @return array<string, mixed>|null */
    public static function usuario(): ?array
    {
        $dados = Sessao::obter(self::CHAVE_USUARIO);

        return is_array($dados) ? $dados : null;
    }

    public static function id(): int
    {
        return (int) (self::usuario()['id'] ?? 0);
    }

    public static function perfil(): string
    {
        return (string) (self::usuario()['perfil'] ?? '');
    }

    /** @param list<string> $perfis */
    public static function temPerfil(array $perfis): bool
    {
        if ($perfis === []) {
            return true;
        }

        return in_array(self::perfil(), $perfis, true);
    }

    public static function ehAdministrador(): bool
    {
        return self::perfil() === PERFIL_ADMIN;
    }

    /**
     * Registro completo do usuario autenticado, lido do banco uma vez por
     * requisicao.
     *
     * @return array<string, mixed>|null
     */
    public static function registroCompleto(): ?array
    {
        if (self::$memoria !== null) {
            return self::$memoria;
        }

        $id = self::id();

        if ($id === 0) {
            return null;
        }

        return self::$memoria = RepositorioUsuario::porId($id);
    }

    /**
     * Exige autenticacao, interrompendo o fluxo quando ausente.
     */
    public static function exigirAutenticacao(): void
    {
        if (!self::autenticado()) {
            throw ExcecaoHttp::naoAutenticado();
        }
    }

    /** @param list<string> $perfis */
    public static function exigirPerfil(array $perfis): void
    {
        self::exigirAutenticacao();

        if (!self::temPerfil($perfis)) {
            throw ExcecaoHttp::naoAutorizado();
        }
    }

    /**
     * Revalida a sessao contra o banco, derrubando o acesso quando a conta foi
     * bloqueada ou excluida durante a sessao ativa.
     */
    public static function revalidar(): void
    {
        if (!self::autenticado()) {
            return;
        }

        $registro = self::registroCompleto();

        if ($registro === null || in_array($registro['usu_status'], [STATUS_BLOQUEADO, STATUS_EXCLUIDO], true)) {
            Sessao::destruir();
            Sessao::iniciar();
            Sessao::erro('Sua conta não está mais ativa. Procure a administração da plataforma.');
            self::$memoria = null;
        }
    }
}
