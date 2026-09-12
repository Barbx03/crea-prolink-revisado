<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auditoria;
use App\Core\Configuracao;
use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Services\ExcecaoApiCrea;
use App\Services\ServicoIntegracaoCrea;
use App\Services\ServicoNotificacao;

/**
 * Configuração da plataforma pelo painel administrativo (RF06 e RF07).
 *
 * Cobre os quatro grupos de parâmetros: dados gerais da plataforma, SMTP,
 * integração com a API oficial e pesos da compatibilização. Valores sensíveis
 * nunca voltam para a tela: o campo aparece em branco e só é gravado quando o
 * administrador digita algo novo.
 */
final class ControladorConfiguracoes extends Controlador
{
    private const GRUPOS = ['PLATAFORMA', 'SMTP', 'API_CREA', 'MATCHING'];

    public function painel(): void
    {
        $integracao = new ServicoIntegracaoCrea();
        $email      = new ServicoNotificacao();

        $this->visao('admin/configuracoes.twig', [
            'grupos' => [
                'PLATAFORMA' => Configuracao::listarGrupo('PLATAFORMA'),
                'SMTP'       => Configuracao::listarGrupo('SMTP'),
                'API_CREA'   => Configuracao::listarGrupo('API_CREA'),
                'MATCHING'   => Configuracao::listarGrupo('MATCHING'),
            ],
            'integracao_ativa' => $integracao->integracaoDisponivel(),
            'smtp_ativo'       => $email->smtpAtivo(),
            'ambiente'         => [
                'ambiente'      => APP_AMBIENTE,
                'debug'         => APP_DEBUG,
                'versao'        => APP_VERSAO,
                'php'           => PHP_VERSION,
                'url'           => APP_URL,
                'fuso'          => date_default_timezone_get(),
                'api_url_env'   => CREA_API_URL !== '' ? CREA_API_URL : null,
                'api_driver'    => CREA_API_DRIVER,
                'api_token_env' => CREA_API_TOKEN !== '',
                'banco'         => DB_NOME . ' em ' . DB_HOST . ':' . DB_PORTA,
            ],
        ]);
    }

    public function salvar(): void
    {
        $grupo = strtoupper((string) $this->requisicao->parametro('grupo'));

        if (!in_array($grupo, self::GRUPOS, true)) {
            throw ExcecaoHttp::requisicaoInvalida('Grupo de configuração inválido.');
        }

        $definicoes = Configuracao::listarGrupo($grupo);
        $enviados   = $this->requisicao->todos()['cfg'] ?? [];
        $alteradas  = 0;

        foreach ($definicoes as $definicao) {
            $chave = (string) $definicao['cfg_chave'];

            if (!array_key_exists($chave, (array) $enviados)) {
                // Caixas de seleção não enviadas equivalem a desmarcadas
                if ($definicao['cfg_tipo'] === 'BOOLEANO' && $definicao['cfg_valor'] !== '0') {
                    Configuracao::definir($grupo, $chave, '0');
                    $alteradas++;
                }

                continue;
            }

            $valor = trim((string) ((array) $enviados)[$chave]);

            // Valor sensível em branco significa "manter o que já está gravado"
            if ($definicao['cfg_sensivel'] === 'S' && $valor === '') {
                continue;
            }

            $valor = match ($definicao['cfg_tipo']) {
                'BOOLEANO' => in_array($valor, ['1', 'on', 'true', 'sim'], true) ? '1' : '0',
                'INTEIRO'  => (string) max(0, (int) $valor),
                default    => $valor,
            };

            if ($valor === (string) $definicao['cfg_valor']) {
                continue;
            }

            Configuracao::definir($grupo, $chave, $valor);
            $alteradas++;

            Auditoria::registrar('CONFIGURACAO_ALTERADA', [
                'entidade'    => 'sis_configuracoes',
                'entidade_id' => (int) $definicao['cfg_id'],
                'descricao'   => sprintf('Parâmetro %s.%s alterado', $grupo, $chave),
                'antes'       => [$chave => $definicao['cfg_sensivel'] === 'S' ? '***' : $definicao['cfg_valor']],
                'depois'      => [$chave => $definicao['cfg_sensivel'] === 'S' ? '***' : $valor],
                'severidade'  => 'CRITICO',
            ]);
        }

        // Os pesos do matching precisam somar algo maior que zero
        if ($grupo === 'MATCHING') {
            $soma = 0;

            foreach (Configuracao::listarGrupo('MATCHING') as $definicao) {
                if (str_starts_with((string) $definicao['cfg_chave'], 'peso_')) {
                    $soma += (int) $definicao['cfg_valor'];
                }
            }

            if ($soma <= 0) {
                Sessao::erro(
                    'A soma dos pesos precisa ser maior que zero. Os valores anteriores foram mantidos '
                    . 'em memória; revise os campos.'
                );
                $this->redirecionar('/admin/configuracoes');

                return;
            }
        }

        Configuracao::limparCache();

        Sessao::sucesso(
            $alteradas === 0
                ? 'Nenhuma alteração a registrar.'
                : sprintf('%d parâmetro(s) atualizado(s).', $alteradas)
        );

        $this->redirecionar('/admin/configuracoes');
    }

    public function testarSmtp(): void
    {
        $destinatario = $this->requisicao->texto('destinatario');

        if (filter_var($destinatario, FILTER_VALIDATE_EMAIL) === false) {
            Sessao::erro('Informe um e-mail válido para receber o teste.');
            $this->redirecionar('/admin/configuracoes');

            return;
        }

        $resultado = (new ServicoNotificacao())->enviarTeste($destinatario);

        Auditoria::registrar('TESTE_SMTP', [
            'descricao'  => sprintf(
                'Teste de envio de e-mail para %s: %s',
                $destinatario,
                $resultado['ok'] ? 'sucesso' : 'falha'
            ),
            'severidade' => $resultado['ok'] ? 'INFO' : 'ALERTA',
        ]);

        $resultado['ok'] ? Sessao::sucesso($resultado['mensagem']) : Sessao::erro($resultado['mensagem']);

        $this->redirecionar('/admin/configuracoes');
    }

    /**
     * Verifica a comunicação com a API oficial usando um documento informado
     * pelo administrador. Nenhum dado é gravado: apenas a chamada é testada.
     */
    public function testarApi(): void
    {
        $documento = \App\Core\Formatador::somenteDigitos($this->requisicao->texto('documento'));

        if (!in_array(strlen($documento), [11, 14], true)) {
            Sessao::erro('Informe um CPF ou CNPJ para testar a consulta na API oficial.');
            $this->redirecionar('/admin/configuracoes');

            return;
        }

        try {
            $integracao = new ServicoIntegracaoCrea();

            $consulta = strlen($documento) === 11
                ? $integracao->consultarProfissional($documento)
                : $integracao->consultarEmpresa($documento);

            $camposPreenchidos = array_filter(
                $consulta['dados'],
                static fn ($valor): bool => $valor !== null && $valor !== '' && $valor !== false && $valor !== []
            );

            Sessao::sucesso(sprintf(
                'A API oficial respondeu com sucesso: %d campo(s) reconhecido(s) na resposta (%s).',
                count($camposPreenchidos),
                implode(', ', array_slice(array_keys($camposPreenchidos), 0, 8))
            ));
        } catch (ExcecaoApiCrea $e) {
            Sessao::erro('Teste de integração: ' . $e->getMessage());
        }

        $this->redirecionar('/admin/auditoria/api');
    }
}
