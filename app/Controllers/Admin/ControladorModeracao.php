<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioAuditoria;
use App\Repositories\RepositorioConversa;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioDenuncia;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioMensagem;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Moderação de conteúdo e tratamento de denúncias (RF06, cenário 7.6).
 *
 * Toda providência tomada aqui é registrada na trilha de auditoria com
 * severidade elevada, e o usuário afetado é notificado do que ocorreu.
 */
final class ControladorModeracao extends Controlador
{
    public function painel(): void
    {
        $this->visao('admin/moderacao.twig', [
            'denuncias'        => RepositorioDenuncia::indicadores(),
            'abertas'          => RepositorioDenuncia::listar(['situacao' => 'ABERTA'], 1, 10)['itens'],
            'demandas_analise' => RepositorioDemanda::listarParaAdministracao(
                ['moderacao' => 'EM_ANALISE'],
                1,
                10
            )['itens'],
            'perfis_analise'   => $this->perfisEmAnalise(),
            'ultimas_acoes'    => RepositorioAuditoria::consultar(['acao' => 'MODERACAO'], 1, 15)['itens'],
        ]);
    }

    public function denuncias(): void
    {
        $filtros = [
            'situacao' => $this->requisicao->texto('situacao'),
            'entidade' => $this->requisicao->texto('entidade'),
            'motivo'   => $this->requisicao->texto('motivo'),
        ];

        $resultado = RepositorioDenuncia::listar($filtros, $this->pagina(), $this->porPagina());

        $this->visao('admin/denuncias.twig', [
            'itens'     => $resultado['itens'],
            'paginacao' => $this->paginacao($resultado['total']),
            'filtros'   => $filtros,
            'entidades' => RepositorioDenuncia::ENTIDADES,
            'motivos'   => RepositorioDenuncia::MOTIVOS,
            'indicadores' => RepositorioDenuncia::indicadores(),
        ]);
    }

    public function verDenuncia(): void
    {
        $denunciaId = $this->requisicao->parametroInteiro('id');
        $denuncia   = RepositorioDenuncia::porId($denunciaId);

        if ($denuncia === null) {
            throw ExcecaoHttp::naoEncontrado('Denúncia não encontrada.');
        }

        // Assume a denúncia ao abrir, registrando o analista responsável
        RepositorioDenuncia::assumir($denunciaId, $this->usuarioId());

        $alvo = RepositorioDenuncia::alvo(
            (string) $denuncia['den_entidade'],
            (int) $denuncia['den_entidade_id']
        );

        $this->visao('admin/denuncia.twig', [
            'denuncia'     => RepositorioDenuncia::porId($denunciaId),
            'alvo'         => $alvo,
            'denunciado'   => $alvo['usuario_id'] !== null
                ? RepositorioUsuario::porId($alvo['usuario_id'], true)
                : null,
            'denunciante'  => $denuncia['den_usu_id'] !== null
                ? RepositorioUsuario::porId((int) $denuncia['den_usu_id'], true)
                : null,
            'providencias' => RepositorioDenuncia::PROVIDENCIAS,
            'historico'    => RepositorioAuditoria::doRegistro(
                'pro_denuncias',
                $denunciaId
            ),
            'outras_denuncias' => $alvo['usuario_id'] !== null
                ? $this->outrasDenunciasDoUsuario($alvo['usuario_id'], $denunciaId)
                : [],
        ]);
    }

    /**
     * Julga a denúncia e aplica a providência escolhida.
     */
    public function julgarDenuncia(): void
    {
        $denunciaId = $this->requisicao->parametroInteiro('id');
        $denuncia   = RepositorioDenuncia::porId($denunciaId);

        if ($denuncia === null) {
            throw ExcecaoHttp::naoEncontrado('Denúncia não encontrada.');
        }

        $situacao    = $this->requisicao->texto('situacao');
        $providencia = $this->requisicao->texto('providencia', 'NENHUMA');

        $validador = Validador::para($this->requisicao->todos())
            ->dentroDe('situacao', ['PROCEDENTE', 'IMPROCEDENTE'], 'o julgamento')
            ->obrigatorio('situacao', 'o julgamento')
            ->dentroDe('providencia', RepositorioDenuncia::PROVIDENCIAS, 'a providência')
            ->obrigatorio('parecer', 'o parecer da moderação')
            ->minimo('parecer', 20, 'O parecer')
            ->maximo('parecer', 4000, 'O parecer');

        if ($situacao === 'IMPROCEDENTE' && $providencia !== 'NENHUMA') {
            $validador->personalizado(
                'providencia',
                false,
                'Denúncia improcedente não admite providência sobre o conteúdo.'
            );
        }

        if (!$validador->valido()) {
            $this->voltarComErros('/admin/moderacao/denuncias/' . $denunciaId, $validador->erros());

            return;
        }

        $parecer = $this->requisicao->texto('parecer');

        RepositorioDenuncia::analisar($denunciaId, $this->usuarioId(), $situacao, $parecer, $providencia);

        $alvo = RepositorioDenuncia::alvo(
            (string) $denuncia['den_entidade'],
            (int) $denuncia['den_entidade_id']
        );

        if ($situacao === 'PROCEDENTE') {
            $this->aplicarProvidencia(
                (string) $denuncia['den_entidade'],
                (int) $denuncia['den_entidade_id'],
                $providencia,
                $parecer,
                $alvo['usuario_id']
            );
        }

        // Informa o denunciante sobre o desfecho
        if ($denuncia['den_usu_id'] !== null) {
            $denunciante = RepositorioUsuario::porId((int) $denuncia['den_usu_id']);

            if ($denunciante !== null) {
                (new ServicoNotificacao())->notificar(
                    'MODERACAO',
                    (int) $denunciante['usu_id'],
                    (string) $denunciante['usu_email'],
                    'CREA Pro-Link | Resultado da sua denúncia',
                    [
                        'titulo'   => 'Sua denúncia foi analisada',
                        'mensagem' => sprintf(
                            'A denúncia que você registrou foi julgada %s pela moderação da plataforma.',
                            mb_strtolower($situacao)
                        ),
                        'detalhes' => array_filter([
                            'Conteúdo'    => $alvo['titulo'],
                            'Providência' => $providencia !== 'NENHUMA'
                                ? \App\Core\Formatador::rotulo($providencia)
                                : null,
                        ]),
                    ]
                );
            }
        }

        Sessao::sucesso('Denúncia julgada e providência aplicada.');
        $this->redirecionar('/admin/moderacao/denuncias');
    }

    public function demandas(): void
    {
        $filtros = [
            'termo'     => $this->requisicao->texto('termo'),
            'situacao'  => $this->requisicao->texto('situacao'),
            'moderacao' => $this->requisicao->texto('moderacao'),
            'incluir_excluidas' => $this->requisicao->booleano('incluir_excluidas'),
        ];

        $resultado = RepositorioDemanda::listarParaAdministracao(
            $filtros,
            $this->pagina(),
            $this->porPagina()
        );

        $this->visao('admin/demandas.twig', [
            'itens'     => $resultado['itens'],
            'paginacao' => $this->paginacao($resultado['total']),
            'filtros'   => $filtros,
            'indicadores' => RepositorioDemanda::indicadores(),
        ]);
    }

    public function moderarDemanda(): void
    {
        $demandaId = $this->requisicao->parametroInteiro('id');
        $demanda   = RepositorioDemanda::completaPorId($demandaId);

        if ($demanda === null) {
            throw ExcecaoHttp::naoEncontrado('Demanda não encontrada.');
        }

        $situacao = $this->requisicao->texto('moderacao');
        $motivo   = $this->requisicao->texto('motivo');

        if (!in_array($situacao, ['APROVADO', 'EM_ANALISE', 'REPROVADO'], true)) {
            Sessao::erro('Situação de moderação inválida.');
            $this->redirecionar('/admin/moderacao/demandas');

            return;
        }

        if ($situacao === 'REPROVADO' && mb_strlen($motivo) < 10) {
            Sessao::erro('Informe o motivo da reprovação, para que o autor possa corrigir.');
            $this->redirecionar('/admin/moderacao/demandas');

            return;
        }

        RepositorioDemanda::definirModeracao($demandaId, $situacao, $motivo);

        $autor = RepositorioUsuario::porId((int) $demanda['dem_usu_id']);

        if ($autor !== null && $situacao !== 'APROVADO') {
            (new ServicoNotificacao())->notificar(
                'MODERACAO',
                (int) $autor['usu_id'],
                (string) $autor['usu_email'],
                'CREA Pro-Link | Sua demanda está em moderação',
                [
                    'titulo'   => 'Situação da demanda "' . (string) $demanda['dem_titulo'] . '"',
                    'mensagem' => $situacao === 'REPROVADO'
                        ? 'Sua demanda foi reprovada pela moderação e não está mais visível na plataforma.'
                        : 'Sua demanda está em análise pela moderação e ficará temporariamente fora das buscas.',
                    'detalhes' => array_filter(['Motivo' => $motivo ?: null]),
                    'acao_texto' => 'Revisar demanda',
                ],
                '/demandas/' . $demandaId . '/editar'
            );
        }

        Sessao::sucesso('Situação de moderação da demanda atualizada.');
        $this->redirecionar('/admin/moderacao/demandas');
    }

    public function perfis(): void
    {
        $this->visao('admin/perfis.twig', [
            'itens' => $this->perfisEmAnalise(200),
        ]);
    }

    public function moderarPerfil(): void
    {
        $perfilId = $this->requisicao->parametroInteiro('id');
        $perfil   = RepositorioPerfil::completoPorId($perfilId);

        if ($perfil === null) {
            throw ExcecaoHttp::naoEncontrado('Perfil não encontrado.');
        }

        $situacao = $this->requisicao->texto('moderacao');
        $motivo   = $this->requisicao->texto('motivo');

        if (!in_array($situacao, ['APROVADO', 'EM_ANALISE', 'REPROVADO'], true)) {
            Sessao::erro('Situação de moderação inválida.');
            $this->redirecionar('/admin/moderacao/perfis');

            return;
        }

        if ($situacao === 'REPROVADO' && mb_strlen($motivo) < 10) {
            Sessao::erro('Informe o motivo da reprovação, para que o titular possa corrigir.');
            $this->redirecionar('/admin/moderacao/perfis');

            return;
        }

        RepositorioPerfil::definirModeracao($perfilId, $situacao, $motivo);

        if ($situacao !== 'APROVADO') {
            (new ServicoNotificacao())->notificar(
                'MODERACAO',
                (int) $perfil['prf_usu_id'],
                (string) $perfil['usu_email'],
                'CREA Pro-Link | Seu perfil está em moderação',
                [
                    'titulo'   => 'Situação do seu perfil profissional',
                    'mensagem' => $situacao === 'REPROVADO'
                        ? 'Seu perfil foi reprovado pela moderação e não aparece nas buscas.'
                        : 'Seu perfil está em análise pela moderação e ficará temporariamente fora das buscas.',
                    'detalhes' => array_filter(['Motivo' => $motivo ?: null]),
                    'acao_texto' => 'Revisar meu perfil',
                ],
                '/meu-perfil/editar'
            );
        }

        Sessao::sucesso('Situação de moderação do perfil atualizada.');
        $this->redirecionar('/admin/moderacao/perfis');
    }

    /**
     * Aplica a providência determinada no julgamento da denúncia.
     */
    private function aplicarProvidencia(
        string $entidade,
        int $entidadeId,
        string $providencia,
        string $parecer,
        ?int $usuarioAfetado
    ): void {
        if ($providencia === 'NENHUMA') {
            return;
        }

        if ($providencia === 'CONTEUDO_OCULTADO' || $providencia === 'CONTEUDO_REMOVIDO') {
            $remover = $providencia === 'CONTEUDO_REMOVIDO';

            match ($entidade) {
                'PERFIL' => $remover
                    ? RepositorioPerfil::excluirLogicamente($entidadeId, 'Removido por decisão de moderação')
                    : RepositorioPerfil::definirModeracao($entidadeId, 'REPROVADO', $parecer),
                'DEMANDA' => $remover
                    ? RepositorioDemanda::excluirLogicamente($entidadeId, 'Removida por decisão de moderação')
                    : RepositorioDemanda::definirModeracao($entidadeId, 'REPROVADO', $parecer),
                'EXPERIENCIA' => $remover
                    ? RepositorioExperiencia::excluirLogicamente($entidadeId, 'Removida por decisão de moderação')
                    : RepositorioExperiencia::definirModeracao($entidadeId, 'REPROVADO'),
                'MENSAGEM' => $this->moderarMensagem($entidadeId, $remover, $parecer),
                default => null,
            };
        }

        if ($usuarioAfetado === null) {
            return;
        }

        $usuario = RepositorioUsuario::porId($usuarioAfetado, true);

        if ($usuario === null) {
            return;
        }

        if ($providencia === 'USUARIO_BLOQUEADO') {
            RepositorioUsuario::bloquear($usuarioAfetado, 'Decisão de moderação: ' . $parecer);
        }

        $servico = new ServicoNotificacao();

        $mensagens = [
            'CONTEUDO_OCULTADO'  => 'Um conteúdo publicado por você foi ocultado por decisão da moderação.',
            'CONTEUDO_REMOVIDO'  => 'Um conteúdo publicado por você foi removido por decisão da moderação.',
            'USUARIO_ADVERTIDO'  => 'Você recebeu uma advertência da moderação da plataforma.',
            'USUARIO_BLOQUEADO'  => 'Sua conta foi bloqueada por decisão da moderação da plataforma.',
        ];

        $servico->notificar(
            'MODERACAO',
            $usuarioAfetado,
            (string) $usuario['usu_email'],
            'CREA Pro-Link | Decisão de moderação',
            [
                'titulo'   => 'Decisão de moderação sobre sua conta',
                'mensagem' => $mensagens[$providencia] ?? 'A moderação tomou uma providência relativa à sua conta.',
                'detalhes' => ['Parecer da moderação' => $parecer],
            ]
        );
    }

    private function moderarMensagem(int $mensagemId, bool $remover, string $parecer): void
    {
        RepositorioMensagem::definirModeracao($mensagemId, 'REPROVADO');

        if ($remover) {
            RepositorioMensagem::excluirLogicamente($mensagemId, 'Removida por decisão de moderação');
        }

        // Bloqueia o canal quando a mensagem é considerada abusiva
        $mensagem = RepositorioMensagem::comContexto($mensagemId);

        if ($mensagem !== null && $remover) {
            RepositorioConversa::bloquear((int) $mensagem['cnv_id'], true);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function perfisEmAnalise(int $limite = 20): array
    {
        return RepositorioPerfil::pendentesDeModeracao($limite);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outrasDenunciasDoUsuario(int $usuarioId, int $ignorarDenuncia): array
    {
        return RepositorioDenuncia::outrasDoUsuario($usuarioId, $ignorarDenuncia);
    }
}