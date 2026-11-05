<?php
$envFile = __DIR__ . DIRECTORY_SEPARATOR . ".env";
$envConfig = is_file($envFile) ? parse_ini_file($envFile, false, INI_SCANNER_RAW) : [];
if ($envConfig === false) {
    error_log("Unable to read the database configuration from .env.");
    http_response_code(500);
    exit("Unable to read the database configuration. Check the .env file syntax.");
}

$getDbSetting = static function ($setting, $default, $aliases = []) use ($envConfig) {
    foreach (array_merge(["STUDENT_DB_" . $setting, "MYSQL_" . $setting], $aliases) as $key) {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        if (array_key_exists($key, $envConfig)) {
            return $envConfig[$key];
        }
    }

    return $default;
};

$host = $getDbSetting("HOST", "localhost");
$port = $getDbSetting("PORT", "3306");
$dbname = $getDbSetting("NAME", "student_db", ["MYSQL_DATABASE"]);
$username = $getDbSetting("USER", "root");
$password = $getDbSetting("PASSWORD", "");

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS courses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            course_code VARCHAR(20) NOT NULL UNIQUE,
            course_name VARCHAR(100) NOT NULL UNIQUE,
            duration_months SMALLINT UNSIGNED NOT NULL DEFAULT 36,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(254) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'teacher') NOT NULL DEFAULT 'teacher',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS students (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            roll_no VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NULL,
            course VARCHAR(100) NULL,
            course_id INT UNSIGNED NULL,
            phone VARCHAR(15) NULL,
            semester TINYINT UNSIGNED NULL,
            admission_date DATE NULL,
            profile_photo VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_students_course FOREIGN KEY (course_id)
                REFERENCES courses(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_registrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            status ENUM('active', 'withdrawn') NOT NULL DEFAULT 'active',
            registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            withdrawn_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_course_registration_student_course (student_id, course_id),
            KEY idx_course_registration_course_status (course_id, status),
            CONSTRAINT fk_course_registrations_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_course_registrations_course FOREIGN KEY (course_id)
                REFERENCES courses(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "INSERT IGNORE INTO course_registrations (student_id, course_id)
         SELECT id, course_id FROM students WHERE course_id IS NOT NULL"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            class_date DATE NOT NULL,
            subject_name VARCHAR(100) NOT NULL DEFAULT '',
            status ENUM('present', 'absent', 'on_leave') NOT NULL,
            marked_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_class (student_id, class_date, course_id, subject_name),
            KEY idx_attendance_course_date (course_id, class_date),
            CONSTRAINT fk_attendance_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_attendance_course FOREIGN KEY (course_id)
                REFERENCES courses(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_attendance_marker FOREIGN KEY (marked_by)
                REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_marks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            subject_name VARCHAR(100) NOT NULL,
            internal_max DECIMAL(7,2) UNSIGNED NOT NULL,
            internal_obtained DECIMAL(7,2) UNSIGNED NOT NULL,
            external_max DECIMAL(7,2) UNSIGNED NOT NULL,
            external_obtained DECIMAL(7,2) UNSIGNED NOT NULL,
            recorded_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_subject_marks (student_id, course_id, subject_name),
            CONSTRAINT fk_marks_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_marks_course FOREIGN KEY (course_id)
                REFERENCES courses(id) ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_marks_recorder FOREIGN KEY (recorded_by)
                REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            fee_type VARCHAR(100) NOT NULL,
            amount_due DECIMAL(10,2) UNSIGNED NOT NULL,
            amount_paid DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
            due_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fee_records_student_due (student_id, due_date),
            CONSTRAINT fk_fee_records_student FOREIGN KEY (student_id)
                REFERENCES students(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            body TEXT NOT NULL,
            posted_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_notices_created_at (created_at),
            CONSTRAINT fk_notices_author FOREIGN KEY (posted_by)
                REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit("Database connection failed. Check that MySQL is running, the database exists, and the STUDENT_DB_* settings are correct.");
}
?>
