<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Autenticacao;
use App\Core\BancoDados;
use App\Core\Configuracao;
use App\Core\Controlador;
use App\Core\Csrf;
use App\Core\ExcecaoHttp;
use App\Core\Formatador;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioLgpd;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioToken;
use App\Repositories\RepositorioUsuario;
use App\Services\ExcecaoApiCrea;
use App\Services\ServicoIntegracaoCrea;
use App\Services\ServicoNotificacao;

/**
 * Cadastro, autenticação e recuperação de acesso (RF01).
 *
 * O cadastro distingue quatro situações previstas no item 3 do Termo de
 * Referência: profissional registrado no CREA-AM, empresa registrada, e
 * terceiros pessoa física ou jurídica sem registro. Nos dois primeiros casos, a
 * validação ocorre na API oficial no momento do cadastro.
 */
final class ControladorAutenticacao extends Controlador
{
    public function formularioEntrar(): void
    {
        if (Autenticacao::autenticado()) {
            $this->redirecionar('/painel');
        }

        $this->visao('auth/entrar.twig');
    }

    public function entrar(): void
    {
        $email = $this->requisicao->texto('email');
        $senha = $this->requisicao->texto('senha');

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('email', 'o e-mail')
            ->email('email')
            ->obrigatorio('senha', 'a senha');

        if (!$validador->valido()) {
            $this->voltarComErros('/entrar', $validador->erros());

            return;
        }

        $resultado = Autenticacao::entrar($email, $senha);

        if (!$resultado['ok']) {
            Sessao::guardarFormulario(['email' => $email]);
            Sessao::erro($resultado['mensagem']);
            $this->redirecionar('/entrar');

            return;
        }

        $destino = Sessao::obter('_destino_pretendido');
        Sessao::remover('_destino_pretendido');

        Sessao::sucesso('Bem-vindo de volta, ' . explode(' ', (string) $resultado['usuario']['usu_nome'])[0] . '.');

        $this->redirecionar(is_string($destino) && $destino !== '' ? $destino : '/painel');
    }

    public function sair(): void
    {
        Autenticacao::sair();
        Sessao::iniciar();
        Csrf::renovar();
        Sessao::informacao('Sessão encerrada.');

        $this->redirecionar('/');
    }

    public function formularioCadastro(): void
    {
        if (Autenticacao::autenticado()) {
            $this->redirecionar('/painel');
        }

        if (!Configuracao::booleano('PLATAFORMA', 'cadastro_aberto', true)) {
            $this->visao('auth/cadastro-fechado.twig');

            return;
        }

        $integracao = new ServicoIntegracaoCrea();

        $this->visao('auth/cadastrar.twig', [
            'termos_uso'          => RepositorioLgpd::termoVigente('TERMOS_USO'),
            'politica'            => RepositorioLgpd::termoVigente('POLITICA_PRIVACIDADE'),
            'ufs'                 => Validador::UNIDADES_FEDERATIVAS,
            'integracao_ativa'    => $integracao->integracaoDisponivel(),
        ]);
    }

    /**
     * Consulta prévia na API oficial, chamada pelo formulário antes do envio.
     * Permite ao usuário conferir o registro encontrado antes de concluir.
     */
    public function verificarNaApi(): void
    {
        $tipo      = $this->requisicao->texto('tipo_vinculo');
        $documento = Formatador::somenteDigitos($this->requisicao->texto('documento'));

        try {
            $integracao = new ServicoIntegracaoCrea();

            if ($tipo === 'PROFISSIONAL') {
                if (!Validador::cpfValido($documento)) {
                    $this->json(['sucesso' => false, 'mensagem' => 'Informe um CPF válido.'], 422);

                    return;
                }

                $consulta = $integracao->consultarProfissional($documento);

                $this->json([
                    'sucesso' => true,
                    'tipo'    => 'PROFISSIONAL',
                    'dados'   => [
                        'nome'     => $consulta['dados']['nome'],
                        'rnp'      => $consulta['dados']['rnp'],
                        'titulo'   => $consulta['dados']['titulo'],
                        'situacao' => $consulta['dados']['situacao'],
                        'uf'       => $consulta['dados']['uf'],
                        'municipio'=> $consulta['dados']['municipio'],
                    ],
                ]);

                return;
            }

            if (!Validador::cnpjValido($documento)) {
                $this->json(['sucesso' => false, 'mensagem' => 'Informe um CNPJ válido.'], 422);

                return;
            }

            $consulta = $integracao->consultarEmpresa($documento);

            $this->json([
                'sucesso' => true,
                'tipo'    => 'EMPRESA',
                'dados'   => [
                    'razao_social'  => $consulta['dados']['razao_social'],
                    'nome_fantasia' => $consulta['dados']['nome_fantasia'],
                    'registro'      => $consulta['dados']['registro'],
                    'situacao'      => $consulta['dados']['situacao'],
                    'uf'            => $consulta['dados']['uf'],
                    'municipio'     => $consulta['dados']['municipio'],
                ],
            ]);
        } catch (ExcecaoApiCrea $e) {
            $this->json([
                'sucesso'  => false,
                'mensagem' => $e->getMessage(),
            ], $e->naoEncontrado ? 404 : 502);
        }
    }

    public function cadastrar(): void
    {
        if (!Configuracao::booleano('PLATAFORMA', 'cadastro_aberto', true)) {
            throw ExcecaoHttp::naoAutorizado('O cadastro de novos usuários está temporariamente suspenso.');
        }

        $dados = $this->requisicao->todos();

        $tipoVinculo = $this->requisicao->texto('tipo_vinculo');
        $cpf         = Formatador::somenteDigitos($this->requisicao->texto('cpf'));
        $cnpj        = Formatador::somenteDigitos($this->requisicao->texto('cnpj'));

        $validador = Validador::para($dados)
            ->obrigatorio('nome', 'o nome completo ou a razão social')
            ->minimo('nome', 5, 'O nome')
            ->maximo('nome', 150, 'O nome')
            ->obrigatorio('email', 'o e-mail')
            ->email('email')
            ->maximo('email', 190, 'O e-mail')
            ->obrigatorio('senha', 'a senha')
            ->senhaForte('senha')
            ->confirmacao('senha', 'senha_confirmacao')
            ->dentroDe('tipo_vinculo', ['PROFISSIONAL', 'EMPRESA', 'TERCEIRO_PF', 'TERCEIRO_PJ'], 'o tipo de cadastro')
            ->obrigatorio('tipo_vinculo', 'o tipo de cadastro')
            ->uf('uf')
            ->maximo('cidade', 120, 'A cidade')
            ->aceito('aceite_termos', 'É necessário aceitar os Termos de Uso para concluir o cadastro.')
            ->aceito('aceite_privacidade', 'É necessário aceitar a Política de Privacidade para concluir o cadastro.');

        // Documento exigido conforme o tipo de vínculo
        if (in_array($tipoVinculo, ['PROFISSIONAL', 'TERCEIRO_PF'], true)) {
            $validador->obrigatorio('cpf', 'o CPF')->cpf('cpf');
        }

        if (in_array($tipoVinculo, ['EMPRESA', 'TERCEIRO_PJ'], true)) {
            $validador->obrigatorio('cnpj', 'o CNPJ')->cnpj('cnpj');
        }

        if (RepositorioUsuario::emailEmUso($this->requisicao->texto('email'))) {
            $validador->personalizado('email', false, 'Este e-mail já possui cadastro. Use a recuperação de acesso.');
        }

        if (RepositorioUsuario::documentoEmUso($cpf ?: null, $cnpj ?: null)) {
            $campo = $cpf !== '' ? 'cpf' : 'cnpj';
            $validador->personalizado($campo, false, 'Já existe cadastro com este documento.');
        }

        if (!$validador->valido()) {
            $this->voltarComErros('/cadastrar', $validador->erros());

            return;
        }

        $perfil = match ($tipoVinculo) {
            'PROFISSIONAL' => PERFIL_PROFISSIONAL,
            'EMPRESA'      => PERFIL_EMPRESA,
            default        => PERFIL_TERCEIRO,
        };

        $tipoPessoa = in_array($tipoVinculo, ['EMPRESA', 'TERCEIRO_PJ'], true) ? 'PJ' : 'PF';

        $usuarioId = BancoDados::transacao(function () use ($perfil, $tipoPessoa, $cpf, $cnpj): int {
            return RepositorioUsuario::criar([
                'nome'        => $this->requisicao->texto('nome'),
                'email'       => $this->requisicao->texto('email'),
                'senha'       => $this->requisicao->texto('senha'),
                'tipo_pessoa' => $tipoPessoa,
                'perfil'      => $perfil,
                'cpf'         => $cpf !== '' ? $cpf : null,
                'cnpj'        => $cnpj !== '' ? $cnpj : null,
                'telefone'    => $this->requisicao->texto('telefone') ?: null,
                'uf'          => strtoupper($this->requisicao->texto('uf')) ?: null,
                'cidade'      => $this->requisicao->texto('cidade') ?: null,
                'status'      => STATUS_ATIVO,
            ]);
        });

        // Trilha de consentimentos (LGPD art. 8)
        $termos   = RepositorioLgpd::termoVigente('TERMOS_USO');
        $politica = RepositorioLgpd::termoVigente('POLITICA_PRIVACIDADE');

        RepositorioLgpd::registrarConsentimento(
            $usuarioId,
            'TERMOS_USO',
            true,
            isset($termos['ter_id']) ? (int) $termos['ter_id'] : null,
            $this->requisicao->ip,
            $this->requisicao->agenteUsuario
        );

        RepositorioLgpd::registrarConsentimento(
            $usuarioId,
            'POLITICA_PRIVACIDADE',
            true,
            isset($politica['ter_id']) ? (int) $politica['ter_id'] : null,
            $this->requisicao->ip,
            $this->requisicao->agenteUsuario
        );

        RepositorioLgpd::registrarConsentimento(
            $usuarioId,
            'COMUNICACOES',
            $this->requisicao->booleano('aceite_comunicacoes'),
            null,
            $this->requisicao->ip,
            $this->requisicao->agenteUsuario
        );

        // Quem se cadastra como registrado autoriza a consulta ao CREA-AM
        if (in_array($perfil, [PERFIL_PROFISSIONAL, PERFIL_EMPRESA], true)) {
            RepositorioLgpd::registrarConsentimento(
                $usuarioId,
                'DADOS_CREA',
                true,
                null,
                $this->requisicao->ip,
                $this->requisicao->agenteUsuario
            );
        }

        RepositorioPerfil::garantirExistencia($usuarioId);

        Auditoria::registrar('CADASTRO', [
            'usuario_id'  => $usuarioId,
            'entidade'    => 'sis_usuarios',
            'entidade_id' => $usuarioId,
            'descricao'   => sprintf('Cadastro concluído como %s', $perfil),
        ]);

        // Validação na API oficial, quando o cadastro se declara registrado
        $mensagemIntegracao = null;

        if ($perfil === PERFIL_PROFISSIONAL || $perfil === PERFIL_EMPRESA) {
            try {
                $integracao = new ServicoIntegracaoCrea();

                $resultado = $perfil === PERFIL_PROFISSIONAL
                    ? $integracao->validarRegistroProfissional($usuarioId, $cpf)
                    : $integracao->validarRegistroEmpresa($usuarioId, $cnpj);

                $mensagemIntegracao = $resultado['mensagem'];
            } catch (ExcecaoApiCrea $e) {
                $mensagemIntegracao = 'Cadastro criado, mas a validação na API oficial não pôde ser concluída agora: '
                    . $e->getMessage()
                    . ' Você pode repetir a validação no seu perfil.';
            }
        }

        // Notificação de cadastro criado (RF07)
        (new ServicoNotificacao())->notificar(
            'CADASTRO_CRIADO',
            $usuarioId,
            $this->requisicao->texto('email'),
            'CREA Pro-Link | Cadastro criado',
            [
                'titulo'   => 'Seu cadastro foi criado',
                'mensagem' => 'Seu acesso ao CREA Pro-Link já está ativo. Complete seu perfil para aparecer nas buscas e receber oportunidades compatíveis.',
                'detalhes' => [
                    'Nome'           => $this->requisicao->texto('nome'),
                    'Tipo de acesso' => Formatador::rotulo($perfil),
                ],
                'acao_texto' => 'Acessar meu painel',
            ],
            '/painel'
        );

        $usuario = RepositorioUsuario::porId($usuarioId);

        if ($usuario !== null) {
            Autenticacao::estabelecerSessao($usuario);
        }

        Sessao::sucesso('Cadastro concluído. Bem-vindo ao CREA Pro-Link.');

        if ($mensagemIntegracao !== null) {
            Sessao::informacao($mensagemIntegracao);
        }

        $this->redirecionar('/painel');
    }

    public function formularioRecuperacao(): void
    {
        $this->visao('auth/recuperar.twig');
    }

    public function solicitarRecuperacao(): void
    {
        $email = $this->requisicao->texto('email');

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('email', 'o e-mail')
            ->email('email');

        if (!$validador->valido()) {
            $this->voltarComErros('/recuperar-acesso', $validador->erros());

            return;
        }

        $usuario = RepositorioUsuario::porEmail($email);

        // Resposta idêntica exista ou não a conta, para não revelar cadastros
        if ($usuario !== null && $usuario['usu_status'] !== STATUS_EXCLUIDO) {
            $token = RepositorioToken::gerar(
                (int) $usuario['usu_id'],
                RepositorioToken::RECUPERACAO_SENHA,
                TOKEN_VALIDADE_MINUTOS,
                $this->requisicao->ip
            );

            (new ServicoNotificacao())->notificar(
                'RECUPERACAO_SENHA',
                (int) $usuario['usu_id'],
                (string) $usuario['usu_email'],
                'CREA Pro-Link | Recuperação de acesso',
                [
                    'titulo'   => 'Redefinição de senha solicitada',
                    'mensagem' => sprintf(
                        'Recebemos um pedido de redefinição de senha para esta conta. O link abaixo é válido por %d minutos e só pode ser usado uma vez. Se não foi você, ignore esta mensagem: nada será alterado.',
                        TOKEN_VALIDADE_MINUTOS
                    ),
                    'acao_texto' => 'Redefinir minha senha',
                ],
                '/redefinir-senha/' . $token
            );

            Auditoria::registrar('RECUPERACAO_SOLICITADA', [
                'usuario_id' => (int) $usuario['usu_id'],
                'descricao'  => 'Solicitação de redefinição de senha',
                'severidade' => 'ALERTA',
            ]);
        }

        Sessao::informacao(
            'Se este e-mail estiver cadastrado, você receberá as instruções de redefinição em instantes. '
            . 'Confira também a caixa de spam.'
        );

        $this->redirecionar('/entrar');
    }

    public function formularioRedefinicao(): void
    {
        $token   = (string) $this->requisicao->parametro('token');
        $registro = $this->tokenValido($token, 'RECUPERACAO_SENHA');

        if ($registro === null) {
            Sessao::erro('Este link de redefinição é inválido ou já expirou. Solicite um novo.');
            $this->redirecionar('/recuperar-acesso');

            return;
        }

        $this->visao('auth/redefinir.twig', ['token' => $token]);
    }

    public function redefinirSenha(): void
    {
        $token = $this->requisicao->texto('token');

        $registro = $this->tokenValido($token, 'RECUPERACAO_SENHA');

        if ($registro === null) {
            Sessao::erro('Este link de redefinição é inválido ou já expirou. Solicite um novo.');
            $this->redirecionar('/recuperar-acesso');

            return;
        }

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('senha', 'a nova senha')
            ->senhaForte('senha')
            ->confirmacao('senha', 'senha_confirmacao');

        if (!$validador->valido()) {
            $this->voltarComErros('/redefinir-senha/' . $token, $validador->erros());

            return;
        }

        $usuarioId = (int) $registro['tok_usu_id'];

        BancoDados::transacao(function () use ($usuarioId, $registro): void {
            RepositorioUsuario::atualizarSenha($usuarioId, $this->requisicao->texto('senha'));

            RepositorioToken::consumir((int) $registro['tok_id']);

            // Uma redefinição bem-sucedida encerra os links ainda em circulação
            RepositorioToken::invalidarPendentes($usuarioId, RepositorioToken::RECUPERACAO_SENHA);
        });

        $usuario = RepositorioUsuario::porId($usuarioId);

        if ($usuario !== null) {
            (new ServicoNotificacao())->notificar(
                'SENHA_ALTERADA',
                $usuarioId,
                (string) $usuario['usu_email'],
                'CREA Pro-Link | Senha alterada',
                [
                    'titulo'   => 'Sua senha foi alterada',
                    'mensagem' => 'A senha da sua conta foi redefinida. Se não foi você, procure imediatamente a administração da plataforma.',
                    'detalhes' => [
                        'Data e hora' => date('d/m/Y H:i'),
                        'Origem'      => $this->requisicao->ip,
                    ],
                ]
            );
        }

        Sessao::sucesso('Senha redefinida. Você já pode entrar com a nova senha.');
        $this->redirecionar('/entrar');
    }

    public function verificarEmail(): void
    {
        $token    = (string) $this->requisicao->parametro('token');
        $registro = $this->tokenValido($token, 'VERIFICACAO_EMAIL');

        if ($registro === null) {
            Sessao::erro('Este link de verificação é inválido ou já expirou.');
            $this->redirecionar('/entrar');

            return;
        }

        RepositorioUsuario::verificarEmail((int) $registro['tok_usu_id']);
        RepositorioToken::consumir((int) $registro['tok_id']);

        Sessao::sucesso('E-mail verificado.');
        $this->redirecionar('/entrar');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tokenValido(string $token, string $tipo): ?array
    {
        if ($token === '') {
            return null;
        }

        return RepositorioToken::pendente($token, $tipo);
    }
}
