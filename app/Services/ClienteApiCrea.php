<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contrato de acesso a API oficial do desafio CREA-AM (RF02, item 8.4).
 *
 * As quatro consultas correspondem exatamente as previstas no Termo de
 * Referencia. Manter o contrato em uma interface permite trocar a
 * implementacao sem tocar em controladores ou repositorios.
 */
interface ClienteApiCrea
{
    /**
     * Profissional registrado, consultado por CPF.
     *
     * @return array{dados: array<string, mixed>, bruto: string}
     */
    public function profissionalPorCpf(string $cpf): array;

    /**
     * Empresa registrada, consultada por CNPJ.
     *
     * @return array{dados: array<string, mixed>, bruto: string}
     */
    public function empresaPorCnpj(string $cnpj): array;

    /**
     * ARTs do profissional, consultadas por RNP e numero da ART.
     *
     * @return array{dados: list<array<string, mixed>>, bruto: string}
     */
    public function artsPorRnp(string $rnp, ?string $numeroArt = null): array;

    /**
     * CATs do profissional, consultadas por RNP e numero da CAT.
     *
     * @return array{dados: list<array<string, mixed>>, bruto: string}
     */
    public function catsPorRnp(string $rnp, ?string $numeroCat = null): array;

    public function configurada(): bool;
}
