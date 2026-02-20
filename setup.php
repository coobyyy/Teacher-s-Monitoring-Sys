<?php
include 'database.php';

// Check if any admin exists
$admin_check = $connection->query("SELECT id FROM teachers WHERE role = 'admin' LIMIT 1");
$admin_exists = $admin_check->num_rows > 0;

$message = '';
$error = '';

// If admin exists, redirect
if ($admin_exists) {
    header("Location: index.php");
    exit();
}

// Handle first admin setup
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['setup'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $fullName = trim($_POST['fullName']);
    
    // Validation
    if (empty($fullName)) {
        $error = "Full name is required.";
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $error = "Username must be 3-20 characters long.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check if username already exists
        $stmt = $connection->prepare("SELECT id FROM teachers WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Username already taken.";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Create admin user with active status
            $stmt = $connection->prepare("INSERT INTO teachers (fullName, username, password, role, status) VALUES (?, ?, ?, 'admin', 'active')");
            $stmt->bind_param("sss", $fullName, $username, $hashed_password);
            
            if ($stmt->execute()) {
                $message = "Admin account created successfully! Redirecting to login...";
                header("refresh:2;url=index.php");
            } else {
                $error = "Failed to create admin account: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Setup - Teacher's Monitoring System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h1 class="card-title text-center mb-4">Initialize Admin Account</h1>
                        
                        <div class="alert alert-info">
                            <strong>First Time Setup</strong><br>
                            Create the admin account to access the Teacher's Monitoring System admin panel.
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="fullName" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="fullName" name="fullName" required>
                            </div>
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                                <small class="text-muted">3-20 characters</small>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <small class="text-muted">At least 6 characters</small>
                            </div>
                            <button type="submit" name="setup" class="btn btn-primary w-100">Create Admin Account</button>
                        </form>

                        <hr>
                        <p class="text-center text-muted small">
                            Already have an admin account? <a href="index.php">Login here</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
