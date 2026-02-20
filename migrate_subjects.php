<?php
// migrate_subjects.php
// Run once (CLI recommended): php migrate_subjects.php
// This will create `subjects` and `teacher_subjects` tables if missing,
// parse newline-separated `teachers.subjects`, insert distinct subjects,
// and populate the join table.

// IMPORTANT: backup your database before running this script!

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Detect CLI vs web for output formatting
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) echo '<pre>';

include __DIR__ . '/database.php';

echo "Starting subjects migration...\n";

// Create tables if missing
$connection->query("CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$connection->query("CREATE TABLE IF NOT EXISTS teacher_subjects (
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (teacher_id, subject_id),
  INDEX (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Prepare statements
$stmtTeachers = $connection->prepare("SELECT id, subjects FROM teachers");
$insSubject = $connection->prepare("INSERT IGNORE INTO subjects (name) VALUES (?)");
$getSubjectId = $connection->prepare("SELECT id FROM subjects WHERE name = ? LIMIT 1");
$insLink = $connection->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");

if (!$stmtTeachers->execute()) {
    echo "Failed to read teachers: " . $connection->error . "\n";
    if (!$isCli) echo '</pre>';
    exit(1);
}

$result = $stmtTeachers->get_result();
$totalTeachers = 0;
$subjectsInserted = 0;
$linksInserted = 0;

while ($row = $result->fetch_assoc()) {
    $totalTeachers++;
    $teacherId = intval($row['id']);
    $raw = $row['subjects'] ?? '';
    if (trim($raw) === '') continue;

    // Split into lines, support different newline types
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    if (!$lines) continue;

    $clean = [];
    foreach ($lines as $l) {
        $s = trim($l);
        if ($s === '') continue;
        $clean[] = $s;
    }
    $clean = array_values(array_unique($clean));
    if (count($clean) === 0) continue;

    foreach ($clean as $sub) {
        // insert subject if missing
        $insSubject->bind_param('s', $sub);
        $insSubject->execute();
        if ($insSubject->affected_rows > 0) $subjectsInserted++;

        // get id
        $getSubjectId->bind_param('s', $sub);
        $getSubjectId->execute();
        $r = $getSubjectId->get_result();
        $sid = null;
        if ($r && ($srow = $r->fetch_assoc())) {
            $sid = intval($srow['id']);
        }
        if ($sid) {
            $insLink->bind_param('ii', $teacherId, $sid);
            $insLink->execute();
            if ($insLink->affected_rows > 0) $linksInserted++;
        }
    }
}

// Summary counts
$c1 = $connection->query("SELECT COUNT(*) AS c FROM subjects")->fetch_assoc()['c'] ?? 0;
$c2 = $connection->query("SELECT COUNT(*) AS c FROM teacher_subjects")->fetch_assoc()['c'] ?? 0;

echo "Processed teachers: {$totalTeachers}\n";
echo "New subjects inserted (approx): {$subjectsInserted}\n";
echo "New teacher-subject links inserted (approx): {$linksInserted}\n";
echo "Current subjects count: {$c1}\n";
echo "Current teacher_subjects count: {$c2}\n";

echo "Migration complete.\n";

if (!$isCli) echo '</pre>';

?>
