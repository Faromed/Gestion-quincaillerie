<?php
session_start();
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = "Veuillez saisir votre nom d'utilisateur et votre mot de passe.";
    } else {
        $sql = "SELECT id, username, password, role_id FROM users WHERE username = :username LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_id'] = $user['role_id'];

            header('Location: index.php');
            exit;
        } else {
            $error_message = "Nom d'utilisateur ou mot de passe incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Système Quincaillerie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(to right, #007bff, #6c757d);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background-color: #fff;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 0 30px rgba(0,0,0,0.15);
            max-width: 400px;
            width: 100%;
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #007bff;
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
        }
        .login-icon {
            font-size: 3rem;
            color: #007bff;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container text-center">
        <i class="fas fa-store login-icon"></i>
        <h3 class="mb-4">Connexion à la Quincaillerie</h3>

        <?php if (!empty($error_message)) : ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3 text-start">
                <label for="username" class="form-label">Nom d'utilisateur</label>
                <input type="text" class="form-control" name="username" id="username" required>
            </div>
            <div class="mb-3 text-start">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" class="form-control" name="password" id="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-sign-in-alt me-2"></i>Se connecter
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
