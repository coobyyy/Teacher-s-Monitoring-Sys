<?php
include 'database.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['teacher_id'])) {
    header("Location: index.php");
    exit();
}

// Get teacher role
$teacher_id = $_SESSION['teacher_id'];
$stmt = $connection->prepare("SELECT role FROM teachers WHERE id = ?");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user['role'] !== 'admin') {
    die("Access denied. Admin only.");
}

// Handle approving/rejecting user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'approve_user') {
        $user_id = $_POST['user_id'];
        $stmt = $connection->prepare("UPDATE teachers SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User approved']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to approve']);
        }
        $stmt->close();
        exit();
    }
    
    if ($action == 'reject_user') {
        $user_id = $_POST['user_id'];
        $stmt = $connection->prepare("DELETE FROM teachers WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User rejected']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reject']);
        }
        $stmt->close();
        exit();
    }
    
    if ($action == 'assign_adviser') {
        $teacher_id_assign = $_POST['teacher_id'];
        $stmt = $connection->prepare("UPDATE teachers SET role = 'adviser' WHERE id = ?");
        $stmt->bind_param("i", $teacher_id_assign);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Adviser role assigned']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to assign']);
        }
        $stmt->close();
        exit();
    }
    
    if ($action == 'remove_adviser') {
        $teacher_id_remove = $_POST['teacher_id'];
        $stmt = $connection->prepare("UPDATE teachers SET role = 'teacher' WHERE id = ?");
        $stmt->bind_param("i", $teacher_id_remove);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Adviser role removed']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove']);
        }
        $stmt->close();
        exit();
    }
}

// Get all pending users
$pending_result = $connection->query("SELECT id, fullName, username FROM teachers WHERE status = 'pending' ORDER BY id DESC");
$pending_users = $pending_result->fetch_all(MYSQLI_ASSOC);

// Get all active users
$active_result = $connection->query("SELECT id, fullName, username, role FROM teachers WHERE status = 'active' ORDER BY fullName");
$active_users = $active_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Teacher's Monitoring System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Admin Panel - Teacher's Monitoring System</span>
            <div>
                <span class="text-white me-3">Welcome, <?php echo htmlspecialchars(isset($_SESSION['first_name']) ? $_SESSION['first_name'] : $_SESSION['username']); ?></span>
                <a href="dashboard.php" class="btn btn-outline-info btn-sm me-2">← Back to Dashboard</a>
                <a href="subjects_admin.php" class="btn btn-outline-secondary btn-sm me-2">Manage Subjects</a>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <!-- Pending User Approvals -->
        <div class="row mb-5">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Pending User Approvals (<?php echo count($pending_users); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($pending_users) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($pending_users as $puser): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><a href="profile.php?id=<?php echo intval($puser['id']); ?>"><?php echo htmlspecialchars($puser['fullName']); ?></a></h6>
                                            <small class="text-muted">@<?php echo htmlspecialchars($puser['username']); ?></small>
                                        </div>
                                        <div>
                                            <a class="btn btn-sm btn-outline-secondary me-1" href="profile.php?id=<?php echo intval($puser['id']); ?>">Edit Profile</a>
                                            <button class="btn btn-sm btn-success approve-btn" data-user-id="<?php echo $puser['id']; ?>">Approve</button>
                                            <button class="btn btn-sm btn-danger reject-btn" data-user-id="<?php echo $puser['id']; ?>">Reject</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No pending approvals.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Users Management -->
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-info">
                        <h5 class="mb-0">Active Users (<?php echo count($active_users); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_users as $auser): ?>
                                        <tr>
                                            <td><a href="profile.php?id=<?php echo intval($auser['id']); ?>"><?php echo htmlspecialchars($auser['fullName']); ?></a></td>
                                            <td>@<?php echo htmlspecialchars($auser['username']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo ucfirst($auser['role']); ?></span></td>
                                            <td>
                                                <a class="btn btn-sm btn-outline-secondary me-1" href="profile.php?id=<?php echo intval($auser['id']); ?>">Edit Profile</a>
                                                <?php if ($auser['role'] == 'teacher'): ?>
                                                    <button class="btn btn-sm btn-primary assign-adviser-btn" data-teacher-id="<?php echo $auser['id']; ?>">Make Adviser</button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-warning remove-adviser-btn" data-teacher-id="<?php echo $auser['id']; ?>">Remove Adviser</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Approve user
        document.querySelectorAll('.approve-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                const formData = new FormData();
                formData.append('action', 'approve_user');
                formData.append('user_id', userId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('User approved successfully', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showMessage(data.message || 'Failed to approve', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred', 'error');
                });
            });
        });

        // Reject user
        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (confirm('Are you sure you want to reject this user?')) {
                    const userId = this.getAttribute('data-user-id');
                    const formData = new FormData();
                    formData.append('action', 'reject_user');
                    formData.append('user_id', userId);
                    
                    fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showMessage('User rejected', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showMessage(data.message || 'Failed to reject', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showMessage('An error occurred', 'error');
                    });
                }
            });
        });

        // Assign adviser role
        document.querySelectorAll('.assign-adviser-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const teacherId = this.getAttribute('data-teacher-id');
                const formData = new FormData();
                formData.append('action', 'assign_adviser');
                formData.append('teacher_id', teacherId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('Adviser role assigned', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showMessage(data.message || 'Failed to assign', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred', 'error');
                });
            });
        });

        // Remove adviser role
        document.querySelectorAll('.remove-adviser-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const teacherId = this.getAttribute('data-teacher-id');
                const formData = new FormData();
                formData.append('action', 'remove_adviser');
                formData.append('teacher_id', teacherId);
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('Adviser role removed', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showMessage(data.message || 'Failed to remove', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred', 'error');
                });
            });
        });

        // Show message function
        function showMessage(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'error' ? 'danger' : 'success'} position-fixed`;
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.textContent = message;
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
    </script>
</body>
</html>
