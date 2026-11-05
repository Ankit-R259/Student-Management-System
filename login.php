<?php
require "academic_helpers.php";
if (!admin_is_configured()) {
    header("Location: setup_admin.php");
    exit;
}
if (!empty($_SESSION["admin_id"])) {
    header("Location: index.php");
    exit;
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please try again.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["admin_id"] = (int) $admin["id"];
            $_SESSION["admin_username"] = $admin["username"];
            header("Location: index.php");
            exit;
        }
        $error = "Incorrect username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Login</title><link rel="stylesheet" href="style.css"></head>
<body><header><h1>Student Management System</h1><p>Administrator sign in</p></header>
<main><section class="card auth-card"><h2>Admin Login</h2><?php if ($error !== ""): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button type="submit">Log In</button></form>
<p>New here? <a href="register.php">Create an account</a></p></section></main></body></html>
