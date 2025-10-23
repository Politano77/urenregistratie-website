<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Check if user is admin
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || $user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle user role update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_role_id'])) {
    $update_user_id = $_POST['update_role_id'];
    $new_role = $_POST['role'];

    if ($update_user_id == $_SESSION['user_id']) {
        $error = "You cannot change your own role.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        if ($stmt === false) {
            $error = "Error preparing role update statement: " . $conn->error;
        } else {
            $stmt->bind_param("si", $new_role, $update_user_id);
            if ($stmt->execute()) {
                $success = "User role updated successfully!";
            } else {
                $error = "Error updating user role: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Delete user
if (isset($_GET['delete_user_id'])) {
    $delete_user_id = $_GET['delete_user_id'];

    if ($delete_user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";
    } else {
        $stmt = $conn->prepare("DELETE FROM hours WHERE user_id = ?");
        $stmt->bind_param("i", $delete_user_id);
        if (!$stmt->execute()) {
            $error = "Error deleting user's hours: " . $stmt->error;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $delete_user_id);
        if ($stmt->execute()) {
            $success = "User deleted successfully!";
        } else {
            $error = "Error deleting user: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Delete hours
if (isset($_GET['delete_hour_id'])) {
    $delete_hour_id = $_GET['delete_hour_id'];

    $stmt = $conn->prepare("DELETE FROM hours WHERE id = ?");
    if ($stmt === false) {
        $error = "Error preparing hour delete statement: " . $conn->error;
    } else {
        $stmt->bind_param("i", $delete_hour_id);
        if ($stmt->execute()) {
            $success = "Hour entry deleted successfully!";
        } else {
            $error = "Error deleting hour entry: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Update hours
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_hour_id'])) {
    $edit_hour_id = $_POST['edit_hour_id'];
    $date = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $break_minutes = $_POST['break_minutes'] ?? 0;
    $description = $_POST['description'] ?? '';
    $project = $_POST['project'] ?? '';

    if (!is_numeric($break_minutes) || $break_minutes < 0) {
        $error = "Break minutes must be a non-negative number.";
    } else {
        $stmt = $conn->prepare("UPDATE hours SET date = ?, start_time = ?, end_time = ?, break_minutes = ?, description = ?, project = ? WHERE id = ?");
        if ($stmt === false) {
            $error = "Error preparing hour update statement: " . $conn->error;
        } else {
            $stmt->bind_param("sssissi", $date, $start_time, $end_time, $break_minutes, $description, $project, $edit_hour_id);
            if ($stmt->execute()) {
                $success = "Hour entry updated successfully!";
            } else {
                $error = "Error updating hour entry: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch all users with total hours
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$users = [];
if ($search) {
    $search_param = "%$search%";
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role,
                                  COALESCE(SUM((TIMESTAMPDIFF(SECOND, h.start_time, h.end_time) - (h.break_minutes * 60)) / 3600), 0) AS total_hours
                           FROM users u 
                           LEFT JOIN hours h ON u.id = h.user_id 
                           WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? 
                           GROUP BY u.id 
                           ORDER BY u.last_name, u.first_name");
    if ($stmt === false) {
        $error = "Error preparing users search statement: " . $conn->error;
    } else {
        $stmt->bind_param("sss", $search_param, $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role,
                                  COALESCE(SUM((TIMESTAMPDIFF(SECOND, h.start_time, h.end_time) - (h.break_minutes * 60)) / 3600), 0) AS total_hours
                           FROM users u 
                           LEFT JOIN hours h ON u.id = h.user_id 
                           GROUP BY u.id 
                           ORDER BY u.last_name, u.first_name");
    if ($stmt === false) {
        $error = "Error preparing users statement: " . $conn->error;
    } else {
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Fetch all hours with filters
$project_filter = isset($_GET['project_filter']) ? trim($_GET['project_filter']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$all_hours = [];
$query = "SELECT h.*, u.first_name, u.last_name 
          FROM hours h 
          JOIN users u ON h.user_id = u.id 
          WHERE 1=1";
$params = [];
$types = "";

if ($project_filter) {
    $query .= " AND h.project = ?";
    $params[] = $project_filter;
    $types .= "s";
}
if ($date_from) {
    $query .= " AND h.date >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if ($date_to) {
    $query .= " AND h.date <= ?";
    $params[] = $date_to;
    $types .= "s";
}
$query .= " ORDER BY h.date DESC, u.last_name, u.first_name";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    $error = "Error preparing hours statement: " . $conn->error;
} else {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $all_hours = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error = "Error executing hours query: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch unique projects for filter dropdown
$stmt = $conn->prepare("SELECT DISTINCT project FROM hours WHERE project IS NOT NULL AND project != '' ORDER BY project");
$stmt->execute();
$result = $stmt->get_result();
$projects = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch hour to edit
$edit_hour = null;
if (isset($_GET['edit_hour_id'])) {
    $edit_hour_id = $_GET['edit_hour_id'];
    $stmt = $conn->prepare("SELECT * FROM hours WHERE id = ?");
    $stmt->bind_param("i", $edit_hour_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_hour = $result->fetch_assoc();
    $stmt->close();
    if (!$edit_hour) {
        $error = "Hour entry not found.";
    }
}

// Calculate duration for hours
foreach ($all_hours as &$hour) {
    $start = strtotime($hour['start_time']);
    $end = strtotime($hour['end_time']);
    $hour['duration'] = round(($end - $start - ($hour['break_minutes'] * 60)) / 3600, 2);
}

// Fetch weekly report (all users)
$weekly_report = [];
$stmt = $conn->prepare("SELECT YEAR(date) AS year, WEEK(date, 1) AS week, SUM((TIMESTAMPDIFF(SECOND, start_time, end_time) - (break_minutes * 60)) / 3600) AS total_hours
                       FROM hours GROUP BY YEAR(date), WEEK(date, 1) ORDER BY year DESC, week DESC");
if ($stmt === false) {
    $error = "Error preparing weekly report statement: " . $conn->error;
} else {
    $stmt->execute();
    $result = $stmt->get_result();
    $weekly_report = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch monthly report (all users)
$monthly_report = [];
$stmt = $conn->prepare("SELECT YEAR(date) AS year, MONTH(date) AS month, SUM((TIMESTAMPDIFF(SECOND, start_time, end_time) - (break_minutes * 60)) / 3600) AS total_hours
                       FROM hours GROUP BY YEAR(date), MONTH(date) ORDER BY year DESC, month DESC");
if ($stmt === false) {
    $error = "Error preparing monthly report statement: " . $conn->error;
} else {
    $stmt->execute();
    $result = $stmt->get_result();
    $monthly_report = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Time Tracking</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dark-theme">
    <div class="container">
        <h2>Admin Dashboard</h2>
        <div class="nav-buttons">
            <a href="dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>

        <?php if (isset($success)): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <h3>All Users (with Total Hours)</h3>
        <form method="get" class="search-form">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email">
            <button type="submit" class="btn btn-primary btn-small">Search</button>
            <a href="admin_dashboard.php" class="btn btn-secondary btn-small">Clear</a>
        </form>
        <?php if (empty($users)): ?>
            <p>No users found.</p>
        <?php else: ?>
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Total Hours</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="update_role_id" value="<?php echo $user['id']; ?>">
                                    <select name="role" onchange="this.form.submit()" <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                        <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>User</option>
                                        <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo number_format($user['total_hours'], 2); ?></td>
                            <td>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <a href="admin_dashboard.php?delete_user_id=<?php echo $user['id']; ?>" 
                                       class="btn btn-danger btn-tiny" 
                                       onclick="return confirm('Are you sure you want to delete this user and all their hours?');">Del</a>
                                <?php else: ?>
                                    <span class="btn btn-tiny btn-disabled">Del</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (isset($edit_hour)): ?>
            <h3>Edit Hour</h3>
            <form method="post" class="compact-form">
                <input type="hidden" name="edit_hour_id" value="<?php echo htmlspecialchars($edit_hour['id']); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="date">Date:</label>
                        <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($edit_hour['date']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="start_time">Start:</label>
                        <input type="time" id="start_time" name="start_time" value="<?php echo htmlspecialchars($edit_hour['start_time']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="end_time">End:</label>
                        <input type="time" id="end_time" name="end_time" value="<?php echo htmlspecialchars($edit_hour['end_time']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="break_minutes">Break (min):</label>
                        <input type="number" id="break_minutes" name="break_minutes" min="0" value="<?php echo htmlspecialchars($edit_hour['break_minutes']); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="project">Project:</label>
                        <input type="text" id="project" name="project" placeholder="Project name" value="<?php echo htmlspecialchars($edit_hour['project'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description" placeholder="Description"><?php echo htmlspecialchars($edit_hour['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-small">Update</button>
                    <a href="admin_dashboard.php" class="btn btn-secondary btn-small">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <h3>All Hours</h3>
            <form method="get" class="filter-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="project_filter">Project:</label>
                        <select id="project_filter" name="project_filter">
                            <option value="">All</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo htmlspecialchars($project['project']); ?>" <?php echo $project_filter == $project['project'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['project']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="date_from">From:</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_to">To:</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-small">Filter</button>
                        <a href="admin_dashboard.php" class="btn btn-secondary btn-small">Clear</a>
                    </div>
                </div>
            </form>
            <?php if (empty($all_hours)): ?>
                <p>No hours recorded.</p>
            <?php else: ?>
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Date</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Break</th>
                            <th>Hours</th>
                            <th>Project</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_hours as $hour): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($hour['first_name'] . ' ' . $hour['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($hour['date']); ?></td>
                                <td><?php echo htmlspecialchars($hour['start_time']); ?></td>
                                <td><?php echo htmlspecialchars($hour['end_time']); ?></td>
                                <td><?php echo htmlspecialchars($hour['break_minutes']); ?></td>
                                <td><?php echo number_format($hour['duration'], 2); ?></td>
                                <td><?php echo htmlspecialchars($hour['project'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($hour['description'] ?? 'N/A'); ?></td>
                                <td>
                                    <a href="admin_dashboard.php?edit_hour_id=<?php echo $hour['id']; ?>" class="btn btn-primary btn-tiny">Edit</a>
                                    <a href="admin_dashboard.php?delete_hour_id=<?php echo $hour['id']; ?>" 
                                       class="btn btn-danger btn-tiny" 
                                       onclick="return confirm('Are you sure you want to delete this hour entry?');">Del</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Weekly Report</h3>
        <?php if (empty($weekly_report)): ?>
            <p>No weekly data.</p>
        <?php else: ?>
            <canvas id="weeklyChart" class="chart"></canvas>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Week</th>
                        <th>Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weekly_report as $week): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($week['year']); ?></td>
                            <td><?php echo htmlspecialchars($week['week']); ?></td>
                            <td><?php echo number_format($week['total_hours'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <script>
                const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
                new Chart(weeklyCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php foreach ($weekly_report as $week) { echo "'" . $week['year'] . '-W' . sprintf("%02d", $week['week']) . "',"; } ?>],
                        datasets: [{
                            label: 'Total Hours',
                            data: [<?php foreach ($weekly_report as $week) { echo $week['total_hours'] . ","; } ?>],
                            backgroundColor: '#007bff',
                            borderColor: '#0056b3',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Hours', font: { size: 12 } } },
                            x: { title: { display: true, text: 'Year-Week', font: { size: 12 } } }
                        },
                        plugins: {
                            legend: { display: true, labels: { font: { size: 11 } } }
                        },
                        maintainAspectRatio: false
                    }
                });
            </script>
        <?php endif; ?>

        <h