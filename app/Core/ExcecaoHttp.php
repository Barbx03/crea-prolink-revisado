<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Excecao que carrega o codigo de status HTTP a ser devolvido ao cliente.
 */
class ExcecaoHttp extends RuntimeException
{
    public function __construct(
        public readonly int $codigoHttp,
        string $mensagem = '',
        public readonly ?string $rotaRetorno = null,
    ) {
        parent::__construct($mensagem !== '' ? $mensagem : self::mensagemPadrao($codigoHttp), $codigoHttp);
    }

    public static function naoEncontrado(string $mensagem = ''): self
    {
        return new self(404, $mensagem);
    }

    public static function naoAutenticado(string $mensagem = ''): self
    {
        return new self(401, $mensagem);
    }

    public static function naoAutorizado(string $mensagem = ''): self
    {
        return new self(403, $mensagem);
    }

    public static function requisicaoInvalida(string $mensagem = ''): self
    {
        return new self(400, $mensagem);
    }

    public static function tokenInvalido(): self
    {
        return new self(419, 'A página expirou por inatividade. Recarregue e tente novamente.');
    }

    private static function mensagemPadrao(int $codigo): string
    {
        return match ($codigo) {
            400 => 'Requisição inválida.',
            401 => 'É necessário entrar na plataforma para continuar.',
            403 => 'Você não tem permissão para acessar este recurso.',
            404 => 'Página não encontrada.',
            405 => 'Método não permitido para este endereço.',
            419 => 'A página expirou. Recarregue e tente novamente.',
            429 => 'Muitas tentativas. Aguarde alguns minutos.',
            default => 'Não foi possível concluir a operação.',
        };
    }
}
