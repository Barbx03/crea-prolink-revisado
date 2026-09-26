<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/_config.php';

if (APP_AMBIENTE !== 'producao') {
    exit(0);
}

use App\Core\BancoDados;

$banco = BancoDados::conexao();
$usuarios = $banco->query(
    "SELECT usu_id, usu_email, usu_senha FROM sis_usuarios WHERE usu_email IN
    ('admin@prolink.local', 'contratante@prolink.local', 'instituicao@prolink.local')"
)->fetchAll();

foreach ($usuarios as $usuario) {
    $admin = $usuario['usu_email'] === 'admin@prolink.local';
    if (!password_verify($admin ? 'Admin@2026' : 'Senha@123', $usuario['usu_senha'])) {
        continue;
    }

    if ($admin) {
        $senha = getenv('ADMIN_SENHA_INICIAL') ?: '';
        if (strlen($senha) < 16 || $senha === 'Admin@2026') {
            fwrite(STDERR, "[prolink] defina ADMIN_SENHA_INICIAL com pelo menos 16 caracteres antes de publicar.\n");
            exit(1);
        }
        $consulta = $banco->prepare('UPDATE sis_usuarios SET usu_senha = ? WHERE usu_id = ? AND usu_senha = ?');
        $consulta->execute([password_hash($senha, PASSWORD_DEFAULT), $usuario['usu_id'], $usuario['usu_senha']]);
    } else {
        $consulta = $banco->prepare("UPDATE sis_usuarios SET usu_senha = ?, usu_status = 'B' WHERE usu_id = ? AND usu_senha = ?");
        $consulta->execute([password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $usuario['usu_id'], $usuario['usu_senha']]);
    }
}

echo "[prolink] credenciais iniciais de produção verificadas.\n";
