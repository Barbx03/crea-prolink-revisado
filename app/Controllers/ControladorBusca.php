<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Autenticacao;
use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Validador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioBusca;

/**
 * Pesquisa pública de profissionais e visualização de perfil (item 3, perfil
 * "Público", e RF04).
 *
 * A montagem do perfil respeita as escolhas de visibilidade do titular: dados
 * de contato, documento, RNP e valor-hora só aparecem quando ele autorizou.
 */
final class ControladorBusca extends Controlador
{
    public function pesquisar(): void
    {
        $filtros = [
            'termo'               => $this->requisicao->texto('termo'),
            'area_id'             => $this->requisicao->inteiro('area_id'),
            'uf'                  => strtoupper($this->requisicao->texto('uf')),
            'cidade'              => $this->requisicao->texto('cidade'),
            'perfil'              => $this->requisicao->texto('perfil'),
            'disponibilidade'     => $this->requisicao->texto('disponibilidade'),
            'experiencia_min'     => $this->requisicao->inteiro('experiencia_min'),
            'competencias'        => $this->requisicao->listaInteiros('competencias'),
            'somente_registrados' => $this->requisicao->booleano('somente_registrados'),
            'com_acervo'          => $this->requisicao->booleano('com_acervo'),
            'incluir_remoto'      => $this->requisicao->booleano('incluir_remoto'),
            'ordem'               => $this->requisicao->texto('ordem', 'relevancia'),
        ];

        $resultado = RepositorioBusca::profissionais(
            $filtros,
            $this->pagina(),
            $this->porPagina(),
            Autenticacao::autenticado()
        );

        $this->visao('busca/profissionais.twig', [
            'itens'        => $resultado['itens'],
            'paginacao'    => $this->paginacao($resultado['total']),
            'filtros'      => $filtros,
            'areas'        => RepositorioCompetencia::areas(),
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
        ]);
    }

    public function verPerfil(): void
    {
        $perfilId = $this->requisicao->parametroInteiro('id');
        $perfil   = RepositorioPerfil::completoPorId($perfilId);

        if ($perfil === null) {
            throw ExcecaoHttp::naoEncontrado('Perfil não encontrado ou não está mais disponível.');
        }

        $ehTitular = $this->usuarioId() === (int) $perfil['prf_usu_id'];
        $ehAdmin   = Autenticacao::ehAdministrador();

        // Visibilidade escolhida pelo titular
        if (!$ehTitular && !$ehAdmin) {
            if ($perfil['prf_visibilidade'] === 'OCULTO' || $perfil['prf_moderacao'] !== 'APROVADO') {
                throw ExcecaoHttp::naoEncontrado('Este perfil não está disponível para visualização.');
            }

            if ($perfil['prf_visibilidade'] === 'AUTENTICADO' && !Autenticacao::autenticado()) {
                throw ExcecaoHttp::naoAutenticado(
                    'Este profissional optou por exibir o perfil apenas a usuários autenticados.'
                );
            }

            if ($perfil['situacao_usuario'] !== STATUS_ATIVO) {
                throw ExcecaoHttp::naoEncontrado('Este perfil não está disponível para visualização.');
            }
        }

        // Somente o titular e o administrador veem itens ocultos
        $exibirTudo = $ehTitular || $ehAdmin;

        if (!$ehTitular) {
            Auditoria::registrar('VISUALIZACAO_PERFIL', [
                'entidade'    => 'pro_perfis',
                'entidade_id' => $perfilId,
                'descricao'   => 'Perfil profissional visualizado',
            ]);
        }

        $this->visao('busca/perfil.twig', [
            'perfil'       => $perfil,
            'competencias' => RepositorioPerfil::competencias($perfilId),
            'experiencias' => RepositorioExperiencia::doPerfil($perfilId, !$exibirTudo),
            'arts'         => RepositorioArt::doPerfil($perfilId, !$exibirTudo),
            'cats'         => RepositorioCat::doPerfil($perfilId, !$exibirTudo),
            'eh_titular'   => $ehTitular,
            'eh_admin'     => $ehAdmin,
            'completude'   => $ehTitular ? RepositorioPerfil::completude($perfilId) : null,
        ]);
    }
}
