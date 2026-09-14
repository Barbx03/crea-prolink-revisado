<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auditoria;
use App\Core\Configuracao;
use App\Core\Formatador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioModalidade;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;

/**
 * Regras de negocio da integracao com a API oficial (RF02 e RF03).
 *
 * O servico decide o que fazer com o retorno da API: valida a titularidade,
 * associa ARTs ao portfolio, confirma o registro do usuario e registra tudo na
 * trilha de auditoria. O cliente HTTP cuida apenas do transporte.
 */
final class ServicoIntegracaoCrea
{
    private ClienteApiCrea $cliente;

    public function __construct(?ClienteApiCrea $cliente = null)
    {
        $this->cliente = $cliente ?? self::clientePadrao();
    }

    public static function clientePadrao(): ClienteApiCrea
    {
        $driver = strtolower(Configuracao::texto('API_CREA', 'driver', CREA_API_DRIVER));

        return $driver === 'offline'
            ? new ClienteApiCreaIndisponivel()
            : new ClienteApiCreaHttp();
    }

    public function integracaoDisponivel(): bool
    {
        return $this->cliente->configurada();
    }

    /**
     * Consulta o profissional por CPF na API oficial (RF02).
     *
     * @return array{dados: array<string, mixed>, bruto: string}
     */
    public function consultarProfissional(string $cpf): array
    {
        $cpf = Formatador::somenteDigitos($cpf);

        $resultado = $this->cliente->profissionalPorCpf($cpf);

        Auditoria::registrar('CONSULTA_API', [
            'entidade'  => 'API_CREA',
            'descricao' => 'Consulta de profissional registrado por CPF ' . Formatador::documentoMascarado($cpf),
        ]);

        return $resultado;
    }

    /**
     * Consulta a empresa por CNPJ na API oficial (RF02).
     *
     * @return array{dados: array<string, mixed>, bruto: string}
     */
    public function consultarEmpresa(string $cnpj): array
    {
        $cnpj = Formatador::somenteDigitos($cnpj);

        $resultado = $this->cliente->empresaPorCnpj($cnpj);

        Auditoria::registrar('CONSULTA_API', [
            'entidade'  => 'API_CREA',
            'descricao' => 'Consulta de empresa registrada por CNPJ ' . Formatador::documentoMascarado($cnpj),
        ]);

        return $resultado;
    }

    /**
     * Consulta as ARTs do profissional por RNP e, opcionalmente, numero (RF02).
     *
     * @return array{dados: list<array<string, mixed>>, bruto: string}
     */
    public function consultarArts(string $rnp, ?string $numeroArt = null): array
    {
        $resultado = $this->cliente->artsPorRnp($rnp, $numeroArt);

        Auditoria::registrar('CONSULTA_API', [
            'entidade'  => 'API_CREA',
            'descricao' => sprintf(
                'Consulta de ARTs do RNP %s%s',
                self::mascararRnp($rnp),
                $numeroArt !== null && $numeroArt !== '' ? ', ART ' . $numeroArt : ''
            ),
        ]);

        return $resultado;
    }

    /**
     * Consulta as CATs do profissional por RNP e, opcionalmente, numero (RF02).
     *
     * @return array{dados: list<array<string, mixed>>, bruto: string}
     */
    public function consultarCats(string $rnp, ?string $numeroCat = null): array
    {
        $resultado = $this->cliente->catsPorRnp($rnp, $numeroCat);

        Auditoria::registrar('CONSULTA_API', [
            'entidade'  => 'API_CREA',
            'descricao' => sprintf(
                'Consulta de CATs do RNP %s%s',
                self::mascararRnp($rnp),
                $numeroCat !== null && $numeroCat !== '' ? ', CAT ' . $numeroCat : ''
            ),
        ]);

        return $resultado;
    }

    /**
     * Valida na API oficial o CPF informado no cadastro e, quando confere,
     * marca o usuario como profissional registrado (RF01 + RF02).
     *
     * @return array{validado: bool, mensagem: string, dados: array<string, mixed>}
     */
    public function validarRegistroProfissional(int $usuarioId, string $cpf): array
    {
        $consulta = $this->consultarProfissional($cpf);
        $dados    = $consulta['dados'];

        $cpfRetornado = Formatador::somenteDigitos((string) ($dados['cpf'] ?? ''));
        $cpfInformado = Formatador::somenteDigitos($cpf);

        // Quando a API devolve o CPF, ele precisa coincidir com o consultado
        if ($cpfRetornado !== '' && $cpfRetornado !== $cpfInformado) {
            return [
                'validado' => false,
                'mensagem' => 'O registro retornado pela API oficial não corresponde ao CPF informado.',
                'dados'    => $dados,
            ];
        }

        if (empty($dados['rnp'])) {
            return [
                'validado' => false,
                'mensagem' => 'A API oficial não retornou o RNP deste registro, necessário para compor o portfólio.',
                'dados'    => $dados,
            ];
        }

        RepositorioUsuario::confirmarRegistroCrea($usuarioId, (string) $dados['rnp'], PERFIL_PROFISSIONAL);

        $perfilId = RepositorioPerfil::garantirExistencia($usuarioId);

        // As modalidades habilitadas acompanham a consulta do registro. Nao sao
        // declaraveis, e por isso substituem integralmente o que houver.
        RepositorioModalidade::sincronizar($perfilId, $dados['modalidades'] ?? []);

        // Preenche o que a API confirma, sem sobrescrever o que o titular editou
        $perfil = RepositorioPerfil::porId($perfilId);

        if ($perfil !== null && empty($perfil['prf_titulo']) && !empty($dados['titulo'])) {
            RepositorioPerfil::salvar($perfilId, [
                'titulo'          => (string) $dados['titulo'],
                'resumo'          => $perfil['prf_resumo'],
                'area_id'         => $perfil['prf_are_id'],
                'uf'              => $perfil['prf_uf'] ?: ($dados['uf'] ?? null),
                'cidade'          => $perfil['prf_cidade'] ?: ($dados['municipio'] ?? null),
                'raio_km'         => $perfil['prf_raio_atuacao_km'],
                'atende_remoto'   => $perfil['prf_atende_remoto'],
                'disponibilidade' => $perfil['prf_disponibilidade'],
                'anos_experiencia'=> $perfil['prf_anos_experiencia'],
                'valor_hora'      => $perfil['prf_valor_hora'],
                'site'            => $perfil['prf_site'],
                'linkedin'        => $perfil['prf_linkedin'],
            ]);
        }

        return [
            'validado' => true,
            'mensagem' => sprintf(
                'Registro confirmado na API oficial do CREA-AM%s.',
                !empty($dados['situacao']) ? ' (situação: ' . $dados['situacao'] . ')' : ''
            ),
            'dados'    => $dados,
        ];
    }

    /**
     * Valida na API oficial o CNPJ informado e marca a empresa como registrada.
     *
     * @return array{validado: bool, mensagem: string, dados: array<string, mixed>}
     */
    public function validarRegistroEmpresa(int $usuarioId, string $cnpj): array
    {
        $consulta = $this->consultarEmpresa($cnpj);
        $dados    = $consulta['dados'];

        $cnpjRetornado = Formatador::somenteDigitos((string) ($dados['cnpj'] ?? ''));
        $cnpjInformado = Formatador::somenteDigitos($cnpj);

        if ($cnpjRetornado !== '' && $cnpjRetornado !== $cnpjInformado) {
            return [
                'validado' => false,
                'mensagem' => 'O registro retornado pela API oficial não corresponde ao CNPJ informado.',
                'dados'    => $dados,
            ];
        }

        RepositorioUsuario::confirmarRegistroCrea($usuarioId, null, PERFIL_EMPRESA);
        RepositorioPerfil::garantirExistencia($usuarioId);

        return [
            'validado' => true,
            'mensagem' => sprintf(
                'Empresa confirmada na API oficial do CREA-AM%s.',
                !empty($dados['situacao']) ? ' (situação: ' . $dados['situacao'] . ')' : ''
            ),
            'dados'    => $dados,
        ];
    }

    /**
     * Associa ao portfolio uma ART validada na API oficial (RF03).
     *
     * A titularidade e checada duas vezes: o RNP consultado e o do proprio
     * usuario autenticado, e a ART retornada precisa pertencer a esse RNP.
     *
     * @return array{ok: bool, mensagem: string, art_id?: int, dados?: array<string, mixed>}
     */
    public function associarArt(int $usuarioId, string $numeroArt): array
    {
        $usuario = RepositorioUsuario::porId($usuarioId);

        if ($usuario === null) {
            return ['ok' => false, 'mensagem' => 'Usuário não encontrado.'];
        }

        if (($usuario['usu_registrado_crea'] ?? 'N') !== 'S' || empty($usuario['usu_rnp'])) {
            return [
                'ok'       => false,
                'mensagem' => 'Valide primeiro seu registro profissional na API oficial para associar ARTs ao portfólio.',
            ];
        }

        $rnp      = (string) $usuario['usu_rnp'];
        $consulta = $this->consultarArts($rnp, $numeroArt);

        if ($consulta['dados'] === []) {
            return [
                'ok'       => false,
                'mensagem' => sprintf(
                    'A API oficial não retornou a ART %s vinculada ao seu RNP. Confira o número informado.',
                    $numeroArt
                ),
            ];
        }

        $art = $consulta['dados'][0];

        // Confirma que a ART retornada pertence ao RNP do titular
        if (!empty($art['rnp']) && Formatador::normalizar((string) $art['rnp']) !== Formatador::normalizar($rnp)) {
            Auditoria::registrar('ART_RECUSADA', [
                'descricao'  => 'ART retornada pela API não pertence ao RNP do usuário autenticado',
                'severidade' => 'ALERTA',
            ]);

            return [
                'ok'       => false,
                'mensagem' => 'A ART retornada não esta vinculada ao seu RNP e por isso não pode ser associada.',
            ];
        }

        // A consulta por numero confirma a veracidade da ART (RF03), mas responde
        // um subconjunto dos campos: nao traz o local nem as atividades da
        // Tabela de Obras e Servicos. Quem as traz e a listagem do RNP, entao o
        // registro validado e completado a partir dela, pelo mesmo numero.
        $art = $this->completarComListagem($rnp, $art);

        $perfilId = RepositorioPerfil::garantirExistencia($usuarioId);
        $artId    = RepositorioArt::registrarValidada($perfilId, $art, $consulta['bruto']);

        return [
            'ok'        => true,
            'mensagem'  => sprintf('ART %s validada na API oficial e associada ao seu portfólio.', $art['numero']),
            'art_id'    => $artId,
            'dados'     => $art,
        ];
    }

    /**
     * Sincroniza as CATs do titular a partir da API oficial (RF03).
     *
     * @return array{ok: bool, mensagem: string, total: int}
     */
    public function sincronizarCats(int $usuarioId, ?string $numeroCat = null): array
    {
        $usuario = RepositorioUsuario::porId($usuarioId);

        if ($usuario === null || ($usuario['usu_registrado_crea'] ?? 'N') !== 'S' || empty($usuario['usu_rnp'])) {
            return [
                'ok'       => false,
                'mensagem' => 'Valide primeiro seu registro profissional na API oficial para consultar CATs.',
                'total'    => 0,
            ];
        }

        $consulta = $this->consultarCats((string) $usuario['usu_rnp'], $numeroCat);

        if ($consulta['dados'] === []) {
            return [
                'ok'       => false,
                'mensagem' => 'A API oficial não retornou CATs vinculadas ao seu RNP.',
                'total'    => 0,
            ];
        }

        $perfilId = RepositorioPerfil::garantirExistencia($usuarioId);
        $total    = 0;

        foreach ($consulta['dados'] as $cat) {
            RepositorioCat::registrarValidada($perfilId, $cat, $consulta['bruto']);
            $total++;
        }

        return [
            'ok'       => true,
            'mensagem' => sprintf('%d certidão(ões) de acervo técnico obtida(s) da API oficial.', $total),
            'total'    => $total,
        ];
    }

    /**
     * Revalida na API oficial as ARTs ja associadas, atualizando situacao.
     *
     * @return array{atualizadas: int, falhas: int}
     */
    public function revalidarArts(int $usuarioId): array
    {
        $usuario = RepositorioUsuario::porId($usuarioId);

        if ($usuario === null || empty($usuario['usu_rnp'])) {
            return ['atualizadas' => 0, 'falhas' => 0];
        }

        $perfil = RepositorioPerfil::porUsuario($usuarioId);

        if ($perfil === null) {
            return ['atualizadas' => 0, 'falhas' => 0];
        }

        $perfilId    = (int) $perfil['prf_id'];
        $atualizadas = 0;
        $falhas      = 0;

        foreach (RepositorioArt::doPerfil($perfilId) as $artLocal) {
            try {
                $consulta = $this->consultarArts((string) $usuario['usu_rnp'], (string) $artLocal['art_numero']);

                if ($consulta['dados'] !== []) {
                    // Mesma limitação da associação: a consulta por número não
                    // devolve local nem atividades, e regravar sem elas apagaria
                    // o que já estava correto.
                    $art = $this->completarComListagem((string) $usuario['usu_rnp'], $consulta['dados'][0]);

                    RepositorioArt::registrarValidada($perfilId, $art, $consulta['bruto']);
                    $atualizadas++;
                } else {
                    $falhas++;
                }
            } catch (ExcecaoApiCrea) {
                $falhas++;
            }
        }

        return ['atualizadas' => $atualizadas, 'falhas' => $falhas];
    }

    private static function mascararRnp(string $rnp): string
    {
        if (mb_strlen($rnp) <= 4) {
            return '***';
        }

        return mb_substr($rnp, 0, 2) . str_repeat('*', mb_strlen($rnp) - 4) . mb_substr($rnp, -2);
    }

    /**
     * Completa uma ART validada por numero com os campos que so a listagem do
     * RNP devolve — local de execucao e atividades da TOS.
     *
     * Nada aqui substitui o que a validacao ja afirmou: os campos existentes
     * sao preservados, e os ausentes sao preenchidos apenas quando a listagem
     * traz o mesmo numero de ART.
     *
     * @param array<string, mixed> $art
     * @return array<string, mixed>
     */
    private function completarComListagem(string $rnp, array $art): array
    {
        $numero = (string) ($art['numero'] ?? '');

        if ($numero === '') {
            return $art;
        }

        try {
            $listagem = NormalizadorApiCrea::arts($this->cliente->artsPorRnp($rnp), $rnp);
        } catch (ExcecaoApiCrea) {
            // A ART ja foi validada; a listagem e complemento, nao requisito.
            return $art;
        }

        foreach ($listagem as $candidata) {
            if (Formatador::normalizar((string) ($candidata['numero'] ?? '')) !== Formatador::normalizar($numero)) {
                continue;
            }

            foreach ($candidata as $campo => $valor) {
                if (($art[$campo] ?? null) === null || $art[$campo] === '' || $art[$campo] === []) {
                    $art[$campo] = $valor;
                }
            }

            break;
        }

        return $art;
    }
}
