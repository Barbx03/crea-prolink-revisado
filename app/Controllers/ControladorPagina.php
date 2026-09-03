<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Repositories\RepositorioLgpd;

/**
 * Páginas institucionais e documentos da plataforma.
 */
final class ControladorPagina extends Controlador
{
    public function sobre(): void
    {
        $this->visao('paginas/sobre.twig');
    }

    public function termosDeUso(): void
    {
        $this->visao('paginas/documento.twig', [
            'documento' => RepositorioLgpd::termoVigente('TERMOS_USO'),
            'tipo'      => 'Termos de Uso',
        ]);
    }

    public function politicaDePrivacidade(): void
    {
        $this->visao('paginas/documento.twig', [
            'documento' => RepositorioLgpd::termoVigente('POLITICA_PRIVACIDADE'),
            'tipo'      => 'Política de Privacidade',
        ]);
    }

    public function acessibilidade(): void
    {
        $this->visao('paginas/acessibilidade.twig');
    }
}
