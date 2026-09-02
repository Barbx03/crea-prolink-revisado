<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador da aplicacao.
 *
 * Cada rota declara metodo, padrao de caminho, controlador, acao e a lista de
 * perfis autorizados. A verificacao de perfil e a validacao do token CSRF sao
 * feitas aqui, antes de qualquer controlador executar, garantindo que nenhuma
 * acao protegida dependa de o controlador lembrar de checar (itens 8.5.e e
 * 8.5.f do Termo de Referencia).
 */
final class Roteador
{
    /** @var list<array{metodo: string, padrao: string, regex: string, parametros: list<string>, controlador: string, acao: string, perfis: list<string>}> */
    private array $rotas = [];

    /** @param list<string> $perfis */
    public function get(string $padrao, string $controlador, string $acao, array $perfis = []): void
    {
        $this->adicionar('GET', $padrao, $controlador, $acao, $perfis);
    }

    /** @param list<string> $perfis */
    public function post(string $padrao, string $controlador, string $acao, array $perfis = []): void
    {
        $this->adicionar('POST', $padrao, $controlador, $acao, $perfis);
    }

    /** @param list<string> $perfis */
    public function put(string $padrao, string $controlador, string $acao, array $perfis = []): void
    {
        $this->adicionar('PUT', $padrao, $controlador, $acao, $perfis);
    }

    /** @param list<string> $perfis */
    public function delete(string $padrao, string $controlador, string $acao, array $perfis = []): void
    {
        $this->adicionar('DELETE', $padrao, $controlador, $acao, $perfis);
    }

    /** @param list<string> $perfis */
    private function adicionar(string $metodo, string $padrao, string $controlador, string $acao, array $perfis): void
    {
        $parametros = [];
        $regex = preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            static function (array $captura) use (&$parametros): string {
                $parametros[] = $captura[1];

                return '([^/]+)';
            },
            $padrao
        );

        $this->rotas[] = [
            'metodo'      => $metodo,
            'padrao'      => $padrao,
            'regex'       => '#^' . $regex . '$#',
            'parametros'  => $parametros,
            'controlador' => $controlador,
            'acao'        => $acao,
            'perfis'      => $perfis,
        ];
    }

    /**
     * Resolve e executa a rota correspondente a requisicao.
     */
    public function despachar(Requisicao $requisicao): void
    {
        $caminhoEncontrado = false;

        foreach ($this->rotas as $rota) {
            if (preg_match($rota['regex'], $requisicao->caminho, $captura) !== 1) {
                continue;
            }

            $caminhoEncontrado = true;

            if ($rota['metodo'] !== $requisicao->metodo) {
                continue;
            }

            array_shift($captura);
            $requisicao->definirParametrosRota(array_combine($rota['parametros'], $captura));

            // 1. Token CSRF em toda requisicao que altera estado (item 8.5.e)
            if ($requisicao->alteraEstado() && !Csrf::valido($requisicao->tokenCsrf())) {
                Auditoria::registrar('CSRF_INVALIDO', [
                    'descricao'  => 'Requisição recusada por token CSRF ausente ou inválido',
                    'rota'       => $requisicao->caminho,
                    'severidade' => 'ALERTA',
                ]);

                throw ExcecaoHttp::tokenInvalido();
            }

            // 2. Controle de perfis de acesso (item 8.5.f)
            if ($rota['perfis'] !== []) {
                if (!Autenticacao::autenticado()) {
                    throw ExcecaoHttp::naoAutenticado();
                }

                if (!Autenticacao::temPerfil($rota['perfis'])) {
                    Auditoria::registrar('ACESSO_NEGADO', [
                        'descricao'  => 'Tentativa de acesso a recurso fora do perfil',
                        'rota'       => $requisicao->caminho,
                        'severidade' => 'ALERTA',
                    ]);

                    throw ExcecaoHttp::naoAutorizado();
                }
            }

            $classe = 'App\\Controllers\\' . $rota['controlador'];

            if (!class_exists($classe)) {
                throw new ExcecaoHttp(500, 'Controlador não encontrado: ' . $rota['controlador']);
            }

            $controlador = new $classe($requisicao);

            if (!method_exists($controlador, $rota['acao'])) {
                throw new ExcecaoHttp(500, sprintf('Ação %s::%s não encontrada.', $rota['controlador'], $rota['acao']));
            }

            $controlador->{$rota['acao']}();

            return;
        }

        throw new ExcecaoHttp($caminhoEncontrado ? 405 : 404);
    }
}
