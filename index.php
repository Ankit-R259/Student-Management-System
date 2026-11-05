<?php
require "auth.php";
require_login();
require "academic_helpers.php";

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
                "INSERT INTO students (roll_no, name, email, course, course_id, phone, semester)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$roll, $name, $email, $course, $courseId, $phone, $semester]);
            if ($courseId !== null) {
                register_student_for_course($pdo, (int) $pdo->lastInsertId(), $courseId);
            }
            $pdo->commit();
            header("Location: index.php?added=1");
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Could not add student. Check the roll number.";
        }
    } else {
        $error = "Enter a roll number and name, and choose a semester from 1 to 8.";
    }
}

$students = $pdo->query(
    "SELECT s.*,
            COALESCE(
                NULLIF((
                    SELECT GROUP_CONCAT(c.course_name ORDER BY c.course_name SEPARATOR ', ')
                    FROM course_registrations r
                    JOIN courses c ON c.id = r.course_id
                    WHERE r.student_id = s.id AND r.status = 'active'
                ), ''),
                NULLIF(TRIM(s.course), ''),
                'Unassigned'
            ) AS assigned_course_name
     FROM students s
     ORDER BY s.id DESC"
)->fetchAll();
$attendanceByStudent = [];
$attendanceSummaries = $pdo->query(
    "SELECT student_id,
            ROUND(100 * SUM(status = 'present') /
                NULLIF(SUM(status IN ('present', 'absent')), 0), 1) AS percentage
     FROM attendance
     GROUP BY student_id"
)->fetchAll();
foreach ($attendanceSummaries as $summary) {
    $attendanceByStudent[(int) $summary["student_id"]] = $summary["percentage"];
}
$subjectMarksByStudent = [];
$subjectMarks = $pdo->query(
    "SELECT m.student_id, m.subject_name, c.course_name,
            SUM(m.internal_obtained + m.external_obtained) AS total_obtained,
            SUM(m.internal_max + m.external_max) AS maximum_marks
     FROM student_marks m
     JOIN courses c ON c.id = m.course_id
     GROUP BY m.student_id, m.course_id, m.subject_name, c.course_name
     ORDER BY c.course_name, m.subject_name"
)->fetchAll();
foreach ($subjectMarks as $subjectMark) {
    $subjectMarksByStudent[(int) $subjectMark["student_id"]][] = $subjectMark;
}
$gradesByStudent = [];
$gradeSummaries = $pdo->query(
    "SELECT student_id,
            SUM(internal_obtained + external_obtained) AS total_obtained,
            SUM(internal_max + external_max) AS maximum_marks,
            ROUND(100 * SUM(internal_obtained + external_obtained) /
                NULLIF(SUM(internal_max + external_max), 0), 1) AS percentage
     FROM student_marks
     GROUP BY student_id"
)->fetchAll();
foreach ($gradeSummaries as $summary) {
    $gradesByStudent[(int) $summary["student_id"]] = $summary;
}
$courses = $pdo->query(
    "SELECT c.course_name, COUNT(r.student_id) AS student_count
     FROM courses c
     JOIN course_registrations r ON r.course_id = c.id AND r.status = 'active'
     GROUP BY c.id, c.course_name
     ORDER BY student_count DESC, c.course_name ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Management System</title>
    <link rel="stylesheet" href="style.css?v=20261105-header-nav">
</head>
<body>
    <header class="feature-header">
        <h1>Student Management System</h1>
        <nav class="feature-nav dashboard-nav" aria-label="Main navigation">
            <span>Signed in as <?= h($_SESSION["admin_username"] ?? "Admin") ?></span>
            <a href="courses.php">Course</a>
            <a href="attendance.php">Attendance</a>
            <a href="marks.php">Grades &amp; Results</a>
            <form method="POST" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <button type="submit">Log Out</button>
            </form>
        </nav>
    </header>
    <main>
        <section class="dashboard" aria-label="Student overview">
            <article class="card dashboard-total"><h2>Total Students</h2><p class="total-count"><?= count($students) ?></p></article>
            <article class="card dashboard-courses">
                <h2>Students by Course</h2>
                <?php if ($courses): ?>
                    <div class="table-wrap"><table><thead><tr><th>Course</th><th>Students</th></tr></thead><tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr><td><?= h($course["course_name"]) ?></td><td><?= (int) $course["student_count"] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?><p>No course data yet. Add a student to see the breakdown.</p><?php endif; ?>
            </article>
        </section>
        <section class="card">
            <h2>Add Student</h2>
            <?php if (isset($_GET["added"])): ?><p class="success">Student added successfully!</p><?php endif; ?>
            <?php if (isset($_GET["updated"])): ?><p class="success">Student updated successfully!</p><?php endif; ?>
            <?php if (isset($_GET["deleted"])): ?><p class="success">Student deleted.</p><?php endif; ?>
            <?php if (($_GET["error"] ?? "") === "student-linked"): ?><p class="error">This student has related records and could not be deleted.</p><?php endif; ?>
            <?php if (isset($error)): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input name="roll_no" placeholder="Roll Number" required>
                <input name="name" placeholder="Student Name" required>
                <input type="email" name="email" placeholder="Email">
                <input name="course" placeholder="Primary course (optional)">
                <input name="phone" placeholder="Phone Number">
                <label>Semester
                    <select name="semester" required>
                        <option value="">Choose a semester</option>
                        <?php for ($semesterOption = 1; $semesterOption <= 8; $semesterOption++): ?>
                            <option value="<?= $semesterOption ?>">Semester <?= $semesterOption ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit">Add Student</button>
            </form>
        </section>
        <section class="card">
            <h2>Student Records</h2>
            <input id="search" placeholder="Search students...">
            <p>Total Students: <?= count($students) ?></p>
            <div class="table-wrap">
                <table id="studentTable">
                    <thead><tr><th>Roll No.</th><th>Name</th><th>Email</th><th>Course</th><th>Semester</th><th>Attendance</th><th>Subject Marks</th><th>Total Marks</th><th>Grade</th><th>Phone</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= h($student["roll_no"]) ?></td>
                            <td><?= h($student["name"]) ?></td>
                            <td><?= h($student["email"]) ?></td>
                            <td><?= h($student["assigned_course_name"]) ?></td>
                            <td><?= $student["semester"] === null ? "—" : (int) $student["semester"] ?></td>
                            <td><span class="attendance-value"><?= !isset($attendanceByStudent[(int) $student["id"]]) ? "—" : h($attendanceByStudent[(int) $student["id"]]) . "%" ?></span><a class="student-detail-link" href="attendance.php?history_student=<?= (int) $student["id"] ?>">Details</a></td>
                            <td>
                                <?php $studentMarks = $gradesByStudent[(int) $student["id"]] ?? null; ?>
                                <?php $studentSubjects = $subjectMarksByStudent[(int) $student["id"]] ?? []; ?>
                                <?php if ($studentSubjects): foreach ($studentSubjects as $subjectMark): ?>
                                    <span class="subject-mark"><strong><?= h($subjectMark["subject_name"]) ?></strong> (<?= h($subjectMark["course_name"]) ?>): <?= h(number_format((float) $subjectMark["total_obtained"], 2)) ?> / <?= h(number_format((float) $subjectMark["maximum_marks"], 2)) ?></span><br>
                                <?php endforeach; else: ?>—<?php endif; ?>
                                <a class="student-detail-link" href="marks.php?student_id=<?= (int) $student["id"] ?>#student-results">Details</a>
                            </td>
                            <td><?php if ($studentMarks !== null): ?><strong class="student-total-marks"><?= h(number_format((float) $studentMarks["total_obtained"], 2)) ?> / <?= h(number_format((float) $studentMarks["maximum_marks"], 2)) ?></strong><?php else: ?>—<?php endif; ?></td>
                            <td>
                                <?php if ($studentMarks !== null): $studentGrade = academic_grade((float) $studentMarks["percentage"]); ?>
                                    <span class="grade grade-<?= h(strtolower(str_replace("+", "plus", $studentGrade))) ?>"><?= h($studentGrade) ?> (<?= h($studentMarks["percentage"]) ?>%)</span>
                                <?php else: ?>—<?php endif; ?>
                                <a class="student-detail-link" href="marks.php?student_id=<?= (int) $student["id"] ?>#student-results">Details</a>
                            </td>
                            <td><?= h($student["phone"]) ?></td>
                            <td>
                                <a href="edit_student.php?id=<?= (int) $student["id"] ?>">Edit</a>
                                <form method="POST" action="delete_student.php" class="delete-form" onsubmit="return confirm('Delete this student?')">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $student["id"] ?>">
                                    <button type="submit" class="delete-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
        document.getElementById("search").addEventListener("input", function () {
            const query = this.value.toLowerCase();
            document.querySelectorAll("#studentTable tbody tr").forEach(row => {
                row.hidden = !row.innerText.toLowerCase().includes(query);
            });
        });
    </script>
</body>
</html>
