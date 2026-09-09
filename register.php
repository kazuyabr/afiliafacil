<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Plans.php';

if (Auth::check()) {
    header('Location: /admin/');
    exit;
}

$error = '';
$trialDays = (int)Settings::get('trial_days', 3);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha todos os campos.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'As senhas não coincidem.';
    } elseif (!empty($_POST['website']) && !empty($_POST['website'])) {
        $error = 'Registro recusado.';
    } elseif (strlen(trim($_POST['website'] ?? '')) > 0) {
        $error = 'Registro recusado.';
    } else {
        $user = Auth::register($name, $email, $password);
        if ($user === null) {
            $error = 'Este e-mail já está cadastrado.';
        } else {
            header('Location: /admin/');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Conta - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-left">
            <div>
                <h1><i class="fas fa-bolt"></i> AfiliaFacil</h1>
                <p>Crie sua conta e comece grátis por <strong><?= $trialDays ?> dias</strong>. Clone páginas, gere pressels e hospede sua estrutura de afiliado — sem código e sem complicação.</p>
            </div>
        </div>
        <div class="login-right">
            <div class="login-card">
                <h2>Create Account</h2>
                <p class="subtitle">Grátis por <?= $trialDays ?> dias em qualquer plano. Sem cartão de crédito.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Seu nome</label>
                        <input type="text" name="name" class="form-control" placeholder="Seu nome completo" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="seu@email.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" required>
                    </div>
                    <div class="form-group">
                        <label>Confirmar senha</label>
                        <input type="password" name="password2" class="form-control" placeholder="Repita a senha" required>
                    </div>
                    <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">
                    <button type="submit" class="btn btn-primary btn-full">
                        <i class="fas fa-arrow-right"></i> Criar minha conta
                    </button>
                </form>
                <p style="text-align:center;margin-top:20px;font-size:.85rem;color:var(--text-secondary);">
                    Já tem conta? <a href="/login">Entrar</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
