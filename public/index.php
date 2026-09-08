<?php

/**
 * =============================================================================
 * CREA Pro-Link | Front controller
 *
 * Único ponto de entrada da aplicação. O servidor web deve apontar a raiz de
 * documentos para este diretório (public/), mantendo código-fonte, dependências
 * e arquivos de configuração fora do alcance direto do navegador — a segregação
 * exigida no item 8.5 do Termo de Referência.
 * =============================================================================
 */

declare(strict_types=1);

/*
 * Servidor embutido do PHP (php -S), usado apenas em desenvolvimento.
 *
 * Nesse modo o script atua como roteador de TODAS as requisições, inclusive
 * das que pedem arquivos estáticos. Devolver false entrega o arquivo ao próprio
 * servidor, replicando o que o Apache faz pelas regras de reescrita
 * (RewriteCond %{REQUEST_FILENAME} -f).
 *
 * Só arquivos existentes dentro de public/ são liberados: o caminho é
 * resolvido com realpath e comparado com este diretório, de modo que
 * ../../.env não escapa.
 */
if (PHP_SAPI === 'cli-server') {
    $caminhoPedido = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $arquivo       = realpath(__DIR__ . rawurldecode($caminhoPedido));

    if (
        $arquivo !== false
        && $arquivo !== __FILE__
        && is_file($arquivo)
        && str_starts_with($arquivo, __DIR__ . DIRECTORY_SEPARATOR)
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/_config.php';

App\Core\Aplicacao::executar();
