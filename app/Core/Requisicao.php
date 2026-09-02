<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Representacao da requisicao HTTP recebida.
 *
 * Toda leitura de entrada do usuario passa por aqui, com sanitizacao de
 * controle (remocao de bytes nulos e normalizacao de espacos). O escape
 * contra XSS acontece na saida, na camada de template (item 8.5.d).
 */
final class Requisicao
{
    /** @var array<string, mixed> */
    private array $rota = [];

    /**
     * Parametros de consulta da requisicao corrente, guardados para que a
     * camada de apresentacao possa remontar a querystring dos links de
     * paginacao e filtro sem ler a entrada bruta.
     *
     * @var array<string, mixed>
     */
    private static array $consultaCorrente = [];

    private function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        /** @var array<string, mixed> */
        public readonly array $consulta,
        /** @var array<string, mixed> */
        public readonly array $corpo,
        /** @var array<string, mixed> */
        public readonly array $arquivos,
        public readonly string $ip,
        public readonly string $agenteUsuario,
    ) {
    }

    public static function capturar(): self
    {
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $caminho = parse_url($uri, PHP_URL_PATH) ?: '/';
        $caminho = '/' . trim(rawurldecode($caminho), '/');

        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Suporte a metodos alem de GET/POST em formularios HTML
        if ($metodo === 'POST' && isset($_POST['_metodo'])) {
            $sobrescrito = strtoupper((string) $_POST['_metodo']);
            if (in_array($sobrescrito, ['PUT', 'PATCH', 'DELETE'], true)) {
                $metodo = $sobrescrito;
            }
        }

        self::$consultaCorrente = self::limpar($_GET);

        return new self(
            metodo: $metodo,
            caminho: $caminho === '/' ? '/' : rtrim($caminho, '/'),
            consulta: self::$consultaCorrente,
            corpo: self::limpar($_POST),
            arquivos: $_FILES,
            ip: self::descobrirIp(),
            agenteUsuario: substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        );
    }

    /** @param array<string, mixed> $parametros */
    public function definirParametrosRota(array $parametros): void
    {
        $this->rota = $parametros;
    }

    public function parametro(string $nome, ?string $padrao = null): ?string
    {
        $valor = $this->rota[$nome] ?? $padrao;

        return $valor === null ? null : (string) $valor;
    }

    public function parametroInteiro(string $nome, int $padrao = 0): int
    {
        return (int) ($this->rota[$nome] ?? $padrao);
    }

    public function texto(string $nome, string $padrao = ''): string
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? $padrao;

        return is_scalar($valor) ? trim((string) $valor) : $padrao;
    }

    public function inteiro(string $nome, ?int $padrao = null): ?int
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? null;

        if ($valor === null || $valor === '') {
            return $padrao;
        }

        return (int) $valor;
    }

    public function decimal(string $nome, ?float $padrao = null): ?float
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? null;

        if ($valor === null || $valor === '') {
            return $padrao;
        }

        // Aceita formato brasileiro (1.234,56) e internacional (1234.56)
        $normalizado = (string) $valor;
        if (str_contains($normalizado, ',')) {
            $normalizado = str_replace(['.', ','], ['', '.'], $normalizado);
        }

        return (float) $normalizado;
    }

    public function booleano(string $nome): bool
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? null;

        return in_array((string) $valor, ['1', 'on', 'true', 'S', 's', 'sim'], true);
    }

    /**
     * Booleano no formato de armazenamento do banco: 'S' ou 'N'.
     */
    public function flag(string $nome): string
    {
        return $this->booleano($nome) ? 'S' : 'N';
    }

    /** @return list<string> */
    public function lista(string $nome): array
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? [];

        if (!is_array($valor)) {
            return $valor === '' ? [] : [(string) $valor];
        }

        return array_values(array_map(static fn ($item) => (string) $item, $valor));
    }

    /** @return list<int> */
    public function listaInteiros(string $nome): array
    {
        return array_values(array_filter(
            array_map('intval', $this->lista($nome)),
            static fn (int $item) => $item > 0
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function consultaCorrente(): array
    {
        return self::$consultaCorrente;
    }

    /** @return array<string, mixed> */
    public function todos(): array
    {
        return array_merge($this->consulta, $this->corpo);
    }

    public function tokenCsrf(): ?string
    {
        $token = $this->corpo['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        return is_string($token) ? $token : null;
    }

    public function alteraEstado(): bool
    {
        return in_array($this->metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function esperaJson(): bool
    {
        $aceita = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $ajax   = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

        return str_contains($aceita, 'application/json')
            || strcasecmp($ajax, 'XMLHttpRequest') === 0
            || str_starts_with($this->caminho, '/api/');
    }

    /**
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    private static function limpar(array $dados): array
    {
        $limpos = [];

        foreach ($dados as $chave => $valor) {
            $chave = str_replace("\0", '', (string) $chave);

            if (is_array($valor)) {
                $limpos[$chave] = self::limpar($valor);
                continue;
            }

            if (is_scalar($valor)) {
                $texto = str_replace("\0", '', (string) $valor);
                // Remove caracteres de controle, exceto tabulacao e quebras de linha
                $limpos[$chave] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '';
                continue;
            }

            $limpos[$chave] = $valor;
        }

        return $limpos;
    }

    private static function descobrirIp(): string
    {
        // Em producao atras de proxy reverso, confie apenas no cabecalho
        // repassado pela infraestrutura do CREA-AM.
        foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $cabecalho) {
            $valor = $_SERVER[$cabecalho] ?? '';

            if ($valor === '') {
                continue;
            }

            $primeiro = trim(explode(',', (string) $valor)[0]);

            if (filter_var($primeiro, FILTER_VALIDATE_IP) !== false) {
                return $primeiro;
            }
        }

        return '0.0.0.0';
    }
}
