<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BancoDados;
use App\Core\Configuracao;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioPerfil;

/**
 * Compatibilizacao entre demandas e profissionais (RF04).
 *
 * O Termo de Referencia exige criterios objetivos e transparentes, e o cenario
 * 7.3 pede que o sistema explique os principais criterios. Por isso o calculo
 * nao devolve apenas uma nota: devolve a lista de criterios avaliados, com o
 * peso de cada um, quanto foi obtido e a justificativa em linguagem comum.
 *
 * Cinco criterios, cujos pesos sao configuraveis no painel administrativo:
 *
 *   1. Competencias tecnicas  - o que a demanda exige x o que o perfil declara
 *   2. Localizacao            - mesma cidade, mesma UF ou atendimento remoto
 *   3. Acervo tecnico         - ARTs e CATs validadas na API oficial do CREA-AM
 *   4. Experiencia            - anos declarados x minimo pedido pela demanda
 *   5. Disponibilidade        - situacao declarada pelo profissional
 *
 * Requisitos eliminatorios da demanda (registro ativo no CREA-AM e acervo
 * comprovado) sao aplicados antes da pontuacao e ficam explicitos no resultado.
 */
final class ServicoCompatibilizacao
{
    /** @var array<string, int> */
    private array $pesos;

    private int $scoreMinimo;

    public function __construct()
    {
        $this->pesos = [
            'competencias'    => Configuracao::inteiro('MATCHING', 'peso_competencias', 45),
            'localizacao'     => Configuracao::inteiro('MATCHING', 'peso_localizacao', 20),
            'acervo'          => Configuracao::inteiro('MATCHING', 'peso_acervo', 20),
            'experiencia'     => Configuracao::inteiro('MATCHING', 'peso_experiencia', 10),
            'disponibilidade' => Configuracao::inteiro('MATCHING', 'peso_disponibilidade', 5),
        ];

        $this->scoreMinimo = Configuracao::inteiro('MATCHING', 'score_minimo', 20);
    }

    /** @return array<string, int> */
    public function pesos(): array
    {
        return $this->pesos;
    }

    public function scoreMinimo(): int
    {
        return $this->scoreMinimo;
    }

    /**
     * Profissionais compativeis com uma demanda, do mais ao menos aderente.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatosParaDemanda(int $demandaId, int $limite = 20): array
    {
        $demanda = RepositorioDemanda::completaPorId($demandaId);

        if ($demanda === null) {
            return [];
        }

        $candidatos = [];

        foreach ($this->perfisElegiveis($demanda) as $perfil) {
            $avaliacao = $this->avaliar($demanda, $perfil);

            if (!$avaliacao['elegivel']) {
                continue;
            }

            if ($avaliacao['score'] < $this->scoreMinimo) {
                continue;
            }

            $candidatos[] = $perfil + ['avaliacao' => $avaliacao];
        }

        usort(
            $candidatos,
            static fn (array $a, array $b): int => $b['avaliacao']['score'] <=> $a['avaliacao']['score']
        );

        return array_slice($candidatos, 0, max(1, $limite));
    }

    /**
     * Demandas compativeis com o perfil de um profissional, para o painel dele.
     *
     * @return list<array<string, mixed>>
     */
    public function demandasParaProfissional(int $usuarioId, int $limite = 10): array
    {
        $perfil = RepositorioPerfil::porUsuario($usuarioId);

        if ($perfil === null) {
            return [];
        }

        $perfilAvaliavel = $this->montarPerfilAvaliavel($perfil);
        $recomendadas    = [];

        $demandas = BancoDados::buscarTodos(
            "SELECT d.dem_id
               FROM pro_demandas d
              WHERE d.dem_status = 'A'
                AND d.dem_situacao = 'PUBLICADA'
                AND d.dem_moderacao = 'APROVADO'
                AND d.dem_usu_id <> :usuario
                AND (d.dem_dt_limite IS NULL OR d.dem_dt_limite >= CURDATE())
              ORDER BY d.dem_dt_publicacao DESC
              LIMIT 120",
            ['usuario' => $usuarioId]
        );

        foreach ($demandas as $linha) {
            $demanda = RepositorioDemanda::completaPorId((int) $linha['dem_id']);

            if ($demanda === null) {
                continue;
            }

            $avaliacao = $this->avaliar($demanda, $perfilAvaliavel);

            if (!$avaliacao['elegivel'] || $avaliacao['score'] < $this->scoreMinimo) {
                continue;
            }

            $recomendadas[] = $demanda + ['avaliacao' => $avaliacao];
        }

        usort(
            $recomendadas,
            static fn (array $a, array $b): int => $b['avaliacao']['score'] <=> $a['avaliacao']['score']
        );

        return array_slice($recomendadas, 0, max(1, $limite));
    }

    /**
     * Aderencia de um perfil especifico a uma demanda, usada na tela de
     * manifestacao de interesse e no detalhe do interessado.
     *
     * @return array<string, mixed>
     */
    public function avaliarUsuarioNaDemanda(int $usuarioId, int $demandaId): array
    {
        $demanda = RepositorioDemanda::completaPorId($demandaId);
        $perfil  = RepositorioPerfil::porUsuario($usuarioId);

        if ($demanda === null || $perfil === null) {
            return [
                'score'     => 0,
                'elegivel'  => false,
                'criterios' => [],
                'resumo'    => 'Ainda não há perfil profissional preenchido para calcular a aderência.',
                'impedimentos' => ['Perfil profissional não preenchido'],
            ];
        }

        return $this->avaliar($demanda, $this->montarPerfilAvaliavel($perfil));
    }

    /**
     * Calcula a aderencia e monta a explicacao dos criterios.
     *
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return array{
     *     score: int,
     *     elegivel: bool,
     *     criterios: list<array<string, mixed>>,
     *     resumo: string,
     *     impedimentos: list<string>
     * }
     */
    public function avaliar(array $demanda, array $perfil): array
    {
        $impedimentos = $this->verificarRequisitosEliminatorios($demanda, $perfil);

        $criterios = [
            $this->criterioCompetencias($demanda, $perfil),
            $this->criterioLocalizacao($demanda, $perfil),
            $this->criterioAcervo($demanda, $perfil),
            $this->criterioExperiencia($demanda, $perfil),
            $this->criterioDisponibilidade($perfil),
        ];

        $pesoTotal = array_sum(array_column($criterios, 'peso'));
        $obtido    = 0.0;

        foreach ($criterios as $criterio) {
            $obtido += ($criterio['percentual'] / 100) * $criterio['peso'];
        }

        $score = $pesoTotal > 0 ? (int) round(($obtido / $pesoTotal) * 100) : 0;

        // Ordena a explicacao pelo peso, para o usuario ler primeiro o que mais pesou
        usort($criterios, static fn (array $a, array $b): int => $b['peso'] <=> $a['peso']);

        return [
            'score'        => $score,
            'elegivel'     => $impedimentos === [],
            'criterios'    => $criterios,
            'resumo'       => $this->resumir($score, $criterios, $impedimentos),
            'impedimentos' => $impedimentos,
        ];
    }

    /**
     * Requisitos que a demanda declara como obrigatorios.
     *
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return list<string>
     */
    private function verificarRequisitosEliminatorios(array $demanda, array $perfil): array
    {
        $impedimentos = [];

        if (($demanda['dem_exige_registro'] ?? 'S') === 'S' && ($perfil['usu_registrado_crea'] ?? 'N') !== 'S') {
            $impedimentos[] = 'A demanda exige registro ativo no CREA-AM, ainda não validado neste perfil.';
        }

        if (($demanda['dem_exige_art'] ?? 'N') === 'S' && (int) ($perfil['total_arts'] ?? 0) === 0) {
            $impedimentos[] = 'A demanda exige acervo comprovado por ART ou CAT, e o perfil não possui nenhuma validada.';
        }

        return $impedimentos;
    }

    /**
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function criterioCompetencias(array $demanda, array $perfil): array
    {
        $requeridas = $demanda['competencias'] ?? [];
        $possuidas  = array_map('intval', $perfil['competencias_ids'] ?? []);

        if ($requeridas === []) {
            return [
                'chave'       => 'competencias',
                'rotulo'      => 'Competências técnicas',
                'peso'        => $this->pesos['competencias'],
                'percentual'  => 100,
                'atendido'    => true,
                'explicacao'  => 'A demanda não especificou competências, portanto este critério não restringe.',
                'detalhes'    => [],
            ];
        }

        $pesoTotal      = 0;
        $pesoAtendido   = 0;
        $atendidas      = [];
        $faltantes      = [];
        $obrigatoriasOk = true;

        foreach ($requeridas as $requerida) {
            $peso = max(1, (int) $requerida['dmc_peso']);
            // Competencia obrigatoria pesa o dobro no criterio
            $pesoEfetivo = $requerida['dmc_obrigatoria'] === 'S' ? $peso * 2 : $peso;
            $pesoTotal  += $pesoEfetivo;

            if (in_array((int) $requerida['dmc_cmp_id'], $possuidas, true)) {
                $pesoAtendido += $pesoEfetivo;
                $atendidas[]   = (string) $requerida['cmp_nome'];
            } else {
                $faltantes[] = (string) $requerida['cmp_nome']
                    . ($requerida['dmc_obrigatoria'] === 'S' ? ' (obrigatória)' : '');

                if ($requerida['dmc_obrigatoria'] === 'S') {
                    $obrigatoriasOk = false;
                }
            }
        }

        $percentual = $pesoTotal > 0 ? (int) round(($pesoAtendido / $pesoTotal) * 100) : 0;

        $explicacao = sprintf(
            'Atende %d de %d competências pedidas%s.',
            count($atendidas),
            count($requeridas),
            $obrigatoriasOk ? ', incluindo todas as obrigatórias' : ', mas falta ao menos uma obrigatória'
        );

        return [
            'chave'      => 'competencias',
            'rotulo'     => 'Competências técnicas',
            'peso'       => $this->pesos['competencias'],
            'percentual' => $percentual,
            'atendido'   => $obrigatoriasOk && $percentual >= 60,
            'explicacao' => $explicacao,
            'detalhes'   => [
                'atendidas' => $atendidas,
                'faltantes' => $faltantes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function criterioLocalizacao(array $demanda, array $perfil): array
    {
        $ufDemanda     = strtoupper((string) ($demanda['dem_uf'] ?? ''));
        $cidadeDemanda = \App\Core\Formatador::normalizar((string) ($demanda['dem_cidade'] ?? ''));
        $ufPerfil      = strtoupper((string) ($perfil['prf_uf'] ?? ''));
        $cidadePerfil  = \App\Core\Formatador::normalizar((string) ($perfil['prf_cidade'] ?? ''));

        $aceitaRemoto  = ($demanda['dem_aceita_remoto'] ?? 'N') === 'S';
        $atendeRemoto  = ($perfil['prf_atende_remoto'] ?? 'N') === 'S';

        if ($ufDemanda === '') {
            return [
                'chave'      => 'localizacao',
                'rotulo'     => 'Localização',
                'peso'       => $this->pesos['localizacao'],
                'percentual' => 100,
                'atendido'   => true,
                'explicacao' => 'A demanda não definiu local de execução.',
                'detalhes'   => [],
            ];
        }

        if ($cidadeDemanda !== '' && $cidadeDemanda === $cidadePerfil) {
            return [
                'chave'      => 'localizacao',
                'rotulo'     => 'Localização',
                'peso'       => $this->pesos['localizacao'],
                'percentual' => 100,
                'atendido'   => true,
                'explicacao' => sprintf('Está no mesmo município da demanda (%s).', (string) $demanda['dem_cidade']),
                'detalhes'   => [],
            ];
        }

        if ($ufDemanda === $ufPerfil) {
            return [
                'chave'      => 'localizacao',
                'rotulo'     => 'Localização',
                'peso'       => $this->pesos['localizacao'],
                'percentual' => 65,
                'atendido'   => true,
                'explicacao' => sprintf(
                    'Está na mesma UF (%s), em município diferente do local da demanda.',
                    $ufDemanda
                ),
                'detalhes'   => [],
            ];
        }

        if ($aceitaRemoto && $atendeRemoto) {
            return [
                'chave'      => 'localizacao',
                'rotulo'     => 'Localização',
                'peso'       => $this->pesos['localizacao'],
                'percentual' => 50,
                'atendido'   => true,
                'explicacao' => 'Está em outra UF, mas a demanda aceita atendimento remoto e o perfil o oferece.',
                'detalhes'   => [],
            ];
        }

        return [
            'chave'      => 'localizacao',
            'rotulo'     => 'Localização',
            'peso'       => $this->pesos['localizacao'],
            'percentual' => 10,
            'atendido'   => false,
            'explicacao' => sprintf(
                'Está em outra UF (%s) e a demanda não prevê atendimento remoto.',
                $ufPerfil !== '' ? $ufPerfil : 'não informada'
            ),
            'detalhes'   => [],
        ];
    }

    /**
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function criterioAcervo(array $demanda, array $perfil): array
    {
        $arts = (int) ($perfil['total_arts'] ?? 0);
        $cats = (int) ($perfil['total_cats'] ?? 0);
        $acervo = $arts + $cats;

        // Escada objetiva: 0 pecas = 0; 1 = 40; 2 = 60; 3-4 = 80; 5+ = 100
        $percentual = match (true) {
            $acervo === 0 => 0,
            $acervo === 1 => 40,
            $acervo === 2 => 60,
            $acervo <= 4  => 80,
            default       => 100,
        };

        $explicacao = $acervo === 0
            ? 'Nenhuma ART ou CAT validada na API oficial associada ao perfil.'
            : sprintf(
                'Possui %d ART(s) e %d CAT(s) validadas na base oficial do CREA-AM.',
                $arts,
                $cats
            );

        if (($demanda['dem_exige_art'] ?? 'N') === 'S' && $acervo > 0) {
            $explicacao .= ' A demanda exige acervo comprovado, e o requisito está atendido.';
        }

        return [
            'chave'      => 'acervo',
            'rotulo'     => 'Acervo técnico validado',
            'peso'       => $this->pesos['acervo'],
            'percentual' => $percentual,
            'atendido'   => $acervo > 0,
            'explicacao' => $explicacao,
            'detalhes'   => ['arts' => $arts, 'cats' => $cats],
        ];
    }

    /**
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function criterioExperiencia(array $demanda, array $perfil): array
    {
        $minimo    = (int) ($demanda['dem_experiencia_min'] ?? 0);
        $declarada = (int) ($perfil['prf_anos_experiencia'] ?? 0);
        $apurada   = (int) ($perfil['anos_experiencia_apurados'] ?? 0);
        $anos      = max($declarada, $apurada);

        if ($minimo === 0) {
            $percentual = min(100, $anos * 10);

            return [
                'chave'      => 'experiencia',
                'rotulo'     => 'Tempo de experiência',
                'peso'       => $this->pesos['experiencia'],
                'percentual' => $percentual,
                'atendido'   => true,
                'explicacao' => $anos > 0
                    ? sprintf('Informa %d ano(s) de experiência; a demanda não definiu mínimo.', $anos)
                    : 'Não informou tempo de experiência; a demanda não definiu mínimo.',
                'detalhes'   => ['anos' => $anos, 'minimo' => 0],
            ];
        }

        $percentual = $anos >= $minimo
            ? 100
            : (int) round(max(0, $anos / $minimo) * 70);

        return [
            'chave'      => 'experiencia',
            'rotulo'     => 'Tempo de experiência',
            'peso'       => $this->pesos['experiencia'],
            'percentual' => $percentual,
            'atendido'   => $anos >= $minimo,
            'explicacao' => sprintf(
                'Informa %d ano(s) de experiência, e a demanda pede no mínimo %d.',
                $anos,
                $minimo
            ),
            'detalhes'   => ['anos' => $anos, 'minimo' => $minimo],
        ];
    }

    /**
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function criterioDisponibilidade(array $perfil): array
    {
        $disponibilidade = (string) ($perfil['prf_disponibilidade'] ?? 'DISPONIVEL');

        [$percentual, $explicacao] = match ($disponibilidade) {
            'DISPONIVEL'   => [100, 'Declara-se disponível para novos trabalhos.'],
            'PARCIAL'      => [60, 'Declara disponibilidade parcial.'],
            'INDISPONIVEL' => [10, 'Declara-se indisponível no momento.'],
            default        => [50, 'Disponibilidade não informada.'],
        };

        return [
            'chave'      => 'disponibilidade',
            'rotulo'     => 'Disponibilidade declarada',
            'peso'       => $this->pesos['disponibilidade'],
            'percentual' => $percentual,
            'atendido'   => $disponibilidade !== 'INDISPONIVEL',
            'explicacao' => $explicacao,
            'detalhes'   => [],
        ];
    }

    /**
     * Frase que resume a avaliacao, exibida junto ao score.
     *
     * @param list<array<string, mixed>> $criterios
     * @param list<string> $impedimentos
     */
    private function resumir(int $score, array $criterios, array $impedimentos): string
    {
        if ($impedimentos !== []) {
            return 'Não atende a um requisito obrigatório da demanda: ' . $impedimentos[0];
        }

        $qualificacao = match (true) {
            $score >= 85 => 'Aderência muito alta',
            $score >= 70 => 'Aderência alta',
            $score >= 50 => 'Aderência média',
            $score >= 30 => 'Aderência baixa',
            default      => 'Aderência marginal',
        };

        // Destaca o criterio de maior peso que mais contribuiu e o que menos
        $ordenados = $criterios;
        usort(
            $ordenados,
            static fn (array $a, array $b): int
                => ($b['percentual'] * $b['peso']) <=> ($a['percentual'] * $a['peso'])
        );

        $melhor = $ordenados[0]['rotulo'] ?? '';
        $pior   = end($ordenados)['rotulo'] ?? '';

        if ($melhor === $pior) {
            return sprintf('%s (%d de 100).', $qualificacao, $score);
        }

        return sprintf(
            '%s (%d de 100). Pesou a favor: %s. Pesou contra: %s.',
            $qualificacao,
            $score,
            mb_strtolower($melhor),
            mb_strtolower($pior)
        );
    }

    /**
     * Perfis que podem ser avaliados para a demanda, com os agregados que o
     * calculo precisa, em uma unica consulta.
     *
     * @param array<string, mixed> $demanda
     * @return list<array<string, mixed>>
     */
    private function perfisElegiveis(array $demanda): array
    {
        $perfis = BancoDados::buscarTodos(
            "SELECT p.prf_id, p.prf_usu_id, p.prf_titulo, p.prf_resumo, p.prf_uf, p.prf_cidade,
                    p.prf_disponibilidade, p.prf_anos_experiencia, p.prf_atende_remoto, p.prf_foto,
                    p.prf_visibilidade, p.prf_exibe_contato,
                    u.usu_nome, u.usu_perfil, u.usu_registrado_crea, u.usu_rnp, u.usu_id,
                    a.are_nome,
                    (SELECT COUNT(*) FROM pro_arts ar
                      WHERE ar.art_prf_id = p.prf_id AND ar.art_status = 'A' AND ar.art_validada = 'S') AS total_arts,
                    (SELECT COUNT(*) FROM pro_cats ct
                      WHERE ct.cat_prf_id = p.prf_id AND ct.cat_status = 'A' AND ct.cat_validada = 'S') AS total_cats
               FROM pro_perfis p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
               LEFT JOIN pro_areas a ON a.are_id = p.prf_are_id
              WHERE p.prf_status = 'A'
                AND u.usu_status = 'A'
                AND p.prf_moderacao = 'APROVADO'
                AND p.prf_visibilidade <> 'OCULTO'
                AND u.usu_perfil IN ('PROFISSIONAL', 'EMPRESA')
                AND u.usu_id <> :autor
              LIMIT 500",
            ['autor' => (int) $demanda['dem_usu_id']]
        );

        foreach ($perfis as $indice => $perfil) {
            $perfilId = (int) $perfil['prf_id'];

            $perfis[$indice]['competencias_ids']          = RepositorioPerfil::idsCompetencias($perfilId);
            $perfis[$indice]['competencias']              = RepositorioPerfil::competencias($perfilId);
            $perfis[$indice]['anos_experiencia_apurados'] = RepositorioExperiencia::anosEstimados($perfilId);
        }

        return $perfis;
    }

    /**
     * Completa o registro do perfil com os agregados usados na avaliacao.
     *
     * @param array<string, mixed> $perfil
     * @return array<string, mixed>
     */
    private function montarPerfilAvaliavel(array $perfil): array
    {
        $perfilId = (int) $perfil['prf_id'];

        return $perfil + [
            'competencias_ids'          => RepositorioPerfil::idsCompetencias($perfilId),
            'competencias'              => RepositorioPerfil::competencias($perfilId),
            'total_arts'                => RepositorioArt::contarDoPerfil($perfilId),
            'total_cats'                => \App\Repositories\RepositorioCat::contarDoPerfil($perfilId),
            'anos_experiencia_apurados' => RepositorioExperiencia::anosEstimados($perfilId),
        ];
    }
}
