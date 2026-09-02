<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validacao de consistencia dos dados cadastrados na propria aplicacao
 * (item 6 do Termo de Referencia).
 *
 * Acumula erros por campo em vez de interromper na primeira falha, para que o
 * usuario receba de uma vez tudo o que precisa corrigir.
 */
final class Validador
{
    /** @var array<string, string> */
    private array $erros = [];

    /** @var array<string, mixed> */
    private array $dados;

    /** @param array<string, mixed> $dados */
    public function __construct(array $dados)
    {
        $this->dados = $dados;
    }

    /** @param array<string, mixed> $dados */
    public static function para(array $dados): self
    {
        return new self($dados);
    }

    public function obrigatorio(string $campo, string $rotulo): self
    {
        $valor = $this->valor($campo);

        if ($valor === '' || $valor === null || (is_array($valor) && $valor === [])) {
            $this->adicionar($campo, "Informe {$rotulo}.");
        }

        return $this;
    }

    public function minimo(string $campo, int $minimo, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor !== '' && mb_strlen($valor) < $minimo) {
            $this->adicionar($campo, "{$rotulo} deve ter ao menos {$minimo} caracteres.");
        }

        return $this;
    }

    public function maximo(string $campo, int $maximo, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor !== '' && mb_strlen($valor) > $maximo) {
            $this->adicionar($campo, "{$rotulo} deve ter no máximo {$maximo} caracteres.");
        }

        return $this;
    }

    public function email(string $campo = 'email'): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor !== '' && filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            $this->adicionar($campo, 'Informe um e-mail válido.');
        }

        return $this;
    }

    public function cpf(string $campo = 'cpf'): self
    {
        $valor = Formatador::somenteDigitos((string) $this->valor($campo));

        if ($valor !== '' && !self::cpfValido($valor)) {
            $this->adicionar($campo, 'CPF inválido.');
        }

        return $this;
    }

    public function cnpj(string $campo = 'cnpj'): self
    {
        $valor = Formatador::somenteDigitos((string) $this->valor($campo));

        if ($valor !== '' && !self::cnpjValido($valor)) {
            $this->adicionar($campo, 'CNPJ inválido.');
        }

        return $this;
    }

    public function senhaForte(string $campo = 'senha'): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor === '') {
            return $this;
        }

        if (mb_strlen($valor) < 8) {
            $this->adicionar($campo, 'A senha deve ter ao menos 8 caracteres.');

            return $this;
        }

        $criterios = 0;
        $criterios += preg_match('/[a-z]/', $valor);
        $criterios += preg_match('/[A-Z]/', $valor);
        $criterios += preg_match('/\d/', $valor);
        $criterios += preg_match('/[^a-zA-Z\d]/', $valor);

        if ($criterios < 3) {
            $this->adicionar(
                $campo,
                'A senha deve combinar ao menos três entre: letra minúscula, letra maiúscula, número e símbolo.'
            );
        }

        return $this;
    }

    public function confirmacao(string $campo, string $campoConfirmacao): self
    {
        $valor        = (string) $this->valor($campo);
        $confirmacao  = (string) $this->valor($campoConfirmacao);

        if ($valor !== '' && $valor !== $confirmacao) {
            $this->adicionar($campoConfirmacao, 'As senhas informadas não coincidem.');
        }

        return $this;
    }

    /** @param list<string> $permitidos */
    public function dentroDe(string $campo, array $permitidos, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor !== '' && !in_array($valor, $permitidos, true)) {
            $this->adicionar($campo, "Selecione um valor válido para {$rotulo}.");
        }

        return $this;
    }

    public function inteiroEntre(string $campo, int $minimo, int $maximo, string $rotulo): self
    {
        $valor = $this->valor($campo);

        if ($valor === '' || $valor === null) {
            return $this;
        }

        $numero = (int) $valor;

        if ($numero < $minimo || $numero > $maximo) {
            $this->adicionar($campo, "{$rotulo} deve estar entre {$minimo} e {$maximo}.");
        }

        return $this;
    }

    public function data(string $campo, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor === '') {
            return $this;
        }

        $data = \DateTimeImmutable::createFromFormat('Y-m-d', $valor);

        if ($data === false || $data->format('Y-m-d') !== $valor) {
            $this->adicionar($campo, "{$rotulo} deve ser uma data válida.");
        }

        return $this;
    }

    public function dataFutura(string $campo, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor === '') {
            return $this;
        }

        if (strtotime($valor) < strtotime(date('Y-m-d'))) {
            $this->adicionar($campo, "{$rotulo} não pode estar no passado.");
        }

        return $this;
    }

    public function intervaloDatas(string $campoInicio, string $campoFim): self
    {
        $inicio = (string) $this->valor($campoInicio);
        $fim    = (string) $this->valor($campoFim);

        if ($inicio !== '' && $fim !== '' && strtotime($fim) < strtotime($inicio)) {
            $this->adicionar($campoFim, 'A data final não pode ser anterior à data inicial.');
        }

        return $this;
    }

    public function intervaloValores(string $campoMinimo, string $campoMaximo, string $rotulo): self
    {
        $minimo = $this->valor($campoMinimo);
        $maximo = $this->valor($campoMaximo);

        if ($minimo !== null && $minimo !== '' && $maximo !== null && $maximo !== '' && (float) $maximo < (float) $minimo) {
            $this->adicionar($campoMaximo, "O valor máximo de {$rotulo} não pode ser menor que o mínimo.");
        }

        return $this;
    }

    public function url(string $campo, string $rotulo): self
    {
        $valor = (string) $this->valor($campo);

        if ($valor !== '' && filter_var($valor, FILTER_VALIDATE_URL) === false) {
            $this->adicionar($campo, "{$rotulo} deve ser um endereço válido, iniciando com http ou https.");
        }

        return $this;
    }

    public function uf(string $campo = 'uf'): self
    {
        $valor = strtoupper((string) $this->valor($campo));

        if ($valor !== '' && !in_array($valor, self::UNIDADES_FEDERATIVAS, true)) {
            $this->adicionar($campo, 'UF inválida.');
        }

        return $this;
    }

    public function aceito(string $campo, string $mensagem): self
    {
        $valor = (string) $this->valor($campo);

        if (!in_array($valor, ['1', 'on', 'true', 'S', 'sim'], true)) {
            $this->adicionar($campo, $mensagem);
        }

        return $this;
    }

    public function personalizado(string $campo, bool $condicaoValida, string $mensagem): self
    {
        if (!$condicaoValida) {
            $this->adicionar($campo, $mensagem);
        }

        return $this;
    }

    public function valido(): bool
    {
        return $this->erros === [];
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }

    public function primeiroErro(): ?string
    {
        return $this->erros === [] ? null : reset($this->erros);
    }

    private function adicionar(string $campo, string $mensagem): void
    {
        // Preserva o primeiro erro de cada campo, que e o mais especifico
        $this->erros[$campo] ??= $mensagem;
    }

    private function valor(string $campo): mixed
    {
        $valor = $this->dados[$campo] ?? null;

        return is_string($valor) ? trim($valor) : $valor;
    }

    public static function cpfValido(string $cpf): bool
    {
        $cpf = Formatador::somenteDigitos($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;

            for ($indice = 0; $indice < $posicao; $indice++) {
                $soma += (int) $cpf[$indice] * (($posicao + 1) - $indice);
            }

            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjValido(string $cnpj): bool
    {
        $cnpj = Formatador::somenteDigitos($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $calcular = static function (string $base, array $pesos): int {
            $soma = 0;

            foreach ($pesos as $indice => $peso) {
                $soma += (int) $base[$indice] * $peso;
            }

            $resto = $soma % 11;

            return $resto < 2 ? 0 : 11 - $resto;
        };

        $primeiro = $calcular(substr($cnpj, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        if ((int) $cnpj[12] !== $primeiro) {
            return false;
        }

        $segundo = $calcular(substr($cnpj, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return (int) $cnpj[13] === $segundo;
    }

    public const UNIDADES_FEDERATIVAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];
}
