<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Ponto de entrada da aplicacao: inicializa a sessao, aplica os cabecalhos de
 * seguranca, resolve a rota e converte qualquer excecao em uma resposta
 * apropriada, sem vazar detalhes internos em producao.
 */
final class Aplicacao
{
    public static function executar(): void
    {
        Sessao::iniciar();
        Resposta::cabecalhosSeguranca();

        $requisicao = Requisicao::capturar();

        try {
            Autenticacao::revalidar();

            $roteador = new Roteador();
            require PATH_APP . '/rotas.php';

            $roteador->despachar($requisicao);
        } catch (ExcecaoHttp $e) {
            self::responderErro($requisicao, $e->codigoHttp, $e->getMessage(), $e);
        } catch (Throwable $e) {
            Registro::critico('Erro não tratado na aplicacao', [
                'mensagem' => $e->getMessage(),
                'arquivo'  => $e->getFile() . ':' . $e->getLine(),
                'rota'     => $requisicao->caminho,
            ]);

            self::responderErro(
                $requisicao,
                500,
                APP_DEBUG ? $e->getMessage() : 'Ocorreu um erro inesperado. A equipe técnica foi notificada.',
                $e
            );
        }
    }

    private static function responderErro(Requisicao $requisicao, int $codigo, string $mensagem, Throwable $e): void
    {
        if ($requisicao->esperaJson()) {
            Resposta::json([
                'sucesso'  => false,
                'mensagem' => $mensagem,
                'codigo'   => $codigo,
            ], $codigo);

            return;
        }

        // Nao autenticado em navegacao normal: manda para o login preservando
        // o destino pretendido
        if ($codigo === 401) {
            Sessao::definir('_destino_pretendido', $requisicao->caminho);
            Sessao::aviso($mensagem);
            Resposta::redirecionar('/entrar');

            return;
        }

        try {
            Resposta::html(
                Visao::renderizar('erros/erro.twig', [
                    'codigo'    => $codigo,
                    'mensagem'  => $mensagem,
                    'detalhe'   => APP_DEBUG ? $e->getFile() . ':' . $e->getLine() : null,
                    'rastro'    => APP_DEBUG ? $e->getTraceAsString() : null,
                ]),
                $codigo
            );
        } catch (Throwable $falhaRender) {
            // Ultimo recurso: nem o template de erro pode ser renderizado
            http_response_code($codigo);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $mensagem;
        }
    }
}
