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
    id INT AUTO_INCREMENT PRIMARY极狐 KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    role ENUM('superadmin', 'department_team_leader', 'director', 'member') NOT NULL DEFAULT 'member',
    department_id INT,
    membership_strength INT DEFAULT 0,
    active_officers INT DEFAULT 0,
    new_members_this_week INT DEFAULT 0,
    team1_attendance INT DEFAULT 0,
    team2_attendance INT DEFAULT 0,
    departmental_leader VARCHAR(100),
    assistant_departmental_leader VARCHAR(100),
    directorate VARCHAR(100),
    is_protected TINYINT(1) DEFAULT 0,
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
    month INT NOT NULL,
    year INT NOT NULL,
    week_label VARCHAR(10) NOT NULL,
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
    $pdo->exec("INSERT INTO departments (name) VALUES ('Church Admin Department '),('Ministry Information Department'),('Service Coordination Department '),('Creative & Brand Management Department '),('Loveway Choir '),('Loveway Minstrels'),('Pastoral Worship Team '),('LAM Theatre Department'),('Full House Department '),('LAM Dance Department'),('Super Infants Department (3months-1year)'),('Super Toddler Department (2years-3years)'),('Super Kids 1 Department (4years-5years)'),('Super Kids 2 Department (6years-7years)'),('Super Kids 3 Department (8years-10years)'),('Pre-Teens Department (11years-13years)'),('Teens Department (14years-16years)'),('Video Production '),('Stage & Lighting'),('Graphics, Animation & Projection (GAP) Department'),('Internet Ministry Department'),('Content Creation Department'),('Photography Department'),('Sound Engineering Department'),('Facility Maintenance Department'),('Resource Production Department'),('Word bank Marketers Department'),('Sanctuary Keepers Department'),('Exterior Keepers Department'),('Altar keepers Team'),('Greeters Department'),('Usher Department'),('Protocols Department'),('Marshals Department'),('Cell Trainings Department'),('Cell Ministry Department '),('Diplomatic Outreach Department '),('Charity Outreach Department'),('Royal Host Department'),('Real Friends Department'),('Maturity Admin Department'),('Maturity Operations Department'),('Healing Hands Department'),('Sports and Fitness Department');");
    
    // Create protected superadmin
    $pdo->exec("INSERT INTO users (name, username, phone, role, is_protected) VALUES 
               ('Super Admin', 'superadmin', 'admin123', 'superadmin', 1)");
    
    // Insert initial deadline
    $pdo->exec("INSERT INTO deadlines (dead极狐line_day, deadline_time) VALUES (5, '17:00:00')");
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

function autoSubmitNilReports($pdo) {
    $currentWeek = date('Y-W');
    $currentMonth = date('n');
    $currentYear = date('Y');
    $weekOfMonth = floor((date('j') - 1) / 7) + 1;
    $weekLabel = 'week' . $weekOfMonth;
    
    $deadlinePassed = isDeadlinePassed($pdo);
    
    if (!$deadlinePassed) return 0;
    
    $stmt = $pdo->prepare("SELECT u.id, u.department_id 
                          FROM users u 
                          WHERE u.role = 'director' 
                          AND NOT EXISTS (
                              SELECT 1 FROM submissions s 
                              WHERE s.director_id = u.id 
                              AND s.week = :week
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
        
        // Add required fields with Nill values
        $nilData = array_merge($nilData, [
            'current_membership_strength' => 'Nill',
            'new_members_this_week' => 'Nill',
            'active_officers' => 'Nill',
            'team1_attendance' => 'Nill',
            'team2_attendance' => '极狐Nill',
            'departmental_attendance' => 'Nill',
            'departmental_leader' => 'Nill',
            'assistant_departmental_leader' => 'Nill',
            'directorate' => 'Nill'
        ]);
        
        $jsonData = json_encode($nilData);
        
        $stmt = $pdo->prepare("INSERT INTO submissions (department_id, director_id, week, month, year, week_label, data, total_points) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([
            $director['department_id'],
            $director['id'],
            $currentWeek,
            $currentMonth,
            $currentYear,
            $weekLabel,
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
            // For directors and member, phone is the password
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
if (isset($_POST['save_form_config']) && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
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
if (isset($_POST['submit_attendance']) && isset($_SESSION['role']) && $_SESSION['role'] === 'director') {
    if (isDeadlinePassed($pdo)) {
        $message = "Submission deadline has passed!";
    } else {
        $data = [];
        $totalPoints = 0;
        
        // Check if already submitted this week
        $currentWeek = date('Y-W');
        $currentMonth = date('n');
        $currentYear = date('Y');
        $weekOfMonth = floor((date('j') - 1) / 7) + 1;
        $weekLabel = 'week' . $weekOfMonth;
        
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
                    // Get new member data and update membership strength
                    $newMembers = (int)$_POST['new_members_this_week'];
                    $activeOfficers = (int)$_POST['active_officers'];
                    $team1Attendance = (int)$_POST['team1_attendance'];
                    $team2Attendance = (int)$_POST['team2_attendance'];
                    
                    // Add the new fields to the data array
                    $data['current_membership_strength'] = $_POST['current_membership_strength'];
                    $data['new_members_this_week'] = $newMembers;
                    $data['active_officers'] = $activeOfficers;
                    $data['team1_attendance'] = $team1Attendance;
                    $data['team2_attendance'] = $team2Attendance;
                    $data['departmental_attendance'] = $team1Attendance + $team2Attendance;
                    $data['departmental_leader'] = $_POST['departmental_leader'];
                    $data['assistant_departmental_leader'] = $_POST['assistant_departmental_leader'];
                    $data['directorate'] = $_POST['directorate'];
                    
                    // Update membership strength in user profile
                    $newStrength = (int)$_POST['current_membership_strength'] + $newMembers;
                    
                    $updateStmt = $pdo->prepare("UPDATE users SET 
                                                membership_strength = ?,
                                                active_officers = ?,
                                                new_members_this_week = ?,
                                                team1_attendance = ?,
                                                team2_attendance = ?
                                                WHERE id = ?");
                    $updateStmt->execute([
                        $newStrength,
                        $activeOfficers,
                        $newMembers,
                        $team1Attendance,
                        $team2Attendance,
                        $_SESSION['user_id']
                    ]);
                    
                    $jsonData = json_encode($data);
                    
                    $stmt = $pdo->prepare("INSERT INTO submissions (department_id, director_id, week, month, year, week_label, data, total_points) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['department_id'],
                        $_SESSION['user_id'],
                        $currentWeek,
                        $currentMonth,
                        $currentYear,
                        $weekLabel,
                        $jsonData,
                        $totalPoints
                    ]);
                    
                    $message = "Attendance submitted successfully!";
                    $userSubmitted = true;
                } catch (Exception $e) {
                    $message = "Error submitting attendance: " . $e->getMessage();
                }
            }
        }
    }
}

// Set deadline (superadmin)
if (isset($_POST['set_deadline']) && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
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
if (isset($_POST['add_user']) && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
    $name = $_POST['name'];
    $username = $_POST['username'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $department_id = $_POST['department_id'] ?? null;
    $membership_strength = (int)$_POST['membership_strength'];
    $active_officers = (int)$_POST['active_officers'];
    $departmental_leader = $_POST['departmental_leader'];
    $assistant_departmental_leader = $_POST['assistant_departmental_leader'];
    $directorate = $_POST['directorate'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, username, phone, role, department_id, 
                              membership_strength, active_officers, departmental_leader, 
                              assistant_departmental_leader, directorate) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $name, $username, $phone, $role, $department_id,
            $membership_strength, $active_officers, $departmental_leader,
            $assistant_departmental_leader, $directorate
        ]);
        $message = "User added successfully!";
    } catch (Exception $e) {
        $message = "Error adding user: " . $e->getMessage();
    }
}

// Delete user (superadmin) - protected users cannot be deleted
if ($action === 'delete_user' && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
    $user_id = $_GET['id'] ?? 0;
    
    try {
        // Check if user is protected
        $stmt = $pdo->prepare("SELECT is_protected FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && $user['is_protected']) {
            $message = "Protected users cannot be deleted!";
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "User deleted successfully!";
        }
    } catch (Exception $e) {
        $message = "Error deleting user: " . $极狐e->getMessage();
    }
}

// Update team leaders (director only)
if (isset($_POST['update_team_leaders']) && isset($_SESSION['role']) && $_SESSION['role'] === 'director') {
    $departmental_leader = $_POST['departmental_leader'];
    $assistant_departmental_leader = $_POST['assistant_departmental_leader'];
    
    try {
        // Update department's team leaders
        $stmt = $pdo->prepare("UPDATE users SET 
                              departmental_leader = ?,
                              assistant_departmental_leader = ?
                              WHERE department_id = ?");
        $stmt->execute([
            $departmental_leader,
            $assistant_departmental_leader,
            $_SESSION['department_id']
        ]);
        
        $message = "Team leaders updated successfully!";
    } catch (Exception $e) {
        $message = "Error updating team leaders: " . $e->getMessage();
    }
}

// Update directorate (superadmin)
if (isset($_POST['update_directorate']) && isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
    $user_id = $_POST['user极狐_id'];
    $directorate = $_POST['directorate'];
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET directorate = ? WHERE id = ?");
        $stmt->execute([$directorate, $user_id]);
        $message = "Directorate updated successfully!";
    } catch (Exception $e) {
        $message = "Error updating directorate: " . $e->getMessage();
    }
}

// CSV Export with month, year, and week
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
        
        // Add the new fields to headers
        $additionalFields = [
            'Current Membership Strength',
            'New Members This Week',
            'Active Departmental Officers',
            'Team 1 Attendance',
            'Team 2 Attendance',
            'Total Departmental Attendance',
            'Departmental Leader',
            'Assistant Departmental Leader',
            'Directorate',
            'Month',
            'Year',
            'Week'
        ];
        
        $allHeaders = array_merge(
            ['Department', 'Director', 'Week', 'Total Points', 'Submitted At'],
            $additionalFields,
            $formFields
        );
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="attendance_report_'.$week.'.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, $allHeaders);
        
        // Data
        foreach ($submissions as $sub) {
            $data = json_decode($sub['data'], true);
            $row = [
                $sub['department_name'],
                $sub['director_name'],
                $sub['week'],
                $sub['total_points'],
                $sub['submitted_at'],
                $data['current_membership_strength'] ?? '',
                $data['new_members_this_week'] ?? '',
                $data['active_officers'] ?? '',
                $data['team1_attendance'] ?? '',
                $data['team2_attendance'] ?? '',
                $data['departmental_attendance'] ?? '',
                $data['departmental_leader'] ?? '',
                $data['assistant_departmental_leader'] ?? '',
                $data['directorate'] ?? '',
                $sub['month'],
                $sub['year'],
                $sub['week_label']
            ];
            
            foreach ($formFields as $field) {
                $row[] = $data[$field] ?? 'Nill';
            }
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } else {
        $message = "No data to export for week $week";
    }
}

// View report endpoint
if ($action === 'view_report') {
    $week = $_GET['week'] ?? date('Y-W');
    $departmentId = $_GET['department_id'] ?? ($_SESSION['department_id'] ?? 0);
    
    // Get the submission for this department and week
    $stmt = $pdo->prepare("SELECT * FROM submissions 
                          WHERE department_id = ? AND week = ?");
    $stmt->execute([$departmentId, $week]);
    $submission = $stmt->fetch();
    
    if ($submission) {
        $data = json_decode($submission['data'], true);
    } else {
        // If no submission, create a Nill data array
        $stmt = $pdo->query("SELECT field_name FROM form_config");
        $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $data = array_fill_keys($fields, 'Nill');
        
        // Add the new fields
        $additionalFields = [
            'current_membership_strength',
            'new_members_this_week',
            'active_officers',
            'team1_attendance',
            'team2_attendance',
            'departmental_attendance',
            'departmental_leader',
            'assistant_departmental_leader',
            'directorate'
        ];
        
        foreach ($additionalFields as $field) {
            $data[$field] = 'Nill';
        }
    }
    
    // Get form config for labels
    $formConfig = $pdo->query("SELECT * FROM form_config")->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate HTML for the report
    ob_start();
    ?>
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Attendance Report - Week <?= $week ?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Field</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Time Information -->
                        <tr>
                            <th>Month</th>
                            <td><?= $submission['month'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Year</th>
                            <td><?= $submission['year'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Week</th>
                            <td><?= $submission['week_label'] ?? 'Nill' ?></td>
                        </tr>
                        
                        <!-- New Fields -->
                        <tr>
                            <th>Current Membership Strength</th>
                            <td><?= $data['current_membership_strength'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>New Members This Week</th>
                            <td><?= $data['new_members_this_week'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Active Departmental Officers</th>
                            <td><?= $data['active_officers'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Team 1 Attendance</th>
                            <td><?= $data['team1_attendance'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Team 2 Attendance</th>
                            <td><?= $data['team2_attendance'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Total Departmental Attendance</th>
                            <td><?= $data['departmental_attendance'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Departmental Leader</th>
                            <td><?= $data['departmental_leader'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Assistant Departmental Leader</th>
                            <td><?= $data['assistant_departmental_leader'] ?? 'Nill' ?></td>
                        </tr>
                        <tr>
                            <th>Directorate</th>
                            <td><?= $data['directorate'] ?? 'Nill' ?></td>
                        </tr>
                        
                        <!-- Custom Form Fields -->
                        <?php foreach($formConfig as $field): ?>
                            <tr>
                                <th><?= $field['field_label'] ?></th>
                                <td><?= isset($data[$field['field_name']]) ? $data[$field['field_name']] : 'Nill' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    echo $html;
    exit;
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

// Get current user details for profile
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get department team leaders
$teamLeaders = [];
if (isset($_SESSION['department_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users 
                          WHERE department_id = ? 
                          AND role IN ('department_team_leader')");
    $stmt->execute([$_SESSION['department_id']]);
    $teamLeaders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'director') {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Attendance Reporting System</title>
    <link href="img/lam-logo.jpg" rel="icon">
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
            border-radius: 15极狐px;
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
        
        .card-body-list {
            overflow-y: scroll;
            padding: 20px;
            height: 330px;
        }
        
        .card-body-cart {
            
            padding: 20px;
            max-height: 350px;
        }
        
        .report-history {
            max-height: 400px;
            overflow-y: auto;
            height:280px;
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
            .btn {
                margin: 20px;
                align-items: center;
                justify-content: center;
            }
            .search-container {
                margin: 37px;
                justify-content: center;
                align-items: center;
            }
            .search-container i {
                margin: 5px;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <!-- Login Form -->
    <?php if(!isset($_SESSION['user_id'])): ?>
        <div class="login-container">
            <h2 class="text-center mb-4" ><img src="img/logo.png" style="height:40px; width:45px; justify-content: center" alt="logo">LAM Departmental Reports</h2><hr>
            <form method="POST" style="margin-top: 39px;">
                <div class="mb-4">
                    <label for="username" class="form-label">Username</label>
                    <input type="text"  class="form-control form-control-lg" id="username" name="username" required>
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
        <!-- Navigation Bar -->
        <nav class="navbar navbar-expand-lg navbar-dark">
            <div class="container">
                <a class="navbar-brand" href="#">
                    <img src="img/logo.png" style="height:40px; width:45px" alt="logo">LAM Departmental Reports
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
                            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                                <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#deadlineModal">
                                    <i class="fas fa-cog me-1"></i>Configure Deadline
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Update Team Leaders (Director Only) -->
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'director'): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Update Team Leaders</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="update_team_leaders" value="1">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Departmental Leader</label>
                                    <input type="text" name="departmental_leader" class="form-control" 
                                           value="<?= $currentUser['departmental_leader'] ?? '' ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Assistant Departmental Leader</label>
                                    <input type="text" name="assistant_departmental_leader" class="form-control" 
                                           value="<?= $currentUser['assistant_departmental_leader'] ?? '' ?>" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Team Leaders
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['superadmin'])): ?>
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
            <?php endif; ?>

            <!-- Main Content -->
            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Directorate Update for Superadmin -->
                    <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                        <div class="card mb-4 ">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Update Directorate</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Select User</label>
                                            <select name="user_id" class="form-select" required>
                                                <?php foreach($users as $user): ?>
                                                    <option value="<?= $user['id'] ?>">
                                                        <?= $user['name'] ?> (<?= $user['dept_name'] ?? 'No Department' ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Directorate</label>
                                            <input type="text" name="directorate" class="form-control" required>
                                        </div>
                                    </div>
                                    <button type="submit" name="update_directorate" class="btn btn-warning">
                                        <i class="fas fa-save me-1"></i>Update Directorate
                                    </button>
                                </form>
                            </div>
                        </div>
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
                        <div class="card-body-list">
                            <h5 class="mb-3">Week: <?= $currentWeek ?></h5>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="departmentTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Department</th>
                                            <th class="text-center">Submissions</th>
                                            <th class="text-center">Avg. Score</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
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
                                                    <a href="?action=view_report&week=<?= $currentWeek ?>&department_id=<?= $report['id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-eye me-1"></i>View Report
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
     
                    <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'director'): ?>
                        <!-- Director's Report History -->
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Your Report History</h5>
                            </div>
                            <div class="card-body report-history">
                                <ul class="list-group">
                                    <?php
                                    $stmt = $pdo->prepare("SELECT * FROM submissions 
                                                          WHERE director_id = ? 
                                                          ORDER BY week DESC");
                                    $stmt->execute([$_SESSION['user_id']]);
                                    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if (count($submissions) > 0):
                                        foreach ($submissions as $sub): 
                                            $data = json_decode($sub['data'], true);
                                    ?>
                                        <li class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1">Week <?= $sub['week'] ?></h6>
                                                    <small class="text-muted"><?= $sub['submitted_at'] ?></small>
                                                    <div>
                                                        <span class="badge bg-secondary">Month: <?= $sub['month'] ?></span>
                                                        <span class="badge bg-secondary">Year: <?= $sub['year'] ?></span>
                                                        <span class="badge bg-secondary">Week: <?= $sub['week_label'] ?></span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <span class="badge bg-primary"><?= $sub['total_points'] ?> points</span>
                                                    <a href="?action=view_report&week=<?= $sub['week'] ?>&department_id=<?= $_SESSION['department_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; 
                                    else: ?>
                                        <li class="list-group-item text-center py-4">
                                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                                            <p class="mb-0">No submission history found</p>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Department Performance -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Department Performance</h5>
                        </div>
                        <div class="card-body-list">
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
                    
                    <!-- Team Leaders Section -->
                    <?php if(count($teamLeaders) > 0): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Department Team Leaders</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group">
                                    <?php foreach($teamLeaders as $leader): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0"><?= $leader['name'] ?></h6>
                                                <small class="text-muted"><?= ucfirst($leader['role']) ?></small>
                                            </div>
                                            <span class="badge bg-primary"><?= $leader['departmental_leader'] ? 'Leader' : 'Assistant' ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- User Info Card -->
                    <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'], ['director','superadmin','department_team_leader'])): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>User Information</h5>
                            </div>
                            <div class="card-body-cart">
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
                                    <li class="list-group-item d-flex justify-content between align-items-center">
                                        <span>Current Week:</span>
                                        <span><?= date('Y-W') ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content between align-items-center">
                                        <span>Submission Status:</span>
                                        <span class="badge bg-<?= $userSubmitted ? 'success' : ($deadlinePassed ? 'danger' : 'warning') ?>">
                                            <?= $userSubmitted ? 'Submitted' : ($deadlinePassed ? 'Deadline Passed' : 'Pending') ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Attendance Form (Director) -->
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'director' && !$deadlinePassed && !$userSubmitted): ?>
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
                        
                        <!-- Team Leaders Information -->
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">Team Leaders</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Departmental Leader</label>
                                        <input type="text" class="form-control" 
                                               value="<?= $currentUser['departmental_leader'] ?? 'Not set' ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Assistant Leader</label>
                                        <input type="text" class="form-control" 
                                               value="<?= $currentUser['assistant_departmental_leader'] ?? 'Not set' ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Weekly Attendance Fields -->
                        <form method="POST" id="attendanceForm">
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Membership Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Current Membership Strength</label>
                                            <input type="number" class="form-control" 
                                                   name="current_membership_strength" 
                                                   value="<?= $currentUser['membership_strength'] ?? 0 ?>" 
                                                   min="0" readonly>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">New Members This Week</label>
                                            <input type="number" class="form-control" 
                                                   name="new_members_this_week" 
                                                   min="0" required>
                                            <small class="form-text text-muted">This will update membership strength</small>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Active Departmental Officers</label>
                                            <input type="number" class="form-control" 
                                                   name="active_officers" 
                                                   value="<?= $currentUser['active_officers'] ?? 0 ?>" 
                                                   min="0" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Team Attendance</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Team 1 Attendance</label>
                                            <input type="number" class="form-control" 
                                                   name="team1_attendance" 
                                                   min="0" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Team 2 Attendance</label>
                                            <input type="number" class="form-control" 
                                                   name="team2_attendance" 
                                                   min="0" required>
                                        </div>
                                    </div>
                                    <p class="text-muted">Note: Team attendance will be combined for departmental reporting</p>
                                </div>
                            </div>
                            
                            <input type="hidden" name="departmental_leader" value="<?= $currentUser['departmental_leader'] ?>">
                            <input type="hidden" name="assistant_departmental_leader" value="<?= $currentUser['assistant_departmental_leader'] ?>">
                            <input type="hidden" name="directorate" value="<?= $currentUser['directorate'] ?>">
                            
                            <!-- Custom Form Fields -->
                            <?php if(count($formConfig) > 0): ?>
                                <div class="card mb-4">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0">Additional Information</h5>
                                    </div>
                                    <div class="card-body">
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
                                                           min="0"
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
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-grid">
                                <button type="submit" name="submit_attendance" class="btn btn-success btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Attendance Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
                  
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
            <?php endif; ?>
                        
            <?php if (($userSubmitted || $deadlinePassed) && isset($_SESSION['role']) && in_array($_SESSION['role'], ['director','department_team_leader'])): ?>
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Department Submission</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $currentWeek = date('Y-W');
                        $stmt = $pdo->prepare("SELECT * FROM submissions 
                                              WHERE department_id = ? AND week = ?");
                        $stmt->execute([$_SESSION['department_id'], $currentWeek]);
                        $submission = $stmt->fetch();
                        
                        if ($submission) {
                            $data = json_decode($submission['data'], true);
                        } else {
                            // If no submission, create a Nill data array
                            $stmt = $pdo->query("SELECT field_name FROM form_config");
                            $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            $data = array_fill_keys($fields, 'Nill');
                            
                            // Add required fields with Nill values
                            $additionalFields = [
                                'current_membership_strength',
                                'new_members_this_week',
                                'active_officers',
                                'team1_attendance',
                                'team2_attendance',
                                'departmental_attendance',
                                'departmental_leader',
                                'assistant_departmental_leader',
                                'directorate'
                            ];
                            
                            foreach ($additionalFields as $field) {
                                $data[$field] = 'Nill';
                            }
                        }
                        ?>
                        <div class="alert alert-success d-flex align-items-center">
                            <i class="fas fa-check-circle me-3 fa-2x"></i>
                            <div>
                                <h5 class="mb-1">Status: <?= $submission ? 'Submitted' : 'Auto-submitted (Nill)' ?></h5>
                                <p class="mb-0">Week: <?= $currentWeek ?></p>
                                <?php if ($submission): ?>
                                    <p class="mb-0">Submitted at: <?= $submission['submitted_at'] ?></p>
                                    <p class="mb-0 fw-bold">Total Points: <?= $submission['total_points'] ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Field</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Time Information -->
                                    <?php if ($submission): ?>
                                        <tr>
                                            <th>Month</th>
                                            <td><?= $submission['month'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Year</th>
                                            <td><?= $submission['year'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Week</th>
                                            <td><?= $submission['week_label'] ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    
                                    <!-- New Fields -->
                                    <tr>
                                        <th>Current Membership Strength</th>
                                        <td><?= $data['current_membership_strength'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>New Members This Week</th>
                                        <td><?= $data['new_members_this_week'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Active Departmental Officers</th>
                                        <td><?= $data['active_officers'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Team 1 Attendance</th>
                                        <td><?= $data['team1_attendance'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Team 2 Attendance</th>
                                        <td><?= $data['team2_attendance'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Total Departmental Attendance</th>
                                        <td><?= $data['departmental_attendance'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Departmental Leader</th>
                                        <td><?= $data['departmental_leader'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Assistant Departmental Leader</th>
                                        <td><?= $data['assistant_departmental_leader'] ?? 'Nill' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Directorate</th>
                                        <td><?= $data['directorate'] ?? 'Nill' ?></td>
                                    </tr>
                                    
                                    <!-- Custom Form Fields -->
                                    <?php foreach($formConfig as $field): ?>
                                        <tr>
                                            <th><?= $field['field_label'] ?></th>
                                            <td><?= isset($data[$field['field_name']]) ? $data[$field['field_name']] : 'Nill' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <a href="?action=export_csv&week=<?= $currentWeek ?>" class="btn btn-primary">
                                <i class="fas fa-file-csv me-1"></i>Export CSV
                            </a>
                            <a href="?action=view_report&week=<?= $currentWeek ?>&department_id=<?= $_SESSION['department_id'] ?>" 
                               class="btn btn-info" target="_blank">
                                <i class="fas fa-print me-1"></i>Print Report
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
                  
            <!-- User Management (Superadmin) -->
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content between align-items-center">
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
                                        <option value="director">Director</option>
                                        <option value="department_team_leader">Department Team Leader</option>
                                        <option value="member">Member</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Department</label>
                                    <select name="department_id" class="form-select">
                                        <option value="">Select Department</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>"><?= $dept['name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Membership Strength</label>
                                    <input type="number" name="membership_strength" class="form-control" min="0" value="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Active Officers</label>
                                    <input type="number" name="active_officers" class="form-control" min="0" value="0">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Departmental Leader</label>
                                    <input type="text" name="departmental_leader" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Assistant Leader</label>
                                    <input type="text" name="assistant_departmental_leader" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Directorate</label>
                                    <input type="text" name="directorate" class="form-control">
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" name="add_user" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-1"></i>Add User
                                </button>
                            </div>
                        </form>
                        
                        <h5 class="mb-3">Existing Users</h5>
                        <div class="card-body-cart">
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
                                                <?php if(!$user['is_protected']): ?>
                                                    <a href="?action=delete_user&id=<?= $user['id'] ?>" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Protected</span>
                                                <?php endif; ?>
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

            <!-- Form Configuration (Superadmin) -->
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Form Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formConfig">
                            <div id="formFields">
                                <?php foreach($formConfig as $index => $field): ?>
                                    <div class极狐="form-field">
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
        </div>
    <?php endif; ?>

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
                                </极狐>
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
