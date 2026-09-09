<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';

if (Auth::check()) {
    header('Location: /admin/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::attempt($email, $password)) {
        header('Location: /admin/');
        exit;
    }
    $error = 'E-mail ou senha incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AfiliaFacil - Entrar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-left">
            <div>
                <h1><i class="fas fa-bolt"></i> AfiliaFacil</h1>
                <p>Clone páginas, crie landing pages e gerencie seus links de afiliado em um só lugar. Sem código. Sem complicação.</p>
                <div style="margin-top:32px;display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-copy"></i></div>
                        <span>Clonador de Páginas completo</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-palette"></i></div>
                        <span>Editor visual arrastar e soltar</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-globe"></i></div>
                        <span>Hospedagem premium inclusa</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="login-right">
            <div class="login-card">
                <h2>Bem-vindo de volta</h2>
                <p class="subtitle">Entre com suas credenciais para acessar o painel.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="seu@email.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="password" class="form-control" placeholder="Sua senha" required>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;justify-content:space-between;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;">
                            <input type="checkbox" name="remember" style="width:auto;"> Lembrar de mim
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
                <p style="text-align:center;margin-top:20px;font-size:.85rem;color:var(--text-secondary);">
                    Não tem conta? <a href="/register"><strong>Criar conta grátis</strong></a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
