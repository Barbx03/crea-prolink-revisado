<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Implementacao usada quando a integracao esta desligada (CREA_API_DRIVER=offline).
 *
 * Existe para permitir o desenvolvimento local de telas que nao dependem da
 * integracao. Ela nao devolve dado nenhum: recusa a consulta com mensagem
 * explicita. A criacao de base propria para simular os dados da API oficial e
 * vedada pelo item 8.4 do Termo de Referencia, e este projeto nao a faz em
 * nenhum ponto.
 */
final class ClienteApiCreaIndisponivel implements ClienteApiCrea
{
    public function configurada(): bool
    {
        return false;
    }

    /** @return array{dados: array<string, mixed>, bruto: string} */
    public function profissionalPorCpf(string $cpf): array
    {
        throw $this->recusar();
    }

    /** @return array{dados: array<string, mixed>, bruto: string} */
    public function empresaPorCnpj(string $cnpj): array
    {
        throw $this->recusar();
    }

    /** @return array{dados: list<array<string, mixed>>, bruto: string} */
    public function artsPorRnp(string $rnp, ?string $numeroArt = null): array
    {
        throw $this->recusar();
    }

    /** @return array{dados: list<array<string, mixed>>, bruto: string} */
    public function catsPorRnp(string $rnp, ?string $numeroCat = null): array
    {
        throw $this->recusar();
    }

    private function recusar(): ExcecaoApiCrea
    {
        return new ExcecaoApiCrea(
            'A integração com a API oficial esta desligada nesta instalacao '
            . '(CREA_API_DRIVER=offline). Defina CREA_API_DRIVER=http com a URL e o '
            . 'token do desafio para consultar registros, ARTs e CATs.',
            0,
            ''
        );
    }
}
