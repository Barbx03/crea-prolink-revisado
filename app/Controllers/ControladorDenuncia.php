<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioDenuncia;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Registro de denúncias de conteúdo pelos usuários (RF06, cenário 7.5).
 */
final class ControladorDenuncia extends Controlador
{
    public function formulario(): void
    {
        $entidade   = strtoupper((string) $this->requisicao->parametro('entidade'));
        $entidadeId = $this->requisicao->parametroInteiro('id');

        if (!in_array($entidade, RepositorioDenuncia::ENTIDADES, true)) {
            throw ExcecaoHttp::requisicaoInvalida('Tipo de conteúdo inválido para denúncia.');
        }

        $alvo = RepositorioDenuncia::alvo($entidade, $entidadeId);

        if ($alvo['usuario_id'] === null) {
            throw ExcecaoHttp::naoEncontrado('O conteúdo indicado não foi encontrado.');
        }

        if ($alvo['usuario_id'] === $this->usuarioId()) {
            Sessao::aviso('Este conteúdo é seu. Para corrigi-lo, use a edição do próprio registro.');
            $this->redirecionar($alvo['link'] ?? '/painel');

            return;
        }

        $this->visao('painel/denunciar.twig', [
            'entidade'    => $entidade,
            'entidade_id' => $entidadeId,
            'alvo'        => $alvo,
            'motivos'     => RepositorioDenuncia::MOTIVOS,
        ]);
    }

    public function registrar(): void
    {
        $entidade   = strtoupper($this->requisicao->texto('entidade'));
        $entidadeId = $this->requisicao->inteiro('entidade_id', 0) ?? 0;
        $motivo     = $this->requisicao->texto('motivo');

        $validador = Validador::para($this->requisicao->todos())
            ->dentroDe('entidade', RepositorioDenuncia::ENTIDADES, 'o tipo de conteúdo')
            ->obrigatorio('motivo', 'o motivo da denúncia')
            ->dentroDe('motivo', RepositorioDenuncia::MOTIVOS, 'o motivo')
            ->obrigatorio('descricao', 'a descrição do problema')
            ->minimo('descricao', 20, 'A descrição')
            ->maximo('descricao', 4000, 'A descrição');

        if ($entidadeId <= 0) {
            $validador->personalizado('entidade_id', false, 'Conteúdo denunciado não identificado.');
        }

        if (!$validador->valido()) {
            $this->voltarComErros(
                '/denunciar/' . strtolower($entidade) . '/' . $entidadeId,
                $validador->erros()
            );

            return;
        }

        $alvo = RepositorioDenuncia::alvo($entidade, $entidadeId);

        if ($alvo['usuario_id'] === null) {
            throw ExcecaoHttp::naoEncontrado('O conteúdo indicado não foi encontrado.');
        }

        if ($alvo['usuario_id'] === $this->usuarioId()) {
            throw ExcecaoHttp::requisicaoInvalida('Não é possível denunciar o próprio conteúdo.');
        }

        $denunciaId = RepositorioDenuncia::registrar(
            $this->usuarioId(),
            $entidade,
            $entidadeId,
            $motivo,
            $this->requisicao->texto('descricao')
        );

        // Avisa a administração para que a denúncia entre na fila de moderação
        $servico = new ServicoNotificacao();

        foreach ($this->administradores() as $administrador) {
            $servico->notificar(
                'DENUNCIA_RECEBIDA',
                (int) $administrador['usu_id'],
                (string) $administrador['usu_email'],
                'CREA Pro-Link | Nova denúncia registrada',
                [
                    'titulo'   => 'Nova denúncia aguardando análise',
                    'mensagem' => sprintf(
                        'Foi registrada uma denúncia de %s referente ao conteúdo "%s".',
                        \App\Core\Formatador::rotulo($motivo),
                        $alvo['titulo']
                    ),
                    'acao_texto' => 'Analisar denúncia',
                ],
                '/admin/moderacao/denuncias/' . $denunciaId
            );
        }

        Sessao::sucesso(
            'Denúncia registrada e encaminhada à moderação. Você pode acompanhar o desfecho '
            . 'pela central de notificações.'
        );

        $this->redirecionar($alvo['link'] ?? '/painel');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function administradores(): array
    {
        return RepositorioUsuario::administradoresAtivos();
    }
}
