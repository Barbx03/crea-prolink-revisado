<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Autenticacao;
use App\Core\Controlador;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioArt;
use App\Repositories\RepositorioCat;
use App\Repositories\RepositorioCompetencia;
use App\Repositories\RepositorioExperiencia;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoUpload;

/**
 * Perfil do próprio usuário: dados profissionais, competências, foto, dados
 * pessoais e senha (RF01 e RF03).
 */
final class ControladorPerfil extends Controlador
{
    public function ver(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $this->visao('perfil/ver.twig', [
            'usuario'      => $this->usuarioAutenticado(),
            'perfil'       => RepositorioPerfil::porUsuario($this->usuarioId()),
            'competencias' => RepositorioPerfil::competencias($perfilId),
            'experiencias' => RepositorioExperiencia::doPerfil($perfilId),
            'arts'         => RepositorioArt::doPerfil($perfilId),
            'cats'         => RepositorioCat::doPerfil($perfilId),
            'completude'   => RepositorioPerfil::completude($perfilId),
            'perfil_id'    => $perfilId,
        ]);
    }

    public function formularioEdicao(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $this->visao('perfil/editar.twig', [
            'usuario'            => $this->usuarioAutenticado(),
            'perfil'             => RepositorioPerfil::porUsuario($this->usuarioId()),
            'areas'              => RepositorioCompetencia::areas(),
            'competencias'       => RepositorioCompetencia::agrupadasPorArea(),
            'minhas_competencias' => RepositorioPerfil::idsCompetencias($perfilId),
            'niveis_atuais'      => $this->niveisAtuais($perfilId),
            'ufs'                => Validador::UNIDADES_FEDERATIVAS,
            'completude'         => RepositorioPerfil::completude($perfilId),
        ]);
    }

    public function salvar(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $validador = Validador::para($this->requisicao->todos())
            ->maximo('titulo', 150, 'O título profissional')
            ->maximo('resumo', 4000, 'O resumo')
            ->uf('uf')
            ->maximo('cidade', 120, 'A cidade')
            ->dentroDe('disponibilidade', ['DISPONIVEL', 'PARCIAL', 'INDISPONIVEL'], 'a disponibilidade')
            ->inteiroEntre('anos_experiencia', 0, 70, 'Os anos de experiência')
            ->inteiroEntre('raio_km', 0, 5000, 'O raio de atuação')
            ->url('site', 'O endereço do site')
            ->url('linkedin', 'O endereço do LinkedIn');

        if (!$validador->valido()) {
            $this->voltarComErros('/meu-perfil/editar', $validador->erros());

            return;
        }

        RepositorioPerfil::salvar($perfilId, [
            'titulo'           => $this->requisicao->texto('titulo') ?: null,
            'resumo'           => $this->requisicao->texto('resumo') ?: null,
            'area_id'          => $this->requisicao->inteiro('area_id') ?: null,
            'uf'              => strtoupper($this->requisicao->texto('uf')) ?: null,
            'cidade'           => $this->requisicao->texto('cidade') ?: null,
            'raio_km'          => $this->requisicao->inteiro('raio_km') ?: null,
            'atende_remoto'    => $this->requisicao->flag('atende_remoto'),
            'disponibilidade'  => $this->requisicao->texto('disponibilidade', 'DISPONIVEL'),
            'anos_experiencia' => $this->requisicao->inteiro('anos_experiencia'),
            'valor_hora'       => $this->requisicao->decimal('valor_hora'),
            'site'             => $this->requisicao->texto('site') ?: null,
            'linkedin'         => $this->requisicao->texto('linkedin') ?: null,
        ]);

        Sessao::sucesso('Perfil atualizado.');
        $this->redirecionar('/meu-perfil');
    }

    public function salvarCompetencias(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());

        $selecionadas = RepositorioCompetencia::filtrarValidos(
            $this->requisicao->listaInteiros('competencias')
        );

        if (count($selecionadas) > 30) {
            Sessao::erro('Selecione no máximo 30 competências, priorizando aquelas em que você tem acervo.');
            $this->redirecionar('/meu-perfil/editar');

            return;
        }

        // Nível informado por competência, quando enviado
        $niveis    = [];
        $permitidos = ['BASICO', 'INTERMEDIARIO', 'AVANCADO', 'ESPECIALISTA'];

        foreach ($this->requisicao->todos()['nivel'] ?? [] as $competenciaId => $nivel) {
            $nivel = strtoupper((string) $nivel);

            if (in_array($nivel, $permitidos, true)) {
                $niveis[(int) $competenciaId] = $nivel;
            }
        }

        RepositorioPerfil::sincronizarCompetencias($perfilId, $selecionadas, $niveis);

        Sessao::sucesso(sprintf('%d competência(s) registrada(s) no seu perfil.', count($selecionadas)));
        $this->redirecionar('/meu-perfil');
    }

    public function enviarFoto(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());
        $perfil   = RepositorioPerfil::porId($perfilId);

        $servico   = new ServicoUpload();
        $resultado = $servico->receberFoto($this->requisicao->arquivos['foto'] ?? [], $perfilId);

        if (!$resultado['ok']) {
            Sessao::erro($resultado['mensagem']);
            $this->redirecionar('/meu-perfil/editar');

            return;
        }

        // Remove a imagem anterior, para não acumular arquivos órfãos
        if ($perfil !== null && !empty($perfil['prf_foto'])) {
            $servico->removerFoto((string) $perfil['prf_foto']);
        }

        RepositorioPerfil::atualizarFoto($perfilId, $resultado['nome'] ?? null);

        Auditoria::registrar('ATUALIZACAO', [
            'entidade'    => 'pro_perfis',
            'entidade_id' => $perfilId,
            'descricao'   => 'Foto do perfil atualizada',
        ]);

        Sessao::sucesso('Foto atualizada.');
        $this->redirecionar('/meu-perfil');
    }

    public function removerFoto(): void
    {
        $perfilId = RepositorioPerfil::garantirExistencia($this->usuarioId());
        $perfil   = RepositorioPerfil::porId($perfilId);

        if ($perfil !== null && !empty($perfil['prf_foto'])) {
            (new ServicoUpload())->removerFoto((string) $perfil['prf_foto']);
        }

        RepositorioPerfil::atualizarFoto($perfilId, null);

        Sessao::informacao('Foto removida.');
        $this->redirecionar('/meu-perfil/editar');
    }

    public function salvarDadosPessoais(): void
    {
        $usuario = $this->usuarioAutenticado();

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('nome', 'o nome')
            ->minimo('nome', 5, 'O nome')
            ->maximo('nome', 150, 'O nome')
            ->uf('uf')
            ->maximo('cidade', 120, 'A cidade')
            ->maximo('telefone', 20, 'O telefone');

        $novoEmail = $this->requisicao->texto('email');
        $trocaEmail = $novoEmail !== '' && $novoEmail !== (string) $usuario['usu_email'];

        if ($trocaEmail) {
            $validador->email('email');

            if (RepositorioUsuario::emailEmUso($novoEmail, $this->usuarioId())) {
                $validador->personalizado('email', false, 'Este e-mail já está em uso por outra conta.');
            }

            // Alterar o e-mail de acesso exige confirmar a senha atual
            if (!password_verify($this->requisicao->texto('senha_atual'), (string) $usuario['usu_senha'])) {
                $validador->personalizado('senha_atual', false, 'Informe a senha atual para alterar o e-mail de acesso.');
            }
        }

        if (!$validador->valido()) {
            $this->voltarComErros('/meu-perfil/editar', $validador->erros());

            return;
        }

        RepositorioUsuario::atualizarDadosPessoais($this->usuarioId(), [
            'nome'     => $this->requisicao->texto('nome'),
            'telefone' => $this->requisicao->texto('telefone') ?: null,
            'uf'       => strtoupper($this->requisicao->texto('uf')) ?: null,
            'cidade'   => $this->requisicao->texto('cidade') ?: null,
        ]);

        if ($trocaEmail) {
            RepositorioUsuario::atualizarEmail($this->usuarioId(), $novoEmail);
            Sessao::aviso('E-mail de acesso alterado. Use o novo endereço no próximo acesso.');
        }

        // Renova a sessão com o nome atualizado
        $atualizado = RepositorioUsuario::porId($this->usuarioId());

        if ($atualizado !== null) {
            Autenticacao::estabelecerSessao($atualizado);
        }

        Sessao::sucesso('Dados pessoais atualizados.');
        $this->redirecionar('/meu-perfil');
    }

    public function alterarSenha(): void
    {
        $usuario = $this->usuarioAutenticado();

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('senha_atual', 'a senha atual')
            ->obrigatorio('senha', 'a nova senha')
            ->senhaForte('senha')
            ->confirmacao('senha', 'senha_confirmacao');

        if (!password_verify($this->requisicao->texto('senha_atual'), (string) $usuario['usu_senha'])) {
            $validador->personalizado('senha_atual', false, 'A senha atual informada está incorreta.');
        }

        if ($this->requisicao->texto('senha') === $this->requisicao->texto('senha_atual')) {
            $validador->personalizado('senha', false, 'A nova senha deve ser diferente da atual.');
        }

        if (!$validador->valido()) {
            $this->voltarComErros('/meu-perfil/editar', $validador->erros());

            return;
        }

        RepositorioUsuario::atualizarSenha($this->usuarioId(), $this->requisicao->texto('senha'));

        Sessao::sucesso('Senha alterada.');
        $this->redirecionar('/meu-perfil');
    }

    /**
     * @return array<int, string>
     */
    private function niveisAtuais(int $perfilId): array
    {
        $niveis = [];

        foreach (RepositorioPerfil::competencias($perfilId) as $competencia) {
            $niveis[(int) $competencia['pcp_cmp_id']] = (string) $competencia['pcp_nivel'];
        }

        return $niveis;
    }
}
