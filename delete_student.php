<?php
require "auth.php";
require_login();
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrf_is_valid()) {
    http_response_code(400);
    exit("Invalid delete request.");
}
$id = isset($_POST["id"]) ? (int) $_POST["id"] : 0;
if ($id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: index.php?deleted=1");
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() !== "23000") throw $e;
        header("Location: index.php?error=student-linked");
        exit;
    }
}
header("Location: index.php");
exit;
?>
