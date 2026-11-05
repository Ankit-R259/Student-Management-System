<?php
require "auth.php";
require_login();
require "academic_helpers.php";

$error = "";
$message = "";
$students = $pdo->query(
    "SELECT s.id, s.roll_no, s.name,
            (SELECT GROUP_CONCAT(c.course_name ORDER BY c.course_name SEPARATOR ', ')
             FROM course_registrations r JOIN courses c ON c.id = r.course_id
             WHERE r.student_id = s.id AND r.status = 'active') AS course_name
     FROM students s ORDER BY s.name, s.roll_no"
)->fetchAll();
$courses = $pdo->query("SELECT id, course_name FROM courses ORDER BY course_name")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $studentId = filter_var($_POST["student_id"] ?? "", FILTER_VALIDATE_INT);
    $courseId = filter_var($_POST["course_id"] ?? "", FILTER_VALIDATE_INT);
    $subject = trim($_POST["subject_name"] ?? "");
    $numbers = [];
    foreach (["internal_max", "internal_obtained", "external_max", "external_obtained"] as $key) {
        $numbers[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : "";
    }
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please submit the marks again.";
    } elseif (!$studentId || !$courseId || $subject === "" || strlen($subject) > 100) {
        $error = "Choose a student and course and enter a subject (maximum 100 characters).";
    } else {
        $registered = $pdo->prepare(
            "SELECT 1 FROM course_registrations WHERE student_id = ? AND course_id = ? AND status = 'active'"
        );
        $registered->execute([$studentId, $courseId]);
        $validNumbers = true;
        foreach ($numbers as $number) {
            if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $number)) $validNumbers = false;
        }
        if ($registered->fetchColumn() === false) {
            $error = "The selected student must be actively registered in the selected course.";
        } elseif (!$validNumbers || (float) $numbers["internal_max"] <= 0 ||
            (float) $numbers["external_max"] <= 0 ||
            (float) $numbers["internal_obtained"] > (float) $numbers["internal_max"] ||
            (float) $numbers["external_obtained"] > (float) $numbers["external_max"]) {
            $error = "Marks must be between zero and their maximum; maximum values must be greater than zero.";
        } else {
            $save = $pdo->prepare(
                "INSERT INTO student_marks
                 (student_id, course_id, subject_name, internal_max, internal_obtained,
                  external_max, external_obtained, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE internal_max = VALUES(internal_max),
                    internal_obtained = VALUES(internal_obtained),
                    external_max = VALUES(external_max),
                    external_obtained = VALUES(external_obtained),
                    recorded_by = VALUES(recorded_by)"
            );
            $save->execute([$studentId, $courseId, $subject, $numbers["internal_max"],
                $numbers["internal_obtained"], $numbers["external_max"], $numbers["external_obtained"],
                (int) $_SESSION["admin_id"]]);
            $message = "Marks saved successfully. Saving the same student, course, and subject updates that result.";
        }
    }
}

$filterCourse = filter_var($_GET["course_id"] ?? "", FILTER_VALIDATE_INT);
$filterCourse = $filterCourse !== false && $filterCourse > 0 ? $filterCourse : null;
$filterSemester = filter_var($_GET["semester"] ?? "", FILTER_VALIDATE_INT);
$filterSemester = $filterSemester !== false && $filterSemester > 0 ? $filterSemester : null;
$filterStudent = filter_var($_GET["student_id"] ?? "", FILTER_VALIDATE_INT);
$filterStudent = $filterStudent !== false && $filterStudent > 0 ? $filterStudent : null;
$conditions = [];
$parameters = [];
if ($filterStudent !== null) { $conditions[] = "m.student_id = ?"; $parameters[] = $filterStudent; }
if ($filterCourse !== null) { $conditions[] = "m.course_id = ?"; $parameters[] = $filterCourse; }
if ($filterSemester !== null) { $conditions[] = "s.semester = ?"; $parameters[] = $filterSemester; }
$resultsSql = "SELECT m.*, s.roll_no, s.name, s.semester, c.course_name
               FROM student_marks m JOIN students s ON s.id = m.student_id
               JOIN courses c ON c.id = m.course_id";
if ($conditions) $resultsSql .= " WHERE " . implode(" AND ", $conditions);
$resultsSql .= " ORDER BY c.course_name, s.name, m.subject_name";
$resultsStmt = $pdo->prepare($resultsSql);
$resultsStmt->execute($parameters);
$results = $resultsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Grades &amp; Results</title><link rel="stylesheet" href="style.css"></head>
<body><header class="feature-header"><h1>Grades &amp; Results</h1><nav class="feature-nav" aria-label="Main navigation">
<a href="index.php">Students</a><a href="attendance.php">Attendance</a><a href="courses.php">Course</a><a href="marks.php" aria-current="page">Grades &amp; Results</a>
<span>Signed in as <?= h($_SESSION["admin_username"] ?? "Admin") ?></span><form method="POST" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Log Out</button></form></nav></header>
<main><section class="card"><h2>Record or update student marks</h2><?php if ($message !== ""): ?><p class="success"><?= h($message) ?></p><?php endif; ?><?php if ($error !== ""): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if (!$students): ?><p class="empty-state">Add students and register them in a course before recording marks.</p><?php else: ?>
<form method="POST" class="feature-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<label>Student<select name="student_id" required><option value="">Choose a student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student["id"] ?>"><?= h($student["roll_no"] . " — " . $student["name"] . " (" . ($student["course_name"] ?? "No course") . ")") ?></option><?php endforeach; ?></select></label>
<label>Course<select name="course_id" required><option value="">Choose a course</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>"><?= h($course["course_name"]) ?></option><?php endforeach; ?></select></label>
<label>Subject<input name="subject_name" maxlength="100" required></label><label>Internal maximum<input type="number" name="internal_max" min="0.01" max="99999.99" step="0.01" required></label><label>Internal obtained<input type="number" name="internal_obtained" min="0" max="99999.99" step="0.01" required></label>
<label>External maximum<input type="number" name="external_max" min="0.01" max="99999.99" step="0.01" required></label><label>External obtained<input type="number" name="external_obtained" min="0" max="99999.99" step="0.01" required></label><button type="submit">Save marks</button></form><?php endif; ?></section>
<section class="card" id="student-results"><h2>Student results</h2><form method="GET" class="feature-filters">
<label>Student<select name="student_id"><option value="">All students</option><?php foreach ($students as $student): ?><option value="<?= (int) $student["id"] ?>" <?= $filterStudent === (int) $student["id"] ? "selected" : "" ?>><?= h($student["roll_no"] . " — " . $student["name"]) ?></option><?php endforeach; ?></select></label>
<label>Course<select name="course_id"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>" <?= $filterCourse === (int) $course["id"] ? "selected" : "" ?>><?= h($course["course_name"]) ?></option><?php endforeach; ?></select></label>
<label>Semester<input type="number" name="semester" min="1" max="20" value="<?= h($filterSemester ?? "") ?>"></label><button type="submit">Filter results</button><button type="button" onclick="window.print()">Print</button></form>
<div class="table-wrap"><table><thead><tr><th>Roll No.</th><th>Student</th><th>Course</th><th>Semester</th><th>Subject</th><th>Internal</th><th>External</th><th>Total</th><th>Percentage</th><th>Grade</th></tr></thead><tbody>
<?php foreach ($results as $result): $total = (float) $result["internal_obtained"] + (float) $result["external_obtained"]; $maximum = (float) $result["internal_max"] + (float) $result["external_max"]; $percentage = $maximum > 0 ? 100 * $total / $maximum : 0; $grade = academic_grade($percentage); ?>
<tr><td><?= h($result["roll_no"]) ?></td><td><?= h($result["name"]) ?></td><td><?= h($result["course_name"]) ?></td><td><?= h($result["semester"] ?? "—") ?></td><td><?= h($result["subject_name"]) ?></td><td><?= h($result["internal_obtained"]) ?> / <?= h($result["internal_max"]) ?></td><td><?= h($result["external_obtained"]) ?> / <?= h($result["external_max"]) ?></td><td><?= h(number_format($total, 2)) ?> / <?= h(number_format($maximum, 2)) ?></td><td><?= h(number_format($percentage, 1)) ?>%</td><td><span class="grade grade-<?= h(strtolower(str_replace("+", "plus", $grade))) ?>"><?= h($grade) ?></span></td></tr>
<?php endforeach; ?><?php if (!$results): ?><tr><td colspan="10" class="empty-state">No grades have been recorded yet.</td></tr><?php endif; ?></tbody></table></div></section></main></body></html>
