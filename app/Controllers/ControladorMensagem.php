<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auditoria;
use App\Core\Controlador;
use App\Core\ExcecaoHttp;
use App\Core\Sessao;
use App\Core\Validador;
use App\Repositories\RepositorioConversa;
use App\Repositories\RepositorioDemanda;
use App\Repositories\RepositorioInteresse;
use App\Repositories\RepositorioMensagem;
use App\Repositories\RepositorioPerfil;
use App\Repositories\RepositorioUsuario;
use App\Services\ServicoNotificacao;

/**
 * Comunicação inicial entre as partes pela plataforma (RF05).
 *
 * A conversa só pode ser iniciada dentro de um contexto legítimo: uma demanda
 * publicada, ou um perfil que autorizou receber contato. Isso evita que a
 * plataforma se torne canal de mensagens não solicitadas.
 */
final class ControladorMensagem extends Controlador
{
    public function caixaDeEntrada(): void
    {
        $this->visao('mensagens/caixa.twig', [
            'conversas' => RepositorioConversa::doUsuario($this->usuarioId()),
        ]);
    }

    public function conversa(): void
    {
        $conversaId = $this->requisicao->parametroInteiro('id');
        $conversa   = $this->conversaDoParticipante($conversaId);

        RepositorioMensagem::marcarLidas($conversaId, $this->usuarioId());

        $euSouOrigem   = (int) $conversa['cnv_usu_origem'] === $this->usuarioId();
        $interlocutor  = [
            'id'     => $euSouOrigem ? (int) $conversa['cnv_usu_destino'] : (int) $conversa['cnv_usu_origem'],
            'nome'   => $euSouOrigem ? (string) $conversa['destino_nome'] : (string) $conversa['origem_nome'],
            'perfil' => $euSouOrigem ? (string) $conversa['destino_perfil'] : (string) $conversa['origem_perfil'],
        ];

        $perfilInterlocutor = RepositorioPerfil::porUsuario($interlocutor['id']);

        $this->visao('mensagens/conversa.twig', [
            'conversa'      => $conversa,
            'mensagens'     => RepositorioMensagem::daConversa($conversaId),
            'interlocutor'  => $interlocutor,
            'perfil_interlocutor' => $perfilInterlocutor,
        ]);
    }

    public function enviar(): void
    {
        $conversaId = $this->requisicao->parametroInteiro('id');
        $conversa   = $this->conversaDoParticipante($conversaId);

        if ($conversa['cnv_bloqueada'] === 'S') {
            Sessao::erro('Esta conversa foi bloqueada pela moderação e não aceita novas mensagens.');
            $this->redirecionar('/mensagens/' . $conversaId);

            return;
        }

        $validador = Validador::para($this->requisicao->todos())
            ->obrigatorio('conteudo', 'a mensagem')
            ->minimo('conteudo', 2, 'A mensagem')
            ->maximo('conteudo', 4000, 'A mensagem');

        if (!$validador->valido()) {
            $this->voltarComErros('/mensagens/' . $conversaId, $validador->erros());

            return;
        }

        RepositorioMensagem::enviar($conversaId, $this->usuarioId(), $this->requisicao->texto('conteudo'));

        $destinatarioId = (int) $conversa['cnv_usu_origem'] === $this->usuarioId()
            ? (int) $conversa['cnv_usu_destino']
            : (int) $conversa['cnv_usu_origem'];

        $destinatario = RepositorioUsuario::porId($destinatarioId);
        $remetente    = $this->usuarioAutenticado();

        if ($destinatario !== null) {
            (new ServicoNotificacao())->notificar(
                'MENSAGEM_RECEBIDA',
                $destinatarioId,
                (string) $destinatario['usu_email'],
                'CREA Pro-Link | Nova mensagem recebida',
                [
                    'titulo'   => 'Nova mensagem de ' . (string) $remetente['usu_nome'],
                    'mensagem' => 'Você recebeu uma nova mensagem na plataforma. '
                        . 'Por segurança, o conteúdo não é reproduzido neste e-mail.',
                    'acao_texto' => 'Abrir conversa',
                ],
                '/mensagens/' . $conversaId
            );
        }

        $this->redirecionar('/mensagens/' . $conversaId);
    }

    /**
     * Abre a conversa a partir de uma demanda ou de um perfil.
     */
    public function iniciar(): void
    {
        $destinatarioId = $this->requisicao->inteiro('usuario_id', 0) ?? 0;
        $demandaId      = $this->requisicao->inteiro('demanda_id');
        $interesseId    = $this->requisicao->inteiro('interesse_id');

        if ($destinatarioId <= 0 || $destinatarioId === $this->usuarioId()) {
            throw ExcecaoHttp::requisicaoInvalida('Destinatário inválido.');
        }

        $destinatario = RepositorioUsuario::porId($destinatarioId);

        if ($destinatario === null || $destinatario['usu_status'] !== STATUS_ATIVO) {
            throw ExcecaoHttp::naoEncontrado('Este usuário não está disponível para contato.');
        }

        $assunto     = 'Contato pela plataforma';
        $contextoOk  = false;

        // Contexto 1: conversa sobre uma demanda em que uma das partes está envolvida
        if ($demandaId !== null && $demandaId > 0) {
            $demanda = RepositorioDemanda::completaPorId($demandaId);

            if ($demanda === null) {
                throw ExcecaoHttp::naoEncontrado('Demanda não encontrada.');
            }

            $souAutor = (int) $demanda['dem_usu_id'] === $this->usuarioId();

            // O autor fala com quem manifestou interesse; o interessado fala com o autor
            if ($souAutor && RepositorioInteresse::jaManifestou($demandaId, $destinatarioId)) {
                $contextoOk = true;
            } elseif (!$souAutor && (int) $demanda['dem_usu_id'] === $destinatarioId) {
                $contextoOk = RepositorioInteresse::jaManifestou($demandaId, $this->usuarioId());
            }

            $assunto = 'Demanda: ' . (string) $demanda['dem_titulo'];
        }

        // Contexto 2: contato direto com perfil que autoriza receber mensagens
        if (!$contextoOk) {
            $perfil = RepositorioPerfil::porUsuario($destinatarioId);

            if ($perfil !== null && $perfil['prf_aceita_contato'] === 'S' && $perfil['prf_moderacao'] === 'APROVADO') {
                $contextoOk = true;
                $assunto    = 'Contato sobre serviços técnicos';
            }
        }

        if (!$contextoOk) {
            Sessao::erro(
                'Não é possível iniciar esta conversa: o contato direto só ocorre em uma demanda '
                . 'em que ambos estejam envolvidos, ou com um perfil que autorizou receber contato.'
            );
            $this->redirecionar('/mensagens');

            return;
        }

        $conversaId = RepositorioConversa::abrir(
            $this->usuarioId(),
            $destinatarioId,
            $demandaId !== null && $demandaId > 0 ? $demandaId : null,
            $interesseId !== null && $interesseId > 0 ? $interesseId : null,
            $assunto
        );

        $mensagemInicial = $this->requisicao->texto('conteudo');

        if ($mensagemInicial !== '' && mb_strlen($mensagemInicial) >= 2) {
            RepositorioMensagem::enviar($conversaId, $this->usuarioId(), mb_substr($mensagemInicial, 0, 4000));

            (new ServicoNotificacao())->notificar(
                'MENSAGEM_RECEBIDA',
                $destinatarioId,
                (string) $destinatario['usu_email'],
                'CREA Pro-Link | Novo contato recebido',
                [
                    'titulo'     => 'Novo contato pela plataforma',
                    'mensagem'   => 'Você recebeu um contato pelo CREA Pro-Link. Acesse a plataforma para responder.',
                    'acao_texto' => 'Abrir conversa',
                ],
                '/mensagens/' . $conversaId
            );
        }

        Auditoria::registrar('CONTATO_INICIADO', [
            'entidade'    => 'pro_conversas',
            'entidade_id' => $conversaId,
            'descricao'   => 'Contato iniciado pela plataforma',
        ]);

        $this->redirecionar('/mensagens/' . $conversaId);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversaDoParticipante(int $conversaId): array
    {
        $conversa = RepositorioConversa::comParticipantes($conversaId);

        if ($conversa === null) {
            throw ExcecaoHttp::naoEncontrado('Conversa não encontrada.');
        }

        $participa = (int) $conversa['cnv_usu_origem'] === $this->usuarioId()
            || (int) $conversa['cnv_usu_destino'] === $this->usuarioId();

        // Nem o administrador lê conversas alheias: a moderação atua por denúncia
        if (!$participa) {
            Auditoria::registrar('ACESSO_NEGADO', [
                'descricao'  => 'Tentativa de acesso a conversa de terceiros',
                'entidade'   => 'pro_conversas',
                'entidade_id' => $conversaId,
                'severidade' => 'ALERTA',
            ]);

            throw ExcecaoHttp::naoAutorizado('Esta conversa é privada entre outros usuários.');
        }

        return $conversa;
    }
}
