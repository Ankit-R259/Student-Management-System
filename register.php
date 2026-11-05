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
    $confirmPassword = $_POST["confirm_password"] ?? "";
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please submit the form again.";
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = "Username must be between 3 and 50 characters.";
    } elseif (strlen($password) < 12) {
        $error = "Choose a password with at least 12 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "The passwords do not match.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, 'teacher')");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION["admin_id"] = (int) $pdo->lastInsertId();
            $_SESSION["admin_username"] = $username;
            header("Location: index.php");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $error = "That username is already registered. Please choose another.";
            } else {
                throw $e;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Create Account</title><link rel="stylesheet" href="style.css"></head>
<body><header><h1>Student Management System</h1><p>Create an account to manage student records</p></header>
<main><section class="card auth-card"><h2>Register</h2><?php if ($error !== ""): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><label for="username">Username</label><input id="username" name="username" autocomplete="username" minlength="3" maxlength="50" required><label for="password">Password (at least 12 characters)</label><input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required><label for="confirm_password">Confirm password</label><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required><button type="submit">Create Account</button></form>
<p>Already registered? <a href="login.php">Log in</a></p></section></main></body></html>
