<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Autenticacao;
use App\Core\Controlador;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioAuditoria;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Painel de privacidade do titular (RF01 e LGPD).
 *
 * Reúne em um só lugar o que a LGPD garante ao titular: saber quais dados
 * existem, corrigir, restringir a visibilidade, revogar consentimentos, levar
 * os dados consigo e pedir eliminação ou anonimização.
 */
final class ControladorPrivacidade extends Controlador
{
    public function painel(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $this->visao('perfil/privacidade.twig', [
            'usuario'         => $this->usuarioAutenticado(),
            'perfil'          => RepositorioPerfil::porUsuario($this->usuarioId()),
            'consentimentos'  => RepositorioLgpd::consentimentosAtuais($this->usuarioId()),
            'finalidades'     => RepositorioLgpd::FINALIDADES,
            'historico'       => RepositorioLgpd::historicoConsentimentos($this->usuarioId()),
            'solicitacoes'    => RepositorioLgpd::solicitacoesDoUsuario($this->usuarioId()),
            'tipos_solicitacao' => RepositorioLgpd::TIPOS_SOLICITACAO,
            'meus_acessos'    => RepositorioAuditoria::doUsuario($this->usuarioId(), 15),
            'perfil_id'       => $perfilId,
        ]);
    }

    /**
     * Controles de visibilidade do perfil, exercendo a restrição de tratamento.
     */
    public function salvarVisibilidade(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $validador = Validador::para($this->requisicao->todos())
            ->dentroDe('visibilidade', ['PUBLICO', 'AUTENTICADO', 'OCULTO'], 'a visibilidade do perfil');

        if (!$validador->valido()) {
            $this->voltarComErros('/privacidade', $validador->erros());

            return;
        }

        RepositorioPerfil::salvarPrivacidade($perfilId, [
            'visibilidade'     => $this->requisicao->texto('visibilidade', 'PUBLICO'),
            'exibe_contato'    => $this->requisicao->flag('exibe_contato'),
            'exibe_documento'  => $this->requisicao->flag('exibe_documento'),
            'exibe_rnp'        => $this->requisicao->flag('exibe_rnp'),
            'exibe_valor_hora' => $this->requisicao->flag('exibe_valor_hora'),
            'aceita_contato'   => $this->requisicao->flag('aceita_contato'),
        ]);

        Sessao::sucesso('Preferências de privacidade atualizadas.');
        $this->redirecionar('/privacidade');
    }

    /**
     * Concessão e revogação de consentimentos por finalidade (LGPD art. 8 e 18).
     */
    public function salvarConsentimentos(): void
    {
        $concedidas = $this->requisicao->lista('finalidades');
        $atuais     = RepositorioLgpd::consentimentosAtuais($this->usuarioId());

        // Termos e política não são revogáveis sem encerrar a conta: sua
        // revogação equivale a pedir a eliminação, tratada em rota própria.
        $gerenciaveis = ['DADOS_CREA', 'PERFIL_PUBLICO', 'COMUNICACOES'];
        $alteracoes   = 0;

        foreach ($gerenciaveis as $finalidade) {
            $desejado = in_array($finalidade, $concedidas, true);
            $vigente  = $atuais[$finalidade]['concedido'] ?? false;

            if ($desejado === $vigente) {
                continue;
            }

            RepositorioLgpd::registrarConsentimento(
                $this->usuarioId(),
                $finalidade,
                $desejado,
                null,
                $this->requisicao->ip,
                $this->requisicao->agenteUsuario
            );

            $alteracoes++;

            // Revogar a exibição pública oculta o perfil imediatamente
            if ($finalidade === 'PERFIL_PUBLICO' && !$desejado) {
                $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());
                $perfil   = RepositorioPerfil::porId($perfilId);

                RepositorioPerfil::salvarPrivacidade($perfilId, [
                    'visibilidade'     => 'OCULTO',
                    'exibe_contato'    => 'N',
                    'exibe_documento'  => 'N',
                    'exibe_rnp'        => $perfil['prf_exibe_rnp'] ?? 'S',
                    'exibe_valor_hora' => 'N',
                    'aceita_contato'   => 'N',
                ]);

                Sessao::aviso(
                    'Ao revogar a exibição pública, seu perfil foi ocultado das buscas '
                    . 'e o recebimento de contatos foi desativado.'
                );
            }
        }

        Sessao::sucesso(
            $alteracoes === 0
                ? 'Nenhuma alteração a registrar nos consentimentos.'
                : sprintf('%d consentimento(s) atualizado(s) e registrado(s) na trilha.', $alteracoes)
        );

        $this->redirecionar('/privacidade');
    }

    /**
     * Portabilidade: entrega ao titular todos os seus dados em formato aberto
     * (LGPD art. 18, II e V).
     */
    public function exportarDados(): void
    {
        $dados = RepositorioUsuario::dadosParaPortabilidade($this->usuarioId());

        Auditoria::registrar('EXPORTACAO_DADOS', [
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $this->usuarioId(),
            'descricao'   => 'Titular exportou os próprios dados (portabilidade)',
            'severidade'  => 'ALERTA',
        ]);

        Resposta::arquivo(
            sprintf('prolink-meus-dados-%s.json', date('Y-m-d')),
            (string) json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'application/json'
        );
    }

    public function abrirSolicitacao(): void
    {
        $tipo = $this->requisicao->texto('tipo');

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('tipo', 'o tipo de requisição')
            ->dentroDe('tipo', RepositorioLgpd::TIPOS_SOLICITACAO, 'o tipo de requisição')
            ->maximo('descricao', 4000, 'A descrição');

        if ($tipo === 'CORRECAO') {
            $validador->obrigatorio('descricao', 'quais dados devem ser corrigidos')
                ->minimo('descricao', 20, 'A descrição');
        }

        if (!$validador->valido()) {
            $this->voltarComErros('/privacidade', $validador->erros());

            return;
        }

        $solicitacaoId = RepositorioLgpd::abrirSolicitacao(
            $this->usuarioId(),
            $tipo,
            $this->requisicao->texto('descricao')
        );

        $usuario = $this->usuarioAutenticado();
        $servico = new ServicoNotificacao();

        // Confirmação ao titular
        $servico->notificar(
            'SOLICITACAO_LGPD',
            $this->usuarioId(),
            (string) $usuario['usu_email'],
            'CREA Pro-Link | Requisição registrada',
            [
                'titulo'   => 'Sua requisição foi registrada',
                'mensagem' => sprintf(
                    'Recebemos sua requisição de %s. Ela será analisada pela administração da plataforma, '
                    . 'e você receberá a resposta por aqui.',
                    mb_strtolower(\App\Core\Formatador::rotulo($tipo))
                ),
                'detalhes' => [
                    'Protocolo' => str_pad((string) $solicitacaoId, 6, '0', STR_PAD_LEFT),
                    'Tipo'      => \App\Core\Formatador::rotulo($tipo),
                    'Registrada em' => date('d/m/Y H:i'),
                ],
            ],
            '/privacidade'
        );

        Sessao::sucesso(sprintf(
            'Requisição registrada sob o protocolo %s. Acompanhe a resposta nesta página.',
            str_pad((string) $solicitacaoId, 6, '0', STR_PAD_LEFT)
        ));

        $this->redirecionar('/privacidade');
    }

    /**
     * Encerramento da conta pelo próprio titular (LGPD art. 18, VI).
     *
     * A exclusão é lógica e acompanhada de anonimização: os dados pessoais
     * deixam de existir, mas os registros de auditoria e os vínculos históricos
     * permanecem, como exige o item 8.6.j do Termo de Referência.
     */
    public function encerrarConta(): void
    {
        $usuario = $this->usuarioAutenticado();

        if (!password_verify($this->requisicao->texto('senha'), (string) $usuario['usu_senha'])) {
            Sessao::erro('Informe a senha correta para confirmar o encerramento da conta.');
            $this->redirecionar('/privacidade');

            return;
        }

        if ($this->requisicao->texto('confirmacao') !== 'ENCERRAR') {
            Sessao::erro('Digite ENCERRAR no campo de confirmação para prosseguir.');
            $this->redirecionar('/privacidade');

            return;
        }

        // Administradores não podem se autoexcluir: a plataforma ficaria sem gestão
        if (Autenticacao::ehAdministrador()) {
            Sessao::erro(
                'Contas administrativas não podem ser encerradas por esta via. '
                . 'Solicite a outro administrador.'
            );
            $this->redirecionar('/privacidade');

            return;
        }

        $usuarioId = $this->usuarioId();

        RepositorioLgpd::abrirSolicitacao(
            $usuarioId,
            'ELIMINACAO',
            'Encerramento de conta solicitado e executado pelo próprio titular.'
        );

        // Oculta o perfil antes de anonimizar, para sair das buscas de imediato
        $perfil = RepositorioPerfil::porUsuario($usuarioId);

        if ($perfil !== null) {
            RepositorioPerfil::salvarPrivacidade((int) $perfil['prf_id'], [
                'visibilidade'     => 'OCULTO',
                'exibe_contato'    => 'N',
                'exibe_documento'  => 'N',
                'exibe_rnp'        => 'N',
                'exibe_valor_hora' => 'N',
                'aceita_contato'   => 'N',
            ]);

            RepositorioPerfil::excluirLogicamente(
                (int) $perfil['prf_id'],
                'Conta encerrada pelo titular'
            );
        }

        RepositorioLgpd::registrarConsentimento(
            $usuarioId,
            'PERFIL_PUBLICO',
            false,
            null,
            $this->requisicao->ip,
            $this->requisicao->agenteUsuario
        );

        RepositorioUsuario::anonimizar($usuarioId);

        Autenticacao::sair();
        Sessao::iniciar();
        Sessao::informacao(
            'Sua conta foi encerrada e seus dados pessoais foram anonimizados. '
            . 'Os registros de auditoria exigidos por lei foram preservados sem identificação.'
        );

        $this->redirecionar('/');
    }
}
