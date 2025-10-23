<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Haal de rol van de huidige gebruiker op
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$is_admin = $user && $user['role'] === 'admin';

// Handle hour submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_hour'])) {
    $date = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $break_minutes = $_POST['break_minutes'] ?? 0;
    $description = $_POST['description'] ?? '';
    $project = $_POST['project'] ?? '';

    if (!is_numeric($break_minutes) || $break_minutes < 0) {
        $error = "Break minutes must be a non-negative number.";
    } else {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO hours (user_id, date, start_time, end_time, break_minutes, description, project) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt === false) {
            $error = "Error preparing statement: " . $conn->error;
        } else {
            $stmt->bind_param("isssiss", $user_id, $date, $start_time, $end_time, $break_minutes, $description, $project);
            if ($stmt->execute()) {
                // Sla de laatst gebruikte projectnaam op in de sessie
                $_SESSION['last_project'] = $project;
                $success = "Hour entry added successfully!";
            } else {
                $error = "Error adding hour entry: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch hours for the current user with filters
$project_filter = isset($_GET['project_filter']) ? trim($_GET['project_filter']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$hours = [];
$query = "SELECT h.*, u.first_name, u.last_name 
          FROM hours h 
          JOIN users u ON h.user_id = u.id 
          WHERE h.user_id = ? AND 1=1";
$params = [$_SESSION['user_id']];
$types = "i";

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
$query .= " ORDER BY h.date DESC, h.start_time DESC";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    $error = "Error preparing hours statement: " . $conn->error;
} else {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $hours = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch unique recent projects for dropdown (last 5)
$recent_projects = [];
$stmt = $conn->prepare("SELECT DISTINCT project 
                       FROM hours 
                       WHERE user_id = ? AND project IS NOT NULL AND project != '' 
                       ORDER BY date DESC, start_time DESC 
                       LIMIT 5");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$recent_projects = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch unique projects for filter dropdown
$stmt = $conn->prepare("SELECT DISTINCT project FROM hours WHERE user_id = ? AND project IS NOT NULL AND project != '' ORDER BY project");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$projects = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate duration for hours
foreach ($hours as &$hour) {
    $start = strtotime($hour['start_time']);
    $end = strtotime($hour['end_time']);
    $hour['duration'] = round(($end - $start - ($hour['break_minutes'] * 60)) / 3600, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Time Tracking</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Dashboard</h2>
        <div class="nav-buttons">
            <?php if ($is_admin): ?>
                <a href="admin_dashboard.php" class="btn btn-secondary btn-small">Admin Dashboard</a>
            <?php endif; ?>
            <a href="profile.php" class="btn btn-secondary btn-small">Profile</a>
            <a href="logout.php" class="btn btn-secondary btn-small">Logout</a>
        </div>

        <?php if (isset($success)): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <h3>Add Hour</h3>
        <form method="post" class="compact-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="date">Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="start_time">Start:</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>
                <div class="form-group">
                    <label for="end_time">End:</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>
                <div class="form-group">
                    <label for="break_minutes">Break (min):</label>
                    <input type="number" id="break_minutes" name="break_minutes" min="0" value="0" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="project_input">Project:</label>
                    <select id="project_select" name="project" class="project-select" onchange="document.getElementById('project_input').value = this.value">
                        <option value="">Select recent project</option>
                        <?php foreach ($recent_projects as $project): ?>
                            <option value="<?php echo htmlspecialchars($project['project']); ?>" <?php echo isset($_SESSION['last_project']) && $_SESSION['last_project'] == $project['project'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['project']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="" disabled>---</option>
                        <option value="new" <?php echo !isset($_SESSION['last_project']) ? 'selected' : ''; ?>>Enter new project</option>
                    </select>
                    <input type="text" id="project_input" name="project" placeholder="Project name" value="<?php echo isset($_SESSION['last_project']) ? htmlspecialchars($_SESSION['last_project']) : ''; ?>" class="project-input">
                </div>
                <div class="form-group">
                    <label for="description">Description:</label>
                    <textarea id="description" name="description" placeholder="Description"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="submit_hour" class="btn btn-primary btn-small">Submit</button>
            </div>
        </form>

        <h3>Your Hours</h3>
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
                    <a href="dashboard.php" class="btn btn-secondary btn-small">Clear</a>
                </div>
            </div>
        </form>
        <?php if (empty($hours)): ?>
            <p>No hours recorded.</p>
        <?php else: ?>
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Break</th>
                        <th>Hours</th>
                        <th>Project</th>
                        <th>Description</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hours as $hour): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($hour['date']); ?></td>
                            <td><?php echo htmlspecialchars($hour['start_time']); ?></td>
                            <td><?php echo htmlspecialchars($hour['end_time']); ?></td>
                            <td><?php echo htmlspecialchars($hour['break_minutes']); ?></td>
                            <td><?php echo number_format($hour['duration'], 2); ?></td>
                            <td><?php echo htmlspecialchars($hour['project'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($hour['description'] ?? 'N/A'); ?></td>
                            <td>
                                <a href="admin_dashboard.php?edit_hour_id=<?php echo $hour['id']; ?>" class="btn btn-primary btn-tiny">Edit</a>
                                <a href="dashboard.php?delete_hour_id=<?php echo $hour['id']; ?>" 
                                   class="btn btn-danger btn-tiny" 
                                   onclick="return confirm('Are you sure you want to delete this hour entry?');">Del</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <script>
        // Synchroniseer de dropdown-selectie met het invoerveld
        document.getElementById('project_select').addEventListener('change', function() {
            const projectInput = document.getElementById('project_input');
            if (this.value === 'new') {
                projectInput.value = '';
                projectInput.focus();
            } else {
                projectInput.value = this.value;
            }
        });

        // Zorg dat de invoer het dropdown-veld bijwerkt bij handmatige wijziging
        document.getElementById('project_input').addEventListener('input', function() {
            const projectSelect = document.getElementById('project_select');
            if (this.value && !Array.from(projectSelect.options).some(opt => opt.value === this.value)) {
                projectSelect.value = 'new';
            } else {
                projectSelect.value = this.value;
            }
        });
    </script>
</body>
</html>