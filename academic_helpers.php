<?php
require_once "auth.php";

function course_id_for_name(PDO $pdo, string $courseName): ?int
{
    $courseName = trim($courseName);
    if ($courseName === "") {
        return null;
    }

    $code = "LEG-" . strtoupper(substr(hash("sha256", strtolower($courseName)), 0, 12));
    $insert = $pdo->prepare(
        "INSERT IGNORE INTO courses (course_code, course_name, duration_months)
         VALUES (?, ?, 36)"
    );
    $insert->execute([$code, $courseName]);

    $lookup = $pdo->prepare("SELECT id FROM courses WHERE course_name = ?");
    $lookup->execute([$courseName]);
    $id = $lookup->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("Could not find or create the selected course.");
    }

    return (int) $id;
}

function register_student_for_course(PDO $pdo, int $studentId, int $courseId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO course_registrations (student_id, course_id)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
            registered_at = IF(status = 'withdrawn', CURRENT_TIMESTAMP, registered_at),
            withdrawn_at = IF(status = 'withdrawn', NULL, withdrawn_at),
            status = 'active'"
    );
    $stmt->execute([$studentId, $courseId]);
}

function academic_grade(float $percentage): string
{
    if ($percentage >= 90) return "A+";
    if ($percentage >= 80) return "A";
    if ($percentage >= 70) return "B";
    if ($percentage >= 60) return "C";
    if ($percentage >= 50) return "D";
    return "F";
}

function valid_iso_date(string $value): bool
{
    $date = DateTime::createFromFormat("!Y-m-d", $value);
    return $date !== false && $date->format("Y-m-d") === $value;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
