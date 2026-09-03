<?php

/**
 * =============================================================================
 * CREA Pro-Link | Mapa de rotas
 *
 * Cada rota declara o método HTTP, o caminho, o controlador, a ação e a lista
 * de perfis autorizados. Lista de perfis vazia significa acesso público.
 *
 * O roteador valida o token CSRF de toda requisição que altera estado e o
 * perfil do usuário antes de instanciar o controlador, de modo que nenhuma
 * ação protegida depende de o controlador se lembrar de verificar.
 *
 * Este arquivo é carregado por App\Core\Aplicacao, que expõe a variável
 * $roteador.
 * =============================================================================
 */

declare(strict_types=1);

/** @var App\Core\Roteador $roteador */

$TODOS_AUTENTICADOS = [PERFIL_ADMIN, PERFIL_PROFISSIONAL, PERFIL_EMPRESA, PERFIL_TERCEIRO];
$PRESTADORES        = [PERFIL_PROFISSIONAL, PERFIL_EMPRESA];
$CONTRATANTES       = [PERFIL_EMPRESA, PERFIL_TERCEIRO, PERFIL_ADMIN];
$SOMENTE_ADMIN      = [PERFIL_ADMIN];

// -----------------------------------------------------------------------------
// Área pública
// -----------------------------------------------------------------------------
$roteador->get('/',                        'ControladorInicio', 'inicio');
$roteador->get('/sobre',                   'ControladorPagina', 'sobre');
$roteador->get('/termos-de-uso',           'ControladorPagina', 'termosDeUso');
$roteador->get('/politica-de-privacidade', 'ControladorPagina', 'politicaDePrivacidade');
$roteador->get('/acessibilidade',          'ControladorPagina', 'acessibilidade');