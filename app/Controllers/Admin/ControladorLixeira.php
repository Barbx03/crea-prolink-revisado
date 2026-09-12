<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;

/**
 * Lixeira administrativa (item 8.6.j do Termo de Referência).
 *
 * Registros com status 'X' não aparecem nas telas operacionais, mas seguem na
 * base e podem ser restaurados aqui, preservando integridade histórica e
 * rastreabilidade.
 */
final class ControladorLixeira extends Controlador
{
    /** @var array<string, array{rotulo: string, repositorio: class-string, titulo: string, id: string}> */
    private const ENTIDADES = [
        'usuarios' => [
            'rotulo'      => 'Usuários',
            'repositorio' => RepositorioUsuario::class,
            'titulo'      => 'usu_nome',
            'id'          => 'usu_id',
        ],
        'perfis' => [
            'rotulo'      => 'Perfis',
            'repositorio' => RepositorioPerfil::class,
            'titulo'      => 'prf_titulo',
            'id'          => 'prf_id',
        ],
        'demandas' => [
            'rotulo'      => 'Demandas',
            'repositorio' => RepositorioDemanda::class,
            'titulo'      => 'dem_titulo',
            'id'          => 'dem_id',
        ],
        'experiencias' => [
            'rotulo'      => 'Experiências',
            'repositorio' => RepositorioExperiencia::class,
            'titulo'      => 'exp_titulo',
            'id'          => 'exp_id',
        ],
        'arts' => [
            'rotulo'      => 'ARTs',
            'repositorio' => RepositorioArt::class,
            'titulo'      => 'art_numero',
            'id'          => 'art_id',
        ],
        'cats' => [
            'rotulo'      => 'CATs',
            'repositorio' => RepositorioCat::class,
            'titulo'      => 'cat_numero',
            'id'          => 'cat_id',
        ],
    ];

    public function listar(): void
    {
        $grupos = [];

        foreach (self::ENTIDADES as $chave => $definicao) {
            /** @var class-string<\App\Core\Repositorio> $repositorio */
            $repositorio = $definicao['repositorio'];

            $grupos[$chave] = [
                'rotulo' => $definicao['rotulo'],
                'campo_titulo' => $definicao['titulo'],
                'campo_id'     => $definicao['id'],
                'itens'  => $repositorio::listarExcluidos(50),
            ];
        }

        $this->visao('admin/lixeira.twig', ['grupos' => $grupos]);
    }

    public function restaurar(): void
    {
        $entidade = (string) $this->requisicao->parametro('entidade');
        $id       = $this->requisicao->parametroInteiro('id');

        if (!isset(self::ENTIDADES[$entidade])) {
            throw ExcecaoHttp::requisicaoInvalida('Tipo de registro inválido para restauração.');
        }

        /** @var class-string<\App\Core\Repositorio> $repositorio */
        $repositorio = self::ENTIDADES[$entidade]['repositorio'];

        if (!$repositorio::restaurar($id)) {
            Sessao::erro('Este registro não está na lixeira ou já foi restaurado.');
            $this->redirecionar('/admin/lixeira');

            return;
        }

        Sessao::sucesso(sprintf(
            '%s restaurado. Confira se os vínculos e a visibilidade estão como esperado.',
            rtrim(self::ENTIDADES[$entidade]['rotulo'], 's')
        ));

        $this->redirecionar('/admin/lixeira');
    }
}
