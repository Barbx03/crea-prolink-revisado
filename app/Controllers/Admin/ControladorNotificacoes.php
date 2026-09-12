<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controlador;
use App\Core\Sessao;
use App\Repositories\RepositorioNotificacao;
use App\Services\ServicoNotificacao;

/**
 * Acompanhamento da fila de notificações (RF07).
 */
final class ControladorNotificacoes extends Controlador
{
    public function listar(): void
    {
        $filtros = [
            'situacao' => $this->requisicao->texto('situacao'),
            'evento'   => $this->requisicao->texto('evento'),
        ];

        $resultado = RepositorioNotificacao::listarParaAdministracao(
            $filtros,
            $this->pagina(),
            $this->porPagina()
        );

        $this->visao('admin/notificacoes.twig', [
            'itens'       => $resultado['itens'],
            'paginacao'   => $this->paginacao($resultado['total']),
            'filtros'     => $filtros,
            'indicadores' => RepositorioNotificacao::indicadores(),
            'eventos'     => ServicoNotificacao::EVENTOS,
            'smtp_ativo'  => (new ServicoNotificacao())->smtpAtivo(),
        ]);
    }

    public function processarFila(): void
    {
        $servico = new ServicoNotificacao();

        if (!$servico->smtpAtivo()) {
            Sessao::erro(
                'O envio por SMTP está desativado. Ative e configure o servidor em Configurações '
                . 'antes de processar a fila.'
            );
            $this->redirecionar('/admin/notificacoes');

            return;
        }

        $resultado = $servico->processarFila(50);

        if ($resultado['enviadas'] === 0 && $resultado['falhas'] === 0) {
            Sessao::informacao('Não há notificações pendentes de envio.');
        } else {
            Sessao::sucesso(sprintf(
                '%d notificação(ões) enviada(s)%s.',
                $resultado['enviadas'],
                $resultado['falhas'] > 0 ? sprintf(' e %d com falha', $resultado['falhas']) : ''
            ));
        }

        $this->redirecionar('/admin/notificacoes');
    }
}
