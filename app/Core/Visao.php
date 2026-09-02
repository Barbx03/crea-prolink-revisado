<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Camada de apresentacao sobre o Twig.
 *
 * O Twig escapa toda variavel impressa por padrao, o que atende a protecao
 * contra XSS exigida no item 8.5.d. Nenhuma view contem PHP, conforme o
 * item 8.2 do Termo de Referencia.
 */
final class Visao
{
    private static ?Environment $twig = null;

    public static function twig(): Environment
    {
        if (self::$twig instanceof Environment) {
            return self::$twig;
        }

        $carregador = new FilesystemLoader(PATH_VIEWS);

        $twig = new Environment($carregador, [
            'cache'            => APP_DEBUG ? false : PATH_CACHE . '/twig',
            'debug'            => APP_DEBUG,
            'strict_variables' => false,
            'autoescape'       => 'html',
            'charset'          => 'UTF-8',
        ]);

        if (APP_DEBUG) {
            $twig->addExtension(new DebugExtension());
        }

        self::registrarGlobais($twig);
        self::registrarFuncoes($twig);
        self::registrarFiltros($twig);

        return self::$twig = $twig;
    }

    /** @param array<string, mixed> $dados */
    public static function renderizar(string $template, array $dados = []): string
    {
        return self::twig()->render($template, $dados);
    }

    private static function registrarGlobais(Environment $twig): void
    {
        $twig->addGlobal('app', [
            'nome'     => APP_NOME,
            'url'      => APP_URL,
            'versao'   => APP_VERSAO,
            'ambiente' => APP_AMBIENTE,
            'debug'    => APP_DEBUG,
            'ano'      => (int) date('Y'),
        ]);
    }

    private static function registrarFuncoes(Environment $twig): void
    {
        $twig->addFunction(new TwigFunction('url', static function (string $caminho = ''): string {
            return APP_URL . '/' . ltrim($caminho, '/');
        }));

        $twig->addFunction(new TwigFunction('asset', static function (string $caminho): string {
            $absoluto = PATH_PUBLIC . '/assets/' . ltrim($caminho, '/');
            $versao   = is_file($absoluto) ? (string) filemtime($absoluto) : APP_VERSAO;

            return URL_ASSETS . '/' . ltrim($caminho, '/') . '?v=' . $versao;
        }));

        // Reconstrói a querystring atual trocando apenas o número da página,
        // para que a paginação preserve os filtros aplicados pelo usuário.
        $twig->addFunction(new TwigFunction('query_pagina', static function (int $pagina): string {
            $parametros = Requisicao::consultaCorrente();
            $parametros['pagina'] = max(1, $pagina);

            return http_build_query($parametros);
        }));

        // Querystring atual com parâmetros adicionais ou substituídos.
        $twig->addFunction(new TwigFunction('query_com', static function (array $novos = []): string {
            $parametros = array_merge(Requisicao::consultaCorrente(), $novos);
            unset($parametros['pagina']);

            $parametros = array_filter(
                $parametros,
                static fn ($valor): bool => $valor !== '' && $valor !== null && $valor !== []
            );

            return http_build_query($parametros);
        }));

        $twig->addFunction(new TwigFunction('campo_csrf', static function (): string {
            return Csrf::campo();
        }, ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('token_csrf', static fn (): string => Csrf::token()));

        $twig->addFunction(new TwigFunction('usuario', static function (): ?array {
            return Autenticacao::usuario();
        }));

        $twig->addFunction(new TwigFunction('autenticado', static fn (): bool => Autenticacao::autenticado()));

        $twig->addFunction(new TwigFunction('tem_perfil', static function (string ...$perfis): bool {
            return Autenticacao::temPerfil($perfis);
        }));

        $twig->addFunction(new TwigFunction('alertas', static function (): array {
            return Sessao::consumirAlertas();
        }));

        $twig->addFunction(new TwigFunction('nao_lidas', static function (): int {
            if (!Autenticacao::autenticado()) {
                return 0;
            }

            return \App\Repositories\RepositorioNotificacao::contarNaoLidas(Autenticacao::id());
        }));
    }

    private static function registrarFiltros(Environment $twig): void
    {
        $twig->addFilter(new TwigFilter('data', static function (?string $valor, string $formato = 'd/m/Y'): string {
            if ($valor === null || $valor === '' || str_starts_with($valor, '0000')) {
                return '';
            }

            $data = date_create($valor);

            return $data === false ? '' : $data->format($formato);
        }));

        $twig->addFilter(new TwigFilter('data_hora', static function (?string $valor): string {
            if ($valor === null || $valor === '') {
                return '';
            }

            $data = date_create($valor);

            return $data === false ? '' : $data->format('d/m/Y H:i');
        }));

        $twig->addFilter(new TwigFilter('moeda', static function (float|int|string|null $valor): string {
            if ($valor === null || $valor === '') {
                return '';
            }

            return 'R$ ' . number_format((float) $valor, 2, ',', '.');
        }));

        $twig->addFilter(new TwigFilter('numero', static function (float|int|string|null $valor, int $decimais = 0): string {
            return number_format((float) $valor, $decimais, ',', '.');
        }));

        $twig->addFilter(new TwigFilter('documento', static function (?string $valor): string {
            return Formatador::documentoMascarado($valor);
        }));

        $twig->addFilter(new TwigFilter('telefone', static function (?string $valor): string {
            return Formatador::telefone($valor);
        }));

        $twig->addFilter(new TwigFilter('rotulo', static function (?string $valor): string {
            return Formatador::rotulo($valor);
        }));

        $twig->addFilter(new TwigFilter('desde', static function (?string $valor): string {
            return Formatador::tempoRelativo($valor);
        }));
    }
}
