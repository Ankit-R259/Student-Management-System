<?php
require "auth.php";
require_login();
require "academic_helpers.php";
$error = "";
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = isset($_POST["action"]) && is_string($_POST["action"]) ? $_POST["action"] : "";
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please submit the form again.";
    } elseif ($action === "create_course") {
        $code = strtoupper(trim($_POST["course_code"] ?? ""));
        $name = trim($_POST["course_name"] ?? "");
        $duration = filter_var($_POST["duration_months"] ?? "", FILTER_VALIDATE_INT);
        if ($code === "" || strlen($code) > 20 || $name === "" || strlen($name) > 100 ||
            $duration === false || $duration < 1 || $duration > 65535) {
            $error = "Enter a valid code, course name, and duration.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, duration_months) VALUES (?, ?, ?)");
                $stmt->execute([$code, $name, $duration]);
                $message = "Course added successfully.";
            } catch (PDOException $e) {
                if ($e->getCode() !== "23000") throw $e;
                $error = "That course code or name is already in use.";
            }
        }
    } elseif ($action === "register_student") {
        $studentId = filter_var($_POST["student_id"] ?? "", FILTER_VALIDATE_INT);
        $courseId = filter_var($_POST["course_id"] ?? "", FILTER_VALIDATE_INT);
        if (!$studentId || !$courseId) {
            $error = "Choose a valid student and course.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $studentExists = $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ?");
            $stmt->execute([$courseId]);
            if ($studentExists === false || $stmt->fetchColumn() === false) {
                $error = "The selected student or course no longer exists.";
            } else {
                register_student_for_course($pdo, (int) $studentId, (int) $courseId);
                $message = "Student registered in the selected course.";
            }
        }
    } elseif ($action === "withdraw_student") {
        $studentId = filter_var($_POST["student_id"] ?? "", FILTER_VALIDATE_INT);
        $courseId = filter_var($_POST["course_id"] ?? "", FILTER_VALIDATE_INT);
        if (!$studentId || !$courseId) {
            $error = "Choose a valid student registration.";
        } else {
            $stmt = $pdo->prepare(
                "UPDATE course_registrations SET status = 'withdrawn', withdrawn_at = CURRENT_TIMESTAMP
                 WHERE student_id = ? AND course_id = ? AND status = 'active'"
            );
            $stmt->execute([$studentId, $courseId]);
            $message = $stmt->rowCount() ? "Registration withdrawn. Existing academic records were kept." : "Active registration not found.";
        }
    } else {
        $error = "Choose a valid action.";
    }
}

$courses = $pdo->query(
    "SELECT id, course_code, course_name, duration_months
     FROM courses ORDER BY course_name"
)->fetchAll();
$students = $pdo->query("SELECT id, roll_no, name FROM students ORDER BY name, roll_no")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Course Management</title><link rel="stylesheet" href="style.css"></head>
<body>
<header class="feature-header"><h1>Course</h1><nav class="feature-nav" aria-label="Main navigation">
<a href="index.php">Students</a><a href="courses.php" aria-current="page">Course</a><a href="attendance.php">Attendance</a><a href="marks.php">Grades &amp; Results</a>
<span>Signed in as <?= h($_SESSION["admin_username"] ?? "Admin") ?></span><form method="POST" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Log Out</button></form></nav></header>
<main>
<?php if ($message !== ""): ?><p class="success"><?= h($message) ?></p><?php endif; ?><?php if ($error !== ""): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<section class="card"><h2>Add course</h2><form method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_course">
<label>Course code<input name="course_code" maxlength="20" required></label><label>Course name<input name="course_name" maxlength="100" required></label><label>Duration (months)<input type="number" name="duration_months" min="1" max="65535" value="36" required></label><button type="submit">Add course</button></form></section>
<section class="card"><h2>Courses</h2><div class="table-wrap"><table><thead><tr><th>Code</th><th>Course</th><th>Duration</th></tr></thead><tbody>
<?php foreach ($courses as $course): ?><tr><td><?= h($course["course_code"]) ?></td><td><?= h($course["course_name"]) ?></td><td><?= (int) $course["duration_months"] ?> months</td></tr><?php endforeach; ?>
<?php if (!$courses): ?><tr><td colspan="3" class="empty-state">No courses added.</td></tr><?php endif; ?></tbody></table></div></section>
</main></body></html>
