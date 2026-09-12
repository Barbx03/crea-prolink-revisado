<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Tratamento das requisições de direitos dos titulares (LGPD art. 18 / RF06).
 */
final class ControladorLgpd extends Controlador
{
    public function solicitacoes(): void
    {
        $filtros = [
            'situacao' => $this->requisicao->texto('situacao'),
            'tipo'     => $this->requisicao->texto('tipo'),
        ];

        $resultado = RepositorioLgpd::listarSolicitacoes($filtros, $this->pagina(), $this->porPagina());

        $this->visao('admin/lgpd.twig', [
            'itens'       => $resultado['itens'],
            'paginacao'   => $this->paginacao($resultado['total']),
            'filtros'     => $filtros,
            'indicadores' => RepositorioLgpd::indicadores(),
            'tipos'       => RepositorioLgpd::TIPOS_SOLICITACAO,
        ]);
    }

    public function verSolicitacao(): void
    {
        $solicitacao = $this->solicitacao($this->requisicao->parametroInteiro('id'));
        $titularId   = (int) $solicitacao['slg_usu_id'];

        $this->visao('admin/lgpd-solicitacao.twig', [
            'solicitacao'    => $solicitacao,
            'titular'        => RepositorioUsuario::porId($titularId, true),
            'perfil'         => RepositorioPerfil::porUsuario($titularId, true),
            'consentimentos' => RepositorioLgpd::consentimentosAtuais($titularId),
            'finalidades'    => RepositorioLgpd::FINALIDADES,
            'historico'      => RepositorioLgpd::historicoConsentimentos($titularId),
            'outras'         => RepositorioLgpd::solicitacoesDoUsuario($titularId),
        ]);
    }

    public function tratarSolicitacao(): void
    {
        $solicitacao = $this->solicitacao($this->requisicao->parametroInteiro('id'));

        $validador = Validador::para($this->requisicao->todos())
            ->dentroDe('situacao', ['EM_ANALISE', 'ATENDIDA', 'RECUSADA'], 'a situação')
            ->obrigatorio('situacao', 'a situação')
            ->obrigatorio('resposta', 'a resposta ao titular')
            ->minimo('resposta', 20, 'A resposta')
            ->maximo('resposta', 4000, 'A resposta');

        if (!$validador->valido()) {
            $this->voltarComErros(
                '/admin/lgpd/' . (int) $solicitacao['slg_id'],
                $validador->erros()
            );

            return;
        }

        $situacao = $this->requisicao->texto('situacao');
        $resposta = $this->requisicao->texto('resposta');

        RepositorioLgpd::responderSolicitacao(
            (int) $solicitacao['slg_id'],
            $this->usuarioId(),
            $situacao,
            $resposta
        );

        // Informa o titular do desfecho, como exige o direito de resposta
        (new ServicoNotificacao())->notificar(
            'SOLICITACAO_LGPD',
            (int) $solicitacao['slg_usu_id'],
            (string) $solicitacao['usu_email'],
            'CREA Pro-Link | Resposta à sua requisição',
            [
                'titulo'   => 'Resposta à requisição ' . str_pad((string) $solicitacao['slg_id'], 6, '0', STR_PAD_LEFT),
                'mensagem' => $resposta,
                'detalhes' => [
                    'Tipo'     => \App\Core\Formatador::rotulo((string) $solicitacao['slg_tipo']),
                    'Situação' => \App\Core\Formatador::rotulo($situacao),
                ],
                'acao_texto' => 'Ver no painel de privacidade',
            ],
            '/privacidade'
        );

        Sessao::sucesso('Requisição tratada e titular notificado.');
        $this->redirecionar('/admin/lgpd');
    }

    /**
     * Executa a anonimização pedida pelo titular (LGPD art. 18, IV e VI).
     *
     * A conta é anonimizada e marcada como excluída; a trilha de auditoria
     * permanece, sem identificar a pessoa.
     */
    public function anonimizar(): void
    {
        $solicitacao = $this->solicitacao($this->requisicao->parametroInteiro('id'));
        $titularId   = (int) $solicitacao['slg_usu_id'];

        if (!in_array($solicitacao['slg_tipo'], ['ANONIMIZACAO', 'ELIMINACAO'], true)) {
            Sessao::erro('Esta requisição não é de anonimização nem de eliminação de dados.');
            $this->redirecionar('/admin/lgpd/' . (int) $solicitacao['slg_id']);

            return;
        }

        if ($this->requisicao->texto('confirmacao') !== 'ANONIMIZAR') {
            Sessao::erro('Digite ANONIMIZAR no campo de confirmação para executar esta ação irreversível.');
            $this->redirecionar('/admin/lgpd/' . (int) $solicitacao['slg_id']);

            return;
        }

        $titular = RepositorioUsuario::porId($titularId, true);

        if ($titular === null) {
            throw ExcecaoHttp::naoEncontrado('Titular não encontrado.');
        }

        if ((string) $titular['usu_perfil'] === PERFIL_ADMIN) {
            Sessao::erro('Contas administrativas não podem ser anonimizadas por esta tela.');
            $this->redirecionar('/admin/lgpd/' . (int) $solicitacao['slg_id']);

            return;
        }

        // Avisa antes de anonimizar, pois depois não há mais endereço válido
        (new ServicoNotificacao())->notificar(
            'SOLICITACAO_LGPD',
            $titularId,
            (string) $titular['usu_email'],
            'CREA Pro-Link | Dados anonimizados',
            [
                'titulo'   => 'Sua requisição foi atendida',
                'mensagem' => 'Seus dados pessoais foram anonimizados de forma irreversível na plataforma. '
                    . 'Os registros de auditoria exigidos por lei foram preservados sem identificação.',
                'detalhes' => [
                    'Protocolo' => str_pad((string) $solicitacao['slg_id'], 6, '0', STR_PAD_LEFT),
                    'Executado em' => date('d/m/Y H:i'),
                ],
            ]
        );

        $perfil = RepositorioPerfil::porUsuario($titularId, true);

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
                'Anonimização atendida a pedido do titular'
            );
        }

        RepositorioUsuario::anonimizar($titularId);

        RepositorioLgpd::responderSolicitacao(
            (int) $solicitacao['slg_id'],
            $this->usuarioId(),
            'ATENDIDA',
            'Dados pessoais anonimizados de forma irreversível, preservando a trilha de auditoria '
            . 'sem identificação do titular.'
        );

        Sessao::sucesso('Dados do titular anonimizados e requisição encerrada.');
        $this->redirecionar('/admin/lgpd');
    }

    /**
     * @return array<string, mixed>
     */
    private function solicitacao(int $solicitacaoId): array
    {
        $solicitacao = RepositorioLgpd::solicitacaoCompleta($solicitacaoId);

        if ($solicitacao === null) {
            throw ExcecaoHttp::naoEncontrado('Requisição não encontrada.');
        }

        return $solicitacao;
    }
}
