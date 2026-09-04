<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Falha na comunicacao com a API oficial do desafio.
 *
 * A aplicacao nunca substitui um erro da API por dado inventado: a excecao
 * sobe ate a interface, que informa a indisponibilidade ao usuario.
 */
final class ExcecaoApiCrea extends RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly int $httpStatus = 0,
        public readonly string $recurso = '',
        public readonly bool $naoEncontrado = false,
    ) {
        parent::__construct($mensagem);
    }

    public static function naoConfigurada(): self
    {
        return new self(
            'A integração com a API oficial do CREA-AM não esta configurada nesta instalacao. '
            . 'Defina CREA_API_URL e CREA_API_TOKEN no arquivo .env, ou informe-os no painel administrativo.',
            0,
            '',
            false
        );
    }

    public static function registroNaoEncontrado(string $recurso): self
    {
        return new self(
            'A consulta foi realizada, mas a API oficial não retornou registro para os dados informados.',
            404,
            $recurso,
            true
        );
    }
}
