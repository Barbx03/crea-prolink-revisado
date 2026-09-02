<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Formatacao e mascaramento de valores para exibicao.
 *
 * O mascaramento de CPF e CNPJ implementa a minimizacao de dados prevista na
 * LGPD: mesmo quando o titular autoriza exibir o documento, apenas parte dele
 * chega a tela.
 */
final class Formatador
{
    public static function somenteDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    public static function cpf(?string $valor): string
    {
        $digitos = self::somenteDigitos($valor);

        if (strlen($digitos) !== 11) {
            return (string) $valor;
        }

        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos) ?? $digitos;
    }

    public static function cnpj(?string $valor): string
    {
        $digitos = self::somenteDigitos($valor);

        if (strlen($digitos) !== 14) {
            return (string) $valor;
        }

        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos) ?? $digitos;
    }

    /**
     * Documento parcialmente oculto, preservando apenas o inicio e o final
     * dos digitos, conforme a minimizacao de dados prevista na LGPD.
     */
    public static function documentoMascarado(?string $valor): string
    {
        $digitos = self::somenteDigitos($valor);

        return match (strlen($digitos)) {
            11 => substr($digitos, 0, 3) . '.***.***-' . substr($digitos, -2),
            14 => substr($digitos, 0, 2) . '.***.***/****-' . substr($digitos, -2),
            default => '',
        };
    }

    public static function telefone(?string $valor): string
    {
        $digitos = self::somenteDigitos($valor);

        return match (strlen($digitos)) {
            11 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7)),
            10 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6)),
            default => (string) $valor,
        };
    }

    /**
     * Converte constantes internas em texto legivel: EM_ANALISE -> Em analise
     */
    public static function rotulo(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        $mapa = [
            'DISPONIVEL'               => 'Disponível',
            'PARCIAL'                  => 'Disponibilidade parcial',
            'INDISPONIVEL'             => 'Indisponível',
            'RASCUNHO'                 => 'Rascunho',
            'PUBLICADA'                => 'Publicada',
            'EM_ANALISE'               => 'Em análise',
            'ENCERRADA'                => 'Encerrada',
            'CANCELADA'                => 'Cancelada',
            'APROVADO'                 => 'Aprovado',
            'REPROVADO'                => 'Reprovado',
            'ENVIADO'                  => 'Enviado',
            'VISUALIZADO'              => 'Visualizado',
            'EM_NEGOCIACAO'            => 'Em negociação',
            'SELECIONADO'              => 'Selecionado',
            'RECUSADO'                 => 'Recusado',
            'RETIRADO'                 => 'Retirado',
            'ABERTA'                   => 'Aberta',
            'PROCEDENTE'               => 'Procedente',
            'IMPROCEDENTE'             => 'Improcedente',
            'ATENDIDA'                 => 'Atendida',
            'RECUSADA'                 => 'Recusada',
            'PUBLICO'                  => 'Público',
            'AUTENTICADO'              => 'Somente usuários autenticados',
            'OCULTO'                   => 'Oculto',
            'ADMIN'                    => 'Administrador',
            'PROFISSIONAL'             => 'Profissional',
            'EMPRESA'                  => 'Empresa',
            'TERCEIRO'                 => 'Contratante',
            'BASICO'                   => 'Básico',
            'INTERMEDIARIO'            => 'Intermediário',
            'AVANCADO'                 => 'Avançado',
            'ESPECIALISTA'             => 'Especialista',
            'PROJETO'                  => 'Projeto',
            'CONSULTORIA'              => 'Consultoria',
            'LAUDO'                    => 'Laudo ou perícia',
            'EXECUCAO'                 => 'Execução',
            'FISCALIZACAO'             => 'Fiscalização',
            'OUTROS'                   => 'Outros',
            'CONTEUDO_INADEQUADO'      => 'Conteúdo inadequado',
            'INFORMACAO_FALSA'         => 'Informação falsa',
            'SPAM'                     => 'Spam ou propaganda',
            'DADO_PESSOAL_INDEVIDO'    => 'Dado pessoal indevido',
            'EXERCICIO_ILEGAL'         => 'Indício de exercício ilegal',
            'OUTRO'                    => 'Outro motivo',
            'NENHUMA'                  => 'Nenhuma providência',
            'CONTEUDO_OCULTADO'        => 'Conteúdo ocultado',
            'CONTEUDO_REMOVIDO'        => 'Conteúdo removido',
            'USUARIO_ADVERTIDO'        => 'Usuário advertido',
            'USUARIO_BLOQUEADO'        => 'Usuário bloqueado',
            'ACESSO'                   => 'Acesso aos dados',
            'CORRECAO'                 => 'Correção de dados',
            'PORTABILIDADE'            => 'Portabilidade',
            'ANONIMIZACAO'             => 'Anonimização',
            'ELIMINACAO'               => 'Eliminação',
            'REVOGACAO_CONSENTIMENTO'  => 'Revogação de consentimento',
        ];

        if (isset($mapa[$valor])) {
            return $mapa[$valor];
        }

        return ucfirst(strtolower(str_replace('_', ' ', $valor)));
    }

    public static function tempoRelativo(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        $data = date_create($valor);

        if ($data === false) {
            return '';
        }

        $segundos = time() - $data->getTimestamp();

        if ($segundos < 60) {
            return 'agora mesmo';
        }

        if ($segundos < 3600) {
            $minutos = (int) floor($segundos / 60);

            return $minutos === 1 ? 'há 1 minuto' : "há {$minutos} minutos";
        }

        if ($segundos < 86400) {
            $horas = (int) floor($segundos / 3600);

            return $horas === 1 ? 'há 1 hora' : "há {$horas} horas";
        }

        $dias = (int) floor($segundos / 86400);

        if ($dias === 1) {
            return 'ontem';
        }

        if ($dias < 30) {
            return "há {$dias} dias";
        }

        return 'em ' . $data->format('d/m/Y');
    }

    /**
     * Reduz um texto preservando palavras inteiras.
     */
    public static function resumir(?string $texto, int $limite = 180): string
    {
        $texto = trim((string) $texto);

        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $limite), " .,;:") . '...';
    }

    /**
     * Normaliza texto para busca: minusculas e sem acentos.
     */
    public static function normalizar(?string $texto): string
    {
        $texto = mb_strtolower(trim((string) $texto));

        $de   = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];

        return str_replace($de, $para, $texto);
    }
}
