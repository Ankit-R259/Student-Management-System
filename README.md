# Student Management System

This project uses PHP and MySQL.

## Requirements

- PHP 8.0+ with PDO MySQL enabled
- MySQL 8.0+
- Apache, XAMPP, or another PHP-capable web server

## Setup

1. Start MySQL and create the database by importing `database.sql` in phpMyAdmin or the MySQL command line.
2. Copy `.env.example` to `.env` in this folder and set `MYSQL_PASSWORD` to your MySQL account's password. The app reads this file automatically. Alternatively, configure these environment variables for the PHP/web-server process (they take precedence over `.env`):
   - `STUDENT_DB_HOST` (default: `localhost`)
   - `STUDENT_DB_PORT` (default: `3306`; `MYSQL_PORT` is also supported)
   - `STUDENT_DB_NAME` (default: `student_db`)
   - `STUDENT_DB_USER` (default: `root`)
   - `STUDENT_DB_PASSWORD` (default: empty; `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` are also supported)

   The `.env` file is excluded from source control. Restart the web server after changing its environment variables. Do not commit database passwords.
3. Place this folder under your web server's document root.
4. Open `setup_admin.php` in your browser and create the first administrator account.
5. Sign in and manage students, courses, registrations, attendance, and grades.

`db.php` also creates any missing application tables and enrolls existing students in their primary course when connected to the database.

When upgrading an older database whose `students.id` column is signed, run `migrations/004_unsigned_student_ids.sql` before opening the app. It updates the student ID columns to match the current schema while preserving existing records.

## Course registration

Students can be registered in multiple courses from `courses.php`. Withdrawing a registration preserves existing attendance and grade records.
