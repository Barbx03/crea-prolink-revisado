<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controlador;
use App\Repositories\RepositorioCompetencia;
use App\Services\ServicoCompatibilizacao;

/**
 * Endpoints JSON de apoio à interface.
 *
 * Servem apenas para preencher campos dependentes e mostrar a aderência em
 * tempo real. Não expõem dado pessoal: quem precisa de perfil usa as telas
 * normais, sujeitas às regras de visibilidade.
 */
final class ControladorApoio extends Controlador
{
    public function competencias(): void
    {
        $areaId = $this->requisicao->inteiro('area_id');

        $competencias = $areaId !== null && $areaId > 0
            ? RepositorioCompetencia::porArea($areaId)
            : RepositorioCompetencia::todas();

        $this->json([
            'sucesso' => true,
            'itens'   => $competencias,
        ]);
    }

    /**
     * Municípios do Amazonas, para agilizar o preenchimento no cenário local.
     * Outras UFs continuam com entrada livre de texto.
     */
    public function municipios(): void
    {
        $uf = strtoupper((string) $this->requisicao->parametro('uf'));

        $this->json([
            'sucesso'    => true,
            'uf'         => $uf,
            'municipios' => $uf === 'AM' ? self::MUNICIPIOS_AM : [],
        ]);
    }

    public function aderencia(): void
    {
        $demandaId = $this->requisicao->parametroInteiro('demanda');

        $avaliacao = (new ServicoCompatibilizacao())
            ->avaliarUsuarioNaDemanda($this->usuarioId(), $demandaId);

        $this->json([
            'sucesso'   => true,
            'score'     => $avaliacao['score'],
            'elegivel'  => $avaliacao['elegivel'],
            'resumo'    => $avaliacao['resumo'],
            'criterios' => array_map(
                static fn (array $criterio): array => [
                    'rotulo'     => $criterio['rotulo'],
                    'peso'       => $criterio['peso'],
                    'percentual' => $criterio['percentual'],
                    'atendido'   => $criterio['atendido'],
                    'explicacao' => $criterio['explicacao'],
                ],
                $avaliacao['criterios']
            ),
            'impedimentos' => $avaliacao['impedimentos'],
        ]);
    }

    /** Municípios do Amazonas (IBGE). */
    private const MUNICIPIOS_AM = [
        'Alvarães', 'Amaturá', 'Anamã', 'Anori', 'Apuí', 'Atalaia do Norte',
        'Autazes', 'Barcelos', 'Barreirinha', 'Benjamin Constant', 'Beruri',
        'Boa Vista do Ramos', 'Boca do Acre', 'Borba', 'Caapiranga',
        'Canutama', 'Carauari', 'Careiro', 'Careiro da Várzea', 'Coari',
        'Codajás', 'Eirunepé', 'Envira', 'Fonte Boa', 'Guajará', 'Humaitá',
        'Ipixuna', 'Iranduba', 'Itacoatiara', 'Itamarati', 'Itapiranga',
        'Japurá', 'Juruá', 'Jutaí', 'Lábrea', 'Manacapuru', 'Manaquiri',
        'Manaus', 'Manicoré', 'Maraã', 'Maués', 'Nhamundá',
        'Nova Olinda do Norte', 'Novo Airão', 'Novo Aripuanã', 'Parintins',
        'Pauini', 'Presidente Figueiredo', 'Rio Preto da Eva', 'Santa Isabel do Rio Negro',
        'Santo Antônio do Içá', 'São Gabriel da Cachoeira', 'São Paulo de Olivença',
        'São Sebastião do Uatumã', 'Silves', 'Tabatinga', 'Tapauá', 'Tefé',
        'Tonantins', 'Uarini', 'Urucará', 'Urucurituba',
    ];
}
