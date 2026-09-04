<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Autenticacao;
use App\Core\Controlador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioConversa;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioNotificacao;
use App\Repositories\RepositorioPerfil;
use App\Services\ServicoCompatibilizacao;

/**
 * Painel do usuário autenticado, com conteúdo específico por perfil.
 */
final class ControladorPainel extends Controlador
{
    public function painel(): void
    {
        // Encerra demandas cujo prazo expirou, dispensando agendador externo
        RepositorioDemanda::encerrarVencidas();

        $usuario = $this->usuarioAutenticado();
        $perfil  = Autenticacao::perfil();

        if ($perfil === PERFIL_ADMIN) {
            $this->redirecionar('/admin');

            return;
        }

        $dados = [
            'usuario'       => $usuario,
            'nao_lidas'     => RepositorioNotificacao::contarNaoLidas($this->usuarioId()),
            'mensagens'     => RepositorioConversa::contarNaoLidas($this->usuarioId()),
            'notificacoes'  => array_slice(RepositorioNotificacao::doUsuario($this->usuarioId(), 5), 0, 5),
            'conversas'     => array_slice(RepositorioConversa::doUsuario($this->usuarioId()), 0, 4),
        ];

        if (in_array($perfil, [PERFIL_PROFISSIONAL, PERFIL_EMPRESA], true)) {
            $dados += $this->dadosPrestador();
        }

        if (in_array($perfil, [PERFIL_EMPRESA, PERFIL_TERCEIRO], true)) {
            $dados += $this->dadosContratante();
        }

        $this->visao('painel/painel.twig', $dados);
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosPrestador(): array
    {
        $registroPerfil = RepositorioPerfil::porUsuario($this->usuarioId());
        $perfilId       = $registroPerfil !== null ? (int) $registroPerfil['prf_id'] : 0;

        $compatibilizacao = new ServicoCompatibilizacao();

        return [
            'perfil'          => $registroPerfil,
            'completude'      => $perfilId > 0 ? RepositorioPerfil::completude($perfilId) : 0,
            'competencias'    => $perfilId > 0 ? RepositorioPerfil::competencias($perfilId) : [],
            'total_arts'      => $perfilId > 0 ? RepositorioArt::contarDoPerfil($perfilId) : 0,
            'total_cats'      => $perfilId > 0 ? RepositorioCat::contarDoPerfil($perfilId) : 0,
            'total_experiencias' => $perfilId > 0 ? count(RepositorioExperiencia::doPerfil($perfilId)) : 0,
            'meus_interesses' => array_slice(RepositorioInteresse::doUsuario($this->usuarioId()), 0, 5),
            'recomendadas'    => $compatibilizacao->demandasParaProfissional($this->usuarioId(), 5),
            'pesos_matching'  => $compatibilizacao->pesos(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosContratante(): array
    {
        $demandas = RepositorioDemanda::doAutor($this->usuarioId());

        $totalInteresses = 0;
        $publicadas      = 0;

        foreach ($demandas as $demanda) {
            $totalInteresses += (int) $demanda['dem_total_interesses'];

            if ($demanda['dem_situacao'] === 'PUBLICADA') {
                $publicadas++;
            }
        }

        return [
            'minhas_demandas'         => array_slice($demandas, 0, 5),
            'total_demandas'          => count($demandas),
            'demandas_publicadas'     => $publicadas,
            'total_interesses_recebidos' => $totalInteresses,
        ];
    }
}
