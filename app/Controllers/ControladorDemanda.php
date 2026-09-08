<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Autenticacao;
use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoCompatibilizacao;
use App\Services\ServicoNotificacao;

/**
 * Cadastro, publicação, pesquisa, compatibilização e encerramento de demandas
 * por serviços técnicos (RF04).
 */
final class ControladorDemanda extends Controlador
{
    public const MODALIDADES = ['PROJETO', 'CONSULTORIA', 'LAUDO', 'EXECUCAO', 'FISCALIZACAO', 'OUTROS'];

    public function listar(): void
    {
        $filtros = [
            'termo'           => $this->requisicao->texto('termo'),
            'area_id'         => $this->requisicao->inteiro('area_id'),
            'uf'              => strtoupper($this->requisicao->texto('uf')),
            'cidade'          => $this->requisicao->texto('cidade'),
            'modalidade'      => $this->requisicao->texto('modalidade'),
            'competencias'    => $this->requisicao->listaInteiros('competencias'),
            'exige_art'       => $this->requisicao->booleano('exige_art'),
            'somente_abertas' => $this->requisicao->texto('somente_abertas', '1') === '1',
            'incluir_remoto'  => $this->requisicao->booleano('incluir_remoto'),
            'ordem'           => $this->requisicao->texto('ordem', 'recentes'),
        ];

        $resultado = RepositorioDemanda::pesquisar(
            $filtros,
            $this->pagina(),
            $this->porPagina(),
            Autenticacao::autenticado()
        );

        $this->visao('demandas/listar.twig', [
            'itens'        => $resultado['itens'],
            'paginacao'    => $this->paginacao($resultado['total']),
            'filtros'      => $filtros,
            'areas'        => RepositorioCompetencia::areas(),
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
            'modalidades'  => self::MODALIDADES,
        ]);
    }

    public function ver(): void
    {
        $demandaId = $this->requisicao->parametroInteiro('id');
        $demanda   = RepositorioDemanda::completaPorId($demandaId);

        if ($demanda === null) {
            throw ExcecaoHttp::naoEncontrado('Demanda não encontrada.');
        }

        $ehAutor = $this->usuarioId() === (int) $demanda['dem_usu_id'];
        $ehAdmin = Autenticacao::ehAdministrador();

        if (!$ehAutor && !$ehAdmin) {
            if ($demanda['dem_situacao'] === 'RASCUNHO' || $demanda['dem_moderacao'] !== 'APROVADO') {
                throw ExcecaoHttp::naoEncontrado('Esta demanda não está disponível para visualização.');
            }

            if ($demanda['dem_visibilidade'] === 'AUTENTICADO' && !Autenticacao::autenticado()) {
                throw ExcecaoHttp::naoAutenticado(
                    'Esta demanda está visível apenas para usuários autenticados.'
                );
            }
        }

        $meuInteresse = null;
        $aderencia    = null;

        if (Autenticacao::autenticado() && !$ehAutor) {
            $meuInteresse = RepositorioInteresse::doUsuarioNaDemanda($demandaId, $this->usuarioId());

            if (Autenticacao::temPerfil([PERFIL_PROFISSIONAL, PERFIL_EMPRESA])) {
                $aderencia = (new ServicoCompatibilizacao())
                    ->avaliarUsuarioNaDemanda($this->usuarioId(), $demandaId);
            }
        }

        $this->visao('demandas/ver.twig', [
            'demanda'      => $demanda,
            'eh_autor'     => $ehAutor,
            'eh_admin'     => $ehAdmin,
            'meu_interesse' => $meuInteresse,
            'aderencia'    => $aderencia,
            'aberta'       => $this->estaAberta($demanda),
        ]);
    }

    public function minhas(): void
    {
        $situacao = $this->requisicao->texto('situacao');

        $this->visao('demandas/minhas.twig', [
            'itens'    => RepositorioDemanda::doAutor($this->usuarioId(), $situacao ?: null),
            'situacao' => $situacao,
        ]);
    }

    public function formularioNova(): void
    {
        $this->visao('demandas/formulario.twig', [
            'demanda'      => null,
            'areas'        => RepositorioCompetencia::areas(),
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'selecionadas' => [],
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
            'modalidades'  => self::MODALIDADES,
        ]);
    }

    public function formularioEdicao(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        $selecionadas = [];

        foreach (RepositorioDemanda::competencias((int) $demanda['dem_id']) as $competencia) {
            $selecionadas[(int) $competencia['dmc_cmp_id']] = [
                'obrigatoria' => (string) $competencia['dmc_obrigatoria'],
                'peso'        => (int) $competencia['dmc_peso'],
            ];
        }

        $this->visao('demandas/formulario.twig', [
            'demanda'      => $demanda,
            'areas'        => RepositorioCompetencia::areas(),
            'competencias' => RepositorioCompetencia::agrupadasPorArea(),
            'selecionadas' => $selecionadas,
            'ufs'          => Validador::UNIDADES_FEDERATIVAS,
            'modalidades'  => self::MODALIDADES,
        ]);
    }

    public function criar(): void
    {
        $validador = $this->validar();

        if (!$validador->valido()) {
            $this->voltarComErros('/demandas/nova', $validador->erros());

            return;
        }

        $dados     = $this->dadosFormulario();
        $demandaId = RepositorioDemanda::criar($this->usuarioId(), $dados, $this->competenciasFormulario());

        if ($dados['situacao'] === 'PUBLICADA') {
            $this->notificarPublicacao($demandaId, $dados['titulo']);
            Sessao::sucesso('Demanda publicada. Veja abaixo os profissionais mais compatíveis.');
            $this->redirecionar('/demandas/' . $demandaId . '/correspondencias');

            return;
        }

        Sessao::sucesso('Demanda salva como rascunho. Publique quando estiver pronta.');
        $this->redirecionar('/demandas/' . $demandaId);
    }

    public function atualizar(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        if (in_array($demanda['dem_situacao'], ['ENCERRADA', 'CANCELADA'], true)) {
            Sessao::erro('Demandas encerradas não podem ser editadas.');
            $this->redirecionar('/demandas/' . (int) $demanda['dem_id']);

            return;
        }

        $validador = $this->validar();

        if (!$validador->valido()) {
            $this->voltarComErros(
                '/demandas/' . (int) $demanda['dem_id'] . '/editar',
                $validador->erros()
            );

            return;
        }

        $eraRascunho = $demanda['dem_situacao'] === 'RASCUNHO';
        $dados       = $this->dadosFormulario();

        RepositorioDemanda::atualizar((int) $demanda['dem_id'], $dados, $this->competenciasFormulario());

        // Avisa os interessados quando uma demanda publicada muda (RF07)
        if (!$eraRascunho && $dados['situacao'] === 'PUBLICADA') {
            $this->notificarInteressados(
                (int) $demanda['dem_id'],
                'DEMANDA_ATUALIZADA',
                'Demanda atualizada: ' . $dados['titulo'],
                'A demanda em que você manifestou interesse foi atualizada pelo contratante. '
                . 'Confira as informações mais recentes.'
            );
        }

        if ($eraRascunho && $dados['situacao'] === 'PUBLICADA') {
            $this->notificarPublicacao((int) $demanda['dem_id'], $dados['titulo']);
        }

        Sessao::sucesso('Demanda atualizada.');
        $this->redirecionar('/demandas/' . (int) $demanda['dem_id']);
    }

    public function publicar(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        if ($demanda['dem_situacao'] !== 'RASCUNHO') {
            Sessao::aviso('Esta demanda já foi publicada.');
            $this->redirecionar('/demandas/' . (int) $demanda['dem_id']);

            return;
        }

        if (RepositorioDemanda::competencias((int) $demanda['dem_id']) === []) {
            Sessao::erro(
                'Selecione ao menos uma competência técnica antes de publicar: '
                . 'é o que permite encontrar profissionais compatíveis.'
            );
            $this->redirecionar('/demandas/' . (int) $demanda['dem_id'] . '/editar');

            return;
        }

        RepositorioDemanda::publicar((int) $demanda['dem_id']);
        $this->notificarPublicacao((int) $demanda['dem_id'], (string) $demanda['dem_titulo']);

        Sessao::sucesso('Demanda publicada.');
        $this->redirecionar('/demandas/' . (int) $demanda['dem_id'] . '/correspondencias');
    }

    public function encerrar(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        $motivo   = $this->requisicao->texto('motivo');
        $situacao = $this->requisicao->texto('situacao') === 'CANCELADA' ? 'CANCELADA' : 'ENCERRADA';

        if (mb_strlen($motivo) > 255) {
            $motivo = mb_substr($motivo, 0, 255);
        }

        RepositorioDemanda::encerrar(
            (int) $demanda['dem_id'],
            $motivo !== '' ? $motivo : 'Encerrada pelo autor',
            $situacao
        );

        $this->notificarInteressados(
            (int) $demanda['dem_id'],
            'DEMANDA_ENCERRADA',
            'Demanda encerrada: ' . (string) $demanda['dem_titulo'],
            sprintf(
                'A demanda em que você manifestou interesse foi %s pelo contratante.%s',
                $situacao === 'CANCELADA' ? 'cancelada' : 'encerrada',
                $motivo !== '' ? ' Motivo informado: ' . $motivo : ''
            )
        );

        Sessao::informacao('Demanda encerrada. Os interessados foram notificados.');
        $this->redirecionar('/minhas-demandas');
    }

    public function remover(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        RepositorioDemanda::excluirLogicamente(
            (int) $demanda['dem_id'],
            'Removida pelo autor'
        );

        Sessao::informacao(
            'Demanda removida. O registro fica preservado na base para fins de auditoria '
            . 'e pode ser restaurado pela administração.'
        );
        $this->redirecionar('/minhas-demandas');
    }

    /**
     * Correspondências entre a demanda e os profissionais da plataforma,
     * com a explicação dos critérios (RF04 e cenário 7.3).
     */
    public function correspondencias(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        $compatibilizacao = new ServicoCompatibilizacao();

        $this->visao('demandas/correspondencias.twig', [
            'demanda'     => RepositorioDemanda::completaPorId((int) $demanda['dem_id']),
            'candidatos'  => $compatibilizacao->candidatosParaDemanda((int) $demanda['dem_id'], 20),
            'pesos'       => $compatibilizacao->pesos(),
            'score_minimo' => $compatibilizacao->scoreMinimo(),
        ]);
    }

    public function interessados(): void
    {
        $demanda = $this->demandaDoAutor($this->requisicao->parametroInteiro('id'));

        $this->visao('demandas/interessados.twig', [
            'demanda'      => RepositorioDemanda::completaPorId((int) $demanda['dem_id']),
            'interessados' => RepositorioInteresse::daDemanda((int) $demanda['dem_id']),
        ]);
    }

    private function validar(): Validador
    {
        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('titulo', 'o título da demanda')
            ->minimo('titulo', 10, 'O título')
            ->maximo('titulo', 190, 'O título')
            ->obrigatorio('escopo', 'o escopo do serviço')
            ->minimo('escopo', 50, 'O escopo')
            ->maximo('escopo', 8000, 'O escopo')
            ->dentroDe('modalidade', self::MODALIDADES, 'a modalidade')
            ->uf('uf')
            ->maximo('cidade', 120, 'A cidade')
            ->maximo('prazo_execucao', 120, 'O prazo de execução')
            ->inteiroEntre('experiencia_min', 0, 50, 'A experiência mínima')
            ->data('dt_limite', 'O prazo para manifestação')
            ->dataFutura('dt_limite', 'O prazo para manifestação')
            ->intervaloValores('orcamento_min', 'orcamento_max', 'orçamento')
            ->dentroDe('situacao', ['RASCUNHO', 'PUBLICADA'], 'a situação')
            ->dentroDe('visibilidade', ['PUBLICO', 'AUTENTICADO'], 'a visibilidade');

        // Publicar exige local ou aceite de atendimento remoto
        if (
            $this->requisicao->texto('situacao') === 'PUBLICADA'
            && $this->requisicao->texto('uf') === ''
            && !$this->requisicao->booleano('aceita_remoto')
        ) {
            $validador->personalizado(
                'uf',
                false,
                'Informe a UF de execução ou marque que a demanda aceita atendimento remoto.'
            );
        }

        if ($this->requisicao->texto('situacao') === 'PUBLICADA' && $this->competenciasFormulario() === []) {
            $validador->personalizado(
                'competencias',
                false,
                'Selecione ao menos uma competência técnica para publicar a demanda.'
            );
        }

        return $validador;
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosFormulario(): array
    {
        return [
            'titulo'          => $this->requisicao->texto('titulo'),
            'escopo'          => $this->requisicao->texto('escopo'),
            'area_id'         => $this->requisicao->inteiro('area_id') ?: null,
            'uf'              => strtoupper($this->requisicao->texto('uf')) ?: null,
            'cidade'          => $this->requisicao->texto('cidade') ?: null,
            'aceita_remoto'   => $this->requisicao->flag('aceita_remoto'),
            'modalidade'      => $this->requisicao->texto('modalidade', 'PROJETO'),
            'exige_art'       => $this->requisicao->flag('exige_art'),
            'exige_registro'  => $this->requisicao->texto('exige_registro', '1') === '1' ? 'S' : 'N',
            'experiencia_min' => $this->requisicao->inteiro('experiencia_min') ?: null,
            'orcamento_min'   => $this->requisicao->decimal('orcamento_min'),
            'orcamento_max'   => $this->requisicao->decimal('orcamento_max'),
            'prazo_execucao'  => $this->requisicao->texto('prazo_execucao') ?: null,
            'dt_limite'       => $this->requisicao->texto('dt_limite') ?: null,
            'situacao'        => $this->requisicao->texto('situacao', 'RASCUNHO'),
            'visibilidade'    => $this->requisicao->texto('visibilidade', 'PUBLICO'),
        ];
    }

    /**
     * Competências exigidas, com peso e obrigatoriedade.
     *
     * @return array<int, array{obrigatoria: string, peso: int}>
     */
    private function competenciasFormulario(): array
    {
        $selecionadas = RepositorioCompetencia::filtrarValidos(
            $this->requisicao->listaInteiros('competencias')
        );

        $obrigatorias = $this->requisicao->listaInteiros('obrigatorias');
        $pesos        = $this->requisicao->todos()['peso'] ?? [];

        $configuracao = [];

        foreach ($selecionadas as $competenciaId) {
            $configuracao[$competenciaId] = [
                'obrigatoria' => in_array($competenciaId, $obrigatorias, true) ? 'S' : 'N',
                'peso'        => max(1, min(5, (int) ($pesos[$competenciaId] ?? 1))),
            ];
        }

        return $configuracao;
    }

    /**
     * @param array<string, mixed> $demanda
     */
    private function estaAberta(array $demanda): bool
    {
        if ($demanda['dem_situacao'] !== 'PUBLICADA') {
            return false;
        }

        if ($demanda['dem_dt_limite'] === null) {
            return true;
        }

        return strtotime((string) $demanda['dem_dt_limite']) >= strtotime(date('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    private function demandaDoAutor(int $demandaId): array
    {
        $demanda = RepositorioDemanda::completaPorId($demandaId);

        if ($demanda === null) {
            throw ExcecaoHttp::naoEncontrado('Demanda não encontrada.');
        }

        $this->exigirPropriedade((int) $demanda['dem_usu_id']);

        return $demanda;
    }

    /**
     * Avisa os profissionais cujo perfil é compatível com a demanda recém
     * publicada, respeitando o consentimento para comunicações (RF07).
     */
    private function notificarPublicacao(int $demandaId, string $titulo): void
    {
        $servico     = new ServicoNotificacao();
        $candidatos  = (new ServicoCompatibilizacao())->candidatosParaDemanda($demandaId, 10);

        foreach ($candidatos as $candidato) {
            if ((int) $candidato['avaliacao']['score'] < 60) {
                continue;
            }

            $usuarioId = (int) $candidato['usu_id'];

            if (!\App\Repositories\RepositorioLgpd::temConsentimento($usuarioId, 'COMUNICACOES')) {
                // Sem consentimento para e-mail, fica apenas a notificação interna
                $servico->notificarInterno(
                    'DEMANDA_PUBLICADA',
                    $usuarioId,
                    'Nova demanda compatível com seu perfil',
                    [
                        'titulo'   => 'Nova demanda compatível: ' . $titulo,
                        'mensagem' => sprintf(
                            'Uma demanda publicada agora tem %d%% de aderência ao seu perfil.',
                            (int) $candidato['avaliacao']['score']
                        ),
                    ],
                    '/demandas/' . $demandaId
                );

                continue;
            }

            $usuario = RepositorioUsuario::porId($usuarioId);

            $servico->notificar(
                'DEMANDA_PUBLICADA',
                $usuarioId,
                $usuario !== null ? (string) $usuario['usu_email'] : null,
                'CREA Pro-Link | Nova demanda compatível com seu perfil',
                [
                    'titulo'   => 'Nova demanda compatível: ' . $titulo,
                    'mensagem' => sprintf(
                        'Uma demanda publicada agora apresenta %d%% de aderência ao seu perfil. %s',
                        (int) $candidato['avaliacao']['score'],
                        (string) $candidato['avaliacao']['resumo']
                    ),
                    'acao_texto' => 'Ver a demanda',
                ],
                '/demandas/' . $demandaId
            );
        }
    }

    private function notificarInteressados(int $demandaId, string $evento, string $assunto, string $mensagem): void
    {
        $servico = new ServicoNotificacao();

        foreach (RepositorioInteresse::daDemanda($demandaId) as $interessado) {
            if ($interessado['int_situacao'] === 'RETIRADO') {
                continue;
            }

            $servico->notificar(
                $evento,
                (int) $interessado['int_usu_id'],
                (string) $interessado['usu_email'],
                'CREA Pro-Link | ' . $assunto,
                [
                    'titulo'     => $assunto,
                    'mensagem'   => $mensagem,
                    'acao_texto' => 'Ver a demanda',
                ],
                '/demandas/' . $demandaId
            );
        }
    }
}
