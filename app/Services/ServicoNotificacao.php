<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Configuracao;
use App\Core\Registro;
use App\Core\Visao;
use App\Repositories\RepositorioNotificacao;
use PHPMailer\PHPMailer\Exception as ExcecaoPHPMailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Notificações da plataforma (RF07).
 *
 * Cada evento relevante é primeiro gravado na fila (sis_notificacoes) e só
 * depois enviado. Assim nenhum evento se perde quando o SMTP está indisponível
 * ou desligado: ele fica visível na central de notificações do usuário e pode
 * ser reenviado pelo painel administrativo.
 *
 * As credenciais de SMTP vêm do painel administrativo e, na falta delas, do
 * arquivo de configuração — as duas formas previstas no RF07.
 */
final class ServicoNotificacao
{
    public const EVENTOS = [
        'CADASTRO_CRIADO'       => 'Cadastro criado',
        'RECUPERACAO_SENHA'     => 'Recuperação de senha',
        'SENHA_ALTERADA'        => 'Senha alterada',
        'DEMANDA_PUBLICADA'     => 'Demanda publicada',
        'DEMANDA_ATUALIZADA'    => 'Demanda atualizada',
        'DEMANDA_ENCERRADA'     => 'Demanda encerrada',
        'INTERESSE_RECEBIDO'    => 'Manifestação de interesse recebida',
        'INTERESSE_RESPONDIDO'  => 'Manifestação de interesse respondida',
        'MENSAGEM_RECEBIDA'     => 'Nova mensagem recebida',
        'MODERACAO'             => 'Ação de moderação',
        'DENUNCIA_RECEBIDA'     => 'Denúncia registrada',
        'SOLICITACAO_LGPD'      => 'Requisição de direitos do titular',
        'REGISTRO_VALIDADO'     => 'Registro profissional validado',
    ];

    /**
     * Registra o evento e tenta entregá-lo por e-mail.
     *
     * @param array<string, mixed> $dados Variáveis do template do e-mail
     */
    public function notificar(
        string $evento,
        ?int $usuarioId,
        ?string $email,
        string $assunto,
        array $dados = [],
        ?string $link = null
    ): int {
        $corpo = $this->renderizar($evento, $assunto, $dados, $link);

        $notificacaoId = RepositorioNotificacao::enfileirar([
            'usuario_id'   => $usuarioId,
            'canal'        => 'EMAIL',
            'evento'       => $evento,
            'destinatario' => $email,
            'assunto'      => $assunto,
            'corpo'        => $corpo,
            'link'         => $link,
        ]);

        if ($email !== null && $email !== '' && $this->smtpAtivo()) {
            $this->tentarEnviar($notificacaoId, $email, $assunto, $corpo);
        }

        return $notificacaoId;
    }

    /**
     * Notificação apenas interna, sem envio de e-mail.
     *
     * @param array<string, mixed> $dados
     */
    public function notificarInterno(
        string $evento,
        int $usuarioId,
        string $assunto,
        array $dados = [],
        ?string $link = null
    ): int {
        return RepositorioNotificacao::enfileirar([
            'usuario_id' => $usuarioId,
            'canal'      => 'INTERNA',
            'evento'     => $evento,
            'assunto'    => $assunto,
            'corpo'      => $this->renderizar($evento, $assunto, $dados, $link),
            'link'       => $link,
        ]);
    }

    /**
     * Reprocessa a fila de notificações pendentes ou com falha.
     *
     * @return array{enviadas: int, falhas: int}
     */
    public function processarFila(int $limite = 25): array
    {
        if (!$this->smtpAtivo()) {
            return ['enviadas' => 0, 'falhas' => 0];
        }

        $enviadas = 0;
        $falhas   = 0;

        foreach (RepositorioNotificacao::pendentes($limite) as $notificacao) {
            $destinatario = (string) ($notificacao['not_destinatario'] ?? '');

            if ($destinatario === '') {
                RepositorioNotificacao::marcarFalha(
                    (int) $notificacao['not_id'],
                    'Notificação sem destinatário definido'
                );
                $falhas++;
                continue;
            }

            $sucesso = $this->tentarEnviar(
                (int) $notificacao['not_id'],
                $destinatario,
                (string) $notificacao['not_assunto'],
                (string) $notificacao['not_corpo']
            );

            $sucesso ? $enviadas++ : $falhas++;
        }

        return ['enviadas' => $enviadas, 'falhas' => $falhas];
    }

    /**
     * Envio de teste, disparado pelo painel administrativo.
     *
     * @return array{ok: bool, mensagem: string}
     */
    public function enviarTeste(string $destinatario): array
    {
        if (!$this->smtpAtivo()) {
            return [
                'ok'       => false,
                'mensagem' => 'O envio por SMTP está desativado. Ative-o e preencha o servidor antes de testar.',
            ];
        }

        $corpo = $this->renderizar(
            'MODERACAO',
            'Teste de configuração de e-mail',
            [
                'titulo'    => 'Teste de configuração de e-mail',
                'mensagem'  => 'Se você recebeu esta mensagem, as credenciais de SMTP do CREA Pro-Link estão corretas.',
                'detalhes'  => [
                    'Servidor'  => $this->configuracao('smtp_host', MAIL_HOST),
                    'Porta'     => (string) $this->configuracaoInteira('smtp_porta', MAIL_PORTA),
                    'Segurança' => $this->configuracao('smtp_seguranca', MAIL_SEGURANCA),
                    'Ambiente'  => APP_AMBIENTE,
                ],
            ],
            null
        );

        try {
            $mailer = $this->montarMailer();
            $mailer->addAddress($destinatario);
            $mailer->Subject = 'CREA Pro-Link | Teste de configuração de e-mail';
            $mailer->msgHTML($corpo);
            $mailer->AltBody = strip_tags($corpo);
            $mailer->send();

            return ['ok' => true, 'mensagem' => 'E-mail de teste enviado para ' . $destinatario . '.'];
        } catch (ExcecaoPHPMailer | \Throwable $e) {
            Registro::erro('Falha no envio do e-mail de teste', ['erro' => $e->getMessage()]);

            return ['ok' => false, 'mensagem' => 'Falha no envio: ' . $e->getMessage()];
        }
    }

    public function smtpAtivo(): bool
    {
        $ativo = Configuracao::booleano('SMTP', 'smtp_ativo', MAIL_ATIVO);
        $host  = $this->configuracao('smtp_host', MAIL_HOST);

        return $ativo && $host !== '';
    }

    private function tentarEnviar(int $notificacaoId, string $destinatario, string $assunto, string $corpo): bool
    {
        try {
            $mailer = $this->montarMailer();
            $mailer->addAddress($destinatario);
            $mailer->Subject = $assunto;
            $mailer->msgHTML($corpo);
            $mailer->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $corpo)));
            $mailer->send();

            RepositorioNotificacao::marcarEnviada($notificacaoId);

            return true;
        } catch (ExcecaoPHPMailer | \Throwable $e) {
            RepositorioNotificacao::marcarFalha($notificacaoId, $e->getMessage());

            Registro::erro('Falha no envio de notificação por e-mail', [
                'notificacao' => $notificacaoId,
                'erro'        => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function montarMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host       = $this->configuracao('smtp_host', MAIL_HOST);
        $mailer->Port       = $this->configuracaoInteira('smtp_porta', MAIL_PORTA);
        $mailer->CharSet    = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding   = PHPMailer::ENCODING_BASE64;
        $mailer->Timeout    = 20;
        $mailer->SMTPDebug  = SMTP::DEBUG_OFF;

        $usuario = $this->configuracao('smtp_usuario', MAIL_USUARIO);
        $senha   = $this->configuracao('smtp_senha', MAIL_SENHA);

        if ($usuario !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $usuario;
            $mailer->Password = $senha;
        }

        $seguranca = strtolower($this->configuracao('smtp_seguranca', MAIL_SEGURANCA));

        if ($seguranca === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($seguranca === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure  = '';
            $mailer->SMTPAutoTLS = false;
        }

        $mailer->setFrom(
            $this->configuracao('smtp_remetente_email', MAIL_REMETENTE_EMAIL),
            $this->configuracao('smtp_remetente_nome', MAIL_REMETENTE_NOME)
        );

        return $mailer;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function renderizar(string $evento, string $assunto, array $dados, ?string $link): string
    {
        try {
            return Visao::renderizar('emails/base.twig', array_merge([
                'evento'  => $evento,
                'assunto' => $assunto,
                'titulo'  => $dados['titulo'] ?? $assunto,
                'link'    => $link,
            ], $dados));
        } catch (\Throwable $e) {
            Registro::erro('Falha ao renderizar template de e-mail', ['erro' => $e->getMessage()]);

            // Corpo mínimo, para que a notificação não deixe de existir
            return '<p>' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    private function configuracao(string $chave, string $padrao): string
    {
        return trim(Configuracao::texto('SMTP', $chave, $padrao));
    }

    private function configuracaoInteira(string $chave, int $padrao): int
    {
        return Configuracao::inteiro('SMTP', $chave, $padrao);
    }
}
