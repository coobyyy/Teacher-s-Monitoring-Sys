-- Migration: Add admin system and seating requests
-- Run these SQL queries in phpMyAdmin or MySQL console

-- DROP old seating request tables (removed feature)
DROP TABLE IF EXISTS seating_plans;
DROP TABLE IF EXISTS seat_requests;

-- 1. Alter teachers table to add role and status columns
ALTER TABLE teachers ADD COLUMN role VARCHAR(20) DEFAULT 'teacher' AFTER username;
ALTER TABLE teachers ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER role;

-- 2. Alter classes table to add adviser_id
ALTER TABLE classes ADD COLUMN adviser_id INT DEFAULT NULL AFTER teacher_id;

ALTER TABLE classes ADD COLUMN last_seating_update TIMESTAMP NULL DEFAULT NULL AFTER adviser_id;

-- 4. Normalize subjects: create subjects and teacher_subjects join table
CREATE TABLE IF NOT EXISTS subjects (
	id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS teacher_subjects (
	teacher_id INT NOT NULL,
	subject_id INT NOT NULL,
	PRIMARY KEY (teacher_id, subject_id),
	FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
	FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- Optional: migrate existing newline-separated `teachers.subjectsinto normalized tables.
-- Run this only once after creating the above tables. It splits subjects by newline and inserts them.
-- Note: adjust SQL mode/commands depending on MySQL version; a small PHP migration script may be safer.
--
-- INSERT INTO subjects (name)
-- SELECT DISTINCT TRIM(s) FROM (
--   SELECT REPLACE(REPLACE(subjects, '\r', ''), '\n', ',') as subjstr FROM teachers WHERE subjects IS NOT NULL
-- ) t
-- CROSS JOIN (SELECT 1) q;
--
-- The above is illustrative; prefer running a PHP script to parse and insert safely.
-- UPDATE teachers SET role = 'admin' WHERE id = 1; -- Set first user as admin

-- 5. Create assessments and scores tables (normalized) and add compatibility columns
CREATE TABLE IF NOT EXISTS assessments (
	id INT AUTO_INCREMENT PRIMARY KEY,
	subject_id INT NULL,
	name VARCHAR(150) NOT NULL,
	max_score DECIMAL(6,2) DEFAULT 100,
	assessment_date DATE DEFAULT NULL,
	FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

-- Create scores table if missing (keeps `subject` text for backward compatibility,
-- and includes `subject_id` and `assessment_id` for normalized data)
CREATE TABLE IF NOT EXISTS scores (
	id INT AUTO_INCREMENT PRIMARY KEY,
	student_id INT NOT NULL,
	class_id INT NOT NULL,
	subject VARCHAR(150) DEFAULT NULL,
	score DECIMAL(6,2) NOT NULL,
	subject_id INT DEFAULT NULL,
	assessment_id INT DEFAULT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX (student_id),
	INDEX (class_id),
	FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
	FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
	FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
	FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE SET NULL
);

-- If an older `scores` table exists without the new columns, the next step is to
-- run an ALTER to add `subject_id`, `assessment_id`, `created_at` as needed.
-- Example (run if necessary):
-- ALTER TABLE scores ADD COLUMN subject_id INT DEFAULT NULL;
-- ALTER TABLE scores ADD COLUMN assessment_id INT DEFAULT NULL;
-- ALTER TABLE scores ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Add initial subjects (provided by user)
INSERT IGNORE INTO subjects (name) VALUES ('Computer Servicing System (CSS) II');
INSERT IGNORE INTO subjects (name) VALUES ('Research Project');
INSERT IGNORE INTO subjects (name) VALUES ('Contemporary Philippine Arts From The Region');
INSERT IGNORE INTO subjects (name) VALUES ('English for Academic and Professional Purposes');
