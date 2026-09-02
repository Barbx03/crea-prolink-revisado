<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Trilha de auditoria em banco (itens 8.5.g e RF06).
 *
 * A tabela sis_auditoria e tratada como append only: registros nunca sao
 * alterados nem removidos pela aplicacao. Valores sensiveis sao removidos
 * antes da gravacao do estado anterior e posterior.
 */
final class Auditoria
{
    private const CAMPOS_OMITIDOS = [
        'usu_senha', 'senha', 'senha_atual', 'senha_confirmacao', 'password',
        'tok_hash', '_token', 'cfg_valor_sensivel', 'api_token', 'smtp_senha',
    ];

    /**
     * @param array{
     *     descricao?: string,
     *     entidade?: string,
     *     entidade_id?: int|string|null,
     *     antes?: array<string, mixed>|null,
     *     depois?: array<string, mixed>|null,
     *     usuario_id?: int|null,
     *     severidade?: string,
     *     rota?: string|null
     * } $contexto
     */
    public static function registrar(string $acao, array $contexto = []): void
    {
        try {
            $usuarioId = $contexto['usuario_id'] ?? Autenticacao::id();

            // A severidade é resolvida antes da consulta: ler a chave dentro do
            // ternário devolvia nulo quando o chamador não a informava, e a
            // coluna não aceita nulo — o evento se perdia silenciosamente.
            $severidade = $contexto['severidade'] ?? 'INFO';

            if (!in_array($severidade, ['INFO', 'ALERTA', 'CRITICO'], true)) {
                $severidade = 'INFO';
            }

            BancoDados::executar(
                'INSERT INTO sis_auditoria
                    (aud_usu_id, aud_acao, aud_entidade, aud_entidade_id, aud_descricao,
                     aud_dados_antes, aud_dados_depois, aud_ip_origem, aud_user_agent,
                     aud_rota, aud_severidade, aud_log)
                 VALUES
                    (:usuario, :acao, :entidade, :entidade_id, :descricao,
                     :antes, :depois, :ip, :agente,
                     :rota, :severidade, :log)',
                [
                    'usuario'     => $usuarioId > 0 ? $usuarioId : null,
                    'acao'        => substr($acao, 0, 60),
                    'entidade'    => isset($contexto['entidade']) ? substr((string) $contexto['entidade'], 0, 60) : null,
                    'entidade_id' => isset($contexto['entidade_id']) ? substr((string) $contexto['entidade_id'], 0, 40) : null,
                    'descricao'   => isset($contexto['descricao']) ? substr((string) $contexto['descricao'], 0, 500) : null,
                    'antes'       => self::serializar($contexto['antes'] ?? null),
                    'depois'      => self::serializar($contexto['depois'] ?? null),
                    'ip'          => self::ip(),
                    'agente'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    'rota'        => substr((string) ($contexto['rota'] ?? ($_SERVER['REQUEST_URI'] ?? '')), 0, 190),
                    'severidade'  => $severidade,
                    'log'         => null,
                ]
            );
        } catch (\Throwable $e) {
            // Auditoria nunca deve derrubar a operacao principal; o incidente
            // vai para o log em arquivo para investigacao posterior.
            Registro::erro('Falha ao gravar trilha de auditoria', [
                'acao' => $acao,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Registra alteracao comparando estados e omitindo campos iguais, para
     * que a trilha mostre exatamente o que mudou.
     *
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     */
    public static function alteracao(
        string $entidade,
        int|string $entidadeId,
        array $antes,
        array $depois,
        string $descricao = ''
    ): void {
        $diferencaAntes  = [];
        $diferencaDepois = [];

        foreach ($depois as $campo => $valorNovo) {
            $valorAntigo = $antes[$campo] ?? null;

            if ((string) $valorAntigo !== (string) $valorNovo) {
                $diferencaAntes[$campo]  = $valorAntigo;
                $diferencaDepois[$campo] = $valorNovo;
            }
        }

        if ($diferencaDepois === []) {
            return;
        }

        self::registrar('ATUALIZACAO', [
            'entidade'    => $entidade,
            'entidade_id' => $entidadeId,
            'descricao'   => $descricao !== '' ? $descricao : 'Alteracao de ' . $entidade,
            'antes'       => $diferencaAntes,
            'depois'      => $diferencaDepois,
        ]);
    }

    /**
     * @param array<string, mixed>|null $dados
     */
    private static function serializar(?array $dados): ?string
    {
        if ($dados === null || $dados === []) {
            return null;
        }

        foreach ($dados as $campo => $valor) {
            foreach (self::CAMPOS_OMITIDOS as $omitido) {
                if (stripos((string) $campo, $omitido) !== false) {
                    $dados[$campo] = '***';
                    continue 2;
                }
            }
        }

        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $cabecalho) {
            $valor = (string) ($_SERVER[$cabecalho] ?? '');

            if ($valor === '') {
                continue;
            }

            $primeiro = trim(explode(',', $valor)[0]);

            if (filter_var($primeiro, FILTER_VALIDATE_IP) !== false) {
                return $primeiro;
            }
        }

        return '0.0.0.0';
    }
}
