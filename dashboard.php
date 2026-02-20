<?php
// Prevent PHP warnings/notices from being sent as HTML in AJAX responses.
// Log errors instead to a file for investigation.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

include 'database.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['teacher_id'])) {
    header("Location: index.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// Get user role if not in session
if (!isset($_SESSION['user_role'])) {
    $stmt = $connection->prepare("SELECT role FROM teachers WHERE id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_role'] = $user['role'];
    }
    $stmt->close();
}

// Get all classes for this teacher
$stmt = $connection->prepare("SELECT * FROM classes WHERE teacher_id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get public classes that have advisers so other teachers can view their seating
$public_stmt = $connection->prepare("SELECT c.*, t.fullName as adviser_name, t.id as adviser_user_id FROM classes c LEFT JOIN teachers t ON c.adviser_id = t.id WHERE c.adviser_id IS NOT NULL ORDER BY c.className, c.section");
$public_stmt->execute();
$public_res = $public_stmt->get_result();
$public_classes = $public_res->fetch_all(MYSQLI_ASSOC);
$public_stmt->close();

// Handle adding new class
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_class') {
    $className = $_POST['className'];
    $section = $_POST['section'];
    $is_advisory = isset($_POST['is_advisory']) ? 1 : 0;
    $academic_year = date('Y');
    $adviser_id = $is_advisory ? $teacher_id : null;

    $stmt = $connection->prepare("INSERT INTO classes (teacher_id, className, section, academic_year, is_advisory, adviser_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issiii", $teacher_id, $className, $section, $academic_year, $is_advisory, $adviser_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Class added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add class']);
    }
    $stmt->close();
    exit();
}

// Handle getting students for a class (seating arrangement)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_students') {
    $class_id = intval($_GET['class_id']);
    $stmt = $connection->prepare("SELECT * FROM students WHERE class_id = ? ORDER BY seat_row, seat_column");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'students' => $students]);
    exit();
}

// Get last seating update timestamp for a class
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_class_last_update') {
    $class_id = intval($_GET['class_id']);
    $stmt = $connection->prepare("SELECT last_seating_update FROM classes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    $ts = $row && $row['last_seating_update'] ? $row['last_seating_update'] : null;
    echo json_encode(['success' => true, 'last_update' => $ts]);
    exit();
}

// Handle adding student with auto-seat assignment (transaction-safe)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_student_auto') {
    $class_id = intval($_POST['class_id']);
    $student_name = $_POST['student_name'];

    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT COALESCE(MAX(seat_row), 0) as max_row, COALESCE(MAX(seat_column), 0) as max_col FROM students WHERE class_id = ? FOR UPDATE");
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row_data = $result->fetch_assoc();
        $stmt->close();

        $seat_row = $row_data['max_row'] ?: 1;
        $seat_column = ($row_data['max_col'] ?: 0) + 1;
        if ($seat_column > 10) {
            $seat_row++;
            $seat_column = 1;
        }
        if ($seat_row > 5) {
            $connection->rollback();
            echo json_encode(['success' => false, 'message' => 'Seating arrangement is full (max 50 students)']);
            exit();
        }

        $insert = $connection->prepare("INSERT INTO students (class_id, student_name, seat_row, seat_column) VALUES (?, ?, ?, ?)");
        $insert->bind_param("isii", $class_id, $student_name, $seat_row, $seat_column);
        $insert->execute();
        $insert->close();

        // Update last_seating_update on classes
        $u = $connection->prepare("UPDATE classes SET last_seating_update = NOW() WHERE id = ?");
        $u->bind_param("i", $class_id);
        $u->execute();
        $u->close();

        $connection->commit();
        echo json_encode(['success' => true, 'message' => 'Student added successfully']);
    } catch (Exception $e) {
        $connection->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to add student']);
    }
    exit();
}

// Handle adding student to specific seat (transaction-safe)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_student_specific_seat') {
    $class_id = intval($_POST['class_id']);
    $student_name = $_POST['student_name'];
    $seat_row = intval($_POST['seat_row']);
    $seat_column = intval($_POST['seat_column']);

    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT id FROM students WHERE class_id = ? AND seat_row = ? AND seat_column = ? FOR UPDATE");
        $stmt->bind_param("iii", $class_id, $seat_row, $seat_column);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            $connection->rollback();
            echo json_encode(['success' => false, 'message' => 'This seat is already occupied']);
            exit();
        }
        $stmt->close();

        $insert = $connection->prepare("INSERT INTO students (class_id, student_name, seat_row, seat_column) VALUES (?, ?, ?, ?)");
        $insert->bind_param("isii", $class_id, $student_name, $seat_row, $seat_column);
        $insert->execute();
        $insert->close();

        $u = $connection->prepare("UPDATE classes SET last_seating_update = NOW() WHERE id = ?");
        $u->bind_param("i", $class_id);
        $u->execute();
        $u->close();

        $connection->commit();
        echo json_encode(['success' => true, 'message' => 'Student added successfully']);
    } catch (Exception $e) {
        $connection->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to add student']);
    }
    exit();
}

// Handle deleting a class
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_class') {
    $class_id = intval($_POST['class_id']);
    $teacher_id = $_SESSION['teacher_id'];
    $stmt = $connection->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $class_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Class not found']);
        exit();
    }
    $stmt->close();

    // Delete related data safely inside a transaction to avoid FK constraint failures
    $connection->begin_transaction();
    try {
        // Delete scores for this class first (scores may reference students)
        $d1 = $connection->prepare("DELETE FROM scores WHERE class_id = ?");
        if ($d1) {
            $d1->bind_param("i", $class_id);
            $d1->execute();
            $d1->close();
        }

        // Then delete students
        $d2 = $connection->prepare("DELETE FROM students WHERE class_id = ?");
        if ($d2) {
            $d2->bind_param("i", $class_id);
            $d2->execute();
            $d2->close();
        }

        // Finally delete the class record (ensure teacher owns it)
        $d3 = $connection->prepare("DELETE FROM classes WHERE id = ? AND teacher_id = ?");
        if ($d3) {
            $d3->bind_param("ii", $class_id, $teacher_id);
            if (!$d3->execute()) throw new Exception('Failed to delete class');
            $d3->close();
        } else {
            throw new Exception('Failed to prepare delete class');
        }

        $connection->commit();
        echo json_encode(['success' => true, 'message' => 'Class deleted successfully']);
    } catch (Exception $e) {
        $connection->rollback();
        error_log('Delete class error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete class: ' . $e->getMessage()]);
    }
    exit();
}

// Handle editing student (update name) and update seating timestamp
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_student') {
    $student_id = intval($_POST['student_id']);
    $student_name = $_POST['student_name'];

    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("UPDATE students SET student_name = ? WHERE id = ?");
        $stmt->bind_param("si", $student_name, $student_id);
        $stmt->execute();
        $stmt->close();

        // get class id
        $g = $connection->prepare("SELECT class_id FROM students WHERE id = ? LIMIT 1");
        $g->bind_param("i", $student_id);
        $g->execute();
        $res = $g->get_result();
        $row = $res->fetch_assoc();
        $g->close();
        if ($row && isset($row['class_id'])) {
            $class_id = intval($row['class_id']);
            $u = $connection->prepare("UPDATE classes SET last_seating_update = NOW() WHERE id = ?");
            $u->bind_param("i", $class_id);
            $u->execute();
            $u->close();
        }

        $connection->commit();
        echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
    } catch (Exception $e) {
        $connection->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to update student']);
    }
    exit();
}

// Claim advisory for a class (teacher becomes adviser for the class)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'claim_advisory') {
    $class_id = intval($_POST['class_id']);

    // Check if class already has an adviser
    $stmt = $connection->prepare("SELECT adviser_id FROM classes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['adviser_id'])) {
        echo json_encode(['success' => false, 'message' => 'This class already has an adviser']);
        exit();
    }

    $stmt = $connection->prepare("UPDATE classes SET adviser_id = ?, is_advisory = 1 WHERE id = ?");
    $stmt->bind_param("ii", $teacher_id, $class_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'You are now the adviser for this class']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to claim advisory']);
    }
    $stmt->close();
    exit();
}

// Handle getting scores for a class
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_scores') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $class_id = intval($_GET['class_id']);

    // Detect whether normalized columns exist
    $hasSubjectId = false;
    $hasAssessmentId = false;
    $r1 = $connection->query("SHOW COLUMNS FROM scores LIKE 'subject_id'");
    $r2 = $connection->query("SHOW COLUMNS FROM scores LIKE 'assessment_id'");
    if ($r1 && $r1->num_rows > 0) $hasSubjectId = true;
    if ($r2 && $r2->num_rows > 0) $hasAssessmentId = true;

    if ($hasSubjectId && $hasAssessmentId) {
        $stmt = $connection->prepare("SELECT s.id, s.student_name, sc.score, sc.created_at, sc.subject AS subject_text, sub.name AS subject_name, a.name AS assessment_name, sc.subject_id, sc.assessment_id FROM students s LEFT JOIN scores sc ON s.id = sc.student_id LEFT JOIN subjects sub ON sc.subject_id = sub.id LEFT JOIN assessments a ON sc.assessment_id = a.id WHERE s.class_id = ? ORDER BY sc.created_at DESC LIMIT 200");
    } elseif ($hasSubjectId && !$hasAssessmentId) {
        // scores table has subject_id but no assessment_id
        $stmt = $connection->prepare("SELECT s.id, s.student_name, sc.score, sc.created_at, sc.subject AS subject_text, sub.name AS subject_name, sc.subject_id FROM students s LEFT JOIN scores sc ON s.id = sc.student_id LEFT JOIN subjects sub ON sc.subject_id = sub.id WHERE s.class_id = ? ORDER BY sc.created_at DESC LIMIT 200");
    } else {
        // legacy schema
        $stmt = $connection->prepare("SELECT s.id, s.student_name, sc.subject, sc.score FROM students s LEFT JOIN scores sc ON s.id = sc.student_id WHERE s.class_id = ? ORDER BY sc.id DESC LIMIT 200");
    }
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $scores = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = ob_get_clean();
    $resp = ['success' => true, 'scores' => $scores];
    if ($out !== '') $resp['debug'] = trim($out);
    echo json_encode($resp);
    exit();
}
 


// Handle adding score (old version - kept for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_score') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $student_id = intval($_POST['student_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    // NOTE: client currently sends assessment name in the 'subject' field (legacy naming)
    $assessmentName = trim($_POST['subject'] ?? '');
    $score = $_POST['score'] ?? null;

    $subject_id = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? intval($_POST['subject_id']) : null;
    $assessment_id = null;

    // Determine legacy subject text to store in `scores.subject` (use subject name when subject_id provided)
    $subject_text = '';
    if ($subject_id) {
        $sstmt = $connection->prepare("SELECT name FROM subjects WHERE id = ? LIMIT 1");
        if ($sstmt) {
            $sstmt->bind_param('i', $subject_id);
            $sstmt->execute();
            $sr = $sstmt->get_result();
            if ($sr && $sr->num_rows > 0) {
                $subject_text = $sr->fetch_assoc()['name'];
            }
            $sstmt->close();
        }
    }
    // fallback: if no subject_id or lookup failed, use assessmentName as subject_text (legacy behavior)
    if (empty($subject_text)) $subject_text = $assessmentName;

    // If we have a subject_id and an assessment name, try to find or create the assessment and get its id
    if ($subject_id && $assessmentName !== '') {
        $as = $connection->prepare("SELECT id FROM assessments WHERE subject_id = ? AND name = ? LIMIT 1");
        if ($as) {
            $as->bind_param('is', $subject_id, $assessmentName);
            $as->execute();
            $ar = $as->get_result();
            if ($ar && $ar->num_rows > 0) {
                $assessment_id = intval($ar->fetch_assoc()['id']);
            }
            $as->close();
        }
        if (!$assessment_id) {
            // Insert assessment (don't assume a created_at column exists)
            $ins = $connection->prepare("INSERT INTO assessments (subject_id, name) VALUES (?, ?)");
            if ($ins) {
                $ins->bind_param('is', $subject_id, $assessmentName);
                if ($ins->execute()) {
                    $assessment_id = intval($connection->insert_id);
                }
                $ins->close();
            }
        }
    }

    $hasSubjectId = false;
    $hasAssessmentId = false;
    $r = $connection->query("SHOW COLUMNS FROM scores LIKE 'subject_id'");
    $rA = $connection->query("SHOW COLUMNS FROM scores LIKE 'assessment_id'");
    if ($r && $r->num_rows > 0) $hasSubjectId = true;
    if ($rA && $rA->num_rows > 0) $hasAssessmentId = true;

    // Basic validation
    if ($student_id <= 0 || $class_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student or class id']);
        exit();
    }
    if ($score === null || $score === '') {
        echo json_encode(['success' => false, 'message' => 'Score is required']);
        exit();
    }
    if (!is_numeric($score)) {
        echo json_encode(['success' => false, 'message' => 'Score must be numeric']);
        exit();
    }

    $scoreVal = floatval($score);

    $stmt = null;
    if ($hasSubjectId) {
        if ($hasAssessmentId) {
            // Handle nullable subject_id/assessment_id by generating SQL with NULL literals when needed
            if ($subject_id === null && $assessment_id === null) {
                $sql = "INSERT INTO scores (student_id, class_id, subject, score, subject_id, assessment_id, created_at) VALUES (?, ?, ?, ?, NULL, NULL, NOW())";
                $stmt = $connection->prepare($sql);
                if ($stmt) $stmt->bind_param("iisd", $student_id, $class_id, $subject_text, $scoreVal);
            } elseif ($subject_id !== null && $assessment_id === null) {
                $sql = "INSERT INTO scores (student_id, class_id, subject, score, subject_id, assessment_id, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())";
                $stmt = $connection->prepare($sql);
                if ($stmt) $stmt->bind_param("iisdi", $student_id, $class_id, $subject_text, $scoreVal, $subject_id);
            } else {
                // both provided
                $sql = "INSERT INTO scores (student_id, class_id, subject, score, subject_id, assessment_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $connection->prepare($sql);
                if ($stmt) $stmt->bind_param("iisdii", $student_id, $class_id, $subject_text, $scoreVal, $subject_id, $assessment_id);
            }
        } else {
            // has subject_id but no assessment_id column
            if ($subject_id === null) {
                $sql = "INSERT INTO scores (student_id, class_id, subject, score, subject_id, created_at) VALUES (?, ?, ?, ?, NULL, NOW())";
                $stmt = $connection->prepare($sql);
                if ($stmt) $stmt->bind_param("iisd", $student_id, $class_id, $subject_text, $scoreVal);
            } else {
                $sql = "INSERT INTO scores (student_id, class_id, subject, score, subject_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $connection->prepare($sql);
                if ($stmt) $stmt->bind_param("iisdi", $student_id, $class_id, $subject_text, $scoreVal, $subject_id);
            }
        }
    } else {
        $sql = "INSERT INTO scores (student_id, class_id, subject, score) VALUES (?, ?, ?, ?)";
        $stmt = $connection->prepare($sql);
        if ($stmt) $stmt->bind_param("iisd", $student_id, $class_id, $subject_text, $scoreVal);
    }

    if (!$stmt) {
        $out = ob_get_clean();
        $resp = ['success' => false, 'message' => 'Failed to prepare insert', 'error' => $connection->error ?? 'prepare_failed'];
        if ($out !== '') $resp['debug'] = trim($out);
        echo json_encode($resp);
        exit();
    }

    if ($stmt->execute()) {
        $out = ob_get_clean();
        $resp = ['success' => true, 'message' => 'Score added successfully', 'assessment_id' => $assessment_id];
        if ($out !== '') $resp['debug'] = trim($out);
        echo json_encode($resp);
    } else {
        $out = ob_get_clean();
        $resp = ['success' => false, 'message' => 'Failed to add score', 'error' => $stmt->error ?: $connection->error];
        if ($out !== '') $resp['debug'] = trim($out);
        echo json_encode($resp);
    }
    if ($stmt) $stmt->close();
    exit();
}

// Get list of advisers
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_advisers') {
    $stmt = $connection->query("SELECT id, fullName FROM teachers ORDER BY fullName");
    $advisers = $stmt->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'advisers' => $advisers]);
    exit();
}

// Get list of subjects (JSON)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_subjects') {
    // Return subjects filtered by adviser for the class or by the current teacher
    $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
    $subjects = [];

    // Decide which teacher's subjects to return: if the class has an adviser and the current
    // user is the adviser, return the adviser's subjects. Otherwise return the current user's subjects.
    $currentTeacher = isset($_SESSION['teacher_id']) ? intval($_SESSION['teacher_id']) : null;
    $targetTeacher = $currentTeacher;
    if ($class_id && $currentTeacher) {
        $cstmt = $connection->prepare("SELECT adviser_id FROM classes WHERE id = ? LIMIT 1");
        if ($cstmt) {
            $cstmt->bind_param('i', $class_id);
            $cstmt->execute();
            $cres = $cstmt->get_result();
            if ($cres && $cres->num_rows > 0) {
                $crow = $cres->fetch_assoc();
                $adviser = $crow['adviser_id'] ? intval($crow['adviser_id']) : null;
                // If current user is the adviser, show adviser's subjects; otherwise show current user's subjects
                if ($adviser && $adviser === $currentTeacher) {
                    $targetTeacher = $adviser;
                }
            }
            $cstmt->close();
        }
    }

    if ($targetTeacher) {
        $sstmt = $connection->prepare("SELECT s.id, s.name FROM subjects s JOIN teacher_subjects ts ON s.id = ts.subject_id WHERE ts.teacher_id = ? ORDER BY s.name");
        if ($sstmt) {
            $sstmt->bind_param('i', $targetTeacher);
            $sstmt->execute();
            $sres = $sstmt->get_result();
            if ($sres) $subjects = $sres->fetch_all(MYSQLI_ASSOC);
            $sstmt->close();
        }
    }

    // If no subjects found for the selected teacher, fall back to returning all subjects to avoid empty dropdowns
    if (empty($subjects)) {
        $all = $connection->query("SELECT id, name FROM subjects ORDER BY name");
        $subjects = $all ? $all->fetch_all(MYSQLI_ASSOC) : [];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'subjects' => $subjects]);
    exit();
}

// Get assessments for a subject (JSON)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_assessments') {
    $subject_id = intval($_GET['subject_id'] ?? 0);
    if ($subject_id <= 0) {
        echo json_encode(['success' => false, 'assessments' => []]);
        exit();
    }
    $stmt = $connection->prepare("SELECT id, name FROM assessments WHERE subject_id = ? ORDER BY name");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $assessments = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    echo json_encode(['success' => true, 'assessments' => $assessments]);
    exit();
}

// Seating request endpoints removed — advisers update seating directly

// Get seating requests for adviser
// Seating request endpoints removed

// Adviser lookup is now handled when rendering class info; no separate endpoint

// Seating plan approval flow removed — advisers update seating directly

// Seating request rejection flow removed
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Teacher's Monitoring System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Teacher's Monitoring System</span>
            <div class="d-flex ms-auto align-items-center gap-2">
                <div class="dropdown">
                <button class="btn profile-menu-toggle" id="profileMenuBtn" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="menu-lines" aria-hidden="true"><span></span></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileMenuBtn">
                    <li class="px-3 py-2 text-muted small">Signed in as <strong><?php echo htmlspecialchars(isset($_SESSION['first_name']) ? $_SESSION['first_name'] : ($_SESSION['username'] ?? '')); ?></strong></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="profile.php?id=<?php echo intval($teacher_id); ?>">View Profile</a></li>
                    <li><a class="dropdown-item" href="#" id="quickEditProfileToggle">Edit Profile</a></li>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                        <li><a class="dropdown-item" href="admin.php">Admin Panel</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-5">
        <div class="row">
            <div class="col-lg-8 dashboard-main">
                <?php
                // Note: quick profile card removed per UI request. The quick edit modal is opened
                // from the navbar dropdown's Edit Profile item which triggers the hidden button.
                ?>

                <!-- Edit Profile Modal -->
                <div class="modal fade" id="quickProfileModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Profile</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="quickProfileForm">
                                    <input type="hidden" name="user_id" id="qp_user_id">
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="email" id="qp_email">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Contacts</label>
                                        <input type="text" class="form-control" name="contacts" id="qp_contacts">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Subjects</label>
                                        <div id="qp_subjects_checkbox_list" class="mb-2">
                                            <?php
                                            // Render checkboxes of all available subjects so teachers can select existing ones
                                            $allSubjectsStmt = $connection->query("SELECT id, name FROM subjects ORDER BY name");
                                            $linked = [];
                                            $ls = $connection->prepare("SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?");
                                            $ls->bind_param("i", $teacher_id);
                                            $ls->execute();
                                            $lres = $ls->get_result();
                                            while ($row = $lres->fetch_assoc()) $linked[] = intval($row['subject_id']);
                                            $ls->close();
                                            if ($allSubjectsStmt && $allSubjectsStmt->num_rows > 0) {
                                                echo '<div class="row">';
                                                while ($s = $allSubjectsStmt->fetch_assoc()) {
                                                    $checked = in_array(intval($s['id']), $linked) ? 'checked' : '';
                                                    echo '<div class="col-6"><div class="form-check">';
                                                    echo '<input class="form-check-input qp-subject-cb" type="checkbox" name="subjects[]" value="' . htmlspecialchars($s['name']) . '" id="qp_sub_' . intval($s['id']) . '" ' . $checked . '>';
                                                    echo '<label class="form-check-label" for="qp_sub_' . intval($s['id']) . '">' . htmlspecialchars($s['name']) . '</label>';
                                                    echo '</div></div>';
                                                }
                                                echo '</div>';
                                            } else {
                                                echo '<p class="text-muted small">No subjects available. Ask an admin to add subjects.</p>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button id="qp_save_btn" type="button" class="btn btn-primary">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- My Advisory Class Section -->
                <?php 
                $advisory_classes = array_filter($classes, function($c) { return $c['is_advisory'] == 1; });
                if (count($advisory_classes) > 0): 
                ?>
                <div class="advisory-classes-section">
                    <h3>My Advisory Class</h3>
                    <?php foreach ($advisory_classes as $class): ?>
                        <div class="card advisory-card mb-3">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <h5 class="card-title"><?php echo htmlspecialchars($class['className']) . " - " . htmlspecialchars($class['section']); ?></h5>
                                        <small class="text-muted">Academic Year: <?php echo htmlspecialchars($class['academic_year']); ?></small>
                                        <?php if ($class['adviser_id']): ?>
                                            <div style="margin-top: 0.5rem;">
                                                <?php 
                                                    $adviser_stmt = $connection->prepare("SELECT fullName FROM teachers WHERE id = ?");
                                                    $adviser_stmt->bind_param("i", $class['adviser_id']);
                                                    $adviser_stmt->execute();
                                                    $adviser_result = $adviser_stmt->get_result();
                                                    if ($adviser_result->num_rows > 0) {
                                                        $adviser = $adviser_result->fetch_assoc();
                                                        echo '<small class="text-success"><strong>Adviser: <a href="profile.php?id=' . intval($class['adviser_id']) . '">' . htmlspecialchars($adviser['fullName']) . '</a></strong></small>';
                                                    }
                                                    $adviser_stmt->close();
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-5 text-end">
                                        <button class="btn btn-sm btn-info view-seating" data-class-id="<?php echo $class['id']; ?>">Seating</button>
                                        <button class="btn btn-sm btn-success view-scores" data-class-id="<?php echo $class['id']; ?>">Scores</button>
                                        <button class="btn btn-sm btn-danger delete-class" data-class-id="<?php echo $class['id']; ?>">Delete</button>
                                                            <?php if (empty($class['adviser_id'])): ?>
                                                                <button class="btn btn-sm btn-outline-primary claim-advisory" data-class-id="<?php echo $class['id']; ?>">Claim Advisory</button>
                                                            <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <h2 class="mb-4">My Classes</h2>

                <!-- Public Adviser Sections (visible to all teachers) -->
                <?php if (!empty($public_classes)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Adviser Sections (Public)</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($public_classes as $pc): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($pc['className'] . ' - ' . $pc['section']); ?></strong>
                                        <div class="small text-muted">Adviser: <a href="profile.php?id=<?php echo intval($pc['adviser_user_id']); ?>"><?php echo htmlspecialchars($pc['adviser_name']); ?></a></div>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-info view-seating" data-class-id="<?php echo intval($pc['id']); ?>">View Seating</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Add Class Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Add New Class</h5>
                    </div>
                    <div class="card-body">
                        <form id="addClassForm">
                            <div class="row">
                                <div class="col-md-5 mb-3">
                                    <label for="className" class="form-label">Class Name</label>
                                    <select class="form-select" id="className" name="className" required>
                                        <option value="">Select Class</option>
                                        <option value="STEM">STEM</option>
                                        <option value="HUMSS">HUMSS</option>
                                        <option value="ABM">ABM</option>
                                        <option value="GAS">GAS</option>
                                        <option value="AD">AD</option>
                                        <option value="HE">HE</option>
                                        <option value="ICT">ICT</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="section" class="form-label">Section</label>
                                    <select class="form-select" id="section" name="section" required>
                                        <option value="">Select Section</option>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="D">D</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="is_advisory" name="is_advisory">
                                        <label class="form-check-label" for="is_advisory">
                                            Mark as Advisory
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Add Class</button>
                        </form>
                    </div>
                </div>

                <!-- Classes List (excluding advisory) -->
                <div id="classesList">
                    <?php 
                    $regular_classes = array_filter($classes, function($c) { return $c['is_advisory'] == 0; });
                    if (count($regular_classes) > 0): 
                    ?>
                        <?php foreach ($regular_classes as $class): ?>
                            <div class="card mb-3 class-card" data-class-id="<?php echo $class['id']; ?>">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-7">
                                                            <h5 class="card-title"><?php echo htmlspecialchars($class['className']) . " - " . htmlspecialchars($class['section']); ?></h5>
                                                            <p class="card-text mb-0">
                                                                <br><small class="text-muted">Academic Year: <?php echo htmlspecialchars($class['academic_year']); ?></small>
                                                            </p>
                                                            <?php if (!empty($class['adviser_id'])): ?>
                                                                <?php
                                                                    $adv_stmt = $connection->prepare("SELECT id, fullName FROM teachers WHERE id = ? LIMIT 1");
                                                                    $adv_stmt->bind_param("i", $class['adviser_id']);
                                                                    $adv_stmt->execute();
                                                                    $adv_r = $adv_stmt->get_result();
                                                                    if ($adv_r && $adv_r->num_rows > 0) {
                                                                        $adv = $adv_r->fetch_assoc();
                                                                        echo '<div class="mt-1"><small class="text-success"><strong>Adviser: <a href="profile.php?id=' . intval($adv['id']) . '">' . htmlspecialchars($adv['fullName']) . '</a></strong></small></div>';
                                                                    }
                                                                    $adv_stmt->close();
                                                                ?>
                                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-5 text-end">
                                            <button class="btn btn-sm btn-info view-seating" data-class-id="<?php echo $class['id']; ?>">Seating</button>
                                            <button class="btn btn-sm btn-success view-scores" data-class-id="<?php echo $class['id']; ?>">Scores</button>
                                            <button class="btn btn-sm btn-danger delete-class" data-class-id="<?php echo $class['id']; ?>">Delete</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="alert alert-info">No regular classes added yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            <!-- hidden trigger used by dropdown Edit Profile to open quick modal -->
            <button id="openEditProfileBtn" class="d-none" data-user-id="<?php echo intval($teacher_id); ?>"></button>

            <!-- Right Sidebar for Scores Only -->
            <div class="col-lg-4 dashboard-sidebar">
                <!-- Scores Section -->
                <div id="scoresSection" class="card" style="display: none;">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0" id="scoresStudentName">Add Scores</h5>
                        <button class="btn btn-sm btn-secondary" onclick="closeScores()">Close</button>
                    </div>
                    <div class="card-body">
                        <div id="scoresList" class="mt-4"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seating Arrangement Modal -->
    <div class="modal fade" id="seatingModal" tabindex="-1" aria-labelledby="seatingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="seatingModalLabel">Seating Arrangement <small id="seatingLastUpdated" class="text-muted ms-2"></small></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left side: Seating Grid -->
                        <div class="col-lg-8">
                            <div class="blackboard mb-3 p-2 text-center text-white">Front (Blackboard)</div>
                            <input type="hidden" id="currentClassId" name="class_id">
                            <p class="small text-muted">Click on a seat to edit or add a student</p>
                            <div id="seatingGrid" class="mt-3"></div>
                        </div>
                        
                        <!-- Right side: Actions (requests removed - seating updates are applied immediately) -->
                        <div class="col-lg-4">
                            <p class="text-muted">Seating updates are applied immediately by advisers.</p>
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Subject (for this seating)</label>
                                        <select id="seatingSubjectSelect" class="form-select">
                                            <option value="">-- Select Subject --</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted">Choose a subject here so score actions use it by default.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editStudentForm">
                    <div class="modal-body">
                        <input type="hidden" id="editStudentId" name="student_id">
                        <input type="hidden" id="currentClassIdForStudent" name="class_id">
                        <div class="mb-3">
                            <label for="editStudentName" class="form-label">Student Name</label>
                            <input type="text" class="form-control" id="editStudentName" name="student_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-success" id="addScoresFromStudentBtn" style="display: none;" data-bs-toggle="modal" data-bs-target="#scoreSheetModal">Add Scores</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Score Sheet Modal -->
    <div class="modal fade" id="scoreSheetModal" tabindex="-1" aria-labelledby="scoreSheetModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="scoreSheetModalLabel">Score Sheet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="scoreSheetForm">
                    <div class="modal-body">
                        <input type="hidden" id="modalStudentId" name="student_id">
                        <input type="hidden" id="modalClassId" name="class_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Subject</label>
                                <select id="scoreSubjectSelect" class="form-select">
                                    <option value="">-- Select Subject --</option>
                                </select>
                            </div>
                        </div>

                        <div class="score-categories">
                            <!-- Quiz -->
                            <div class="category-group">
                                <h6>Quiz</h6>
                                <div class="score-input-group">
                                    <label for="quiz1">Quiz 1</label>
                                    <input type="number" class="form-control form-control-sm" id="quiz1" name="quiz_1" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="quiz2">Quiz 2</label>
                                    <input type="number" class="form-control form-control-sm" id="quiz2" name="quiz_2" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="quiz3">Quiz 3</label>
                                    <input type="number" class="form-control form-control-sm" id="quiz3" name="quiz_3" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                            </div>

                            <!-- Activity -->
                            <div class="category-group">
                                <h6>Activity</h6>
                                <div class="score-input-group">
                                    <label for="activity1">Activity 1</label>
                                    <input type="number" class="form-control form-control-sm" id="activity1" name="activity_1" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="activity2">Activity 2</label>
                                    <input type="number" class="form-control form-control-sm" id="activity2" name="activity_2" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="activity3">Activity 3</label>
                                    <input type="number" class="form-control form-control-sm" id="activity3" name="activity_3" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                            </div>

                            <!-- Project -->
                            <div class="category-group">
                                <h6>Project</h6>
                                <div class="score-input-group">
                                    <label for="project1">Project 1</label>
                                    <input type="number" class="form-control form-control-sm" id="project1" name="project_1" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="project2">Project 2</label>
                                    <input type="number" class="form-control form-control-sm" id="project2" name="project_2" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                            </div>

                            <!-- Exams -->
                            <div class="category-group">
                                <h6>Exams</h6>
                                <div class="score-input-group">
                                    <label for="monthlyExam">Monthly Exam</label>
                                    <input type="number" class="form-control form-control-sm" id="monthlyExam" name="monthly_exam" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                                <div class="score-input-group">
                                    <label for="quarterlyExam">Quarterly Exam</label>
                                    <input type="number" class="form-control form-control-sm" id="quarterlyExam" name="quarterly_exam" min="0" max="100" step="0.01" placeholder="0-100">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Scores</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function(){
        const openBtn = document.getElementById('openEditProfileBtn');
        const modalEl = document.getElementById('quickProfileModal');
        const qpUserId = document.getElementById('qp_user_id');
        const qpEmail = document.getElementById('qp_email');
        const qpContacts = document.getElementById('qp_contacts');
        const qpSubjects = document.getElementById('qp_subjects');
        const qpSave = document.getElementById('qp_save_btn');
        let bsModal = null;
        if (modalEl) bsModal = new bootstrap.Modal(modalEl);

        if (openBtn) {
            openBtn.addEventListener('click', function(){
                const uid = this.getAttribute('data-user-id');
                qpUserId.value = uid;
                // fetch profile JSON
                fetch('profile.php?id=' + encodeURIComponent(uid) + '&format=json')
                    .then(r => r.json())
                    .then(data => {
                        qpEmail.value = data.email || '';
                        qpContacts.value = data.contacts || '';
                        // clear all checkboxes then check those returned in profile
                        const boxes = document.querySelectorAll('#qp_subjects_checkbox_list .qp-subject-cb');
                        boxes.forEach(cb => cb.checked = false);
                        if (data.subjects) {
                            const subs = data.subjects.split(/\r?\n/).map(s => s.trim()).filter(s => s !== '');
                            subs.forEach(sv => {
                                boxes.forEach(cb => {
                                    if (cb.value.toLowerCase() === sv.toLowerCase()) cb.checked = true;
                                });
                            });
                        }
                        if (bsModal) bsModal.show();
                    }).catch(err => {
                        console.error('Failed to load profile', err);
                        alert('Failed to load profile data');
                    });
            });
        }

        // Also allow opening the same modal from the profile dropdown
        const quickToggle = document.getElementById('quickEditProfileToggle');
        if (quickToggle && openBtn) {
            quickToggle.addEventListener('click', function(e){
                e.preventDefault();
                openBtn.click();
            });
        }

        if (qpSave) {
            qpSave.addEventListener('click', function(){
                const uid = qpUserId.value;
                const form = new FormData();
                form.append('save_profile', '1');
                form.append('email', qpEmail.value);
                form.append('contacts', qpContacts.value);
                // append selected subjects[] entries
                const boxes = document.querySelectorAll('#qp_subjects_checkbox_list .qp-subject-cb:checked');
                boxes.forEach(cb => form.append('subjects[]', cb.value));

                fetch('profile.php?id=' + encodeURIComponent(uid), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: form
                }).then(r => r.json())
                  .then(resp => {
                      if (resp && resp.success) {
                          if (bsModal) bsModal.hide();
                          // update small card display (email/subjects not shown), reload to reflect changes
                          location.reload();
                      } else {
                          alert('Failed to save profile');
                      }
                  }).catch(err => {
                      console.error('Save error', err);
                      alert('An error occurred while saving');
                  });
            });
        }
    })();
    </script>
    <script src="assets/js/script.js"></script>
</body>
</html>
