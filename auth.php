<?php
require_once "db.php";

ini_set("session.use_strict_mode", "1");
session_set_cookie_params([
    "httponly" => true,
    "samesite" => "Lax",
    "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
]);
session_start();

function admin_is_configured(): bool
{
    global $pdo;
    return (bool) $pdo->query("SELECT id FROM admin_users LIMIT 1")->fetch();
}

function csrf_token(): string
{
    if (!isset($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_is_valid(): bool
{
    $submitted = $_POST["csrf_token"] ?? "";
    return is_string($submitted)
        && isset($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $submitted);
}

function require_login(): void
{
    if (empty($_SESSION["admin_id"])) {
        header("Location: " . (admin_is_configured() ? "login.php" : "setup_admin.php"));
        exit;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
    $stmt->execute([(int) $_SESSION["admin_id"]]);
    $username = $stmt->fetchColumn();
    if ($username === false) {
        $_SESSION = [];
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $_SESSION["admin_username"] = $username;
}
?>
