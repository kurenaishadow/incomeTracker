<?php
session_start();

// Database configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // Your database username
define('DB_PASSWORD', '');     // Your database password
define('DB_NAME', 'dashboard_db'); // Your database name

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$message = '';
$redirect_to_dashboard = false;

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Fetch current needs_password_change status and actual password hash directly from DB
$sql_user_data = "SELECT password_hash, needs_password_change FROM users WHERE id = ?";
$current_password_hash = null;
$current_needs_password_change = 0;

if ($stmt = $conn->prepare($sql_user_data)) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($current_password_hash_from_db, $current_needs_password_change);
    $stmt->fetch();
    $stmt->close();
    
    $current_password_hash = $current_password_hash_from_db; // Store for later verification
    
    // If needs_password_change is 0, user doesn't need to be here, redirect to dashboard
    if ($current_needs_password_change == 0) {
        header('Location: dashboard.php');
        exit();
    }
} else {
    // Handle error if statement preparation fails
    $message = '<p class="text-red-500 text-sm mt-2">Error checking user data: ' . $conn->error . '</p>';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password_input = $_POST['current_password'] ?? ''; // New: Current password input
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate current password first
    if (empty($current_password_input)) {
        $message = '<p class="text-red-500 text-sm mt-2">Please enter your current password.</p>';
    } elseif (!password_verify($current_password_input, $current_password_hash)) {
        $message = '<p class="text-red-500 text-sm mt-2">The current password you entered is incorrect.</p>';
    } elseif (empty($new_password) || empty($confirm_password)) {
        $message = '<p class="text-red-500 text-sm mt-2">Please enter and confirm your new password.</p>';
    } elseif (strlen($new_password) < 6) {
        $message = '<p class="text-red-500 text-sm mt-2">New password must be at least 6 characters long.</p>';
    } elseif ($new_password !== $confirm_password) {
        $message = '<p class="text-red-500 text-sm mt-2">New passwords do not match.</p>';
    } elseif ($new_password === $current_password_input) {
        $message = '<p class="text-red-500 text-sm mt-2">Your new password cannot be the same as your current password.</p>';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $sql_update_password = "UPDATE users SET password_hash = ?, needs_password_change = 0 WHERE id = ?";
        if ($stmt = $conn->prepare($sql_update_password)) {
            $stmt->bind_param('si', $hashed_password, $user_id);
            if ($stmt->execute()) {
                // Update session variable as well
                $_SESSION['needs_password_change'] = 0;
                $message = '<p class="text-green-500 text-sm mt-2">Your password has been updated successfully! Redirecting to dashboard...</p>';
                $redirect_to_dashboard = true;
            } else {
                $message = '<p class="text-red-500 text-sm mt-2">Error updating password: ' . $stmt->error . '</p>';
            }
            $stmt->close();
        } else {
            $message = '<p class="text-red-500 text-sm mt-2">Error preparing password update: ' . $conn->error . '</p>';
        }
    }
}

$conn->close();

if ($redirect_to_dashboard) {
    echo '<script>setTimeout(function() { window.location.href = "dashboard.php"; }, 3000);</script>';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Executive Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-image: linear-gradient(to right bottom, #fefce8, #fef08a); /* Yellowish gradient for urgency */
        }
        .container {
            width: 100%;
            max-width: 450px;
            padding: 2.5rem;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.8s ease-out;
            transform: translateY(0);
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
        }
        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .form-input {
            transition: all 0.2s ease-in-out;
        }
        .form-input:focus {
            border-color: #f59e0b; /* Amber ring */
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25); /* Amber shadow */
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Change Your Password</h2>
        <p class="text-center text-yellow-700 text-md mb-6 font-semibold">
            An administrator has required you to change your password. Please set a new, strong password to continue.
        </p>
        <?php echo $message; ?>
        <form action="change_password_force.php" method="POST" class="space-y-6">
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password:</label>
                <input type="password" id="current_password" name="current_password" required
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-yellow-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <div>
                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password:</label>
                <input type="password" id="new_password" name="new_password" required
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-yellow-500 focus:border-transparent outline-none transition duration-200"
                       minlength="6">
            </div>
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-yellow-500 focus:border-transparent outline-none transition duration-200"
                       minlength="6">
            </div>
            <button type="submit"
                    class="w-full bg-yellow-600 text-white py-3 rounded-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-opacity-50 font-semibold transition duration-200 shadow-md">
                Update Password
            </button>
        </form>
    </div>
</body>
</html>
