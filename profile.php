<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch current user details
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
if ($stmt === false) {
    $error = "Error preparing statement: " . $conn->error;
} else {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if (!$user) {
        $error = "User not found.";
    }
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email is already taken by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $error = "Email is already in use by another account.";
        }
        $stmt->close();

        if (!isset($error)) {
            if (!empty($password)) {
                // Update with new password
                $password_hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, password = ? WHERE id = ?");
                if ($stmt === false) {
                    $error = "Error preparing update statement: " . $conn->error;
                } else {
                    $stmt->bind_param("ssssi", $first_name, $last_name, $email, $password_hashed, $user_id);
                    if ($stmt->execute()) {
                        $success = "Profile updated successfully!";
                    } else {
                        $error = "Error updating profile: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                // Update without changing password
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
                if ($stmt === false) {
                    $error = "Error preparing update statement: " . $conn->error;
                } else {
                    $stmt->bind_param("sssi", $first_name, $last_name, $email, $user_id);
                    if ($stmt->execute()) {
                        $success = "Profile updated successfully!";
                    } else {
                        $error = "Error updating profile: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Time Tracking</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h2>Edit Profile</h2>
        <p><a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a></p>

        <?php if (isset($success)): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="first_name">First Name:</label>
                <input type="text" id="first_name" name="first_name" value="<?php echo isset($user) ? htmlspecialchars($user['first_name']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="last_name">Last Name:</label>
                <input type="text" id="last_name" name="last_name" value="<?php echo isset($user) ? htmlspecialchars($user['last_name']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo isset($user) ? htmlspecialchars($user['email']) : ''; ?>" required>
            </div>
            <div class="form-group">
                <label for="password">New Password (leave blank to keep current):</label>
                <input type="password" id="password" name="password" placeholder="Enter new password">
            </div>
            <button type="submit" class="btn btn-primary">Update Profile</button>
        </form>
    </div>
</body>
</html>