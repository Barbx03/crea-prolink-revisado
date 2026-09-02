<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base dos controladores (camada de apresentacao do MVC).
 *
 * Controladores nao contem SQL nem HTML: delegam a persistencia aos
 * repositorios, as regras aos servicos, e a renderizacao ao Twig.
 */
abstract class Controlador
{
    public function __construct(protected readonly Requisicao $requisicao)
    {
    }

    /**
     * @param array<string, mixed> $dados
     */
    protected function visao(string $template, array $dados = [], int $codigo = 200): void
    {
        $dados['erros']      ??= Sessao::consumirErros();
        $dados['formulario'] ??= Sessao::consumirFormulario();

        Resposta::html(Visao::renderizar($template, $dados), $codigo);
    }

    /**
     * @param array<string, mixed>|list<mixed> $dados
     */
    protected function json(array $dados, int $codigo = 200): void
    {
        Resposta::json($dados, $codigo);
    }

    protected function redirecionar(string $caminho): void
    {
        Resposta::redirecionar($caminho);
    }

    /**
     * Volta para o formulario preservando o que o usuario digitou.
     *
     * @param array<string, string> $erros
     */
    protected function voltarComErros(string $caminho, array $erros, ?string $mensagem = null): void
    {
        Sessao::guardarFormulario($this->requisicao->todos(), $erros);

        if ($mensagem !== null) {
            Sessao::erro($mensagem);
        } elseif ($erros !== []) {
            Sessao::erro('Verifique os campos destacados e tente novamente.');
        }

        $this->redirecionar($caminho);
    }

    protected function usuarioId(): int
    {
        return Autenticacao::id();
    }

    /**
     * @return array<string, mixed>
     */
    protected function usuarioAutenticado(): array
    {
        $usuario = Autenticacao::registroCompleto();

        if ($usuario === null) {
            throw ExcecaoHttp::naoAutenticado();
        }

        return $usuario;
    }

    /**
     * Garante que o registro pertence ao usuario autenticado, salvo quando ele
     * e administrador. Evita acesso horizontal indevido a dados de terceiros.
     */
    protected function exigirPropriedade(int $usuarioDono): void
    {
        if (Autenticacao::ehAdministrador()) {
            return;
        }

        if ($usuarioDono !== $this->usuarioId()) {
            Auditoria::registrar('ACESSO_NEGADO', [
                'descricao'  => 'Tentativa de acesso a registro de outro usuário',
                'severidade' => 'ALERTA',
            ]);

            throw ExcecaoHttp::naoAutorizado();
        }
    }

    protected function pagina(): int
    {
        return max(1, (int) $this->requisicao->inteiro('pagina', 1));
    }

    protected function porPagina(): int
    {
        return Configuracao::inteiro('PLATAFORMA', 'itens_por_pagina', 12);
    }

    /**
     * Dados de paginacao para a barra de navegacao das listagens.
     *
     * @return array{pagina: int, por_pagina: int, total: int, paginas: int, primeiro: int, ultimo: int}
     */
    protected function paginacao(int $total, ?int $pagina = null, ?int $porPagina = null): array
    {
        $pagina    = $pagina ?? $this->pagina();
        $porPagina = $porPagina ?? $this->porPagina();
        $paginas   = max(1, (int) ceil($total / max(1, $porPagina)));
        $pagina    = min($pagina, $paginas);

        return [
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
            'total'      => $total,
            'paginas'    => $paginas,
            'primeiro'   => $total === 0 ? 0 : (($pagina - 1) * $porPagina) + 1,
            'ultimo'     => min($pagina * $porPagina, $total),
        ];
    }
}
