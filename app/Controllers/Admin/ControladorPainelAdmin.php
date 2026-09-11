<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Configuracao;
use App\Core\Controlador;
use App\Repositories\RepositorioApiConsulta;
use App\Repositories\RepositorioAuditoria;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioDenuncia;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioNotificacao;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoIntegracaoCrea;
use App\Services\ServicoNotificacao;

/**
 * Painel administrativo: visão geral e indicadores gerenciais (RF06).
 */
final class ControladorPainelAdmin extends Controlador
{
    public function painel(): void
    {
        RepositorioDemanda::encerrarVencidas();

        $integracao = new ServicoIntegracaoCrea();
        $servicoEmail = new ServicoNotificacao();

        $this->visao('admin/painel.twig', [
            'usuarios'      => RepositorioUsuario::indicadores(),
            'demandas'      => RepositorioDemanda::indicadores(),
            'interesses'    => RepositorioInteresse::indicadores(),
            'denuncias'     => RepositorioDenuncia::indicadores(),
            'lgpd'          => RepositorioLgpd::indicadores(),
            'notificacoes'  => RepositorioNotificacao::indicadores(),
            'auditoria'     => RepositorioAuditoria::indicadores(),
            'api'           => $this->indicadoresApi(),
            'integracao_ativa' => $integracao->integracaoDisponivel(),
            'smtp_ativo'    => $servicoEmail->smtpAtivo(),
            'ultimos_eventos' => RepositorioAuditoria::consultar([], 1, 12)['itens'],
            'pendencias'    => $this->pendencias(),
        ]);
    }

    public function indicadores(): void
    {
        $this->visao('admin/indicadores.twig', [
            'usuarios'         => RepositorioUsuario::indicadores(),
            'demandas'         => RepositorioDemanda::indicadores(),
            'interesses'       => RepositorioInteresse::indicadores(),
            'denuncias'        => RepositorioDenuncia::indicadores(),
            'api'              => $this->indicadoresApi(),
            'resumo_auditoria' => RepositorioAuditoria::resumoPorAcao(30),
            'mais_demandadas'  => RepositorioCompetencia::maisDemandadas(12),
            'mais_ofertadas'   => RepositorioCompetencia::maisOfertadas(12),
            'cadastros_mes'    => $this->cadastrosPorMes(),
            'demandas_mes'     => $this->demandasPorMes(),
            'pesos_matching'   => Configuracao::grupo('MATCHING'),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function indicadoresApi(): array
    {
        return RepositorioApiConsulta::indicadores();
    }

    /**
     * Itens que exigem acao da administracao, reunidos em um so lugar.
     *
     * @return list<array{rotulo: string, total: int, link: string, urgente: bool}>
     */
    private function pendencias(): array
    {
        $denuncias    = RepositorioDenuncia::indicadores();
        $lgpd         = RepositorioLgpd::indicadores();
        $demandas     = RepositorioDemanda::indicadores();
        $notificacoes = RepositorioNotificacao::indicadores();

        $pendencias = [
            [
                'rotulo'  => 'Denúncias abertas',
                'total'   => $denuncias['abertas'] ?? 0,
                'link'    => '/admin/moderacao/denuncias?situacao=ABERTA',
                'urgente' => ($denuncias['abertas'] ?? 0) > 0,
            ],
            [
                'rotulo'  => 'Requisições de titulares em aberto',
                'total'   => ($lgpd['abertas'] ?? 0) + ($lgpd['em_analise'] ?? 0),
                'link'    => '/admin/lgpd?situacao=ABERTA',
                'urgente' => ($lgpd['abertas'] ?? 0) > 0,
            ],
            [
                'rotulo'  => 'Demandas em moderação',
                'total'   => $demandas['em_moderacao'] ?? 0,
                'link'    => '/admin/moderacao/demandas?moderacao=EM_ANALISE',
                'urgente' => false,
            ],
            [
                'rotulo'  => 'Perfis em moderação',
                'total'   => RepositorioPerfil::contarPendentesDeModeracao(),
                'link'    => '/admin/moderacao/perfis',
                'urgente' => false,
            ],
            [
                'rotulo'  => 'Notificações com falha de envio',
                'total'   => $notificacoes['falhas'] ?? 0,
                'link'    => '/admin/notificacoes?situacao=F',
                'urgente' => false,
            ],
        ];

        return array_values(array_filter(
            $pendencias,
            static fn (array $pendencia): bool => $pendencia['total'] > 0
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cadastrosPorMes(): array
    {
        return RepositorioUsuario::cadastrosPorMes();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function demandasPorMes(): array
    {
        return RepositorioDemanda::porMes();
    }
}