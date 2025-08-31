<?php
session_start();

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from GET parameters (e.g., from redirects like register.php, change_password.php, or self-redirects from this page)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Define developer contact information for PHP to pass to JavaScript
$developer_contacts_html = '
    <p class="text-gray-700 mb-4">For any account-related issues or support, please reach out to me:</p>
    <div class="text-left space-y-2">
        <p class="font-semibold">Telegram: <a href="https://t.me/Samplegui" class="text-blue-600 hover:underline" target="_blank">Contact via Telegram</a></p>
        <p class="font-semibold">Facebook: <a href="https://www.facebook.com/samplegui" target="_blank" class="text-blue-600 hover:underline">Contact via Messenger or FB</a></p>
        <p class="font-semibold">Phone: Not Available</p>
    </div>
';

// Database configuration
require_once("connections.php");

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // For critical DB connection errors, display a SweetAlert2 error
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // No further processing if DB connection fails, but let the page render to show SweetAlert.
} else {

    // Redirect if already logged in (This section is critical for session management)
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $is_admin_session = $_SESSION['is_admin'] ?? 0;

        // Fetch the latest status from the database to ensure session is up-to-date
        $sql_fetch_status = "SELECT needs_password_change, account_status, expiration_date FROM users WHERE id = ?";
        if ($stmt_status = $conn->prepare($sql_fetch_status)) {
            $stmt_status->bind_param('i', $user_id);
            $stmt_status->execute();
            $stmt_status->bind_result($db_needs_password_change, $db_account_status, $db_expiration_date);
            $stmt_status->fetch();
            $stmt_status->close();

            // Update session variables with fresh data
            $_SESSION['needs_password_change'] = $db_needs_password_change;
            $_SESSION['account_status'] = $db_account_status; // Update account_status in session for consistency
            $_SESSION['expiration_date'] = $db_expiration_date;

            // Check password change requirement
            if ($db_needs_password_change == 1) {
                header('Location: change_password.php?swal_message=' . urlencode('You must change your password before proceeding.') . '&swal_type=warning');
                exit();
            }

            // Check account status
            if ($db_account_status !== 'active') {
                // If not active, destroy session and redirect to login.php with GET params
                session_unset();
                session_destroy();
                header('Location: login.php?swal_message=' . urlencode('Your account is not active. Please contact support.') . '&swal_type=error');
                exit();
            }

            // Check expiration date
            if ($db_expiration_date && strtotime($db_expiration_date) < time()) {
                // Account expired, update status in DB
                $sql_update_expired = "UPDATE users SET account_status = 'expired' WHERE id = ?";
                if ($stmt_update_expired = $conn->prepare($sql_update_expired)) {
                    $stmt_update_expired->bind_param('i', $user_id);
                    $stmt_update_expired->execute();
                    $stmt_update_expired->close();
                }
                // Destroy session and redirect to login.php with GET params
                session_unset();
                session_destroy();
                header('Location: login.php?swal_message=' . urlencode('Your account has expired. Please contact support to reactivate.') . '&swal_type=error');
                exit();
            }

            // If all checks pass, redirect to appropriate dashboard
            if ($is_admin_session) {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: dashboard.php');
            }
            exit();
        } else {
            error_log("Error preparing user status fetch statement in login.php (initial check): " . $conn->error);
            // Fallback for DB error during initial status check, destroy session and redirect
            session_unset();
            session_destroy();
            header('Location: login.php?swal_message=' . urlencode('A critical error occurred during login check. Please try again.') . '&swal_type=error');
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $swal_message = 'Please enter both username and password.';
            $swal_type = 'error';
        } else {
            // Prepare a select statement to get user details
            $sql = "SELECT id, username, password_hash, is_admin, account_status, expiration_date, currency, needs_password_change FROM users WHERE username = ?";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('s', $param_username);
                $param_username = $username;

                if ($stmt->execute()) {
                    $stmt->store_result();

                    if ($stmt->num_rows == 1) {
                        $stmt->bind_result($id, $username_db, $password_hash, $is_admin, $account_status, $expiration_date, $currency_symbol, $needs_password_change);
                        if ($stmt->fetch()) {
                            if (password_verify($password, $password_hash)) {
                                // Password is correct, now check account status and expiration
                                if ($account_status === 'active') {
                                    if ($expiration_date && strtotime($expiration_date) < time()) {
                                        // Account expired - update status in DB
                                        $sql_update_expired = "UPDATE users SET account_status = 'expired' WHERE id = ?";
                                        if ($stmt_update_expired = $conn->prepare($sql_update_expired)) {
                                            $stmt_update_expired->bind_param('i', $id);
                                            $stmt_update_expired->execute();
                                            $stmt_update_expired->close();
                                        }
                                        $swal_message = 'Your account has expired. Please contact support to reactivate.';
                                        $swal_type = 'error';
                                        // The page will now render this message without a redirect.
                                    } else {
                                        // Account is active and not expired, so start a new session
                                        session_regenerate_id();
                                        $_SESSION['user_id'] = $id;
                                        $_SESSION['username'] = $username_db;
                                        $_SESSION['is_admin'] = $is_admin;
                                        $_SESSION['currency'] = $currency_symbol;
                                        $_SESSION['needs_password_change'] = $needs_password_change;
                                        $_SESSION['expiration_date'] = $expiration_date;

                                        // Redirect based on needs_password_change flag
                                        if ($needs_password_change == 1) {
                                            header('Location: change_password.php?swal_message=' . urlencode('You must change your password before proceeding.') . '&swal_type=warning');
                                        } elseif ($is_admin) {
                                            header('Location: admin_dashboard.php');
                                        } else {
                                            header('Location: dashboard.php');
                                        }
                                        exit();
                                    }
                                } else {
                                    // Account is not active (e.g., 'inactive', 'expired' from previous session, 'pending_renewal').
                                    $swal_message = 'Your account is currently ' . htmlspecialchars($account_status) . '. Please contact support for activation.';
                                    $swal_type = 'error';
                                }
                            } else {
                                // Password is not valid
                                $swal_message = 'Invalid username or password.';
                                $swal_type = 'error';
                            }
                        }
                    } else {
                        // Username doesn't exist
                        $swal_message = 'Invalid username or password.';
                        $swal_type = 'error';
                    }
                } else {
                    error_log("Error executing login statement: " . $stmt->error);
                    $swal_message = 'Oops! Something went wrong. Please try again later.';
                    $swal_type = 'error';
                }
                $stmt->close();
            } else {
                error_log("Error preparing login statement: " . $conn->error);
                $swal_message = 'Oops! Something went wrong. Please try again later.';
                $swal_type = 'error';
            }
        }
    }
    // Close connection
    $conn->close();
} // End of else (DB connection successful)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Executive Dashboard</title>
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
            background-image: linear-gradient(to right bottom, #dbeafe, #bfdbfe);
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.8s ease-out;
            transform: translateY(0);
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
        }
        .login-container:hover {
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
            border-color: #3b82f6; /* Blue ring */
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); /* Blue shadow */
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Login</h2>
        <!-- PHP $message output removed; SweetAlert2 will handle display -->
        <form action="login.php" method="POST" class="space-y-6">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username:</label>
                <input type="text" id="username" name="username" required
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password:</label>
                <input type="password" id="password" name="password" required
                       class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
            </div>
            <button type="submit"
                    class="w-full bg-blue-600 text-white py-3 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 font-semibold transition duration-200 shadow-md">
                Log In
            </button>
        </form>
        <p class="mt-6 text-center text-gray-600 text-sm">
            Don't have an account? <a href="register.php" class="text-blue-600 hover:text-blue-800 font-medium transition duration-200">Register here</a>
        </p>
        <!-- NEW: Contact Developer Button -->
        <p class="mt-4 text-center text-gray-600 text-sm">
            Having trouble? <a href="#" onclick="showContactDeveloperInfo(event)"
                               class="text-purple-600 hover:text-purple-800 font-medium transition duration-200">Contact the Creator</a>
        </p>
    </div>

    <script>
        // Define developer contacts in JavaScript
        // This variable now holds the raw HTML string
        const developerContactsHtml = `<?php echo addslashes($developer_contacts_html); ?>`;

        // SweetAlert2 integration - this runs after the DOM is fully loaded
        window.onload = function() {
            // Check for PHP-generated SweetAlert2 messages
            let phpSwalMessage = <?php echo json_encode($swal_message); ?>;
            const phpSwalType = "<?php echo $swal_type; ?>";

            if (phpSwalMessage) {
                // The logic to append developer contacts for expired/inactive accounts has been removed.
                // Now, only the original message will be shown.

                Swal.fire({
                    icon: phpSwalType,
                    title: phpSwalType.charAt(0).toUpperCase() + phpSwalType.slice(1), // Capitalize first letter
                    html: phpSwalMessage, // Use html to allow for bold text and links in the message
                    confirmButtonText: 'Okay',
                    customClass: {
                        confirmButton: (phpSwalType === 'success' || phpSwalType === 'info') ? 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg' : 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                }).then(() => {
                    // Remove the swal_message and swal_type from the URL after the alert is closed
                    // This prevents the alert from reappearing if the user refreshes the page
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };

        // Function to show SweetAlert2 for Contact Developer (from the dedicated button)
        function showContactDeveloperInfo(event) {
            event.preventDefault(); // Prevent the default link behavior (e.g., mailto)

            Swal.fire({
                title: 'Contact the Developer',
                icon: 'info',
                html: developerContactsHtml, // Use the raw HTML string directly
                confirmButtonText: 'Close',
                customClass: {
                    confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg',
                },
                buttonsStyling: false
            });
        }
    </script>
</body>
</html>
