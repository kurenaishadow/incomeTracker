<?php
session_start();

require_once("connections.php");

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from GET parameters (e.g., from redirects)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Check if the user is logged in AND is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    // If not logged in as admin, redirect to login page with an error message
    header('Location: index.php?swal_message=' . urlencode('You do not have administrative access.') . '&swal_type=error');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
// $message = ''; // Message variable is replaced by SweetAlert2

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // No further processing if DB connection fails
    // Set default values for stats if DB connection fails
    $total_users = 0;
    $active_users = 0;
    $inactive_users = 0;
    $expired_users = 0;
    $admins = 0;
    $needs_password_change_users = 0;
} else {

    // --- BEGIN Security Checks for Admin ---
    // Fetch latest admin user status from DB to ensure session is up-to-date
    $sql_fetch_user_status = "SELECT needs_password_change, account_status, expiration_date FROM users WHERE id = ?";
    if ($stmt_status = $conn->prepare($sql_fetch_user_status)) {
        $stmt_status->bind_param('i', $user_id);
        $stmt_status->execute();
        $stmt_status->bind_result($db_needs_password_change, $db_account_status, $db_expiration_date);
        $stmt_status->fetch();
        $stmt_status->close();

        // Update session variables with fresh data
        $_SESSION['needs_password_change'] = $db_needs_password_change;
        $_SESSION['expiration_date'] = $db_expiration_date;
        $_SESSION['account_status'] = $db_account_status;

        // Check if password needs to be changed
        if ($_SESSION['needs_password_change'] == 1) {
            header('Location: change_password.php?swal_message=' . urlencode('You must change your password before proceeding.') . '&swal_type=warning');
            exit();
        }

        // Check if account is active
        if ($_SESSION['account_status'] !== 'active') {
            header('Location: logout.php?swal_message=' . urlencode('Your account is not active. Please contact support.') . '&swal_type=error');
            exit();
        }

        // Check for account expiration
        if ($_SESSION['expiration_date'] && strtotime($_SESSION['expiration_date']) < time()) {
            // Account has expired, update DB and redirect to logout
            $sql_update_expired = "UPDATE users SET account_status = 'expired' WHERE id = ?";
            if ($stmt_update_expired = $conn->prepare($sql_update_expired)) {
                $stmt_update_expired->bind_param('i', $user_id);
                $stmt_update_expired->execute();
                $stmt_update_expired->close();
            }
            header('Location: logout.php?swal_message=' . urlencode('Your account has expired. Please contact support.') . '&swal_type=error');
            exit();
        }
    } else {
        error_log("Error preparing user status fetch statement in admin_dashboard.php: " . $conn->error);
        header('Location: logout.php?swal_message=' . urlencode('A critical error occurred. Please try again.') . '&swal_type=error');
        exit();
    }
    // --- END Security Checks for Admin ---

    // --- Fetch Dashboard Statistics ---
    $total_users = 0;
    $active_users = 0;
    $inactive_users = 0;
    $expired_users = 0;
    $admins = 0;
    $needs_password_change_users = 0;

    // Total Users
    $result = $conn->query("SELECT COUNT(id) FROM users");
    if ($result) { $total_users = $result->fetch_row()[0]; $result->free(); }

    // Active Users
    $result = $conn->query("SELECT COUNT(id) FROM users WHERE account_status = 'active'");
    if ($result) { $active_users = $result->fetch_row()[0]; $result->free(); }

    // Inactive Users
    $result = $conn->query("SELECT COUNT(id) FROM users WHERE account_status = 'inactive'");
    if ($result) { $inactive_users = $result->fetch_row()[0]; $result->free(); }

    // Expired Users (check against current time)
    $result = $conn->query("SELECT COUNT(id) FROM users WHERE account_status = 'expired' OR (expiration_date IS NOT NULL AND expiration_date < NOW())");
    if ($result) { $expired_users = $result->fetch_row()[0]; $result->free(); }
    
    // Admins
    $result = $conn->query("SELECT COUNT(id) FROM users WHERE is_admin = 1");
    if ($result) { $admins = $result->fetch_row()[0]; $result->free(); }

    // Users needing password change
    $result = $conn->query("SELECT COUNT(id) FROM users WHERE needs_password_change = 1");
    if ($result) { $needs_password_change_users = $result->fetch_row()[0]; $result->free(); }

    // Close connection after all DB operations for this page
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
    <meta name="viewport" content="width=1100">
    <title>Admin Dashboard - Executive Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: linear-gradient(to right bottom, #e0f2fe, #bfdbfe);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
            flex-grow: 1;
        }
        .nav-link:hover {
            transform: scale(1.05);
            color: #dbeafe;
        }
        /* Mobile adjustments for header */
        @media (max-width: 768px) {
            .nav-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .nav-user-controls {
                margin-top: 1rem;
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
                gap: 0.5rem;
            }
            .nav-user-controls span, .nav-user-controls a {
                width: 100%;
                text-align: center;
            }
            .nav-user-controls a {
                padding: 0.75rem 0;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-gradient-to-r from-blue-700 to-blue-500 p-4 shadow-lg">
        <div class="container flex justify-between items-center nav-header">
            <h1 class="text-white text-3xl font-bold tracking-wide">Admin Dashboard</h1>
            <div class="flex items-center space-x-6 nav-user-controls">
                <span class="text-white text-lg font-medium">Welcome, Admin <?php echo htmlspecialchars($username); ?>!</span>
                <a href="dashboard.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    User Dashboard
                </a>
                <a href="logout.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-10">
        <!-- SweetAlert2 messages will be displayed by the JS at the bottom -->

        <div class="bg-white p-10 rounded-xl shadow-2xl mb-10 text-center">
            <h2 class="text-4xl font-extrabold text-gray-800 mb-6">Welcome to the Admin Control Panel!</h2>
            <p class="text-gray-700 text-xl leading-relaxed mb-8">
                From here, you can manage user accounts, activate/deactivate access, and view system overviews.
            </p>
        </div>

        <!-- Admin Overview Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
            <div class="bg-white p-6 rounded-xl shadow-lg border border-blue-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Total Users</h3>
                <p class="text-5xl font-bold text-blue-600"><?php echo $total_users; ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-lg border border-green-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Active Users</h3>
                <p class="text-5xl font-bold text-green-600"><?php echo $active_users; ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-lg border border-red-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Inactive Users</h3>
                <p class="text-5xl font-bold text-red-600"><?php echo $inactive_users; ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-lg border border-purple-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Admin Users</h3>
                <p class="text-5xl font-bold text-purple-600"><?php echo $admins; ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-lg border border-yellow-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Needs Password Change</h3>
                <p class="text-5xl font-bold text-yellow-600"><?php echo $needs_password_change_users; ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-lg border border-orange-200">
                <h3 class="text-xl font-semibold text-gray-700 mb-3">Expired Accounts</h3>
                <p class="text-5xl font-bold text-orange-600"><?php echo $expired_users; ?></p>
            </div>
        </div>

        <div class="flex flex-wrap justify-center gap-6 mt-8">
            <a href="admin_users.php" class="bg-blue-600 text-white px-8 py-4 rounded-lg text-lg font-semibold shadow-lg hover:bg-blue-700 transition duration-300 ease-in-out transform hover:scale-105">
                Manage Users
            </a>
            <!-- Future admin links can go here -->
        </div>
    </main>

    <footer class="bg-gray-900 text-white p-6 text-center mt-auto shadow-inner">
        <p class="text-lg">&copy; <?php echo date('Y'); ?> Executive Dashboard Admin. All rights reserved.</p>
    </footer>

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
                    confirmButtonText: 'Okay',
                    customClass: {
                        confirmButton: (phpSwalType === 'success' || phpSwalType === 'info') ? 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg' : 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                }).then(() => {
                    // Remove the swal_message and swal_type from the URL after the alert is closed
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
    </script>
</body>
</html>
