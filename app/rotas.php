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

// Busca de profissionais e perfis públicos (perfil "Público" do item 3)
$roteador->get('/profissionais',        'ControladorBusca', 'pesquisar');
$roteador->get('/profissionais/{id}',   'ControladorBusca', 'verPerfil');

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

// Privacidade e direitos do titular (LGPD)
$roteador->get('/privacidade',                   'ControladorPrivacidade', 'painel',              $TODOS_AUTENTICADOS);
$roteador->post('/privacidade/visibilidade',     'ControladorPrivacidade', 'salvarVisibilidade',  $TODOS_AUTENTICADOS);
$roteador->post('/privacidade/consentimentos',   'ControladorPrivacidade', 'salvarConsentimentos', $TODOS_AUTENTICADOS);
$roteador->get('/privacidade/exportar',          'ControladorPrivacidade', 'exportarDados',       $TODOS_AUTENTICADOS);
$roteador->post('/privacidade/solicitacoes',     'ControladorPrivacidade', 'abrirSolicitacao',    $TODOS_AUTENTICADOS);
$roteador->post('/privacidade/encerrar-conta',   'ControladorPrivacidade', 'encerrarConta',       $TODOS_AUTENTICADOS);

// -----------------------------------------------------------------------------
// Demandas do autor e compatibilização (RF04)
// -----------------------------------------------------------------------------
$roteador->get('/minhas-demandas',                 'ControladorDemanda', 'minhas',            $CONTRATANTES);
$roteador->get('/demandas/{id}/editar',            'ControladorDemanda', 'formularioEdicao',  $CONTRATANTES);
$roteador->post('/demandas/{id}',                  'ControladorDemanda', 'atualizar',         $CONTRATANTES);
$roteador->post('/demandas/{id}/publicar',         'ControladorDemanda', 'publicar',          $CONTRATANTES);
$roteador->post('/demandas/{id}/encerrar',         'ControladorDemanda', 'encerrar',          $CONTRATANTES);
$roteador->post('/demandas/{id}/remover',          'ControladorDemanda', 'remover',           $CONTRATANTES);
$roteador->get('/demandas/{id}/correspondencias',  'ControladorDemanda', 'correspondencias',  $CONTRATANTES);
$roteador->get('/demandas/{id}/interessados',      'ControladorDemanda', 'interessados',      $CONTRATANTES);

// -----------------------------------------------------------------------------
// Manifestação de interesse e comunicação (RF05)
// -----------------------------------------------------------------------------
$roteador->get('/demandas/{id}/manifestar',     'ControladorInteresse', 'formulario', $PRESTADORES);
$roteador->post('/demandas/{id}/manifestar',    'ControladorInteresse', 'manifestar', $PRESTADORES);
$roteador->get('/meus-interesses',              'ControladorInteresse', 'meus',       $PRESTADORES);
$roteador->post('/interesses/{id}/retirar',     'ControladorInteresse', 'retirar',    $PRESTADORES);
$roteador->post('/interesses/{id}/responder',   'ControladorInteresse', 'responder',  $CONTRATANTES);
$roteador->post('/interesses/{id}/visualizar',  'ControladorInteresse', 'visualizar', $CONTRATANTES);

$roteador->get('/mensagens',                'ControladorMensagem', 'caixaDeEntrada', $TODOS_AUTENTICADOS);
$roteador->get('/mensagens/{id}',           'ControladorMensagem', 'conversa',       $TODOS_AUTENTICADOS);
$roteador->post('/mensagens/{id}',          'ControladorMensagem', 'enviar',         $TODOS_AUTENTICADOS);
$roteador->post('/mensagens/iniciar',       'ControladorMensagem', 'iniciar',        $TODOS_AUTENTICADOS);

// Notificações (RF07)
$roteador->get('/notificacoes',                  'ControladorNotificacao', 'central',        $TODOS_AUTENTICADOS);
$roteador->post('/notificacoes/{id}/lida',       'ControladorNotificacao', 'marcarLida',     $TODOS_AUTENTICADOS);
$roteador->post('/notificacoes/marcar-todas',    'ControladorNotificacao', 'marcarTodas',    $TODOS_AUTENTICADOS);

// Denúncias (RF06)
$roteador->get('/denunciar/{entidade}/{id}', 'ControladorDenuncia', 'formulario', $TODOS_AUTENTICADOS);
$roteador->post('/denunciar',                'ControladorDenuncia', 'registrar',  $TODOS_AUTENTICADOS);

// -----------------------------------------------------------------------------
// Painel administrativo (RF06)
// -----------------------------------------------------------------------------
$roteador->get('/admin',            'Admin\ControladorPainelAdmin', 'painel',      $SOMENTE_ADMIN);
$roteador->get('/admin/indicadores', 'Admin\ControladorPainelAdmin', 'indicadores', $SOMENTE_ADMIN);

$roteador->get('/admin/usuarios',                  'Admin\ControladorUsuarios', 'listar',      $SOMENTE_ADMIN);
$roteador->get('/admin/usuarios/{id}',             'Admin\ControladorUsuarios', 'ver',         $SOMENTE_ADMIN);
$roteador->post('/admin/usuarios/{id}/bloquear',   'Admin\ControladorUsuarios', 'bloquear',    $SOMENTE_ADMIN);
$roteador->post('/admin/usuarios/{id}/desbloquear', 'Admin\ControladorUsuarios', 'desbloquear', $SOMENTE_ADMIN);
$roteador->post('/admin/usuarios/{id}/perfil',     'Admin\ControladorUsuarios', 'alterarPerfil', $SOMENTE_ADMIN);
$roteador->post('/admin/usuarios/{id}/excluir',    'Admin\ControladorUsuarios', 'excluir',     $SOMENTE_ADMIN);

$roteador->get('/admin/moderacao',                    'Admin\ControladorModeracao', 'painel',        $SOMENTE_ADMIN);
$roteador->get('/admin/moderacao/denuncias',          'Admin\ControladorModeracao', 'denuncias',     $SOMENTE_ADMIN);
$roteador->get('/admin/moderacao/denuncias/{id}',     'Admin\ControladorModeracao', 'verDenuncia',   $SOMENTE_ADMIN);
$roteador->post('/admin/moderacao/denuncias/{id}',    'Admin\ControladorModeracao', 'julgarDenuncia', $SOMENTE_ADMIN);
$roteador->get('/admin/moderacao/demandas',           'Admin\ControladorModeracao', 'demandas',      $SOMENTE_ADMIN);
$roteador->post('/admin/moderacao/demandas/{id}',     'Admin\ControladorModeracao', 'moderarDemanda', $SOMENTE_ADMIN);
$roteador->get('/admin/moderacao/perfis',             'Admin\ControladorModeracao', 'perfis',        $SOMENTE_ADMIN);
$roteador->post('/admin/moderacao/perfis/{id}',       'Admin\ControladorModeracao', 'moderarPerfil', $SOMENTE_ADMIN);

$roteador->get('/admin/auditoria',          'Admin\ControladorAuditoria', 'consultar', $SOMENTE_ADMIN);
$roteador->get('/admin/auditoria/exportar', 'Admin\ControladorAuditoria', 'exportar',  $SOMENTE_ADMIN);
$roteador->get('/admin/auditoria/api',      'Admin\ControladorAuditoria', 'integracao', $SOMENTE_ADMIN);

$roteador->get('/admin/configuracoes',                'Admin\ControladorConfiguracoes', 'painel',      $SOMENTE_ADMIN);
$roteador->post('/admin/configuracoes/{grupo}',       'Admin\ControladorConfiguracoes', 'salvar',      $SOMENTE_ADMIN);
$roteador->post('/admin/configuracoes/smtp/testar',   'Admin\ControladorConfiguracoes', 'testarSmtp',  $SOMENTE_ADMIN);
$roteador->post('/admin/configuracoes/api/testar',    'Admin\ControladorConfiguracoes', 'testarApi',   $SOMENTE_ADMIN);

$roteador->get('/admin/notificacoes',             'Admin\ControladorNotificacoes', 'listar',        $SOMENTE_ADMIN);
$roteador->post('/admin/notificacoes/processar',  'Admin\ControladorNotificacoes', 'processarFila', $SOMENTE_ADMIN);

$roteador->get('/admin/lgpd',                  'Admin\ControladorLgpd', 'solicitacoes',      $SOMENTE_ADMIN);
$roteador->get('/admin/lgpd/{id}',             'Admin\ControladorLgpd', 'verSolicitacao',    $SOMENTE_ADMIN);
$roteador->post('/admin/lgpd/{id}',            'Admin\ControladorLgpd', 'tratarSolicitacao', $SOMENTE_ADMIN);
$roteador->post('/admin/lgpd/{id}/anonimizar', 'Admin\ControladorLgpd', 'anonimizar',        $SOMENTE_ADMIN);

$roteador->get('/admin/lixeira',                          'Admin\ControladorLixeira', 'listar',    $SOMENTE_ADMIN);
$roteador->post('/admin/lixeira/{entidade}/{id}/restaurar', 'Admin\ControladorLixeira', 'restaurar', $SOMENTE_ADMIN);

// -----------------------------------------------------------------------------
// Endpoints JSON de apoio à interface
// -----------------------------------------------------------------------------
$roteador->get('/api/competencias',              'Api\ControladorApoio', 'competencias');
$roteador->get('/api/municipios/{uf}',           'Api\ControladorApoio', 'municipios');
$roteador->get('/api/aderencia/{demanda}',       'Api\ControladorApoio', 'aderencia', $PRESTADORES);
