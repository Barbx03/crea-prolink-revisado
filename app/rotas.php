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

// -----------------------------------------------------------------------------
// Autenticação e recuperação de acesso (RF01)
// -----------------------------------------------------------------------------
$roteador->get('/entrar',                     'ControladorAutenticacao', 'formularioEntrar');
$roteador->post('/entrar',                    'ControladorAutenticacao', 'entrar');
$roteador->post('/sair',                      'ControladorAutenticacao', 'sair', $TODOS_AUTENTICADOS);
$roteador->get('/cadastrar',                  'ControladorAutenticacao', 'formularioCadastro');
$roteador->post('/cadastrar',                 'ControladorAutenticacao', 'cadastrar');
$roteador->post('/cadastrar/verificar-crea',  'ControladorAutenticacao', 'verificarNaApi');
$roteador->get('/recuperar-acesso',           'ControladorAutenticacao', 'formularioRecuperacao');
$roteador->post('/recuperar-acesso',          'ControladorAutenticacao', 'solicitarRecuperacao');
$roteador->get('/redefinir-senha/{token}',    'ControladorAutenticacao', 'formularioRedefinicao');
$roteador->post('/redefinir-senha',           'ControladorAutenticacao', 'redefinirSenha');
$roteador->get('/verificar-email/{token}',    'ControladorAutenticacao', 'verificarEmail');

// -----------------------------------------------------------------------------
// Painel do usuário autenticado
// -----------------------------------------------------------------------------
$roteador->get('/painel', 'ControladorPainel', 'painel', $TODOS_AUTENTICADOS);

// -----------------------------------------------------------------------------
// Perfil, portfólio e privacidade do titular (RF01 e RF03)
// -----------------------------------------------------------------------------
$roteador->get('/meu-perfil',                  'ControladorPerfil', 'ver',                  $TODOS_AUTENTICADOS);
$roteador->get('/meu-perfil/editar',           'ControladorPerfil', 'formularioEdicao',     $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil',                 'ControladorPerfil', 'salvar',               $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/foto',            'ControladorPerfil', 'enviarFoto',           $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/foto/remover',    'ControladorPerfil', 'removerFoto',          $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/dados-pessoais',  'ControladorPerfil', 'salvarDadosPessoais',  $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/senha',           'ControladorPerfil', 'alterarSenha',         $TODOS_AUTENTICADOS);