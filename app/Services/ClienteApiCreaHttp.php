<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;
use App\Core\Registro;

/**
 * Cliente HTTP da API REST oficial do desafio (item 8.4).
 *
 * O acesso usa o Token de Acesso Individual fornecido pela plataforma do
 * desafio, enviado no cabecalho Authorization. Os caminhos dos recursos ficam
 * em configuracao (.env ou painel administrativo) porque a documentacao
 * tecnica e entregue apenas as equipes habilitadas: ao receber a documentacao,
 * ajusta-se o caminho sem alterar codigo.
 *
 * Cada chamada e registrada em sis_api_consultas com duracao e resultado,
 * dando rastreabilidade a integracao.
 */
final class ClienteApiCreaHttp implements ClienteApiCrea
{
    /**
     * Caminhos padrao, sobrescreviveis por configuracao.
     * O marcador entre chaves e substituido pelo parametro da consulta.
     */
    private const ROTAS_PADRAO = [
        'PROFISSIONAL' => '?p=profissionais',
        'EMPRESA'      => '?p=empresas',
        'ART'          => '?p=profissionais/{rnp}/arts',
        'ART_NUMERO'   => '?p=arts',
        'CAT'          => '?p=profissionais/{rnp}/cats',
        'CAT_NUMERO'   => '?p=cats',
    ];

    public function __construct(
        private readonly string $urlBase = '',
        private readonly string $token = '',
        private readonly int $timeout = 0,
    ) {
    }

    public function configurada(): bool
    {
        return $this->url() !== '' && $this->credencial() !== '';
    }

    /** @return array{dados: array<string, mixed>, bruto: string} */
    public function profissionalPorCpf(string $cpf): array
    {
        $resposta = $this->consultar('PROFISSIONAL', [], ['cpf' => $cpf]);

        return [
            'dados' => NormalizadorApiCrea::profissional($resposta['corpo']),
            'bruto' => $resposta['bruto'],
        ];
    }

    /** @return array{dados: array<string, mixed>, bruto: string} */
    public function empresaPorCnpj(string $cnpj): array
    {
        $resposta = $this->consultar('EMPRESA', [], ['cnpj' => $cnpj]);

        return [
            'dados' => NormalizadorApiCrea::empresa($resposta['corpo']),
            'bruto' => $resposta['bruto'],
        ];
    }

    /** @return array{dados: list<array<string, mixed>>, bruto: string} */
    public function artsPorRnp(string $rnp, ?string $numeroArt = null): array
    {
        // Informado o número, usa-se o endpoint de validação de veracidade da
        // ART, que casa RNP e número — é exatamente a conferência pedida no
        // RF03. Sem número, lista-se o acervo do RNP de forma paginada.
        $resposta = $numeroArt !== null && $numeroArt !== ''
            ? $this->consultar('ART_NUMERO', [], ['rnp' => $rnp, 'art_numero' => $numeroArt])
            : $this->consultar('ART', ['rnp' => $rnp], ['limit' => '100']);

        return [
            'dados' => NormalizadorApiCrea::arts($resposta['corpo'], $rnp, $numeroArt),
            'bruto' => $resposta['bruto'],
        ];
    }

    /** @return array{dados: list<array<string, mixed>>, bruto: string} */
    public function catsPorRnp(string $rnp, ?string $numeroCat = null): array
    {
        $resposta = $numeroCat !== null && $numeroCat !== ''
            ? $this->consultar('CAT_NUMERO', [], ['rnp' => $rnp, 'cat_numero' => $numeroCat])
            : $this->consultar('CAT', ['rnp' => $rnp], ['limit' => '100']);

        return [
            'dados' => NormalizadorApiCrea::cats($resposta['corpo'], $rnp, $numeroCat),
            'bruto' => $resposta['bruto'],
        ];
    }

    /**
     * @param array<string, string> $substituicoes
     * @param array<string, string> $parametrosConsulta
     * @return array{corpo: array<string, mixed>|list<mixed>, bruto: string, http: int}
     */
    private function consultar(string $recurso, array $substituicoes, array $parametrosConsulta): array
    {
        if (!$this->configurada()) {
            throw ExcecaoApiCrea::naoConfigurada();
        }

        $endpoint = $this->montarEndpoint($recurso, $substituicoes, $parametrosConsulta);
        $inicio   = microtime(true);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->tempoLimite(),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->tempoLimite()),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->credencial(),
                'X-Access-Token: ' . $this->credencial(),
                'User-Agent: CREA-Pro-Link/' . APP_VERSAO,
            ],
        ]);

        $corpoBruto = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $erroCurl   = curl_error($curl);

        // curl_close() deixou de ter efeito no PHP 8.0: o recurso e liberado
        // quando a variavel sai de escopo.
        unset($curl);

        $duracao = (int) round((microtime(true) - $inicio) * 1000);

        if ($corpoBruto === false) {
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, $erroCurl);

            throw new ExcecaoApiCrea(
                'Não foi possível comunicar com a API oficial do CREA-AM: ' . $erroCurl,
                0,
                $recurso
            );
        }

        $corpoBruto = (string) $corpoBruto;

        if ($httpStatus === 401 || $httpStatus === 403) {
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, 'Token recusado');

            throw new ExcecaoApiCrea(
                'A API oficial recusou o token de acesso. Verifique o token individual da equipe.',
                $httpStatus,
                $recurso
            );
        }

        if ($httpStatus === 404) {
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, 'Registro não encontrado');

            throw ExcecaoApiCrea::registroNaoEncontrado($recurso);
        }

        if ($httpStatus >= 400) {
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, 'Resposta HTTP ' . $httpStatus);

            throw new ExcecaoApiCrea(
                sprintf('A API oficial respondeu com erro HTTP %d.', $httpStatus),
                $httpStatus,
                $recurso
            );
        }

        $corpo = json_decode($corpoBruto, true);

        if (!is_array($corpo)) {
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, 'Resposta não e JSON válido');

            throw new ExcecaoApiCrea(
                'A API oficial retornou um conteúdo que não pode ser interpretado como JSON.',
                $httpStatus,
                $recurso
            );
        }

        // A API sinaliza erro de negócio com HTTP 200 e a chave "error"
        if (isset($corpo['error'])) {
            $mensagem = (string) $corpo['error'];
            $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, false, $duracao, $mensagem);

            throw new ExcecaoApiCrea(
                'A API oficial recusou a consulta: ' . $mensagem,
                $httpStatus,
                $recurso,
                str_contains(mb_strtolower($mensagem), 'encontrad')
            );
        }

        $this->registrarConsulta($recurso, $substituicoes, $endpoint, $httpStatus, true, $duracao, 'Consulta realizada');

        return ['corpo' => $corpo, 'bruto' => $corpoBruto, 'http' => $httpStatus];
    }

    /**
     * @param array<string, string> $substituicoes
     * @param array<string, string> $parametrosConsulta
     */
    private function montarEndpoint(string $recurso, array $substituicoes, array $parametrosConsulta): string
    {
        $rota = Configuracao::texto('API_CREA', 'rota_' . strtolower($recurso), '');

        if ($rota === '') {
            $rota = \App\Core\Ambiente::texto('CREA_API_ROTA_' . $recurso, self::ROTAS_PADRAO[$recurso]);
        }

        foreach ($substituicoes as $chave => $valor) {
            $rota = str_replace('{' . $chave . '}', rawurlencode($valor), $rota);
        }

        $endpoint = $this->url() . '/' . ltrim($rota, '/');

        if ($parametrosConsulta !== []) {
            // A rota da API oficial já começa com "?p=", então a junção precisa
            // usar "&"; a verificação cobre os dois formatos.
            $separador = str_contains($endpoint, '?') ? '&' : '?';
            $endpoint .= $separador . http_build_query($parametrosConsulta);
        }

        return $endpoint;
    }

    /**
     * @param array<string, string> $substituicoes
     */
    private function registrarConsulta(
        string $recurso,
        array $substituicoes,
        string $endpoint,
        int $httpStatus,
        bool $sucesso,
        int $duracao,
        string $mensagem
    ): void {
        try {
            \App\Core\BancoDados::executar(
                'INSERT INTO sis_api_consultas
                    (apc_usu_id, apc_recurso, apc_parametros, apc_endpoint,
                     apc_http_status, apc_sucesso, apc_duracao_ms, apc_mensagem, apc_log, apc_status)
                 VALUES (:usuario, :recurso, :parametros, :endpoint,
                         :http, :sucesso, :duracao, :mensagem, :log, :ativo)',
                [
                    'usuario'    => \App\Core\Autenticacao::id() > 0 ? \App\Core\Autenticacao::id() : null,
                    'recurso'    => $recurso,
                    // Identificadores mascarados: a trilha nao guarda CPF nem CNPJ completo
                    'parametros' => substr($this->mascararParametros($substituicoes), 0, 255),
                    'endpoint'   => substr(preg_replace('#/[^/]{6,}$#', '/***', $endpoint) ?? $endpoint, 0, 255),
                    'http'       => $httpStatus > 0 ? $httpStatus : null,
                    'sucesso'    => $sucesso ? 'S' : 'N',
                    'duracao'    => $duracao,
                    'mensagem'   => substr($mensagem, 0, 500),
                    'log'        => null,
                    'ativo'      => STATUS_ATIVO,
                ]
            );
        } catch (\Throwable $e) {
            Registro::erro('Falha ao registrar consulta a API oficial', ['erro' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $parametros */
    private function mascararParametros(array $parametros): string
    {
        $partes = [];

        foreach ($parametros as $chave => $valor) {
            $partes[] = $chave . '=' . \App\Core\Formatador::documentoMascarado($valor)
                ?: $chave . '=' . substr($valor, 0, 3) . '***';
        }

        return implode('&', $partes);
    }

    private function url(): string
    {
        if ($this->urlBase !== '') {
            return rtrim($this->urlBase, '/');
        }

        $doPainel = Configuracao::texto('API_CREA', 'api_base_url', '');

        return rtrim($doPainel !== '' ? $doPainel : CREA_API_URL, '/');
    }

    private function credencial(): string
    {
        if ($this->token !== '') {
            return $this->token;
        }

        $doPainel = Configuracao::texto('API_CREA', 'api_token', '');

        return $doPainel !== '' ? $doPainel : CREA_API_TOKEN;
    }

    private function tempoLimite(): int
    {
        if ($this->timeout > 0) {
            return $this->timeout;
        }

        return max(3, Configuracao::inteiro('API_CREA', 'api_timeout', CREA_API_TIMEOUT));
    }
}
