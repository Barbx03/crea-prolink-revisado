<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Registro;

/**
 * Recebimento de imagem de perfil.
 *
 * O tipo do arquivo é determinado pelo conteúdo, não pela extensão nem pelo
 * cabeçalho enviado pelo navegador, e o nome final é gerado pela aplicação.
 * Isso impede que um arquivo executável entre no diretório público com um nome
 * escolhido por quem envia.
 */
final class ServicoUpload
{
    /**
     * @param array<string, mixed> $arquivo Entrada de $_FILES
     * @return array{ok: bool, mensagem: string, nome?: string}
     */
    public function receberFoto(array $arquivo, int $perfilId): array
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($erro === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'mensagem' => 'Nenhum arquivo foi enviado.'];
        }

        if ($erro !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'mensagem' => $this->mensagemErro($erro)];
        }

        $caminhoTemporario = (string) ($arquivo['tmp_name'] ?? '');

        if ($caminhoTemporario === '' || !is_uploaded_file($caminhoTemporario)) {
            return ['ok' => false, 'mensagem' => 'O arquivo enviado não pôde ser lido.'];
        }

        $tamanho = (int) ($arquivo['size'] ?? 0);

        if ($tamanho > UPLOAD_MAX_BYTES) {
            return [
                'ok'       => false,
                'mensagem' => sprintf(
                    'A imagem tem %s e o limite é de %s.',
                    $this->formatarBytes($tamanho),
                    $this->formatarBytes(UPLOAD_MAX_BYTES)
                ),
            ];
        }

        // Tipo real do conteúdo, e não o informado pelo cliente
        $informacoes = @getimagesize($caminhoTemporario);

        if ($informacoes === false) {
            return ['ok' => false, 'mensagem' => 'O arquivo enviado não é uma imagem válida.'];
        }

        $mime = (string) ($informacoes['mime'] ?? '');

        if (!in_array($mime, UPLOAD_MIME_PERMITIDOS, true)) {
            return [
                'ok'       => false,
                'mensagem' => 'Formato não aceito. Envie uma imagem JPEG, PNG ou WebP.',
            ];
        }

        $extensao = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $nome = sprintf('perfil-%d-%s.%s', $perfilId, bin2hex(random_bytes(6)), $extensao);
        $destino = PATH_UPLOAD . '/' . $nome;

        if (!is_dir(PATH_UPLOAD) && !@mkdir(PATH_UPLOAD, 0775, true)) {
            return ['ok' => false, 'mensagem' => 'O diretório de uploads não está acessível para escrita.'];
        }

        if (!move_uploaded_file($caminhoTemporario, $destino)) {
            Registro::erro('Falha ao mover arquivo enviado', ['destino' => $destino]);

            return ['ok' => false, 'mensagem' => 'Não foi possível salvar a imagem. Tente novamente.'];
        }

        @chmod($destino, 0644);

        return ['ok' => true, 'mensagem' => 'Imagem atualizada.', 'nome' => $nome];
    }

    public function removerFoto(?string $nome): void
    {
        if ($nome === null || $nome === '') {
            return;
        }

        // Impede travessia de diretório: só o nome base é aceito
        $nome = basename($nome);
        $caminho = PATH_UPLOAD . '/' . $nome;

        if (is_file($caminho)) {
            @unlink($caminho);
        }
    }

    private function mensagemErro(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A imagem excede o tamanho máximo permitido.',
            UPLOAD_ERR_PARTIAL                        => 'O envio foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Falha temporária do servidor ao receber o arquivo.',
            UPLOAD_ERR_EXTENSION                      => 'O envio foi bloqueado pela configuração do servidor.',
            default                                   => 'Não foi possível processar o arquivo enviado.',
        };
    }

    private function formatarBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
