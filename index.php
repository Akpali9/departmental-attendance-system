<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
// Initialize database tables
$pdo->exec("CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    role ENUM('superadmin', 'department_team_leader', 'director', 'staff') NOT NULL DEFAULT 'staff',
    department_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS form_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_name VARCHAR(100) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'select', 'checkbox') NOT NULL DEFAULT 'text',
    options TEXT,
    required TINYINT(1) DEFAULT 0,
    points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    director_id INT NOT NULL,
    week VARCHAR(10) NOT NULL,
    data TEXT NOT NULL,
    total_points INT DEFAULT 0,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (director_id) REFERENCES users(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS deadlines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deadline_day TINYINT NOT NULL DEFAULT 5, -- Friday
    deadline_time TIME NOT NULL DEFAULT '17:00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insert initial data if needed
if ($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() == 0) {
    // Create departments
    $pdo->exec("INSERT INTO departments (name) VALUES ('Sales'), ('Marketing'), ('Engineering')");
    
    // Create superadmin
    $pdo->exec("INSERT INTO users (name, username, phone, role) VALUES 
               ('Super Admin', 'superadmin', 'admin123', 'superadmin')");
    
    // Create directors
    $pdo->exec("INSERT INTO users (name, username, phone, role, department_id) VALUES 
               ('Jane Doe', 'jane_doe', '5551234567', 'director', 1),
               ('John Smith', 'john_smith', '5557654321', 'director', 2),
               ('Robert Johnson', 'robert_j', '5559876543', 'director', 3)");
    
    // Create staff
    $pdo->exec("INSERT INTO users (name, username, phone, role, department_id) VALUES 
               ('Mike Wilson', 'mike_w', '5551112233', 'staff', 1),
               ('Sarah Brown', 'sarah_b', '5554445566', 'staff', 2),
               ('David Lee', 'david_l', '5557778899', 'staff', 3)");
    
    // Insert initial deadline
    $pdo->exec("INSERT INTO deadlines (deadline_day, deadline_time) VALUES (5, '17:00:00')");
    
    // Create sample form config with options
    $pdo->exec("INSERT INTO form_config (field_name, field_label, field_type, required, options, points) VALUES 
               ('attendance', 'Attendance Rate', 'select', 1, 'Excellent:30,Good:20,Fair:10,None:0', 0),
               ('projects', 'Projects Completed', 'number', 1, '', 25),
               ('meetings', 'Meetings Attended', 'number', 1, '', 20),
               ('challenges', 'Challenges Faced', 'text', 0, '', 15),
               ('improvements', 'Improvement Suggestions', 'text', 0, '', 10)");
}

// Helper functions
function getDeadline($pdo) {
    $stmt = $pdo->query("SELECT deadline_day, deadline_time FROM deadlines LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function nextDeadline($deadline) {
    $today = new DateTime();
    $day = $deadline['deadline_day'];
    $time = $deadline['deadline_time'];
    
    $next = new DateTime();
    $next->setISODate($today->format('o'), $today->format('W'), $day);
    $next->setTime(substr($time, 0, 2), substr($time, 3, 2));
    
    if ($next < $today) {
        $next->modify('+1 week');
    }
    
    return $next;
}

function isDeadlinePassed($pdo) {
    $deadline = getDeadline($pdo);
    $nextDeadline = nextDeadline($deadline);
    $now = new DateTime();
    return $now > $nextDeadline;
}

function sendSMSReminder($pdo) {
    $deadline = getDeadline($pdo);
    $nextDeadline = nextDeadline($deadline);
    $deadlineStr = $nextDeadline->format('l, F j, Y \a\t H:i');
    $currentWeek = date('Y-W');
    
    $stmt = $pdo->prepare("SELECT u.phone, u.name, d.name AS dept_name 
                          FROM users u 
                          JOIN departments d ON u.department_id = d.id
                          WHERE u.role = 'director' 
                          AND NOT EXISTS (
                              SELECT 1 FROM submissions 
                              WHERE director_id = u.id 
                              AND week = :week
                          )");
    $stmt->execute([':week' => $currentWeek]);
    $directors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($directors as $director) {
        // In a real system, you would send an actual SMS
        $message = "Hi {$director['name']}! Weekly attendance report for {$director['dept_name']} is due by {$deadlineStr}. Please submit soon.";
        error_log("SMS sent to {$director['phone']}: $message");
    }
    
    return count($directors);
}

function autoSubmitNilReports($pdo) {
    $currentWeek = date('Y-W');
    $deadlinePassed = isDeadlinePassed($pdo);
    
    if (!$deadlinePassed) return 0;
    
    $stmt = $pdo->prepare("SELECT u.id, u.department_id 
                          FROM users u 
                          WHERE u.role = 'director' 
                          AND NOT EXISTS (
                              SELECT 1 FROM submissions 
                              WHERE director_id = u.id 
                              AND week = :week
                          )");
    $stmt->execute([':week' => $currentWeek]);
    $directors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    foreach ($directors as $director) {
        // Create nil data
        $nilData = [];
        $stmt = $pdo->query("SELECT field_name FROM form_config");
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($fields as $field) {
            $nilData[$field] = 'Nill';
        }
        
        $jsonData = json_encode($nilData);
        
        $stmt = $pdo->prepare("INSERT INTO submissions (department_id, director_id, week, data, total_points) 
                              VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([
            $director['department_id'],
            $director['id'],
            $currentWeek,
            $jsonData
        ]);
        
        $count++;
    }
    
    return $count;
}

// Auto submit nil reports for missed deadlines
autoSubmitNilReports($pdo);

// Handle form submissions
$message = '';
$action = $_GET['action'] ?? '';

// Search parameters
$searchQuery = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 5; // Users per page

// Login
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user) {
        // For superadmin, use password field
        if ($user['role'] === 'superadmin') {
            if ($password === $user['phone']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department_id'] = $user['department_id'];
                $message = "Login successful!";
            } else {
                $message = "Invalid password!";
            }
        } else {
            // For directors and staff, phone is the password
            if ($password === $user['phone']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department_id'] = $user['department_id'];
                $message = "Login successful!";
            } else {
                $message = "Invalid password!";
            }
        }
    } else {
        $message = "Invalid username!";
    }
}

// Logout
if ($action === 'logout') {
    session_destroy();
    header("Location: ?");
    exit;
}

// Save form configuration (superadmin)
if (isset($_POST['save_form_config']) && $_SESSION['role'] === 'superadmin') {
    $pdo->beginTransaction();
    
    try {
        $pdo->exec("DELETE FROM form_config");
        
        foreach ($_POST['field_label'] as $index => $label) {
            $name = $_POST['field_name'][$index];
            $type = $_POST['field_type'][$index];
            $required = isset($_POST['field_required'][$index]) ? 1 : 0;
            $options = $_POST['field_options'][$index] ?? '';
            $points = $_POST['field_points'][$index] ?? 0;
            
            $stmt = $pdo->prepare("INSERT INTO form_config (field_name, field_label, field_type, required, options, points) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $label, $type, $required, $options, $points]);
        }
        
        $pdo->commit();
        $message = "Form configuration saved successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error saving form configuration: " . $e->getMessage();
    }
}

// Submit attendance (director)
if (isset($_POST['submit_attendance']) && $_SESSION['role'] === 'director') {
    if (isDeadlinePassed($pdo)) {
        $message = "Submission deadline has passed!";
    } else {
        $data = [];
        $totalPoints = 0;
        
        // Check if already submitted this week
        $currentWeek = date('Y-W');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions 
                              WHERE director_id = ? AND week = ?");
        $stmt->execute([$_SESSION['user_id'], $currentWeek]);
        
        if ($stmt->fetchColumn() > 0) {
            $message = "You have already submitted for this week!";
        } else {
            // Get form configuration
            $stmt = $pdo->query("SELECT * FROM form_config");
            $formConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Validate and collect data
            $valid = true;
            foreach ($formConfig as $field) {
                $value = $_POST[$field['field_name']] ?? '';
                
                if ($field['required'] && empty($value)) {
                    $valid = false;
                    $message = "Field '{$field['field_label']}' is required!";
                    break;
                }
                
                $data[$field['field_name']] = $value;
                
                // Calculate points
                if (!empty($value)) {
                    // For select fields, get points from option
                    if ($field['field_type'] === 'select' && !empty($field['options'])) {
                        $options = explode(',', $field['options']);
                        foreach ($options as $option) {
                            list($optName, $optPoints) = explode(':', $option);
                            if (trim($optName) === $value) {
                                $totalPoints += intval($optPoints);
                                break;
                            }
                        }
                    } else {
                        $totalPoints += $field['points'];
                    }
                }
            }
            
            if ($valid) {
                try {
                    $jsonData = json_encode($data);
                    
                    $stmt = $pdo->prepare("INSERT INTO submissions (department_id, director_id, week, data, total_points) 
                                          VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['department_id'],
                        $_SESSION['user_id'],
                        $currentWeek,
                        $jsonData,
                        $totalPoints
                    ]);
                    
                    $message = "Attendance submitted successfully!";
                } catch (Exception $e) {
                    $message = "Error submitting attendance: " . $e->getMessage();
                }
            }
        }
    }
}

// Set deadline (superadmin)
if (isset($_POST['set_deadline']) && $_SESSION['role'] === 'superadmin') {
    $day = $_POST['deadline_day'];
    $time = $_POST['deadline_time'];
    
    try {
        $stmt = $pdo->prepare("UPDATE deadlines SET deadline_day = ?, deadline_time = ?");
        $stmt->execute([$day, $time]);
        $message = "Deadline updated successfully!";
    } catch (Exception $e) {
        $message = "Error updating deadline: " . $e->getMessage();
    }
}

// Add user (superadmin)
if (isset($_POST['add_user']) && $_SESSION['role'] === 'superadmin') {
    $name = $_POST['name'];
    $username = $_POST['username'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $department_id = $_POST['department_id'] ?? null;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, username, phone, role, department_id) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $username, $phone, $role, $department_id]);
        $message = "User added successfully!";
    } catch (Exception $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}

// Delete user (superadmin)
if ($action === 'delete_user' && $_SESSION['role'] === 'superadmin') {
    $user_id = $_GET['id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $message = "User deleted successfully!";
    } catch (Exception $e) {
        $message = "Error deleting user: " . $e->getMessage();
    }
}

// CSV Export
if ($action === 'export_csv') {
    $week = $_GET['week'] ?? date('Y-W');
    
    $stmt = $pdo->prepare("SELECT s.*, d.name AS department_name, u.name AS director_name
                          FROM submissions s
                          JOIN departments d ON s.department_id = d.id
                          JOIN users u ON s.director_id = u.id
                          WHERE s.week = ?");
    $stmt->execute([$week]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($submissions) > 0) {
        // Get form config for headers
        $stmt = $pdo->query("SELECT field_label FROM form_config");
        $formFields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="attendance_report_'.$week.'.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        $headers = array_merge(
            ['Department', 'Director', 'Week', 'Total Points', 'Submitted At'],
            $formFields
        );
        fputcsv($output, $headers);
        
        // Data
        foreach ($submissions as $sub) {
            $data = json_decode($sub['data'], true);
            $row = [
                $sub['department_name'],
                $sub['director_name'],
                $sub['week'],
                $sub['total_points'],
                $sub['submitted_at']
            ];
            
            foreach ($formFields as $field) {
                $row[] = $data[$field] ?? '';
            }
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } else {
        $message = "No data to export for week $week";
    }
}

// Get data for display
$formConfig = [];
$deadline = getDeadline($pdo);
$nextDeadline = nextDeadline($deadline);
$deadlineStr = $nextDeadline->format('l, F j, Y \a\t H:i');
$deadlinePassed = isDeadlinePassed($pdo);

// Get form config
$stmt = $pdo->query("SELECT * FROM form_config");
$formConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments
$stmt = $pdo->query("SELECT * FROM departments");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current week's submissions
$currentWeek = date('Y-W');
$reports = [];

$stmt = $pdo->prepare("SELECT d.id, d.name, 
                      (SELECT COUNT(*) FROM submissions s WHERE s.department_id = d.id AND s.week = ?) AS submissions,
                      (SELECT AVG(s.total_points) FROM submissions s WHERE s.department_id = d.id AND s.week = ?) AS avg_score
                      FROM departments d");
$stmt->execute([$currentWeek, $currentWeek]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if current user has submitted for this week
$userSubmitted = false;
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'director') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions 
                          WHERE director_id = ? AND week = ?");
    $stmt->execute([$_SESSION['user_id'], $currentWeek]);
    $userSubmitted = $stmt->fetchColumn() > 0;
}

// Get users with search and pagination
$userQuery = "SELECT u.*, d.name AS dept_name 
              FROM users u 
              LEFT JOIN departments d ON u.department_id = d.id";

$userParams = [];
if (!empty($searchQuery)) {
    $userQuery .= " WHERE u.name LIKE ? OR u.username LIKE ? OR d.name LIKE ? OR u.role LIKE ?";
    $searchTerm = "%$searchQuery%";
    $userParams = array_fill(0, 4, $searchTerm);
}

$userQuery .= " ORDER BY u.name ASC";

// Pagination
$userCountStmt = $pdo->prepare(str_replace('u.*, d.name AS dept_name', 'COUNT(*)', $userQuery));
$userCountStmt->execute($userParams);
$totalUsers = $userCountStmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

$userQuery .= " LIMIT " . (($page - 1) * $perPage) . ", $perPage";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute($userParams);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Send SMS reminders
if (isset($_GET['send_reminders'])) {
    $count = sendSMSReminder($pdo);
    $message = "Sent SMS reminders to $count directors";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Attendance Reporting System</title>
    <link href="" rel="icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4776E6;
            --secondary: #8E54E9;
            --success: #00b09b;
            --warning: #ffc107;
            --danger: #dc3545;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e7f0 100%);
            min-height: 100vh;
            padding-bottom: 50px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .card-header {
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .navbar {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .deadline-card {
            background: linear-gradient(45deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            border-radius: 15px;
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .login-container {
            max-width: 450px;
            margin: 100px auto;
            padding: 35px;
            border-radius: 20px;
            background: white;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .btn-primary {
            background: linear-gradient(45deg, var(--primary) 0%, var(--secondary) 100%);
            border: none;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #3a63c4 0%, #7a3dd6 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn-success {
            background: linear-gradient(45deg, var(--success) 0%, #96c93d 100%);
            border: none;
        }
        
        .btn-danger {
            background: linear-gradient(45deg, var(--danger) 0%, #e35d6a 100%);
            border: none;
        }
        
        .status-badge {
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .form-field {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }
        
        .user-card {
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }
        
        .user-card:hover {
            transform: translateX(5px);
        }
        
        .dashboard-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .dashboard-stat {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .dashboard-title {
            font-size: 1.1rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .progress {
            height: 10px;
            border-radius: 10px;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(71, 118, 230, 0.25);
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(71, 118, 230, 0.05);
        }
        
        .page-title {
            position: relative;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 70px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
        }
        
        .gradient-badge {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
        }
        
        .confirmation-modal {
            background: rgba(0,0,0,0.7);
        }
        
        .sms-indicator {
            position: relative;
            display: inline-block;
            margin-left: 10px;
        }
        
        .sms-indicator::after {
            content: '✉️';
            position: absolute;
            right: -25px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }
        
        .search-container {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-container .form-control {
            padding-left: 40px;
        }
        
        .search-container i {
            position: absolute;
            left: 15px;
            top: 12px;
            color: #6c757d;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .login-container {
                margin: 50px auto;
                padding: 25px;
            }
            
            .card-header h5 {
                font-size: 1.1rem;
            }
            
            .dashboard-stat {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
            <img src="" alt="logo">Departmental Reports
            </a>
            <?php if(isset($_SESSION['user_id'])): ?>
                <div class="d-flex align-items-center">
                    <span class="text-white me-3 d-none d-md-block">Welcome, <?= $_SESSION['name'] ?></span>
                    <a href="?action=logout" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Messages -->
        <?php if(!empty($message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <?php if(!isset($_SESSION['user_id'])): ?>
            <div class="login-container">
                <h2 class="text-center mb-4"><i class="fas fa-user-lock me-2"></i>System Login</h2>
                <form method="POST">
                    <div class="mb-4">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control form-control-lg" id="username" name="username" required>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control form-control-lg" id="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                </form>
               
            </div>
        <?php else: ?>
            <!-- Dashboard Content -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card deadline-card">
                        <div class="card-body text-center py-4">
                            <h3 class="card-title mb-3"><i class="fas fa-clock me-2"></i>Submission Deadline</h3>
                            <p class="card-text display-5 fw-bold mb-3"><?= $deadlineStr ?></p>
                            <p class="card-text mb-4">
                                Status: 
                                <span class="badge bg-<?= $deadlinePassed ? 'danger' : 'success' ?> py-2 px-3 fs-6">
                                    <?= $deadlinePassed ? 'Deadline Passed' : 'Open for Submission' ?>
                                </span>
                            </p>
                            <?php if($_SESSION['role'] === 'superadmin'): ?>
                                <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#deadlineModal">
                                    <i class="fas fa-cog me-1"></i>Configure Deadline
                                </button>
                                <a href="?send_reminders=1" class="btn btn-light btn-lg ms-2">
                                    <i class="fas fa-comment me-1"></i>Send SMS Reminders
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center py-4">
                        <div class="dashboard-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="dashboard-stat">
                            <?= count($departments) ?>
                        </div>
                        <div class="dashboard-title">Departments</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center py-4">
                        <div class="dashboard-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="dashboard-stat">
                            <?php 
                                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                                echo $stmt->fetchColumn();
                            ?>
                        </div>
                        <div class="dashboard-title">Total Users</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center py-4">
                        <div class="dashboard-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="dashboard-stat">
                            <?php 
                                $stmt = $pdo->query("SELECT COUNT(*) FROM submissions WHERE week = '$currentWeek'");
                                echo $stmt->fetchColumn();
                            ?>
                        </div>
                        <div class="dashboard-title">This Week's Submissions</div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Form Configuration (Superadmin) -->
                    <?php if($_SESSION['role'] === 'superadmin'): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Form Configuration</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="formConfig">
                                    <div id="formFields">
                                        <?php foreach($formConfig as $index => $field): ?>
                                            <div class="form-field">
                                                <div class="row mb-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label">Field Label</label>
                                                        <input type="text" name="field_label[]" class="form-control" 
                                                               value="<?= $field['field_label'] ?>" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Field Name</label>
                                                        <input type="text" name="field_name[]" class="form-control" 
                                                               value="<?= $field['field_name'] ?>" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Field Type</label>
                                                        <select name="field_type[]" class="form-select" required>
                                                            <option value="text" <?= $field['field_type'] === 'text' ? 'selected' : '' ?>>Text</option>
                                                            <option value="number" <?= $field['field_type'] === 'number' ? 'selected' : '' ?>>Number</option>
                                                            <option value="date" <?= $field['field_type'] === 'date' ? 'selected' : '' ?>>Date</option>
                                                            <option value="select" <?= $field['field_type'] === 'select' ? 'selected' : '' ?>>Select</option>
                                                            <option value="checkbox" <?= $field['field_type'] === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Points</label>
                                                        <input type="number" name="field_points[]" class="form-control" 
                                                               value="<?= $field['points'] ?>" min="0" step="1">
                                                    </div>
                                                </div>
                                                <div class="row mb-3">
                                                    <div class="col-md-9">
                                                        <div class="row options-row" style="<?= $field['field_type'] === 'select' ? '' : 'display:none;' ?>">
                                                            <div class="col-md-12">
                                                                <label class="form-label">Options (format: Option1:Points, Option2:Points)</label>
                                                                <input type="text" name="field_options[]" class="form-control" 
                                                                       value="<?= $field['options'] ?>" placeholder="e.g., Excellent:30,Good:20,None:0">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 d-flex align-items-end">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="field_required[]" id="required<?= $index ?>" 
                                                                   <?= $field['required'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="required<?= $index ?>">
                                                                Required
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-danger remove-field">
                                                    <i class="fas fa-trash me-1"></i>Remove Field
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="d-flex justify-content-between mt-4">
                                        <button type="button" id="addField" class="btn btn-secondary">
                                            <i class="fas fa-plus me-1"></i>Add Field
                                        </button>
                                        <button type="submit" name="save_form_config" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Save Configuration
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- User Management (Superadmin) -->
                    <?php if($_SESSION['role'] === 'superadmin'): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-users-cog me-2"></i>User Management</h5>
                                    <div class="search-container">
                                        <i class="fas fa-search"></i>
                                        <input type="text" id="userSearch" class="form-control" placeholder="Search users..." value="<?= htmlspecialchars($searchQuery) ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="mb-4">
                                    <h5 class="mb-3">Add New User</h5>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Full Name</label>
                                            <input type="text" name="name" class="form-control" required>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" name="username" class="form-control" required>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Phone (Password)</label>
                                            <input type="text" name="phone" class="form-control" required>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Role</label>
                                            <select name="role" class="form-select" required>
                                                <option value="superadmin">Superadmin</option>
                                                <option value="department_admin">Department Admin</option>
                                                <option value="director">Director</option>
                                                <option value="staff">Staff</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Department</label>
                                            <select name="department_id" class="form-select">
                                                <option value="">-- Select Department --</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?= $dept['id'] ?>"><?= $dept['name'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="submit" name="add_user" class="btn btn-primary w-100">
                                                <i class="fas fa-user-plus me-1"></i>Add User
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                
                                <h5 class="mb-3">Existing Users</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Phone</th>
                                                <th>Role</th>
                                                <th>Department</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($users as $user): ?>
                                                <tr>
                                                    <td><?= $user['name'] ?></td>
                                                    <td><?= $user['username'] ?></td>
                                                    <td><?= $user['phone'] ?></td>
                                                    <td>
                                                        <span class="badge bg-info"><?= ucfirst($user['role']) ?></span>
                                                    </td>
                                                    <td><?= $user['dept_name'] ?? 'N/A' ?></td>
                                                    <td>
                                                        <a href="?action=delete_user&id=<?= $user['id'] ?>" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if($totalPages > 1): ?>
                                    <nav>
                                        <ul class="pagination">
                                            <?php if($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($searchQuery) ?>">
                                                        <i class="fas fa-chevron-left"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($searchQuery) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if($page < $totalPages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($searchQuery) ?>">
                                                        <i class="fas fa-chevron-right"></i>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Attendance Form (Director) -->
                    <?php if($_SESSION['role'] === 'director' && !$deadlinePassed && !$userSubmitted): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Weekly Attendance Form</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info d-flex align-items-center">
                                    <i class="fas fa-info-circle me-3 fs-4"></i>
                                    <div>
                                        <h5 class="mb-1">Director: <?= $_SESSION['name'] ?></h5>
                                        <p class="mb-0">Department: 
                                            <?php 
                                                $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                                                $stmt->execute([$_SESSION['department_id']]);
                                                echo $stmt->fetchColumn();
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <form method="POST" id="attendanceForm">
                                    <?php foreach($formConfig as $field): ?>
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">
                                                <?= $field['field_label'] ?>
                                                <?php if($field['required']): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                                <?php if($field['field_type'] === 'select'): ?>
                                                    <span class="badge gradient-badge float-end">Points vary by option</span>
                                                <?php else: ?>
                                                    <span class="badge gradient-badge float-end">+<?= $field['points'] ?> points</span>
                                                <?php endif; ?>
                                            </label>
                                            
                                            <?php if($field['field_type'] === 'text'): ?>
                                                <input type="text" class="form-control" 
                                                       name="<?= $field['field_name'] ?>" 
                                                       <?= $field['required'] ? 'required' : '' ?>>
                                                
                                            <?php elseif($field['field_type'] === 'number'): ?>
                                                <input type="number" class="form-control" 
                                                       name="<?= $field['field_name'] ?>" 
                                                       <?= $field['required'] ? 'required' : '' ?>>
                                                
                                            <?php elseif($field['field_type'] === 'date'): ?>
                                                <input type="date" class="form-control" 
                                                       name="<?= $field['field_name'] ?>" 
                                                       <?= $field['required'] ? 'required' : '' ?>>
                                                
                                            <?php elseif($field['field_type'] === 'select'): ?>
                                                <select class="form-select" 
                                                        name="<?= $field['field_name'] ?>" 
                                                        <?= $field['required'] ? 'required' : '' ?>>
                                                    <option value="">-- Select --</option>
                                                    <option value="None">None (0 points)</option>
                                                    <?php if(!empty($field['options'])): 
                                                        $options = explode(',', $field['options']);
                                                        foreach($options as $option): 
                                                            $parts = explode(':', $option);
                                                            $optName = trim($parts[0]);
                                                            $optPoints = isset($parts[1]) ? intval(trim($parts[1])) : 0;
                                                    ?>
                                                        <option value="<?= $optName ?>"><?= $optName ?> (<?= $optPoints ?> points)</option>
                                                    <?php endforeach; endif; ?>
                                                </select>
                                                
                                            <?php elseif($field['field_type'] === 'checkbox'): ?>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="<?= $field['field_name'] ?>" 
                                                           <?= $field['required'] ? 'required' : '' ?>>
                                                    <label class="form-check-label">Yes</label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="d-grid">
                                        <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#confirmationModal">
                                            <i class="fas fa-paper-plane me-2"></i>Submit Attendance Report
                                        </button>
                                    </div>
                                    
                                    <!-- Confirmation Modal -->
                                    <div class="modal fade confirmation-modal" id="confirmationModal" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Confirm Submission</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="fs-5">Are you sure you want to submit this week's attendance report?</p>
                                                    <p class="text-muted">Note: You can only submit once per week.</p>
                                                    <p class="fw-bold">Submitted by: <?= $_SESSION['name'] ?> (Director)</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" name="submit_attendance" class="btn btn-success">Confirm Submission</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($_SESSION['role'] === 'director'): ?>
                        <?php if ($deadlinePassed): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Deadline Passed</h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-danger">
                                        <h4><i class="fas fa-clock me-2"></i>Submission deadline has passed!</h4>
                                        <p class="mb-0">Your report has been automatically submitted with "Nill" for all unfilled fields.</p>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($userSubmitted): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Submission Complete</h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-success">
                                        <h4><i class="fas fa-check me-2"></i>Attendance Submitted!</h4>
                                        <p class="mb-0">You have successfully submitted this week's attendance report.</p>
                                        <p class="fw-bold mt-2">Submitted by: <?= $_SESSION['name'] ?> (Director)</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Weekly Report -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Weekly Attendance Report</h5>
                                <div class="d-flex">
                                    <div class="search-container me-2">
                                        <i class="fas fa-search"></i>
                                        <input type="text" id="departmentSearch" class="form-control" placeholder="Search departments...">
                                    </div>
                                    <a href="?action=export_csv&week=<?= $currentWeek ?>" class="btn btn-sm btn-dark">
                                        <i class="fas fa-file-csv me-1"></i>Export CSV
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <h5 class="mb-3">Week: <?= $currentWeek ?></h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="departmentTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Department</th>
                                            <th class="text-center">Submissions</th>
                                            <th class="text-center">Avg. Score</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($reports as $report): ?>
                                            <tr>
                                                <td class="fw-bold"><?= $report['name'] ?></td>
                                                <td class="text-center"><?= $report['submissions'] ?></td>
                                                <td class="text-center">
                                                    <?php if($report['avg_score']): ?>
                                                        <span class="badge bg-primary p-2 fs-6">
                                                            <?= number_format($report['avg_score'], 1) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?= $report['submissions'] > 0 ? 'success' : 'warning' ?> p-2">
                                                        <?= $report['submissions'] > 0 ? 'Submitted' : 'Pending' ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye me-1"></i>View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- User Info Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>User Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary text-white rounded-circle p-3 me-3">
                                    <i class="fas fa-user fa-2x"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0"><?= $_SESSION['name'] ?></h4>
                                    <p class="mb-0 text-muted">@<?= $_SESSION['username'] ?></p>
                                    <span class="badge bg-info mt-1"><?= ucfirst($_SESSION['role']) ?></span>
                                </div>
                            </div>
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Department:</span>
                                    <span class="fw-bold">
                                        <?php if($_SESSION['department_id']): 
                                            $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                                            $stmt->execute([$_SESSION['department_id']]);
                                            echo $stmt->fetchColumn();
                                        else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Last Login:</span>
                                    <span><?= date('Y-m-d H:i') ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Current Week:</span>
                                    <span><?= date('Y-W') ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Submission Status:</span>
                                    <span class="badge bg-<?= $userSubmitted ? 'success' : ($deadlinePassed ? 'danger' : 'warning') ?>">
                                        <?= $userSubmitted ? 'Submitted' : ($deadlinePassed ? 'Deadline Passed' : 'Pending') ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Department Performance -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Department Performance</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach($reports as $report): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-bold"><?= $report['name'] ?></span>
                                        <span><?= $report['avg_score'] ? number_format($report['avg_score'], 1) : '0' ?> pts</span>
                                    </div>
                                    <div class="progress" style="height: 12px;">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= min(100, ($report['avg_score'] ?? 0)) ?>%;" 
                                             aria-valuenow="<?= $report['avg_score'] ?? 0 ?>" 
                                             aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Submission Statistics -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Submission Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="submissionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Deadline Modal -->
    <div class="modal fade" id="deadlineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Set Submission Deadline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Deadline Day</label>
                            <select name="deadline_day" class="form-select" required>
                                <option value="0" <?= $deadline['deadline_day'] == 0 ? 'selected' : '' ?>>Sunday</option>
                                <option value="1" <?= $deadline['deadline_day'] == 1 ? 'selected' : '' ?>>Monday</option>
                                <option value="2" <?= $deadline['deadline_day'] == 2 ? 'selected' : '' ?>>Tuesday</option>
                                <option value="3" <?= $deadline['deadline_day'] == 3 ? 'selected' : '' ?>>Wednesday</option>
                                <option value="4" <?= $deadline['deadline_day'] == 4 ? 'selected' : '' ?>>Thursday</option>
                                <option value="5" <?= $deadline['deadline_day'] == 5 ? 'selected' : '' ?>>Friday</option>
                                <option value="6" <?= $deadline['deadline_day'] == 6 ? 'selected' : '' ?>>Saturday</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deadline Time</label>
                            <input type="time" class="form-control" name="deadline_time" 
                                   value="<?= $deadline['deadline_time'] ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="set_deadline" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form field management
        document.addEventListener('DOMContentLoaded', function() {
            // Add new form field
            const addFieldBtn = document.getElementById('addField');
            if (addFieldBtn) {
                addFieldBtn.addEventListener('click', function() {
                    const fieldHTML = `
                        <div class="form-field">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Field Label</label>
                                    <input type="text" name="field_label[]" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Field Name</label>
                                    <input type="text" name="field_name[]" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Field Type</label>
                                    <select name="field_type[]" class="form-select" required>
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                        <option value="date">Date</option>
                                        <option value="select">Select</option>
                                        <option value="checkbox">Checkbox</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Points</label>
                                    <input type="number" name="field_points[]" class="form-control" value="0" min="0">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-9">
                                    <div class="row options-row" style="display:none;">
                                        <div class="col-md-12">
                                            <label class="form-label">Options (format: Option1:Points, Option2:Points)</label>
                                            <input type="text" name="field_options[]" class="form-control" placeholder="e.g., Excellent:30,Good:20,None:0">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="field_required[]">
                                        <label class="form-check-label">Required</label>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-danger remove-field">
                                <i class="fas fa-trash me-1"></i>Remove Field
                            </button>
                        </div>
                    `;
                    document.getElementById('formFields').insertAdjacentHTML('beforeend', fieldHTML);
                });
            }
            
            // Remove form field
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-field')) {
                    e.target.closest('.form-field').remove();
                }
            });
            
            // Show/hide options based on field type
            document.addEventListener('change', function(e) {
                if (e.target.name === 'field_type[]') {
                    const row = e.target.closest('.form-field');
                    const optionsRow = row.querySelector('.options-row');
                    optionsRow.style.display = (e.target.value === 'select') ? 'block' : 'none';
                }
            });
            
            // Initialize existing form fields
            document.querySelectorAll('select[name="field_type[]"]').forEach(select => {
                const row = select.closest('.form-field');
                const optionsRow = row.querySelector('.options-row');
                optionsRow.style.display = (select.value === 'select') ? 'block' : 'none';
            });
            
            // Submission chart
            const ctx = document.getElementById('submissionChart');
            if (ctx) {
                const submitted = <?= $reports[0]['submissions'] ?? 0 ?>;
                const pending = <?= count($departments) - ($reports[0]['submissions'] ?? 0) ?>;
                
                new Chart(ctx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Submitted', 'Pending'],
                        datasets: [{
                            data: [submitted, pending],
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 99, 132, 0.8)'
                            ],
                            borderColor: [
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 99, 132, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Submission Status'
                            }
                        }
                    }
                });
            }
            
            // Search functionality
            const userSearch = document.getElementById('userSearch');
            if (userSearch) {
                userSearch.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        const query = userSearch.value.trim();
                        window.location.href = `?search=${encodeURIComponent(query)}`;
                    }
                });
            }
            
            const departmentSearch = document.getElementById('departmentSearch');
            if (departmentSearch) {
                departmentSearch.addEventListener('keyup', function() {
                    const value = departmentSearch.value.toLowerCase();
                    const rows = document.querySelectorAll('#departmentTable tbody tr');
                    
                    rows.forEach(row => {
                        const name = row.querySelector('td:first-child').textContent.toLowerCase();
                        row.style.display = name.includes(value) ? '' : 'none';
                    });
                });
            }
        });
    </script>
</body>
</html>
