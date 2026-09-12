<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auditoria;
use App\Core\Controlador;
use App\Core\Resposta;
use App\Repositories\RepositorioApiConsulta;
use App\Repositories\RepositorioAuditoria;

/**
 * Consulta e exportação da trilha de auditoria (RF06, cenário 7.6).
 */
final class ControladorAuditoria extends Controlador
{
    public function consultar(): void
    {
        $filtros = $this->filtros();

        $resultado = RepositorioAuditoria::consultar($filtros, $this->pagina(), $this->porPaginaAuditoria());

        $this->visao('admin/auditoria.twig', [
            'itens'       => $resultado['itens'],
            'paginacao'   => $this->paginacao($resultado['total'], null, $this->porPaginaAuditoria()),
            'filtros'     => $filtros,
            'acoes'       => RepositorioAuditoria::acoesRegistradas(),
            'indicadores' => RepositorioAuditoria::indicadores(),
            'resumo'      => RepositorioAuditoria::resumoPorAcao(30),
        ]);
    }

    public function exportar(): void
    {
        $filtros  = $this->filtros();
        $conteudo = RepositorioAuditoria::exportarCsv($filtros);

        Auditoria::registrar('EXPORTACAO_AUDITORIA', [
            'descricao'  => 'Trilha de auditoria exportada em CSV',
            'severidade' => 'ALERTA',
        ]);

        Resposta::arquivo(
            sprintf('prolink-auditoria-%s.csv', date('Y-m-d-His')),
            $conteudo,
            'text/csv'
        );
    }

    /**
     * Registro das consultas feitas à API oficial do CREA-AM (RF02).
     */
    public function integracao(): void
    {
        $filtros = [
            'recurso' => $this->requisicao->texto('recurso'),
            'sucesso' => $this->requisicao->texto('sucesso'),
        ];

        $resultado = RepositorioApiConsulta::listar(
            $filtros,
            $this->pagina(),
            $this->porPaginaAuditoria()
        );

        $this->visao('admin/integracao.twig', [
            'itens'     => $resultado['itens'],
            'paginacao' => $this->paginacao($resultado['total'], null, $this->porPaginaAuditoria()),
            'resumo'    => RepositorioApiConsulta::resumoPorRecurso(),
            'filtros'   => $filtros,
            'recursos'  => ['PROFISSIONAL', 'EMPRESA', 'ART', 'CAT'],
        ]);
    }
    /**
     * @return array<string, mixed>
     */
    private function filtros(): array
    {
        return [
            'acao'        => $this->requisicao->texto('acao'),
            'usuario_id'  => $this->requisicao->inteiro('usuario_id'),
            'entidade'    => $this->requisicao->texto('entidade'),
            'entidade_id' => $this->requisicao->texto('entidade_id'),
            'severidade'  => $this->requisicao->texto('severidade'),
            'data_inicio' => $this->requisicao->texto('data_inicio'),
            'data_fim'    => $this->requisicao->texto('data_fim'),
            'termo'       => $this->requisicao->texto('termo'),
        ];
    }

    private function porPaginaAuditoria(): int
    {
        return 40;
    }
}
