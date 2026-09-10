<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\Sessao;
use App\Repositories\RepositorioNotificacao;
use App\Services\ServicoNotificacao;

/**
 * Central de notificações do usuário (RF07).
 */
final class ControladorNotificacao extends Controlador
{
    public function central(): void
    {
        $this->visao('painel/notificacoes.twig', [
            'itens'      => RepositorioNotificacao::doUsuario($this->usuarioId(), 100),
            'nao_lidas'  => RepositorioNotificacao::contarNaoLidas($this->usuarioId()),
            'eventos'    => ServicoNotificacao::EVENTOS,
        ]);
    }

    public function marcarLida(): void
    {
        RepositorioNotificacao::marcarLida(
            $this->requisicao->parametroInteiro('id'),
            $this->usuarioId()
        );

        $this->redirecionar('/notificacoes');
    }

    public function marcarTodas(): void
    {
        RepositorioNotificacao::marcarTodasLidas($this->usuarioId());

        Sessao::informacao('Todas as notificações foram marcadas como lidas.');
        $this->redirecionar('/notificacoes');
    }
}
