<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Repositories\RepositorioBusca;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioDemanda;

/**
 * Página inicial pública.
 */
final class ControladorInicio extends Controlador
{
    public function inicio(): void
    {
        $this->visao('inicio.twig', [
            'numeros'            => RepositorioBusca::numerosPlataforma(),
            'destaques'          => RepositorioBusca::destaques(6),
            'demandas'           => RepositorioDemanda::recentes(6),
            'areas'              => RepositorioCompetencia::areas(),
            'mais_demandadas'    => RepositorioCompetencia::maisDemandadas(8),
        ]);
    }
}
