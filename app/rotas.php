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

// Demandas públicas
$roteador->get('/demandas',             'ControladorDemanda', 'listar');
$roteador->get('/demandas/nova',        'ControladorDemanda', 'formularioNova', $CONTRATANTES);
$roteador->post('/demandas',            'ControladorDemanda', 'criar',          $CONTRATANTES);
$roteador->get('/demandas/{id}',        'ControladorDemanda', 'ver');

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
$roteador->post('/meu-perfil/competencias',    'ControladorPerfil', 'salvarCompetencias',   $PRESTADORES);
$roteador->post('/meu-perfil/foto',            'ControladorPerfil', 'enviarFoto',           $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/foto/remover',    'ControladorPerfil', 'removerFoto',          $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/dados-pessoais',  'ControladorPerfil', 'salvarDadosPessoais',  $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/senha',           'ControladorPerfil', 'alterarSenha',         $TODOS_AUTENTICADOS);

// Validação do registro na API oficial (RF02)
$roteador->get('/meu-perfil/registro-crea',    'ControladorPortfolio', 'painelIntegracao', $TODOS_AUTENTICADOS);
$roteador->post('/meu-perfil/registro-crea',   'ControladorPortfolio', 'validarRegistro',  $TODOS_AUTENTICADOS);

// Portfólio: ARTs e CATs (RF03)
$roteador->post('/meu-perfil/arts',                    'ControladorPortfolio', 'associarArt',      $PRESTADORES);
$roteador->post('/meu-perfil/arts/{id}/visibilidade',  'ControladorPortfolio', 'alternarArt',      $PRESTADORES);
$roteador->post('/meu-perfil/arts/{id}/destaque',      'ControladorPortfolio', 'destacarArt',      $PRESTADORES);
$roteador->post('/meu-perfil/arts/{id}/remover',       'ControladorPortfolio', 'removerArt',       $PRESTADORES);
$roteador->post('/meu-perfil/arts/revalidar',          'ControladorPortfolio', 'revalidarArts',    $PRESTADORES);
$roteador->post('/meu-perfil/cats/sincronizar',        'ControladorPortfolio', 'sincronizarCats',  $PRESTADORES);
$roteador->post('/meu-perfil/cats/{id}/visibilidade',  'ControladorPortfolio', 'alternarCat',      $PRESTADORES);

// Experiências profissionais (RF03)
$roteador->get('/meu-perfil/experiencias/nova',      'ControladorExperiencia', 'formularioNova', $PRESTADORES);
$roteador->post('/meu-perfil/experiencias',          'ControladorExperiencia', 'criar',          $PRESTADORES);
$roteador->get('/meu-perfil/experiencias/{id}',      'ControladorExperiencia', 'formularioEdicao', $PRESTADORES);
$roteador->post('/meu-perfil/experiencias/{id}',     'ControladorExperiencia', 'atualizar',      $PRESTADORES);
$roteador->post('/meu-perfil/experiencias/{id}/remover', 'ControladorExperiencia', 'remover',    $PRESTADORES);

// -----------------------------------------------------------------------------
// Demandas do autor e compatibilização (RF04)
// -----------------------------------------------------------------------------
$roteador->get('/minhas-demandas',                 'ControladorDemanda', 'minhas',            $CONTRATANTES);
$roteador->get('/demandas/{id}/editar',            'ControladorDemanda', 'formularioEdicao',  $CONTRATANTES);
$roteador->post('/demandas/{id}',                  'ControladorDemanda', 'atualizar',         $CONTRATANTES);
$roteador->post('/demandas/{id}/publicar',         'ControladorDemanda', 'publicar',          $CONTRATANTES);
$roteador->post('/demandas/{id}/encerrar',         'ControladorDemanda', 'encerrar',          $CONTRATANTES);
$roteador->post('/demandas/{id}/remover',          'ControladorDemanda', 'remover',           $CONTRATANTES);