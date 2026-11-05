<?php
require "auth.php";
require_login();
require "academic_helpers.php";

$id = isset($_GET["id"]) ? (int) $_GET["id"] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit;
}
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $roll = trim($_POST["roll_no"] ?? "");
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $course = trim($_POST["course"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $semester = filter_var($_POST["semester"] ?? "", FILTER_VALIDATE_INT);
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please submit the form again.";
    } elseif ($roll !== "" && $name !== "" && $semester !== false && $semester >= 1 && $semester <= 8) {
        try {
            $pdo->beginTransaction();
            $courseId = course_id_for_name($pdo, $course);
            $stmt = $pdo->prepare(
                "UPDATE students
                 SET roll_no = ?, name = ?, email = ?, course = ?, course_id = ?, phone = ?, semester = ?
                 WHERE id = ?"
            );
            $stmt->execute([$roll, $name, $email, $course, $courseId, $phone, $semester, $id]);
            if ($courseId !== null) register_student_for_course($pdo, $id, $courseId);
            $pdo->commit();
            header("Location: index.php?updated=1");
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Could not update student. Check the roll number.";
        }
    } else {
        $error = "Enter a roll number and name, and choose a semester from 1 to 8.";
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edit Student</title><link rel="stylesheet" href="style.css"></head>
<body><header><h1>Edit Student</h1></header><main><section class="card"><h2>Update Student Details</h2>
<?php if (isset($error)): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<input type="text" name="roll_no" value="<?= h($student["roll_no"]) ?>" placeholder="Roll Number" required>
<input type="text" name="name" value="<?= h($student["name"]) ?>" placeholder="Student Name" required>
<input type="email" name="email" value="<?= h($student["email"]) ?>" placeholder="Email">
<input type="text" name="course" value="<?= h($student["course"]) ?>" placeholder="Primary course (optional)">
<input type="text" name="phone" value="<?= h($student["phone"]) ?>" placeholder="Phone Number">
<label>Semester<select name="semester" required><option value="">Choose a semester</option>
<?php for ($semesterOption = 1; $semesterOption <= 8; $semesterOption++): ?><option value="<?= $semesterOption ?>" <?= (int) $student["semester"] === $semesterOption ? "selected" : "" ?>>Semester <?= $semesterOption ?></option><?php endfor; ?>
</select></label><button type="submit">Update Student</button><a href="index.php">Back to list</a></form></section></main></body></html>
