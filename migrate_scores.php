<?php
// migrate_scores.php
// Usage (CLI recommended): php migrate_scores.php
// This script will create/alter `assessments` and `scores` tables to support normalized subjects/assessments,
// and optionally migrate existing scores.subject text into `subjects` and `assessments`.

ini_set('display_errors', 1);
error_reporting(E_ALL);

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) echo "<pre>";

include __DIR__ . '/database.php';

echo "Starting scores/assessments migration...\n";

// Create subjects table if missing (safe)
$connection->query("CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Create assessments table if missing
$connection->query("CREATE TABLE IF NOT EXISTS assessments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NULL,
  name VARCHAR(150) NOT NULL,
  max_score DECIMAL(6,2) DEFAULT 100,
  assessment_date DATE DEFAULT NULL,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Check if scores table exists; if not, create it
$tbl = $connection->query("SHOW TABLES LIKE 'scores'");
if (!$tbl || $tbl->num_rows == 0) {
    echo "Creating scores table...\n";
    $connection->query("CREATE TABLE IF NOT EXISTS scores (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} else {
    echo "Scores table exists — ensuring columns exist...\n";
    // Add missing columns if needed
    $cols = ['subject_id', 'assessment_id', 'created_at'];
    foreach ($cols as $c) {
        $res = $connection->query("SHOW COLUMNS FROM scores LIKE '" . $connection->real_escape_string($c) . "'");
        if (!$res || $res->num_rows == 0) {
            if ($c === 'created_at') {
                $connection->query("ALTER TABLE scores ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            } else {
                $connection->query("ALTER TABLE scores ADD COLUMN `" . $connection->real_escape_string($c) . "` INT DEFAULT NULL");
            }
            echo "Added column: $c\n";
        }
    }
}

// Now migrate existing scores.subject text into subjects + assessments if subject_id is null
echo "Scanning for legacy score rows with subject text...\n";
$distinct = $connection->query("SELECT DISTINCT TRIM(subject) AS subject FROM scores WHERE subject IS NOT NULL AND TRIM(subject) <> '' AND (subject_id IS NULL OR subject_id = 0)");
if ($distinct && $distinct->num_rows > 0) {
    $insertSubject = $connection->prepare("INSERT IGNORE INTO subjects (name) VALUES (?)");
    $getSubjectId = $connection->prepare("SELECT id FROM subjects WHERE name = ? LIMIT 1");
    $insertAssessment = $connection->prepare("INSERT INTO assessments (subject_id, name) VALUES (?, ?)");
    $updateScores = $connection->prepare("UPDATE scores SET subject_id = ?, assessment_id = ? WHERE subject = ? AND (subject_id IS NULL OR subject_id = 0)");

    $migratedCount = 0;
    while ($row = $distinct->fetch_assoc()) {
        $subName = $row['subject'];
        if ($subName === null || trim($subName) === '') continue;
        $subName = trim($subName);

        // insert subject if missing
        $insertSubject->bind_param('s', $subName);
        $insertSubject->execute();

        // get id
        $getSubjectId->bind_param('s', $subName);
        $getSubjectId->execute();
        $gr = $getSubjectId->get_result();
        $sid = null;
        if ($gr && ($g = $gr->fetch_assoc())) $sid = intval($g['id']);

        if ($sid) {
            // create a generic imported assessment for this subject
            $assName = 'Imported - ' . $subName;
            $insertAssessment->bind_param('is', $sid, $assName);
            $insertAssessment->execute();
            $aid = intval($insertAssessment->insert_id);
            if ($aid === 0) {
                // maybe assessment already exists — try to fetch
                $ar = $connection->query($connection->real_escape_string("SELECT id FROM assessments WHERE subject_id = " . $sid . " AND name = '" . $connection->real_escape_string($assName) . "' LIMIT 1"));
                if ($ar && ($aa = $ar->fetch_assoc())) $aid = intval($aa['id']);
            }

            // If still no assessment id, set to NULL
            if ($aid <= 0) $aid = null;

            // update scores rows using this subject text
            $updateScores->bind_param('iis', $sid, $aid, $subName);
            $updateScores->execute();
            $migratedCount += $updateScores->affected_rows;
            echo "Migrated subject '$subName' -> subject_id={$sid}, assessment_id={$aid}, updated {$updateScores->affected_rows} rows\n";
        }
    }

    $insertSubject->close();
    $getSubjectId->close();
    $insertAssessment->close();
    $updateScores->close();

    echo "Migration complete. Rows migrated (approx): {$migratedCount}\n";
} else {
    echo "No legacy score subjects found or already migrated.\n";
}

echo "Done.\n";
if (!$isCli) echo "</pre>";

?>