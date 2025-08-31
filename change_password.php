<?php
session_start();

// Database configuration
require_once("connections.php");

$message = '';

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$is_admin = $_SESSION['is_admin'] ?? 0; // Default to 0 if not set
$needs_password_change_flag = $_SESSION['needs_password_change'] ?? 0; // Get the flag from session

// If the user *doesn't* need to change their password, redirect them away from this page
// Also check for expiration here to prevent access if already expired, even if password changed
if ($needs_password_change_flag == 0) {
    // Fetch current expiration date from DB to be sure (session might be outdated if expired from outside)
    $sql_check_exp = "SELECT expiration_date FROM users WHERE id = ?";
    if ($stmt_check_exp = $conn->prepare($sql_check_exp)) {
        $stmt_check_exp->bind_param('i', $user_id);
        $stmt_check_exp->execute();
        $stmt_check_exp->bind_result($db_expiration_date);
        $stmt_check_exp->fetch();
        $stmt_check_exp->close();

        if ($db_expiration_date && strtotime($db_expiration_date) < time()) {
            // Account expired, redirect to a message page or login with appropriate message
            // For now, redirect to login, index.php will handle the 'expired' message
            header('Location: logout.php'); // Log out and then index.php will show expired msg
            exit();
        }
    }


    if ($is_admin) {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}


// Process form submission to change password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_new_password = $_POST['confirm_new_password'] ?? '';

    // Determine the 'current_password' for verification
    // If the needs_password_change flag is set, we assume their current password is the default
    $current_password_for_verification = ($needs_password_change_flag == 1) ? 'abc123!' : ($_POST['current_password'] ?? '');

    // First, verify the current password against the stored hash
    $sql_fetch_password = "SELECT password_hash FROM users WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch_password)) {
        $stmt_fetch->bind_param('i', $user_id);
        $stmt_fetch->execute();
        $stmt_fetch->bind_result($stored_password_hash);
        $stmt_fetch->fetch();
        $stmt_fetch->close();

        if (!password_verify($current_password_for_verification, $stored_password_hash)) {
            $message = '<p class="text-red-500 text-sm mt-2">The current password you entered is incorrect.</p>';
            // Special message if they were supposed to use default but entered something else
            if ($needs_password_change_flag == 1 && $current_password_for_verification !== 'abc123!') {
                 $message .= '<p class="text-red-500 text-sm mt-2">Since you just registered, your current password should be \'abc123!\'.</p>';
            }
        } elseif (empty($new_password) || strlen($new_password) < 6) {
            $message = '<p class="text-red-500 text-sm mt-2">New password must be at least 6 characters long.</p>';
        } elseif ($new_password !== $confirm_new_password) {
            $message = '<p class="text-red-500 text-sm mt-2">New passwords do not match.</p>';
        } else {
            // Hash the new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // Calculate expiration date (24 hours from now)
            $expiration_date = date('Y-m-d H:i:s', strtotime('+24 hours')); // YYYY-MM-DD HH:MM:SS format

            // Update password, set needs_password_change to 0, and set the expiration_date
            $sql_update_password = "UPDATE users SET password_hash = ?, needs_password_change = 0, expiration_date = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update_password)) {
                $stmt_update->bind_param('ssi', $new_password_hash, $expiration_date, $user_id);
                if ($stmt_update->execute()) {
                    // Update session flags
                    $_SESSION['needs_password_change'] = 0;
                    $_SESSION['expiration_date'] = $expiration_date; // Store new expiration in session

                    $message = '<p class="text-green-500 text-sm mt-2">Your password has been changed successfully! Your preview period starts now.</p>';
                    // Redirect to dashboard after successful password change
                    if ($is_admin) {
                        header('Location: admin_dashboard.php');
                    } else {
                        header('Location: dashboard.php');
                    }
                    exit();
                } else {
                    $message = '<p class="text-red-500 text-sm mt-2">Error updating password or expiration: ' . $stmt_update->error . '</p>';
                }
                $stmt_update->close();
            } else {
                $message = '<p class="text-red-500 text-sm mt-2">Error preparing update statement: ' . $conn->error . '</p>';
            }
        }
    } else {
        $message = '<p class="text-red-500 text-sm mt-2">Error preparing password verification statement: ' . $conn->error . '</p>';
    }
}

// Close connection
$conn->close();
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
            background-image: linear-gradient(to right bottom, #fef2f2, #fee2e2); /* Light red gradient for a warning feel */
        }
        .container {
            width: 100%;
            max-width: 500px;
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
            border-color: #ef4444; /* Red ring */
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.25); /* Red shadow */
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Change Your Password</h2>
        <p class="text-red-600 text-center mb-6 font-semibold">
            For security, please change your password before proceeding.
        </p>
        <?php echo $message; ?>
        <form action="change_password.php" method="POST" class="space-y-6">
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password (or 'abc123!'):</label>
                <input type="password" id="current_password" name="current_password" required
                       value="<?php echo ($needs_password_change_flag == 1) ? 'abc123!' : ''; ?>"
                       <?php echo ($needs_password_change_flag == 1) ? 'disabled readonly' : ''; ?>
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <div>
                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password:</label>
                <input type="password" id="new_password" name="new_password" required minlength="6"
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <div>
                <label for="confirm_new_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password:</label>
                <input type="password" id="confirm_new_password" name="confirm_new_password" required minlength="6"
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <button type="submit"
                    class="w-full bg-red-600 text-white py-3 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-50 font-semibold transition duration-200 shadow-md">
                Change Password
            </button>
        </form>
        <p class="mt-6 text-center text-gray-600 text-sm">
            <a href="logout.php" class="text-blue-600 hover:text-blue-800 font-medium transition duration-200">Logout</a>
        </p>
    </div>
</body>
</html>
