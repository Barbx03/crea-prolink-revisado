<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Formatador;

/**
 * Traducao da resposta da API oficial para o vocabulario interno.
 *
 * A documentacao tecnica da API e entregue apenas as equipes habilitadas, de
 * modo que o nome exato de cada campo pode variar. Em vez de acoplar a
 * aplicacao a um unico formato, cada campo interno declara a lista de nomes
 * aceitos: chegando a documentacao, basta conferir ou acrescentar um alias
 * aqui, sem tocar em nenhuma outra camada.
 *
 * Nada e inventado: campo ausente na resposta permanece nulo.
 */
final class NormalizadorApiCrea
{
    /**
     * @param array<string, mixed>|list<mixed> $resposta
     * @return array<string, mixed>
     */
    public static function profissional(array $resposta): array
    {
        $dados = self::extrairObjeto($resposta);

        return [
            'nome'            => self::campo($dados, ['pro_nome', 'nome', 'nomeProfissional', 'nome_completo', 'nomeCompleto', 'razaoSocial']),
            'cpf'             => Formatador::somenteDigitos((string) self::campo($dados, ['pro_cpf', 'cpf', 'numeroCpf', 'documento'])),
            'rnp'             => self::campo($dados, ['pro_rnp', 'rnp', 'numeroRnp', 'registroNacional', 'numero_rnp']),
            'registro'        => self::campo($dados, ['pro_registro_crea', 'registro', 'numeroRegistro', 'carteira', 'numeroCarteira']),
            'titulo'          => self::campo($dados, ['titulo', 'tituloProfissional', 'titulos', 'formacao']),
            'situacao'        => self::campo($dados, ['pro_status', 'situacao', 'situacaoRegistro', 'status', 'situacaoCadastral']),
            'tipo_registro'   => self::campo($dados, ['tipoRegistro', 'categoria', 'tipo']),
            'dt_registro'     => self::data(self::campo($dados, ['dataRegistro', 'dtRegistro', 'data_registro'])),
            'dt_visto'        => self::data(self::campo($dados, ['dataVisto', 'dtVisto'])),
            'uf'              => self::uf(self::campo($dados, ['art_local_uf', 'uf', 'ufRegistro', 'estado'])),
            'municipio'       => self::campo($dados, ['municipio', 'cidade', 'municipioRegistro']),
            'email'           => self::campo($dados, ['email', 'emailProfissional']),
            'telefone'        => self::campo($dados, ['telefone', 'celular', 'fone']),
            'ativo'           => self::situacaoAtiva((string) self::campo($dados, ['pro_status', 'situacao', 'situacaoRegistro', 'status'])),
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $resposta
     * @return array<string, mixed>
     */
    public static function empresa(array $resposta): array
    {
        $dados = self::extrairObjeto($resposta);

        return [
            'razao_social'      => self::campo($dados, ['emp_razao_social', 'razaoSocial', 'razao_social', 'nome', 'nomeEmpresarial']),
            'nome_fantasia'     => self::campo($dados, ['emp_nome_fantasia', 'nomeFantasia', 'nome_fantasia', 'fantasia']),
            'cnpj'              => Formatador::somenteDigitos((string) self::campo($dados, ['emp_cnpj', 'cnpj', 'numeroCnpj', 'documento'])),
            'registro'          => self::campo($dados, ['emp_registro_crea', 'registro', 'numeroRegistro', 'numeroPessoaJuridica']),
            'situacao'          => self::campo($dados, ['situacao', 'situacaoRegistro', 'status']),
            'dt_registro'       => self::data(self::campo($dados, ['emp_dt_registro', 'dataRegistro', 'dtRegistro'])),
            'uf'                => self::uf(self::campo($dados, ['uf', 'estado'])),
            'municipio'         => self::campo($dados, ['municipio', 'cidade']),
            'email'             => self::campo($dados, ['email']),
            'telefone'          => self::campo($dados, ['telefone', 'fone']),
            'responsaveis'      => self::responsaveis($dados),
            'ativo'             => self::situacaoAtiva((string) self::campo($dados, ['pro_status', 'situacao', 'situacaoRegistro', 'status'])),
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $resposta
     * @return list<array<string, mixed>>
     */
    public static function arts(array $resposta, string $rnp, ?string $numeroFiltro = null): array
    {
        $normalizadas = [];

        foreach (self::extrairColecao($resposta) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $numero = (string) self::campo($item, ['art_numero', 'numero', 'numeroArt', 'nrArt', 'art', 'numeroAnotacao']);

            if ($numero === '') {
                continue;
            }

            if ($numeroFiltro !== null && $numeroFiltro !== '' && !self::mesmoNumero($numero, $numeroFiltro)) {
                continue;
            }

            $normalizadas[] = [
                'numero'         => $numero,
                'rnp'            => (string) (self::campo($item, ['rnp', 'numeroRnp']) ?: $rnp),
                'tipo'           => self::campo($item, ['art_tipo', 'tipo', 'tipoArt', 'modalidade', 'tipoAnotacao']),
                'objeto'         => self::campo($item, ['art_objeto', 'objeto', 'descricao', 'objetoContrato', 'atividade', 'descricaoObra']),
                'contratante'    => self::campo($item, ['art_contratante_nome', 'contratante', 'nomeContratante', 'cliente', 'proprietario']),
                'valor_contrato' => self::decimal(self::campo($item, ['valorContrato', 'valor', 'valorObra'])),
                'municipio'      => self::campo($item, ['art_local_municipio', 'municipio', 'cidade', 'municipioObra']),
                'uf'             => self::uf(self::campo($item, ['art_local_uf', 'uf', 'estado'])),
                'dt_inicio'      => self::data(self::campo($item, ['dataInicio', 'dtInicio', 'inicio', 'dataRegistro'])),
                'dt_fim'         => self::data(self::campo($item, ['dataFim', 'dtFim', 'fim', 'dataConclusao', 'dataBaixa'])),
                'situacao'       => self::campo($item, ['art_situacao', 'situacao', 'status', 'situacaoArt']),
            ];
        }

        return $normalizadas;
    }

    /**
     * @param array<string, mixed>|list<mixed> $resposta
     * @return list<array<string, mixed>>
     */
    public static function cats(array $resposta, string $rnp, ?string $numeroFiltro = null): array
    {
        $normalizadas = [];

        foreach (self::extrairColecao($resposta) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $numero = (string) self::campo($item, ['cat_numero', 'numero', 'numeroCat', 'nrCat', 'cat', 'numeroCertidao']);

            if ($numero === '') {
                continue;
            }

            if ($numeroFiltro !== null && $numeroFiltro !== '' && !self::mesmoNumero($numero, $numeroFiltro)) {
                continue;
            }

            $normalizadas[] = [
                'numero'      => $numero,
                'rnp'         => (string) (self::campo($item, ['rnp', 'numeroRnp']) ?: $rnp),
                'tipo'        => self::campo($item, ['cat_tipo', 'tipo', 'tipoCat', 'modalidade']),
                'objeto'      => self::campo($item, ['cat_finalidade', 'objeto', 'descricao', 'atividade', 'objetoCertidao']),
                'dt_emissao'  => self::data(self::campo($item, ['cat_dt_emissao', 'dataEmissao', 'dtEmissao', 'emissao', 'dataRegistro'])),
                'dt_validade' => self::data(self::campo($item, ['cat_dt_validade', 'dataValidade', 'dtValidade', 'validade'])),
                'situacao'    => self::campo($item, ['cat_tipo', 'situacao', 'status', 'situacaoCat']),
            ];
        }

        return $normalizadas;
    }

    /**
     * A resposta pode vir como objeto direto, ou envelopada em chaves como
     * "data", "dados", "resultado" ou "content".
     *
     * @param array<string, mixed>|list<mixed> $resposta
     * @return array<string, mixed>
     */
    private static function extrairObjeto(array $resposta): array
    {
        foreach (['data', 'dados', 'resultado', 'result', 'content', 'profissional', 'empresa', 'pessoa'] as $envelope) {
            if (isset($resposta[$envelope]) && is_array($resposta[$envelope])) {
                $interno = $resposta[$envelope];

                // Envelope contendo lista de um elemento
                if (array_is_list($interno)) {
                    return is_array($interno[0] ?? null) ? $interno[0] : [];
                }

                return $interno;
            }
        }

        if (array_is_list($resposta)) {
            return is_array($resposta[0] ?? null) ? $resposta[0] : [];
        }

        return $resposta;
    }

    /**
     * @param array<string, mixed>|list<mixed> $resposta
     * @return list<mixed>
     */
    private static function extrairColecao(array $resposta): array
    {
        if (array_is_list($resposta)) {
            return $resposta;
        }

        foreach (['data', 'dados', 'resultado', 'result', 'content', 'items', 'itens', 'arts', 'cats', 'registros', 'lista'] as $envelope) {
            if (isset($resposta[$envelope]) && is_array($resposta[$envelope])) {
                $interno = $resposta[$envelope];

                if (array_is_list($interno)) {
                    return $interno;
                }

                // Objeto unico dentro do envelope
                return [$interno];
            }
        }

        // Objeto unico sem envelope
        return $resposta === [] ? [] : [$resposta];
    }

    /**
     * @param array<string, mixed> $dados
     * @param list<string> $nomes
     */
    private static function campo(array $dados, array $nomes): mixed
    {
        foreach ($nomes as $nome) {
            // Comparacao tolerante a maiusculas, acentos e separadores
            foreach ($dados as $chave => $valor) {
                if (!is_scalar($valor) && !is_null($valor)) {
                    continue;
                }

                if (self::mesmaChave((string) $chave, $nome)) {
                    $texto = is_string($valor) ? trim($valor) : $valor;

                    if ($texto !== '' && $texto !== null) {
                        return $texto;
                    }
                }
            }
        }

        return null;
    }

    private static function mesmaChave(string $chaveResposta, string $nomeEsperado): bool
    {
        $limpar = static fn (string $texto): string => preg_replace('/[^a-z0-9]/', '', Formatador::normalizar($texto)) ?? '';

        return $limpar($chaveResposta) === $limpar($nomeEsperado);
    }

    /**
     * @param array<string, mixed> $dados
     * @return list<array<string, mixed>>
     */
    private static function responsaveis(array $dados): array
    {
        foreach (['quadro_tecnico', 'responsaveis', 'responsaveisTecnicos', 'profissionais', 'quadroTecnico'] as $chave) {
            if (isset($dados[$chave]) && is_array($dados[$chave])) {
                $lista = [];

                foreach ($dados[$chave] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $lista[] = [
                        'nome' => self::campo($item, ['nome', 'nomeProfissional']),
                        'rnp'  => self::campo($item, ['rnp', 'numeroRnp']),
                        'titulo' => self::campo($item, ['titulo', 'tituloProfissional']),
                    ];
                }

                return $lista;
            }
        }

        return [];
    }

    /**
     * Converte as variacoes usuais de data para o formato do banco.
     */
    private static function data(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = trim((string) $valor);

        foreach (['Y-m-d', 'd/m/Y', 'Y-m-d H:i:s', 'd/m/Y H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP'] as $formato) {
            $data = \DateTimeImmutable::createFromFormat($formato, $texto);

            if ($data !== false) {
                return $data->format('Y-m-d');
            }
        }

        $timestamp = strtotime($texto);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private static function decimal(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $texto = preg_replace('/[^\d,.-]/', '', (string) $valor) ?? '';

        if ($texto === '') {
            return null;
        }

        // Formato brasileiro: 1.234.567,89
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }

        return is_numeric($texto) ? (float) $texto : null;
    }

    private static function uf(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $uf = strtoupper(substr(trim((string) $valor), 0, 2));

        return in_array($uf, \App\Core\Validador::UNIDADES_FEDERATIVAS, true) ? $uf : null;
    }

    private static function situacaoAtiva(string $situacao): bool
    {
        if ($situacao === '') {
            // Sem informacao de situacao, nao se afirma nada
            return false;
        }

        $normalizada = Formatador::normalizar($situacao);

        // A API oficial usa o codigo 'A' para registro ativo
        if ($normalizada === 'a') {
            return true;
        }

        foreach (['ativo', 'ativa', 'regular', 'valido', 'valida', 'em dia', 'adimplente'] as $indicativo) {
            if (str_contains($normalizada, $indicativo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compara numeros de ART/CAT ignorando pontuacao e zeros a esquerda.
     */
    private static function mesmoNumero(string $numeroA, string $numeroB): bool
    {
        $limpar = static function (string $numero): string {
            $digitos = preg_replace('/[^a-zA-Z0-9]/', '', $numero) ?? '';

            return ltrim(strtoupper($digitos), '0');
        };

        return $limpar($numeroA) === $limpar($numeroB);
    }
}
