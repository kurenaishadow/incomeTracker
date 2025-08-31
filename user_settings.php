<?php
session_start();

// Database configuration
require_once("connections.php");

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from GET parameters (e.g., from redirects, although this page primarily posts to itself)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
// $message = ''; // This variable is no longer directly used for displaying messages on the page.

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // For critical DB connection errors, we don't proceed with other DB operations
    // We will let the page render to display the Swal error.
} else {

    // --- Fetch current user settings for display and form pre-population ---
    $business_name = '';
    $currency = '$';
    $monthly_income_target = 0.00;
    $monthly_expense_target = 0.00;
    $show_inventory_overview = 1; // Default to 1 (true) if not found

    $sql_fetch_settings = "SELECT business_name, currency, monthly_income_target, monthly_expense_target, show_inventory_overview FROM users WHERE id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch_settings)) {
        $stmt_fetch->bind_param('i', $user_id);
        if ($stmt_fetch->execute()) {
            $stmt_fetch->bind_result($business_name, $currency, $monthly_income_target, $monthly_expense_target, $show_inventory_overview);
            $stmt_fetch->fetch();
            // Ensure values are not null if database has nulls and PHP expects defaults
            $business_name = $business_name ?? '';
            $currency = $currency ?? '$';
            $monthly_income_target = $monthly_income_target ?? 0.00;
            $monthly_expense_target = $monthly_expense_target ?? 0.00;
            $show_inventory_overview = $show_inventory_overview ?? 1; // Default to 1 if DB is null
        } else {
            $swal_message = 'Error fetching settings: ' . $stmt_fetch->error;
            $swal_type = 'error';
        }
        $stmt_fetch->close();
    } else {
        $swal_message = 'Error preparing fetch statement: ' . $conn->error;
        $swal_type = 'error';
    }


    // --- Handle Form Submissions for Updating Settings ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_business_info'])) {
            $new_business_name = trim($_POST['business_name'] ?? '');
            $new_currency = trim($_POST['currency'] ?? '$');

            if (empty($new_business_name)) {
                $swal_message = 'Business name cannot be empty.';
                $swal_type = 'error';
            } else {
                $sql_update = "UPDATE users SET business_name = ?, currency = ? WHERE id = ?";
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param('ssi', $new_business_name, $new_currency, $user_id);
                    if ($stmt_update->execute()) {
                        $_SESSION['business_name'] = $new_business_name;
                        $_SESSION['currency'] = $new_currency;
                        // Update local variables to reflect changes without a full page reload for display purposes
                        $business_name = $new_business_name;
                        $currency = $new_currency;
                        $swal_message = 'Business information updated successfully!';
                        $swal_type = 'success';
                    } else {
                        $swal_message = 'Error updating business information: ' . $stmt_update->error;
                        $swal_type = 'error';
                    }
                    $stmt_update->close();
                } else {
                    $swal_message = 'Error preparing update business information statement: ' . $conn->error;
                    $swal_type = 'error';
                }
            }
        } elseif (isset($_POST['update_targets'])) {
            $new_income_target = (float)($_POST['monthly_income_target'] ?? 0);
            $new_expense_target = (float)($_POST['monthly_expense_target'] ?? 0);

            if ($new_income_target < 0 || $new_expense_target < 0) {
                $swal_message = 'Targets cannot be negative.';
                $swal_type = 'error';
            } else {
                $sql_update_targets = "UPDATE users SET monthly_income_target = ?, monthly_expense_target = ? WHERE id = ?";
                if ($stmt_targets = $conn->prepare($sql_update_targets)) {
                    $stmt_targets->bind_param('ddi', $new_income_target, $new_expense_target, $user_id);
                    if ($stmt_targets->execute()) {
                        $_SESSION['monthly_income_target'] = $new_income_target;
                        $_SESSION['monthly_expense_target'] = $new_expense_target;
                        // Update local variables
                        $monthly_income_target = $new_income_target;
                        $monthly_expense_target = $new_expense_target;
                        $swal_message = 'Monthly targets updated successfully!';
                        $swal_type = 'success';
                    } else {
                        $swal_message = 'Error updating targets: ' . $stmt_targets->error;
                        $swal_type = 'error';
                    }
                    $stmt_targets->close();
                } else {
                    $swal_message = 'Error preparing update targets statement: ' . $conn->error;
                    $swal_type = 'error';
                }
            }
        } elseif (isset($_POST['update_dashboard_preferences'])) {
            $new_show_inventory_overview = isset($_POST['show_inventory_overview']) ? 1 : 0;

            $sql_update_preference = "UPDATE users SET show_inventory_overview = ? WHERE id = ?";
            if ($stmt_preference = $conn->prepare($sql_update_preference)) {
                $stmt_preference->bind_param('ii', $new_show_inventory_overview, $user_id);
                if ($stmt_preference->execute()) {
                    $_SESSION['show_inventory_overview'] = $new_show_inventory_overview;
                    // Update local variable
                    $show_inventory_overview = $new_show_inventory_overview;
                    $swal_message = 'Dashboard preferences updated successfully!';
                    $swal_type = 'success';
                } else {
                    $swal_message = 'Error updating dashboard preferences: ' . $stmt_preference->error;
                    $swal_type = 'error';
                }
                $stmt_preference->close();
            } else {
                $swal_message = 'Error preparing update dashboard preferences statement: ' . $conn->error;
                $swal_type = 'error';
            }
        }
    }
    // No need to re-fetch settings after any update *if* we're setting the local variables for display
    // in each update block. This simplifies the logic and reduces an extra DB call.

    // Close connection
    $conn->close();
} // End of else (DB connection successful)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Settings - Executive Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f9ff; /* Lightest blue-gray */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: linear-gradient(to right bottom, #f0f9ff, #e0f7fa); /* Subtle gradient */
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 1.5rem;
            flex-grow: 1;
        }
        .card {
            background-color: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            transition: all 0.3s ease-in-out;
            border: 1px solid #e2e8f0;
        }
        .card:hover {
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
        }
        .form-input, .form-select {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
            color: #374151;
            transition: all 0.2s ease-in-out;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.06);
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #60a5fa; /* Blue-400 */
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.45); /* Blue-400 with opacity */
        }
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%236B7280'%3e%3cpath d='M7 7l3-3 3 3m0 6l-3 3-3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            text-align: center;
            transition: background-color 0.2s ease-in-out, transform 0.2s ease-in-out;
            display: inline-block;
        }
        .btn-primary {
            background-color: #3b82f6; /* Blue-500 */
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #2563eb; /* Blue-600 */
            transform: translateY(-1px);
        }
        .btn-secondary {
            background-color: #e5e7eb; /* Gray-200 */
            color: #374151;
        }
        .btn-secondary:hover {
            background-color: #d1d5db; /* Gray-300 */
            transform: translateY(-1px);
        }
        .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 1rem;
        }
        .checkbox-input {
            appearance: none;
            width: 1.5rem;
            height: 1.5rem;
            border: 2px solid #d1d5db;
            border-radius: 0.375rem;
            margin-right: 0.75rem;
            cursor: pointer;
            position: relative;
            flex-shrink: 0; /* Prevent it from shrinking */
        }
        .checkbox-input:checked {
            background-color: #3b82f6; /* Blue-500 */
            border-color: #3b82f6;
        }
        .checkbox-input:checked::before {
            content: '✓';
            display: block;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 1rem;
            line-height: 1;
        }
        /* Mobile adjustments */
        @media (max-width: 768px) {
            .card {
                padding: 1.5rem;
            }
            .card h2 {
                font-size: 2rem;
            }
            .grid-cols-2 {
                grid-template-columns: 1fr;
            }
            .btn {
                width: 100%;
                margin-top: 0.75rem;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-gradient-to-r from-blue-600 to-blue-500 p-4 shadow-lg">
        <div class="container flex justify-between items-center">
            <h1 class="text-white text-3xl font-bold tracking-wide">Executive Dashboard</h1>
            <div class="flex items-center space-x-6">
                <span class="text-white text-lg font-medium">Hello, <?php echo htmlspecialchars($username); ?>!</span>
                <a href="dashboard.php" class="bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Dashboard
                </a>
                <a href="logout.php" class="bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-10">
        <h2 class="text-4xl font-extrabold text-gray-800 mb-8 text-center">User Settings</h2>
        <!-- PHP $message output removed; SweetAlert2 will handle display -->

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Business Information Card -->
            <div class="card">
                <h3 class="text-2xl font-bold text-gray-800 mb-6">Update Business Information</h3>
                <form action="user_settings.php" method="POST" class="space-y-6">
                    <div>
                        <label for="business_name" class="block text-sm font-medium text-gray-700 mb-2">Business Name:</label>
                        <input type="text" id="business_name" name="business_name" required
                               value="<?php echo htmlspecialchars($business_name); ?>"
                               class="form-input">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-medium text-gray-700 mb-2">Currency Symbol:</label>
                        <select id="currency" name="currency" class="form-select">
                            <option value="$" <?php echo ($currency == '$') ? 'selected' : ''; ?>>$ (USD)</option>
                            <option value="€" <?php echo ($currency == '€') ? 'selected' : ''; ?>>€ (EUR)</option>
                            <option value="£" <?php echo ($currency == '£') ? 'selected' : ''; ?>>£ (GBP)</option>
                            <option value="¥" <?php echo ($currency == '¥') ? 'selected' : ''; ?>>¥ (JPY)</option>
                            <option value="₱" <?php echo ($currency == '₱') ? 'selected' : ''; ?>>₱ (PHP)</option>
                            <!-- Add more currencies as needed -->
                        </select>
                    </div>
                    <button type="submit" name="update_business_info" class="btn btn-primary w-full">
                        Update Business Info
                    </button>
                </form>
            </div>

            <!-- Monthly Targets Card -->
            <div class="card">
                <h3 class="text-2xl font-bold text-gray-800 mb-6">Set Monthly Targets</h3>
                <form action="user_settings.php" method="POST" class="space-y-6">
                    <div>
                        <label for="monthly_income_target" class="block text-sm font-medium text-gray-700 mb-2">Monthly Income Target (<?php echo htmlspecialchars($currency); ?>):</label>
                        <input type="number" id="monthly_income_target" name="monthly_income_target" step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($monthly_income_target); ?>"
                               class="form-input">
                    </div>
                    <div>
                        <label for="monthly_expense_target" class="block text-sm font-medium text-gray-700 mb-2">Monthly Expense Target (<?php echo htmlspecialchars($currency); ?>):</label>
                        <input type="number" id="monthly_expense_target" name="monthly_expense_target" step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($monthly_expense_target); ?>"
                               class="form-input">
                    </div>
                    <button type="submit" name="update_targets" class="btn btn-primary w-full">
                        Update Targets
                    </button>
                </form>
            </div>

            <!-- Dashboard Preferences Card (NEW) -->
            <div class="card lg:col-span-2"> <!-- Spans two columns on larger screens -->
                <h3 class="text-2xl font-bold text-gray-800 mb-6">Dashboard Preferences</h3>
                <form action="user_settings.php" method="POST">
                    <div class="checkbox-container">
                        <input type="checkbox" id="show_inventory_overview" name="show_inventory_overview"
                               class="checkbox-input" <?php echo ($show_inventory_overview == 1) ? 'checked' : ''; ?>>
                        <label for="show_inventory_overview" class="text-lg text-gray-700 cursor-pointer">Show Inventory Overview on Dashboard</label>
                    </div>
                    <button type="submit" name="update_dashboard_preferences" class="btn btn-primary mt-6">
                        Save Preferences
                    </button>
                </form>
            </div>
        </div>
    </main>

    <footer class="bg-gray-900 text-white p-6 text-center mt-auto shadow-inner">
        <p class="text-lg">&copy; <?php echo date('Y'); ?> Executive Dashboard. All rights reserved.</p>
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
