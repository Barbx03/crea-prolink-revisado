<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Formatador;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioModalidade;
use App\Repositories\RepositorioPerfil;
use App\Services\ExcecaoApiCrea;
use App\Services\ServicoIntegracaoCrea;
use App\Services\ServicoNotificacao;

/**
 * Integração com a API oficial e portfólio técnico (RF02 e RF03).
 *
 * Todo dado de registro, ART e CAT desta tela vem da API oficial do CREA-AM.
 * A aplicação não oferece nenhum caminho para inserir esses dados à mão.
 */
final class ControladorPortfolio extends Controlador
{
    public function painelIntegracao(): void
    {
        $usuario    = $this->usuarioAutenticado();
        $perfilId   = RepositorioPerfil::garantirExistencia($this->usuarioId());
        $integracao = new ServicoIntegracaoCrea();

        $this->visao('perfil/integracao.twig', [
            'usuario'           => $usuario,
            'perfil'            => RepositorioPerfil::porUsuario($this->usuarioId()),
            'arts'              => self::comAtividades(RepositorioArt::doPerfil($perfilId)),
            'modalidades'       => RepositorioModalidade::doPerfil($perfilId),
            'cats'              => RepositorioCat::doPerfil($perfilId),
            'integracao_ativa'  => $integracao->integracaoDisponivel(),
            'consentimento_crea' => RepositorioLgpd::temConsentimento($this->usuarioId(), 'DADOS_CREA'),
        ]);
    }

    /**
     * Valida o registro do usuário na API oficial (RF02).
     */
    public function validarRegistro(): void
    {
        $usuario = $this->usuarioAutenticado();

        // A consulta a dados institucionais depende de consentimento expresso
        if (!RepositorioLgpd::temConsentimento($this->usuarioId(), 'DADOS_CREA')) {
            Sessao::erro(
                'Para consultar seus dados no CREA-AM é necessário autorizar essa finalidade '
                . 'no painel de privacidade.'
            );
            $this->redirecionar('/privacidade');

            return;
        }

        $documento = Formatador::somenteDigitos(
            $this->requisicao->texto('documento') ?: (string) ($usuario['usu_cpf'] ?? $usuario['usu_cnpj'] ?? '')
        );

        if ($documento === '') {
            Sessao::erro('Informe o CPF ou o CNPJ a ser validado na API oficial.');
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        $ehPessoaFisica = strlen($documento) === 11;

        if ($ehPessoaFisica && !Validador::cpfValido($documento)) {
            Sessao::erro('O CPF informado não é válido.');
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        if (!$ehPessoaFisica && !Validador::cnpjValido($documento)) {
            Sessao::erro('O CNPJ informado não é válido.');
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        // O documento validado precisa ser o do próprio cadastro
        $documentoCadastro = Formatador::somenteDigitos(
            (string) ($ehPessoaFisica ? ($usuario['usu_cpf'] ?? '') : ($usuario['usu_cnpj'] ?? ''))
        );

        if ($documentoCadastro !== '' && $documentoCadastro !== $documento) {
            Sessao::erro(
                'O documento informado é diferente do que consta no seu cadastro. '
                . 'Só é possível validar o registro do próprio titular.'
            );
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        try {
            $integracao = new ServicoIntegracaoCrea();

            $resultado = $ehPessoaFisica
                ? $integracao->validarRegistroProfissional($this->usuarioId(), $documento)
                : $integracao->validarRegistroEmpresa($this->usuarioId(), $documento);

            if (!$resultado['validado']) {
                Sessao::erro($resultado['mensagem']);
                $this->redirecionar('/meu-perfil/registro-crea');

                return;
            }

            (new ServicoNotificacao())->notificar(
                'REGISTRO_VALIDADO',
                $this->usuarioId(),
                (string) $usuario['usu_email'],
                'CREA Pro-Link | Registro validado',
                [
                    'titulo'   => 'Seu registro foi validado',
                    'mensagem' => 'Seu registro foi confirmado junto à base oficial do CREA-AM. '
                        . 'Agora você pode associar ARTs ao seu portfólio e ganhar destaque nas buscas.',
                    'detalhes' => array_filter([
                        'Nome no registro' => $resultado['dados']['nome'] ?? ($resultado['dados']['razao_social'] ?? null),
                        'RNP'              => $resultado['dados']['rnp'] ?? null,
                        'Título'           => $resultado['dados']['titulo'] ?? null,
                        'Situação'         => $resultado['dados']['situacao'] ?? null,
                    ]),
                    'acao_texto' => 'Montar meu portfólio',
                ],
                '/meu-perfil/registro-crea'
            );

            Sessao::sucesso($resultado['mensagem']);
        } catch (ExcecaoApiCrea $e) {
            Sessao::erro($e->getMessage());
        }

        $this->redirecionar('/meu-perfil/registro-crea');
    }

    /**
     * Associa ao portfólio uma ART validada na API oficial (RF03).
     */
    public function associarArt(): void
    {
        $numero = $this->requisicao->texto('numero_art');

        if ($numero === '') {
            Sessao::erro('Informe o número da ART que deseja associar.');
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        if (mb_strlen($numero) > 40) {
            Sessao::erro('O número da ART informado é longo demais.');
            $this->redirecionar('/meu-perfil/registro-crea');

            return;
        }

        try {
            $resultado = (new ServicoIntegracaoCrea())->associarArt($this->usuarioId(), $numero);

            $resultado['ok']
                ? Sessao::sucesso($resultado['mensagem'])
                : Sessao::erro($resultado['mensagem']);
        } catch (ExcecaoApiCrea $e) {
            Sessao::erro($e->getMessage());
        }

        $this->redirecionar('/meu-perfil/registro-crea');
    }

    public function alternarArt(): void
    {
        $art = $this->artDoTitular($this->requisicao->parametroInteiro('id'));

        $visivel = $art['art_visivel'] !== 'S';
        RepositorioArt::definirVisibilidade((int) $art['art_id'], $visivel);

        Sessao::informacao(
            $visivel
                ? 'ART passou a ser exibida no seu perfil público.'
                : 'ART deixou de ser exibida no seu perfil público.'
        );

        $this->redirecionar('/meu-perfil/registro-crea');
    }

    public function destacarArt(): void
    {
        $art = $this->artDoTitular($this->requisicao->parametroInteiro('id'));

        RepositorioArt::definirDestaque((int) $art['art_id'], $art['art_destaque'] !== 'S');

        Sessao::informacao('Destaque do portfólio atualizado.');
        $this->redirecionar('/meu-perfil/registro-crea');
    }

    /**
     * Remove a ART do portfólio por exclusão lógica: o registro permanece na
     * base para rastreabilidade, conforme o item 8.6.j.
     */
    public function removerArt(): void
    {
        $art = $this->artDoTitular($this->requisicao->parametroInteiro('id'));

        RepositorioArt::excluirLogicamente(
            (int) $art['art_id'],
            'Removida do portfólio pelo próprio titular'
        );

        Sessao::informacao('ART removida do seu portfólio.');
        $this->redirecionar('/meu-perfil/registro-crea');
    }

    public function revalidarArts(): void
    {
        try {
            $resultado = (new ServicoIntegracaoCrea())->revalidarArts($this->usuarioId());

            if ($resultado['atualizadas'] === 0 && $resultado['falhas'] === 0) {
                Sessao::informacao('Não há ARTs associadas para revalidar.');
            } else {
                Sessao::sucesso(sprintf(
                    '%d ART(s) revalidada(s) na API oficial%s.',
                    $resultado['atualizadas'],
                    $resultado['falhas'] > 0 ? sprintf(' e %d sem retorno', $resultado['falhas']) : ''
                ));
            }
        } catch (ExcecaoApiCrea $e) {
            Sessao::erro($e->getMessage());
        }

        $this->redirecionar('/meu-perfil/registro-crea');
    }

    public function sincronizarCats(): void
    {
        try {
            $resultado = (new ServicoIntegracaoCrea())->sincronizarCats(
                $this->usuarioId(),
                $this->requisicao->texto('numero_cat') ?: null
            );

            $resultado['ok']
                ? Sessao::sucesso($resultado['mensagem'])
                : Sessao::erro($resultado['mensagem']);
        } catch (ExcecaoApiCrea $e) {
            Sessao::erro($e->getMessage());
        }

        $this->redirecionar('/meu-perfil/registro-crea');
    }

    public function alternarCat(): void
    {
        $catId = $this->requisicao->parametroInteiro('id');
        $cat   = RepositorioCat::comDono($catId);

        if ($cat === null) {
            throw ExcecaoHttp::naoEncontrado('CAT não encontrada.');
        }

        $this->exigirPropriedade((int) $cat['prf_usu_id']);

        RepositorioCat::definirVisibilidade($catId, $cat['cat_visivel'] !== 'S');

        Sessao::informacao('Visibilidade da CAT atualizada.');
        $this->redirecionar('/meu-perfil/registro-crea');
    }

    /**
     * @return array<string, mixed>
     */
    private function artDoTitular(int $artId): array
    {
        $art = RepositorioArt::comDono($artId);

        if ($art === null) {
            throw ExcecaoHttp::naoEncontrado('ART não encontrada.');
        }

        $this->exigirPropriedade((int) $art['prf_usu_id']);

        return $art;
    }

    /**
     * Acrescenta a cada ART as atividades TOS que a API informou.
     *
     * @param list<array<string, mixed>> $arts
     * @return list<array<string, mixed>>
     */
    private static function comAtividades(array $arts): array
    {
        foreach ($arts as $i => $art) {
            $arts[$i]['atividades'] = RepositorioArt::atividades((int) $art['art_id']);
        }

        return $arts;
    }
}
