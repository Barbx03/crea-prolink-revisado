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

require dirname(__DIR__) . '/_config.php';

App\Core\Aplicacao::executar();
