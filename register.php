<?php
session_start();

date_default_timezone_set('Asia/Manila');

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from GET parameters (e.g., from redirects, though register.php typically doesn't receive them)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Enable mysqli error reporting for better debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Database configuration
require_once("connections.php");

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // For critical DB connection errors, we'll display a SweetAlert2 error
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // No further processing if DB connection fails
} else {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // Validate inputs
        if (empty($username) || empty($email)) {
            $swal_message = 'Both username and email are required.';
            $swal_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $swal_message = 'Please enter a valid email address.';
            $swal_type = 'error';
        } else {
            // Check if username or email already exists
            $sql_check_duplicate = "SELECT id FROM users WHERE username = ? OR email = ?";
            if ($stmt_check = $conn->prepare($sql_check_duplicate)) {
                $stmt_check->bind_param('ss', $username, $email);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $swal_message = 'Username or Email already exists. Please choose a different one.';
                    $swal_type = 'warning';
                }
                $stmt_check->close();
            } else {
                 $swal_message = 'Error preparing duplicate check statement: ' . $conn->error;
                 $swal_type = 'error';
            }

            // If no duplicates and no other errors, proceed to insert user
            // We check if $swal_message is still empty to ensure no previous errors occurred
            if (empty($swal_message)) {
                // Set the default password and hash it
                $default_password = 'password';
                $password_hash = password_hash($default_password, PASSWORD_DEFAULT);

                // Create variables for binding
                $bind_username = $username;
                $bind_email = $email;
                $bind_password_hash = $password_hash;
                $bind_is_admin = 0; // New users are not admins
                $bind_account_status = 'active'; // New users are 'active' by default
                $bind_expiration_date = null; // No expiration date set initially
                $bind_needs_password_change = 1; // Flag that user needs to change password

                // IMPORTANT: Check if password_hash failed or returned an empty hash string
                if ($bind_password_hash === false || !is_string($bind_password_hash) || empty($bind_password_hash)) {
                    $swal_message = 'Error creating password hash during registration. Please try again or contact support.';
                    $swal_type = 'error';
                    // We will not exit here, but let the SweetAlert2 message display.
                    // The user will be able to retry.
                } else {
                    $sql = "INSERT INTO users (username, email, password_hash, is_admin, account_status, expiration_date, needs_password_change) VALUES (?, ?, ?, ?, ?, ?, ?)";

                    if ($stmt = $conn->prepare($sql)) {
                        // bind_param types: sssisss (string, string, string, int, string, string, int)
                        $stmt->bind_param('sssisss', $bind_username, $bind_email, $bind_password_hash, $bind_is_admin, $bind_account_status, $bind_expiration_date, $bind_needs_password_change);


                        if ($stmt->execute()) {
                            // Set SweetAlert2 message directly for display on this page
                            $swal_message = 'Registration successful!<br>Your username: <strong>' . htmlspecialchars($bind_username) . '</strong>. <br>Your default password is <strong>' . htmlspecialchars($default_password) . '</strong>. <br>Please <a href="login.php" class="text-blue-600 hover:text-blue-800 font-medium">log in</a> and change your password immediately.';
                            $swal_type = 'success';
                            // Clear form fields after successful submission
                            $username = '';
                            $email = '';
                            // NO REDIRECT HERE, the SweetAlert2 will be displayed on the current page.
                        } else {
                            $swal_message = 'Error during registration: ' . $stmt->error;
                            $swal_type = 'error';
                        }
                        $stmt->close();
                    } else {
                        $swal_message = 'Error preparing statement: ' . $conn->error;
                        $swal_type = 'error';
                    }
                }
            }
        }
    }
}
// Close connection if it was opened
if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Executive Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            background-image: linear-gradient(to right bottom, #d1fae5, #a7f3d0);
        }
        .register-container {
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
        .register-container:hover {
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
            border-color: #10b981; /* Green ring */
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25); /* Green shadow */
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Register Account</h2>
        <!-- PHP $message output removed; SweetAlert2 will handle display -->
        <form action="register.php" method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username:</label>
                <input type="text" id="username" name="username" required
                       value="<?php echo htmlspecialchars($username ?? ''); ?>"
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email:</label>
                <input type="email" id="email" name="email" required
                       value="<?php echo htmlspecialchars($email ?? ''); ?>"
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <button type="submit"
                    class="w-full bg-green-600 text-white py-3 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50 font-semibold transition duration-200 shadow-md">
                Register
            </button>
        </form>
        <p class="mt-6 text-center text-gray-600 text-sm">
            Already have an account? <a href="login.php" class="text-blue-600 hover:text-blue-800 font-medium transition duration-200">Login here</a>
        </p>
    </div>

    <script>
        // SweetAlert2 integration - this runs after the DOM is fully loaded
        window.onload = function() {
            // Check for PHP-generated SweetAlert2 messages
            const phpSwalMessage = <?php echo json_encode($swal_message); ?>;
            const phpSwalType = "<?php echo $swal_type; ?>";

            if (phpSwalMessage) {
                Swal.fire({
                    icon: phpSwalType,
                    title: phpSwalType.charAt(0).toUpperCase() + phpSwalType.slice(1), // Capitalize first letter
                    html: phpSwalMessage, // Use html to allow for bold text and links in the message
                    confirmButtonText: 'Log in to your account',
                    customClass: {
                        confirmButton: 'bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                }).then(() => {
                    // Only redirect to login.php if the SweetAlert is a 'success' type
                    if (phpSwalType === 'success') {
                        window.location.href = 'login.php';
                    } else {
                        // For other types (error, warning), just clean the URL or stay on the page
                        window.history.replaceState({}, document.title, window.location.pathname);
                    }
                });
            }
        };
    </script>
</body>
</html>
