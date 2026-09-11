<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioAuditoria;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Gestão de usuários e perfis pela administração (RF06).
 */
final class ControladorUsuarios extends Controlador
{
    public function listar(): void
    {
        $filtros = [
            'termo'      => $this->requisicao->texto('termo'),
            'perfil'     => $this->requisicao->texto('perfil'),
            'situacao'   => $this->requisicao->texto('situacao'),
            'registrado' => $this->requisicao->texto('registrado'),
        ];

        $resultado = RepositorioUsuario::listarParaAdministracao(
            $filtros,
            $this->pagina(),
            $this->porPagina()
        );

        $this->visao('admin/usuarios.twig', [
            'itens'      => $resultado['itens'],
            'paginacao'  => $this->paginacao($resultado['total']),
            'filtros'    => $filtros,
            'indicadores' => RepositorioUsuario::indicadores(),
            'perfis'     => [PERFIL_ADMIN, PERFIL_PROFISSIONAL, PERFIL_EMPRESA, PERFIL_TERCEIRO],
        ]);
    }

    public function ver(): void
    {
        $usuarioId = $this->requisicao->parametroInteiro('id');
        $usuario   = RepositorioUsuario::porId($usuarioId, true);

        if ($usuario === null) {
            throw ExcecaoHttp::naoEncontrado('Usuário não encontrado.');
        }

        $perfil   = RepositorioPerfil::porUsuario($usuarioId, true);
        $perfilId = $perfil !== null ? (int) $perfil['prf_id'] : 0;

        $this->visao('admin/usuario.twig', [
            'usuario'        => $usuario,
            'perfil'         => $perfil,
            'competencias'   => $perfilId > 0 ? RepositorioPerfil::competencias($perfilId) : [],
            'experiencias'   => $perfilId > 0 ? RepositorioExperiencia::doPerfil($perfilId) : [],
            'arts'           => $perfilId > 0 ? RepositorioArt::doPerfil($perfilId) : [],
            'cats'           => $perfilId > 0 ? RepositorioCat::doPerfil($perfilId) : [],
            'demandas'       => RepositorioDemanda::doAutor($usuarioId),
            'interesses'     => RepositorioInteresse::doUsuario($usuarioId),
            'consentimentos' => RepositorioLgpd::consentimentosAtuais($usuarioId),
            'finalidades'    => RepositorioLgpd::FINALIDADES,
            'solicitacoes'   => RepositorioLgpd::solicitacoesDoUsuario($usuarioId),
            'auditoria'      => RepositorioAuditoria::doUsuario($usuarioId, 40),
            'perfis'         => [PERFIL_ADMIN, PERFIL_PROFISSIONAL, PERFIL_EMPRESA, PERFIL_TERCEIRO],
        ]);
    }

    public function bloquear(): void
    {
        $usuario = $this->usuarioAlvo($this->requisicao->parametroInteiro('id'));

        $motivo = $this->requisicao->texto('motivo');

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('motivo', 'o motivo do bloqueio')
            ->minimo('motivo', 10, 'O motivo')
            ->maximo('motivo', 255, 'O motivo');

        if (!$validador->valido()) {
            $this->voltarComErros('/admin/usuarios/' . (int) $usuario['usu_id'], $validador->erros());

            return;
        }

        RepositorioUsuario::bloquear((int) $usuario['usu_id'], $motivo);

        (new ServicoNotificacao())->notificar(
            'MODERACAO',
            (int) $usuario['usu_id'],
            (string) $usuario['usu_email'],
            'CREA Pro-Link | Conta bloqueada',
            [
                'titulo'   => 'Sua conta foi bloqueada',
                'mensagem' => 'O acesso à sua conta no CREA Pro-Link foi bloqueado pela administração da plataforma.',
                'detalhes' => ['Motivo informado' => $motivo],
            ]
        );

        Sessao::informacao('Usuário bloqueado e notificado.');
        $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);
    }

    public function desbloquear(): void
    {
        $usuario = $this->usuarioAlvo($this->requisicao->parametroInteiro('id'));

        RepositorioUsuario::desbloquear((int) $usuario['usu_id']);

        (new ServicoNotificacao())->notificar(
            'MODERACAO',
            (int) $usuario['usu_id'],
            (string) $usuario['usu_email'],
            'CREA Pro-Link | Conta reativada',
            [
                'titulo'   => 'Sua conta foi reativada',
                'mensagem' => 'O acesso à sua conta no CREA Pro-Link foi restabelecido.',
                'acao_texto' => 'Acessar a plataforma',
            ],
            '/entrar'
        );

        Sessao::sucesso('Usuário desbloqueado.');
        $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);
    }

    /**
     * Alteração do perfil de acesso.
     *
     * O perfil de profissional ou empresa registrada não pode ser concedido
     * manualmente: ele depende da validação na API oficial, o que preserva a
     * distinção entre registrados e não registrados exigida pelo item 1 do
     * Termo de Referência.
     */
    public function alterarPerfil(): void
    {
        $usuario = $this->usuarioAlvo($this->requisicao->parametroInteiro('id'));
        $perfil  = $this->requisicao->texto('perfil');

        if (!in_array($perfil, [PERFIL_ADMIN, PERFIL_PROFISSIONAL, PERFIL_EMPRESA, PERFIL_TERCEIRO], true)) {
            Sessao::erro('Perfil de acesso inválido.');
            $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);

            return;
        }

        if (
            in_array($perfil, [PERFIL_PROFISSIONAL, PERFIL_EMPRESA], true)
            && ($usuario['usu_registrado_crea'] ?? 'N') !== 'S'
        ) {
            Sessao::erro(
                'Este perfil depende de registro validado na API oficial do CREA-AM. '
                . 'O próprio titular deve concluir a validação em seu perfil.'
            );
            $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);

            return;
        }

        if ((int) $usuario['usu_id'] === $this->usuarioId() && $perfil !== PERFIL_ADMIN) {
            Sessao::erro('Você não pode remover o seu próprio perfil administrativo.');
            $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);

            return;
        }

        RepositorioUsuario::alterarPerfil((int) $usuario['usu_id'], $perfil);

        Sessao::sucesso('Perfil de acesso atualizado.');
        $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);
    }

    /**
     * Exclusão lógica da conta pela administração (item 8.6.j).
     */
    public function excluir(): void
    {
        $usuario = $this->usuarioAlvo($this->requisicao->parametroInteiro('id'));

        if ((int) $usuario['usu_id'] === $this->usuarioId()) {
            Sessao::erro('Você não pode excluir a sua própria conta.');
            $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);

            return;
        }

        $motivo = $this->requisicao->texto('motivo');

        if (mb_strlen($motivo) < 10) {
            Sessao::erro('Informe o motivo da exclusão, com ao menos 10 caracteres.');
            $this->redirecionar('/admin/usuarios/' . (int) $usuario['usu_id']);

            return;
        }

        RepositorioUsuario::excluirLogicamente((int) $usuario['usu_id'], $motivo);

        $perfil = RepositorioPerfil::porUsuario((int) $usuario['usu_id']);

        if ($perfil !== null) {
            RepositorioPerfil::excluirLogicamente((int) $perfil['prf_id'], 'Conta excluída pela administração');
        }

        Sessao::informacao(
            'Conta marcada como excluída. O registro permanece na base e pode ser '
            . 'restaurado pela lixeira administrativa.'
        );
        $this->redirecionar('/admin/usuarios');
    }

    /**
     * @return array<string, mixed>
     */
    private function usuarioAlvo(int $usuarioId): array
    {
        $usuario = RepositorioUsuario::porId($usuarioId, true);

        if ($usuario === null) {
            throw ExcecaoHttp::naoEncontrado('Usuário não encontrado.');
        }

        return $usuario;
    }
}
