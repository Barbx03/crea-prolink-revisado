<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioPerfil;

/**
 * Experiências profissionais, com ou sem comprovação por ART/CAT (RF03).
 *
 * O vínculo com ART ou CAT só é aceito quando a peça pertence ao próprio
 * titular e foi validada na API oficial, o que sustenta o selo de experiência
 * comprovada exibido no perfil.
 */
final class ControladorExperiencia extends Controlador
{
    public function formularioNova(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $this->visao('perfil/experiencia-formulario.twig', [
            'experiencia'  => null,
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'selecionadas' => [],
            'arts'         => RepositorioArt::doPerfil($perfilId),
            'cats'         => RepositorioCat::doPerfil($perfilId),
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
        ]);
    }

    public function formularioEdicao(): void
    {
        $experiencia = $this->experienciaDoTitular($this->requisicao->parametroInteiro('id'));
        $perfilId    = (int) $experiencia['prf_id'];

        $this->visao('perfil/experiencia-formulario.twig', [
            'experiencia'  => $experiencia,
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'selecionadas' => RepositorioExperiencia::idsCompetencias((int) $experiencia['exp_id']),
            'arts'         => RepositorioArt::doPerfil($perfilId),
            'cats'         => RepositorioCat::doPerfil($perfilId),
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
        ]);
    }

    public function criar(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $validador = $this->validar();

        if (!$validador->valido()) {
            $this->voltarComErros('/meu-perfil/experiencias/nova', $validador->erros());

            return;
        }

        $dados = $this->dadosFormulario($perfilId);

        $experienciaId = RepositorioExperiencia::criar(
            $perfilId,
            $dados,
            RepositorioCompetencia::filtrarValidos($this->requisicao->listaInteiros('competencias'))
        );

        Sessao::sucesso(
            $dados['comprovada_por_api']
                ? 'Experiência publicada e marcada como comprovada pelo acervo validado.'
                : 'Experiência publicada.'
        );

        $this->redirecionar('/meu-perfil');
    }

    public function atualizar(): void
    {
        $experiencia = $this->experienciaDoTitular($this->requisicao->parametroInteiro('id'));

        $validador = $this->validar();

        if (!$validador->valido()) {
            $this->voltarComErros(
                '/meu-perfil/experiencias/' . (int) $experiencia['exp_id'],
                $validador->erros()
            );

            return;
        }

        RepositorioExperiencia::atualizar(
            (int) $experiencia['exp_id'],
            $this->dadosFormulario((int) $experiencia['prf_id']),
            RepositorioCompetencia::filtrarValidos($this->requisicao->listaInteiros('competencias'))
        );

        Sessao::sucesso('Experiência atualizada.');
        $this->redirecionar('/meu-perfil');
    }

    public function remover(): void
    {
        $experiencia = $this->experienciaDoTitular($this->requisicao->parametroInteiro('id'));

        RepositorioExperiencia::excluirLogicamente(
            (int) $experiencia['exp_id'],
            'Removida pelo próprio titular'
        );

        Sessao::informacao('Experiência removida do seu perfil.');
        $this->redirecionar('/meu-perfil');
    }

    private function validar(): Validador
    {
        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('titulo', 'o título da experiência')
            ->minimo('titulo', 5, 'O título')
            ->maximo('titulo', 190, 'O título')
            ->maximo('descricao', 4000, 'A descrição')
            ->maximo('organizacao', 190, 'A organização')
            ->maximo('papel', 120, 'O papel exercido')
            ->maximo('municipio', 120, 'O município')
            ->uf('uf')
            ->data('dt_inicio', 'A data de início')
            ->data('dt_fim', 'A data de término')
            ->intervaloDatas('dt_inicio', 'dt_fim');

        if (!$this->requisicao->booleano('atual') && $this->requisicao->texto('dt_fim') === '') {
            $validador->personalizado(
                'dt_fim',
                false,
                'Informe a data de término ou marque a experiência como em andamento.'
            );
        }

        return $validador;
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosFormulario(int $perfilId): array
    {
        // O vínculo só vale para peças do próprio perfil, validadas na API
        $artId = $this->requisicao->inteiro('art_id');
        $catId = $this->requisicao->inteiro('cat_id');

        $artValida = null;
        $catValida = null;

        if ($artId !== null && $artId > 0) {
            foreach (RepositorioArt::doPerfil($perfilId) as $art) {
                if ((int) $art['art_id'] === $artId && $art['art_validada'] === 'S') {
                    $artValida = $artId;
                    break;
                }
            }
        }

        if ($catId !== null && $catId > 0) {
            foreach (RepositorioCat::doPerfil($perfilId) as $cat) {
                if ((int) $cat['cat_id'] === $catId && $cat['cat_validada'] === 'S') {
                    $catValida = $catId;
                    break;
                }
            }
        }

        return [
            'titulo'      => $this->requisicao->texto('titulo'),
            'descricao'   => $this->requisicao->texto('descricao') ?: null,
            'organizacao' => $this->requisicao->texto('organizacao') ?: null,
            'papel'       => $this->requisicao->texto('papel') ?: null,
            'municipio'   => $this->requisicao->texto('municipio') ?: null,
            'uf'          => strtoupper($this->requisicao->texto('uf')) ?: null,
            'dt_inicio'   => $this->requisicao->texto('dt_inicio') ?: null,
            'dt_fim'      => $this->requisicao->texto('dt_fim') ?: null,
            'atual'       => $this->requisicao->flag('atual'),
            'art_id'      => $artValida,
            'cat_id'      => $catValida,
            'visivel'     => $this->requisicao->flag('visivel'),
            'comprovada_por_api' => $artValida !== null || $catValida !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function experienciaDoTitular(int $experienciaId): array
    {
        $experiencia = RepositorioExperiencia::comDono($experienciaId);

        if ($experiencia === null) {
            throw ExcecaoHttp::naoEncontrado('Experiência não encontrada.');
        }

        $this->exigirPropriedade((int) $experiencia['prf_usu_id']);

        return $experiencia;
    }
}
