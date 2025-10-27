<?php 
require_once 'config.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Haal de rol van de huidige gebruiker op
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$first_name = $user['first_name'] ?? '';
// Optionally store in session
$_SESSION['first_name'] = $first_name;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_hour'])) {
    $delete_id = intval($_POST['hour_id'] ?? 0);
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("DELETE FROM hours WHERE id = ? AND user_id = ?");
    if ($stmt === false) {
        $error = "Error preparing delete statement: " . $conn->error;
    } else {
        $stmt->bind_param("ii", $delete_id, $user_id);
        if ($stmt->execute()) {
            $success = "Hour entry deleted successfully.";
        } else {
            $error = "Error deleting hour entry: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Handle hour submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_hour'])) {
    $date = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $break_minutes = $_POST['break_minutes'] ?? 0;
    $description = $_POST['description'] ?? '';

    if (!is_numeric($break_minutes) || $break_minutes < 0) {
        $error = "Break minutes must be a non-negative number.";
    } else {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO hours (user_id, date, start_time, end_time, break_minutes, description) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt === false) {
            $error = "Error preparing statement: " . $conn->error;
        } else {
            $stmt->bind_param("isssis", $user_id, $date, $start_time, $end_time, $break_minutes, $description,);
            if ($stmt->execute()) {
                $success = "Hour entry added successfully!";
            } else {
                $error = "Error adding hour entry: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch hours data for the current user
$stmt = $conn->prepare("SELECT id, date, start_time, end_time, break_minutes, description, 
    TIMESTAMPDIFF(MINUTE, CONCAT(date, ' ', start_time), CONCAT(date, ' ', end_time))/60 - break_minutes/60 as total_hours 
    FROM hours WHERE user_id = ? ORDER BY date DESC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$hours = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <div class="container">
        <h1>Welcome, <?php echo htmlspecialchars($first_name); ?>!</h1>
        <p>This is your dashboard.</p>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <a href="logout.php">Logout</a>

        <h3>Add Hours</h3>
        <form method="post" class="compact-form">
            <label for="date">Date:</label>
            <input type="date" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required><br>

            <label for="hours">Start:</label>
            <input type="time" id="start_time" name="start_time" required><br>

            <label for="hours">End:</label>
            <input type="time" id="end_time" name="end_time" required><br>

            <label for="break_minutes">Break (min)</label>
            <input type="number" id="break_minutes" name="break_minutes" required><br>

            <label for="description">Description:</label>
            <input type="text" id="description" name="description" required><br>

            <button type="submit" name="submit_hour" class="btn btn-primary btn-small">Submit</button>
        </form>

        <h3>Your Hours</h3>
        <?php if (count($hours) > 0): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Break (min)</th>
                        <th>Description</th>
                        <th>Total Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hours as $hour): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($hour['date']); ?></td>
                            <td><?php echo htmlspecialchars($hour['start_time']); ?></td>
                            <td><?php echo htmlspecialchars($hour['end_time']); ?></td>
                            <td><?php echo htmlspecialchars($hour['break_minutes']); ?></td>
                            <td><?php echo htmlspecialchars($hour['description']); ?></td>
                            <td><?php echo number_format($hour['total_hours'], 2); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this entry?');" style="display:inline">
                                    <input type="hidden" name="hour_id" value="<?php echo (int)$hour['id']; ?>">
                                    <button type="submit" name="delete_hour" class="btn btn-danger btn-small">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No hours logged yet.</p> 
        <?php endif; ?>
    </div>
</body>
</html>