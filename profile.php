<?php
include 'database.php';
session_start();

// Determine which profile to show
$profile_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_SESSION['teacher_id']) ? intval($_SESSION['teacher_id']) : 0);
if ($profile_id <= 0) {
    header('Location: index.php');
    exit();
}

// Handle profile update (only for owner or admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!isset($_SESSION['teacher_id'])) {
        die('Not authorized');
    }

    $viewer_id = $_SESSION['teacher_id'];
    // check role
    $is_admin = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');
    if ($viewer_id !== $profile_id && !$is_admin) {
        die('You can only edit your own profile');
    }

    $email = trim($_POST['email'] ?? '');
    $contacts = trim($_POST['contacts'] ?? '');
    // Accept subjects as either textarea string (legacy) or array (new multi-select)
    $subjectsRaw = $_POST['subjects'] ?? null;
    if (is_array($subjectsRaw)) {
        $subjectsArray = array_map('trim', $subjectsRaw);
        $subjectsArray = array_values(array_filter($subjectsArray, function($v){ return $v !== ''; }));
    } else {
        $subjectsText = trim((string)$subjectsRaw);
        $lines = preg_split('/\r?\n/', $subjectsText);
        $subjectsArray = [];
        if ($lines) {
            foreach ($lines as $ln) {
                $v = trim($ln);
                if ($v !== '') $subjectsArray[] = $v;
            }
            $subjectsArray = array_values(array_unique($subjectsArray));
        }
    }

    // Ensure columns exist (email, contacts). subjects will be normalized below if available.
    $cols = ['email', 'contacts'];
    foreach ($cols as $col) {
        $r = $connection->query("SHOW COLUMNS FROM teachers LIKE '" . $connection->real_escape_string($col) . "'");
        if ($r->num_rows == 0) {
            $connection->query("ALTER TABLE teachers ADD COLUMN `" . $col . "` VARCHAR(255) DEFAULT NULL");
        }
    }

    // Update basic fields
    $stmt = $connection->prepare("UPDATE teachers SET email = ?, contacts = ? WHERE id = ?");
    $stmt->bind_param("ssi", $email, $contacts, $profile_id);
    $stmt->execute();
    $stmt->close();

    // Ensure normalized tables exist
    $connection->query("CREATE TABLE IF NOT EXISTS subjects (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE)");
    $connection->query("CREATE TABLE IF NOT EXISTS teacher_subjects (teacher_id INT NOT NULL, subject_id INT NOT NULL, PRIMARY KEY (teacher_id, subject_id))");

    // Build map of subject name -> id. Do NOT create new subjects from teacher input.
    // Teachers should only link to existing subjects; creating subjects is handled by admin.
    $subjectIds = [];
    $sel = $connection->prepare("SELECT id FROM subjects WHERE name = ? LIMIT 1");
    foreach ($subjectsArray as $sub) {
        $sel->bind_param("s", $sub);
        $sel->execute();
        $r = $sel->get_result();
        if ($r && $r->num_rows > 0) {
            $row = $r->fetch_assoc();
            $subjectIds[] = intval($row['id']);
        } else {
            // skip unknown subjects to avoid re-creating deleted subject names
            continue;
        }
    }
    $sel->close();

    // Replace teacher_subjects links
    $d = $connection->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
    $d->bind_param("i", $profile_id);
    $d->execute();
    $d->close();

    $link = $connection->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
    foreach ($subjectIds as $sid) {
        $link->bind_param("ii", $profile_id, $sid);
        $link->execute();
    }
    $link->close();

    // If this was an AJAX request, return JSON instead of redirecting
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }

    // Non-AJAX: redirect back to profile page
    header('Location: profile.php?id=' . $profile_id);
    exit();
}

$colsToEnsure = ['email','contacts','subjects'];
foreach ($colsToEnsure as $c) {
    $r = $connection->query("SHOW COLUMNS FROM teachers LIKE '" . $connection->real_escape_string($c) . "'");
    if (!$r || $r->num_rows == 0) {
        // create column if missing (safe defaults)
        if ($c === 'subjects') {
            $connection->query("ALTER TABLE teachers ADD COLUMN `subjects` TEXT DEFAULT NULL");
        } else {
            $connection->query("ALTER TABLE teachers ADD COLUMN `" . $connection->real_escape_string($c) . "` VARCHAR(255) DEFAULT NULL");
        }
    }
}

// Fetch profile
$stmt = $connection->prepare("SELECT id, fullName, username, role, email, contacts, subjects FROM teachers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows == 0) {
    $stmt->close();
    die('Profile not found');
}
$profile = $res->fetch_assoc();
$stmt->close();

$is_owner = isset($_SESSION['teacher_id']) && $_SESSION['teacher_id'] == $profile_id;

// Prefer normalized subjects if tables exist
$hasSubjectsTable = false;
$r = $connection->query("SHOW TABLES LIKE 'subjects'");
if ($r && $r->num_rows > 0) {
    $hasSubjectsTable = true;
}

if ($hasSubjectsTable) {
    $sstmt = $connection->prepare("SELECT s.name FROM subjects s JOIN teacher_subjects ts ON s.id = ts.subject_id WHERE ts.teacher_id = ? ORDER BY s.name");
    $sstmt->bind_param("i", $profile_id);
    $sstmt->execute();
    $sres = $sstmt->get_result();
    $subs = [];
    while ($row = $sres->fetch_assoc()) {
        $subs[] = $row['name'];
    }
    $sstmt->close();
    // preserve newline-separated format for textarea
    $profile['subjects'] = implode("\n", $subs);
}

// If requested as JSON (ajax), return profile data
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($profile);
    exit();
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile - <?php echo htmlspecialchars($profile['fullName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">Teacher's Monitoring System</a>
            <div>
                <?php if (isset($_SESSION['teacher_id'])): ?>
                    <a class="btn btn-outline-light btn-sm" href="dashboard.php">← Back</a>
                <?php else: ?>
                    <a class="btn btn-outline-light btn-sm" href="index.php">Home</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-grow-1">
                                <h4 class="card-title mb-1"><?php echo htmlspecialchars($profile['fullName']); ?></h4>
                                <p class="text-muted mb-1">@<?php echo htmlspecialchars($profile['username']); ?> &middot; <?php echo htmlspecialchars(ucfirst($profile['role'])); ?></p>
                            </div>
                        </div>

                        <hr>

                        <div class="mb-3 profile-subsection">
                            <h6>Email / Contact</h6>
                            <p class="mb-1"><?php echo htmlspecialchars($profile['email'] ?? ''); ?></p>
                            <p class="mb-0 text-muted small">Contacts: <?php echo htmlspecialchars($profile['contacts'] ?? ''); ?></p>
                        </div>

                        <div class="mb-3 profile-subsection">
                            <h6>Subjects</h6>
                            <div id="subjectsTags" class="mb-2">
                                <?php if (!empty($profile['subjects'])): ?>
                                    <?php foreach (preg_split('/\r?\n/', $profile['subjects']) as $sub):
                                        $sub = trim($sub);
                                        if ($sub === '') continue;
                                    ?>
                                        <span class="tag-item"><?php echo htmlspecialchars($sub); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted small">No subjects listed.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_owner): ?>
                            <hr>
                            <h5>Edit Profile</h5>
                            <form method="POST" id="profileEditForm" class="mt-3">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Contacts</label>
                                    <input type="text" name="contacts" class="form-control" value="<?php echo htmlspecialchars($profile['contacts'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Subjects</label>
                                    <div class="mb-2">
                                        <?php
                                        // Render checkboxes of all available subjects; pre-check those linked to the teacher
                                        $allSubjectsStmt = $connection->query("SELECT id, name FROM subjects ORDER BY name");
                                        $linked = [];
                                        $ls = $connection->prepare("SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?");
                                        $ls->bind_param("i", $profile_id);
                                        $ls->execute();
                                        $lres = $ls->get_result();
                                        while ($row = $lres->fetch_assoc()) $linked[] = intval($row['subject_id']);
                                        $ls->close();
                                        if ($allSubjectsStmt && $allSubjectsStmt->num_rows > 0) {
                                            echo '<div class="row">';
                                            while ($s = $allSubjectsStmt->fetch_assoc()) {
                                                $checked = in_array(intval($s['id']), $linked) ? 'checked' : '';
                                                echo '<div class="col-md-6"><div class="form-check">';
                                                echo '<input class="form-check-input" type="checkbox" name="subjects[]" value="' . htmlspecialchars($s['name']) . '" id="sub_' . intval($s['id']) . '" ' . $checked . '>';
                                                echo '<label class="form-check-label" for="sub_' . intval($s['id']) . '">' . htmlspecialchars($s['name']) . '</label>';
                                                echo '</div></div>';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<p class="text-muted small">No subjects available. Ask an admin to add subjects.</p>';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <button type="submit" name="save_profile" class="btn btn-primary">Save</button>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // No client-side tag editor needed: subjects are selected via checkboxes now.
    </script>
</body>
</html>
