<?php
require "auth.php";
require_login();
require "academic_helpers.php";

$error = "";
$message = "";
$courses = $pdo->query(
    "SELECT c.id, c.course_name, COUNT(r.student_id) AS student_count
     FROM courses c
     JOIN course_registrations r ON r.course_id = c.id AND r.status = 'active'
     GROUP BY c.id, c.course_name
     ORDER BY c.course_name"
)->fetchAll();

$courseId = filter_var($_REQUEST["course_id"] ?? "", FILTER_VALIDATE_INT);
$courseId = $courseId !== false && $courseId > 0 ? $courseId : 0;
$classDate = isset($_REQUEST["class_date"]) && is_string($_REQUEST["class_date"])
    ? trim($_REQUEST["class_date"]) : date("Y-m-d");
$subject = isset($_REQUEST["subject_name"]) && is_string($_REQUEST["subject_name"])
    ? trim($_REQUEST["subject_name"]) : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $statuses = $_POST["status"] ?? null;
    if (!csrf_is_valid()) {
        $error = "Your session expired. Please submit attendance again.";
    } elseif (!$courseId || !valid_iso_date($classDate) || strlen($subject) > 100) {
        $error = "Choose a valid course and class date. Subject must be 100 characters or fewer.";
    } elseif (!is_array($statuses)) {
        $error = "Attendance was not submitted correctly.";
    } else {
        $rosterStmt = $pdo->prepare(
            "SELECT student_id FROM course_registrations
             WHERE course_id = ? AND status = 'active' ORDER BY student_id"
        );
        $rosterStmt->execute([$courseId]);
        $studentIds = array_map("intval", $rosterStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$studentIds) {
            $error = "There are no students registered in this course.";
        } else {
            foreach ($studentIds as $studentId) {
                if (!isset($statuses[$studentId]) ||
                    !in_array($statuses[$studentId], ["present", "absent", "on_leave"], true)) {
                    $error = "Choose Present, Absent, or On Leave for every student.";
                    break;
                }
            }
        }
        if ($error === "") {
            try {
                $pdo->beginTransaction();
                $save = $pdo->prepare(
                    "INSERT INTO attendance
                     (student_id, course_id, class_date, subject_name, status, marked_by)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)"
                );
                foreach ($studentIds as $studentId) {
                    $save->execute([$studentId, $courseId, $classDate, $subject, $statuses[$studentId], (int) $_SESSION["admin_id"]]);
                }
                $pdo->commit();
                $message = "Attendance saved for " . count($studentIds) . " student(s).";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
    }
}

$students = [];
if ($courseId && valid_iso_date($classDate) && strlen($subject) <= 100) {
    $roster = $pdo->prepare(
        "SELECT s.id, s.roll_no, s.name, a.status
         FROM students s
         JOIN course_registrations r ON r.student_id = s.id
          AND r.course_id = ? AND r.status = 'active'
         LEFT JOIN attendance a ON a.student_id = s.id AND a.course_id = ?
          AND a.class_date = ? AND a.subject_name = ?
         ORDER BY s.name, s.roll_no"
    );
    $roster->execute([$courseId, $courseId, $classDate, $subject]);
    $students = $roster->fetchAll();
}

$historyCourse = filter_var($_GET["history_course"] ?? "", FILTER_VALIDATE_INT);
$historyCourse = $historyCourse !== false && $historyCourse > 0 ? $historyCourse : null;
$historyStudent = filter_var($_GET["history_student"] ?? "", FILTER_VALIDATE_INT);
$historyStudent = $historyStudent !== false && $historyStudent > 0 ? $historyStudent : null;
$studentSearch = isset($_GET["student_search"]) && is_string($_GET["student_search"]) ? trim($_GET["student_search"]) : "";
$dateFrom = isset($_GET["from"]) && is_string($_GET["from"]) ? trim($_GET["from"]) : "";
$dateTo = isset($_GET["to"]) && is_string($_GET["to"]) ? trim($_GET["to"]) : "";
$conditions = [];
$parameters = [];
if ($studentSearch !== "") {
    $conditions[] = "(s.name LIKE ? OR s.roll_no LIKE ?)";
    $parameters[] = "%" . $studentSearch . "%";
    $parameters[] = "%" . $studentSearch . "%";
} elseif ($historyStudent !== null) {
    $conditions[] = "a.student_id = ?";
    $parameters[] = $historyStudent;
}
if ($historyCourse !== null) {
    $conditions[] = "a.course_id = ?";
    $parameters[] = $historyCourse;
}
if ($dateFrom !== "" && valid_iso_date($dateFrom)) {
    $conditions[] = "a.class_date >= ?";
    $parameters[] = $dateFrom;
}
if ($dateTo !== "" && valid_iso_date($dateTo)) {
    $conditions[] = "a.class_date <= ?";
    $parameters[] = $dateTo;
}
$historySql = "SELECT a.class_date, a.subject_name, a.status, s.id AS student_id,
                      s.roll_no, s.name, c.course_name
               FROM attendance a JOIN students s ON s.id = a.student_id
               JOIN courses c ON c.id = a.course_id";
if ($conditions) $historySql .= " WHERE " . implode(" AND ", $conditions);
$historySql .= " ORDER BY a.class_date DESC, c.course_name, s.name LIMIT 100";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($parameters);
$history = $historyStmt->fetchAll();
$summary = $pdo->query(
    "SELECT COUNT(*) AS total, SUM(status = 'present') AS present_count,
            SUM(status = 'absent') AS absent_count, SUM(status = 'on_leave') AS leave_count,
            ROUND(100 * SUM(status = 'present') / NULLIF(SUM(status IN ('present', 'absent')), 0), 1) AS percentage
     FROM attendance"
)->fetch();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Attendance | Student Management</title><link rel="stylesheet" href="style.css"></head>
<body>
<header class="feature-header"><h1>Attendance Management</h1><nav class="feature-nav" aria-label="Main navigation">
<a href="index.php">Students</a><a href="courses.php">Course</a><a href="attendance.php" aria-current="page">Attendance</a><a href="marks.php">Grades &amp; Results</a>
<span>Signed in as <?= h($_SESSION["admin_username"] ?? "Admin") ?></span><form method="POST" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Log Out</button></form></nav></header>
<main>
<section class="dashboard" aria-label="Attendance statistics">
<article class="card"><h2>Attendance records</h2><p class="total-count"><?= (int) $summary["total"] ?></p></article>
<article class="card"><h2>Present</h2><p class="total-count"><?= (int) $summary["present_count"] ?></p></article>
<article class="card"><h2>Absent</h2><p class="total-count"><?= (int) $summary["absent_count"] ?></p></article>
<article class="card"><h2>Attendance percentage</h2><p class="total-count"><?= $summary["percentage"] === null ? "—" : h($summary["percentage"]) . "%" ?></p></article></section>
<section class="card"><h2>Mark attendance</h2><p>On Leave is excluded from the present/absent attendance percentage.</p>
<?php if ($message !== ""): ?><p class="success"><?= h($message) ?></p><?php endif; ?><?php if ($error !== ""): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="GET" class="feature-filters"><label>Course<select name="course_id" required><option value="">Choose a course</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>" <?= $courseId === (int) $course["id"] ? "selected" : "" ?>><?= h($course["course_name"]) ?> (<?= (int) $course["student_count"] ?>)</option><?php endforeach; ?></select></label>
<label>Date<input type="date" name="class_date" value="<?= h($classDate) ?>" required></label><label>Subject (optional)<input name="subject_name" maxlength="100" value="<?= h($subject) ?>"></label><button type="submit">Load class</button></form>
<?php if ($courseId && $students): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="class_date" value="<?= h($classDate) ?>"><input type="hidden" name="subject_name" value="<?= h($subject) ?>">
<div class="table-wrap"><table><thead><tr><th>Roll No.</th><th>Student</th><th>Status</th></tr></thead><tbody><?php foreach ($students as $student): ?><tr><td><?= h($student["roll_no"]) ?></td><td><?= h($student["name"]) ?></td><td><select name="status[<?= (int) $student["id"] ?>]" required><?php foreach (["present" => "Present", "absent" => "Absent", "on_leave" => "On Leave"] as $value => $label): ?><option value="<?= h($value) ?>" <?= ($student["status"] ?? "present") === $value ? "selected" : "" ?>><?= h($label) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><div class="attendance-save-actions"><button class="attendance-save-button" type="submit">Save Attendance</button></div></form>
<?php elseif ($courseId): ?><p class="empty-state">No students are registered in this course.</p><?php endif; ?></section>
<section class="card"><h2>Attendance history</h2><form method="GET" class="feature-filters"><label>Course<select name="history_course"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>" <?= $historyCourse === (int) $course["id"] ? "selected" : "" ?>><?= h($course["course_name"]) ?></option><?php endforeach; ?></select></label>
<label>Search student<input type="search" name="student_search" value="<?= h($studentSearch) ?>" placeholder="Name or roll number"></label><label>From<input type="date" name="from" value="<?= h($dateFrom) ?>"></label><label>To<input type="date" name="to" value="<?= h($dateTo) ?>"></label><button type="submit">Search history</button></form>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Course</th><th>Subject</th><th>Roll No.</th><th>Student</th><th>Status</th></tr></thead><tbody>
<?php foreach ($history as $record): ?><tr><td><?= h($record["class_date"]) ?></td><td><?= h($record["course_name"]) ?></td><td><?= h($record["subject_name"] ?: "General") ?></td><td><?= h($record["roll_no"]) ?></td><td><?= h($record["name"]) ?></td><td><span class="status status-<?= h($record["status"]) ?>"><?= h(ucwords(str_replace("_", " ", $record["status"]))) ?></span></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="6" class="empty-state">No attendance records for these filters.</td></tr><?php endif; ?></tbody></table></div></section>
</main></body></html>
