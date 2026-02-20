<?php
include 'database.php';
session_start();

// Admin-only
if (!isset($_SESSION['teacher_id'])) {
    header('Location: index.php'); exit();
}
$uid = $_SESSION['teacher_id'];
$stmt = $connection->prepare("SELECT role FROM teachers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$r = $stmt->get_result();
$u = $r->fetch_assoc();
$stmt->close();
if (!$u || $u['role'] !== 'admin') { die('Access denied'); }

// Handle actions: add, delete, drop_legacy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $i = $connection->prepare("INSERT IGNORE INTO subjects (name) VALUES (?)");
            $i->bind_param('s', $name);
            $i->execute();
            $i->close();
        }
    }
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // Remove related assessments and teacher links to avoid orphaned data
            $delA = $connection->prepare("DELETE FROM assessments WHERE subject_id = ?");
            $delA->bind_param('i', $id);
            $delA->execute();
            $delA->close();

            $delT = $connection->prepare("DELETE FROM teacher_subjects WHERE subject_id = ?");
            $delT->bind_param('i', $id);
            $delT->execute();
            $delT->close();

            $d = $connection->prepare("DELETE FROM subjects WHERE id = ?");
            $d->bind_param('i', $id);
            $d->execute();
            $d->close();
        }
    }
    if ($action === 'add_assessment') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $name = trim($_POST['assessment_name'] ?? '');
        if ($subject_id > 0 && $name !== '') {
            $i = $connection->prepare("INSERT IGNORE INTO assessments (subject_id, name) VALUES (?, ?)");
            $i->bind_param('is', $subject_id, $name);
            $i->execute();
            $i->close();
        }
    }
    if ($action === 'delete_assessment') {
        $id = intval($_POST['assessment_id'] ?? 0);
        if ($id > 0) {
            $d = $connection->prepare("DELETE FROM assessments WHERE id = ?");
            $d->bind_param('i', $id);
            $d->execute();
            $d->close();
        }
    }
    if ($action === 'drop_legacy') {
        // Drop teachers.subjects column if exists
        $r = $connection->query("SHOW COLUMNS FROM teachers LIKE 'subjects'");
        if ($r && $r->num_rows > 0) {
            $connection->query("ALTER TABLE teachers DROP COLUMN subjects");
        }
    }
    header('Location: subjects_admin.php');
    exit();
}

// Read subjects
$res = $connection->query("SELECT id, name FROM subjects ORDER BY name");
$subjects = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Read assessments grouped by subject
$assRes = $connection->query("SELECT id, subject_id, name FROM assessments ORDER BY subject_id, name");
$assessments = [];
if ($assRes) {
    while ($arow = $assRes->fetch_assoc()) {
        $assessments[intval($arow['subject_id'])][] = $arow;
    }
}

// If requested via GET for JSON, return subjects or assessments
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($act === 'list_subjects') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'subjects' => $subjects]);
        exit();
    }
    if ($act === 'get_assessments') {
        $sid = intval($_GET['subject_id'] ?? 0);
        $stmt = $connection->prepare("SELECT id, name FROM assessments WHERE subject_id = ? ORDER BY name");
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $r = $stmt->get_result();
        $ass = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'assessments' => $ass]);
        exit();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin.php">Admin</a>
            <div>
                <a class="btn btn-outline-light btn-sm" href="admin.php">← Back</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Subjects</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="d-flex mb-3">
                    <input name="name" class="form-control me-2" placeholder="New subject name">
                    <input type="hidden" name="action" value="add">
                    <button class="btn btn-primary">Add</button>
                </form>

                <div class="list-group mb-3">
                    <?php foreach ($subjects as $s): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div><?php echo htmlspecialchars($s['name']); ?></div>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo intval($s['id']); ?>">
                                <button class="btn btn-sm btn-danger" onclick="return confirm('Delete subject?')">Delete</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0">Assessments</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($subjects as $s): ?>
                            <div class="mb-3">
                                <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                                <div class="small mb-2">
                                    <?php if (!empty($assessments[intval($s['id'])])): ?>
                                        <?php foreach ($assessments[intval($s['id'])] as $a): ?>
                                            <form method="POST" style="display:inline-block;margin-right:6px;">
                                                <input type="hidden" name="action" value="delete_assessment">
                                                <input type="hidden" name="assessment_id" value="<?php echo intval($a['id']); ?>">
                                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete assessment?')"><?php echo htmlspecialchars($a['name']); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No assessments</span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" class="d-flex mb-2">
                                    <input type="text" name="assessment_name" class="form-control me-2" placeholder="New assessment name for <?php echo htmlspecialchars($s['name']); ?>">
                                    <input type="hidden" name="action" value="add_assessment">
                                    <input type="hidden" name="subject_id" value="<?php echo intval($s['id']); ?>">
                                    <button class="btn btn-primary">Add</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-3">
                    <h6>Legacy column</h6>
                    <p class="small text-muted">If you're confident migration succeeded you may drop the old <code>teachers.subjects</code> column.</p>
                    <form method="POST" onsubmit="return confirm('Drop legacy column subjects from teachers? This is irreversible.');">
                        <input type="hidden" name="action" value="drop_legacy">
                        <button class="btn btn-warning">Drop legacy column</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
