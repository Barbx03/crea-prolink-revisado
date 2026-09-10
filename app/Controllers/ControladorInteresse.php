<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoCompatibilizacao;
use App\Services\ServicoNotificacao;

/**
 * Manifestação de interesse em demandas e resposta do contratante (RF05).
 */
final class ControladorInteresse extends Controlador
{
    public function formulario(): void
    {
        $demandaId = $this->requisicao->parametroInteiro('id');
        $demanda   = $this->demandaAberta($demandaId);

        if ((int) $demanda['dem_usu_id'] === $this->usuarioId()) {
            Sessao::erro('Você não pode manifestar interesse na sua própria demanda.');
            $this->redirecionar('/demandas/' . $demandaId);

            return;
        }

        $perfil = RepositorioPerfil::porUsuario($this->usuarioId());

        if ($perfil === null || empty($perfil['prf_titulo'])) {
            Sessao::aviso(
                'Complete seu perfil profissional antes de manifestar interesse: '
                . 'o contratante avalia justamente essas informações.'
            );
            $this->redirecionar('/meu-perfil/editar');

            return;
        }

        $this->visao('demandas/manifestar.twig', [
            'demanda'       => $demanda,
            'aderencia'     => (new ServicoCompatibilizacao())
                ->avaliarUsuarioNaDemanda($this->usuarioId(), $demandaId),
            'meu_interesse' => RepositorioInteresse::doUsuarioNaDemanda($demandaId, $this->usuarioId()),
        ]);
    }

    public function manifestar(): void
    {
        $demandaId = $this->requisicao->parametroInteiro('id');
        $demanda   = $this->demandaAberta($demandaId);

        if ((int) $demanda['dem_usu_id'] === $this->usuarioId()) {
            throw ExcecaoHttp::naoAutorizado('Não é possível manifestar interesse na própria demanda.');
        }

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('mensagem', 'uma mensagem de apresentação')
            ->minimo('mensagem', 40, 'A mensagem')
            ->maximo('mensagem', 4000, 'A mensagem')
            ->maximo('prazo_proposto', 120, 'O prazo proposto');

        if (!$validador->valido()) {
            $this->voltarComErros('/demandas/' . $demandaId . '/manifestar', $validador->erros());

            return;
        }

        $aderencia = (new ServicoCompatibilizacao())
            ->avaliarUsuarioNaDemanda($this->usuarioId(), $demandaId);

        // Requisitos eliminatórios declarados pela demanda
        if (!$aderencia['elegivel']) {
            Sessao::erro(
                'Esta demanda tem requisito que seu perfil ainda não atende: '
                . implode(' ', $aderencia['impedimentos'])
            );
            $this->redirecionar('/demandas/' . $demandaId);

            return;
        }

        $jaHavia = RepositorioInteresse::jaManifestou($demandaId, $this->usuarioId());

        $interesseId = RepositorioInteresse::manifestar($demandaId, $this->usuarioId(), [
            'mensagem'       => $this->requisicao->texto('mensagem'),
            'valor_proposto' => $this->requisicao->decimal('valor_proposto'),
            'prazo_proposto' => $this->requisicao->texto('prazo_proposto') ?: null,
            'aderencia'      => $aderencia['score'],
        ]);

        // Notifica o autor da demanda (RF05 e RF07)
        $autor   = RepositorioUsuario::porId((int) $demanda['dem_usu_id']);
        $usuario = $this->usuarioAutenticado();

        if ($autor !== null) {
            (new ServicoNotificacao())->notificar(
                'INTERESSE_RECEBIDO',
                (int) $autor['usu_id'],
                (string) $autor['usu_email'],
                'CREA Pro-Link | Nova manifestação de interesse',
                [
                    'titulo'   => 'Nova manifestação de interesse',
                    'mensagem' => sprintf(
                        '%s manifestou interesse na demanda "%s", com %d%% de aderência aos critérios que você definiu.',
                        (string) $usuario['usu_nome'],
                        (string) $demanda['dem_titulo'],
                        $aderencia['score']
                    ),
                    'detalhes' => array_filter([
                        'Profissional' => (string) $usuario['usu_nome'],
                        'Aderência'    => $aderencia['score'] . '%',
                        'Valor proposto' => $this->requisicao->decimal('valor_proposto') !== null
                            ? 'R$ ' . number_format((float) $this->requisicao->decimal('valor_proposto'), 2, ',', '.')
                            : null,
                        'Prazo proposto' => $this->requisicao->texto('prazo_proposto') ?: null,
                    ]),
                    'acao_texto' => 'Ver interessados',
                ],
                '/demandas/' . $demandaId . '/interessados'
            );
        }

        Sessao::sucesso(
            $jaHavia
                ? 'Sua manifestação de interesse foi atualizada.'
                : 'Interesse manifestado. O contratante foi notificado e poderá ver seu perfil.'
        );

        $this->redirecionar('/meus-interesses');
    }

    public function meus(): void
    {
        $this->visao('demandas/meus-interesses.twig', [
            'itens' => RepositorioInteresse::doUsuario($this->usuarioId()),
        ]);
    }

    public function retirar(): void
    {
        $interesse = RepositorioInteresse::completoPorId($this->requisicao->parametroInteiro('id'));

        if ($interesse === null) {
            throw ExcecaoHttp::naoEncontrado('Manifestação não encontrada.');
        }

        $this->exigirPropriedade((int) $interesse['int_usu_id']);

        RepositorioInteresse::retirar(
            (int) $interesse['int_id'],
            $this->requisicao->texto('motivo')
        );

        Sessao::informacao('Manifestação de interesse retirada.');
        $this->redirecionar('/meus-interesses');
    }

    /**
     * O autor da demanda responde à manifestação (RF05).
     */
    public function responder(): void
    {
        $interesse = RepositorioInteresse::completoPorId($this->requisicao->parametroInteiro('id'));

        if ($interesse === null) {
            throw ExcecaoHttp::naoEncontrado('Manifestação não encontrada.');
        }

        // Somente o autor da demanda pode responder
        $this->exigirPropriedade((int) $interesse['autor_id']);

        $situacoes = ['EM_NEGOCIACAO', 'SELECIONADO', 'RECUSADO'];
        $situacao  = $this->requisicao->texto('situacao');

        if (!in_array($situacao, $situacoes, true)) {
            Sessao::erro('Selecione uma resposta válida para a manifestação.');
            $this->redirecionar('/demandas/' . (int) $interesse['dem_id'] . '/interessados');

            return;
        }

        $resposta = $this->requisicao->texto('resposta');

        if (mb_strlen($resposta) > 4000) {
            $resposta = mb_substr($resposta, 0, 4000);
        }

        if ($situacao === 'RECUSADO' && $resposta === '') {
            Sessao::erro('Ao recusar uma manifestação, informe brevemente o motivo ao profissional.');
            $this->redirecionar('/demandas/' . (int) $interesse['dem_id'] . '/interessados');

            return;
        }

        RepositorioInteresse::responder((int) $interesse['int_id'], $situacao, $resposta);

        $textoSituacao = match ($situacao) {
            'SELECIONADO'   => 'Sua manifestação foi selecionada pelo contratante.',
            'EM_NEGOCIACAO' => 'O contratante iniciou negociação com você.',
            default         => 'Sua manifestação não foi selecionada nesta oportunidade.',
        };

        (new ServicoNotificacao())->notificar(
            'INTERESSE_RESPONDIDO',
            (int) $interesse['int_usu_id'],
            (string) $interesse['interessado_email'],
            'CREA Pro-Link | Resposta à sua manifestação de interesse',
            [
                'titulo'   => 'Resposta sobre a demanda "' . (string) $interesse['dem_titulo'] . '"',
                'mensagem' => $textoSituacao . ($resposta !== '' ? ' Mensagem do contratante: ' . $resposta : ''),
                'acao_texto' => 'Ver minhas manifestações',
            ],
            '/meus-interesses'
        );

        Sessao::sucesso('Resposta registrada e profissional notificado.');
        $this->redirecionar('/demandas/' . (int) $interesse['dem_id'] . '/interessados');
    }

    /**
     * Marca que o autor visualizou o perfil do interessado (cenário 7.4).
     */
    public function visualizar(): void
    {
        $interesse = RepositorioInteresse::completoPorId($this->requisicao->parametroInteiro('id'));

        if ($interesse === null) {
            throw ExcecaoHttp::naoEncontrado('Manifestação não encontrada.');
        }

        $this->exigirPropriedade((int) $interesse['autor_id']);

        RepositorioInteresse::marcarVisualizado((int) $interesse['int_id']);

        $perfil = RepositorioPerfil::porUsuario((int) $interesse['int_usu_id']);

        if ($perfil === null) {
            Sessao::aviso('Este profissional ainda não publicou o perfil completo.');
            $this->redirecionar('/demandas/' . (int) $interesse['dem_id'] . '/interessados');

            return;
        }

        $this->redirecionar('/profissionais/' . (int) $perfil['prf_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function demandaAberta(int $demandaId): array
    {
        $demanda = RepositorioDemanda::completaPorId($demandaId);

        if ($demanda === null) {
            throw ExcecaoHttp::naoEncontrado('Demanda não encontrada.');
        }

        if ($demanda['dem_situacao'] !== 'PUBLICADA' || $demanda['dem_moderacao'] !== 'APROVADO') {
            throw ExcecaoHttp::requisicaoInvalida(
                'Esta demanda não está aberta para manifestações de interesse.'
            );
        }

        if (
            $demanda['dem_dt_limite'] !== null
            && strtotime((string) $demanda['dem_dt_limite']) < strtotime(date('Y-m-d'))
        ) {
            throw ExcecaoHttp::requisicaoInvalida(
                'O prazo para manifestação de interesse nesta demanda já encerrou.'
            );
        }

        return $demanda;
    }
}
