<?php
session_start();

// Database configuration
require_once("connections.php");

date_default_timezone_set('Asia/Manila');

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from GET parameters (e.g., from redirects)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); // Assuming login.php doesn't use SweetAlert2 directly for this redirect
    exit();
}

$user_id = $_SESSION['user_id'];
// $message = ''; // This variable is no longer directly used for displaying messages on the page.
$currency = $_SESSION['currency'] ?? '$'; // Get currency from session, default to '$'

// Initialize variables to prevent "Undefined variable" warnings on initial page load
$amount = '';
$description = '';
$show_confirmation = false; // Initialize to false

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // For critical errors like DB connection, you might want to log this
    // and show a generic error page, or use SweetAlert2 if the page can render.
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // If connection fails, no further DB operations are possible, so exit.
    // In a real application, you might redirect to a dedicated error page.
    // For now, we'll let the page render with the Swal error.
} else {
    // Process form submission if DB connection is successful
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = trim($_POST['amount'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // If 'confirm_submission' is set, it means the user has confirmed the data
        if (isset($_POST['confirm_submission']) && $_POST['confirm_submission'] === 'true') {
            // Basic validation after confirmation
            if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
                $swal_message = 'Please enter a valid positive amount.';
                $swal_type = 'error';
            } else {
                // Get current timestamp for expense_date (fool-proof date)
                $expense_date = date('Y-m-d H:i:s'); // Changed to include time for more precision

                // Prepare an insert statement
                $sql = "INSERT INTO expenses (user_id, amount, description, expense_date) VALUES (?, ?, ?, ?)";

                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param('idss', $user_id, $amount, $description, $expense_date);
                    if ($stmt->execute()) {
                        // Redirect to dashboard with success message
                        header('Location: dashboard.php?swal_message=' . urlencode('Expense recorded successfully!') . '&swal_type=success');
                        exit();
                    } else {
                        $swal_message = 'Error recording expense: ' . $stmt->error;
                        $swal_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $swal_message = 'Error preparing statement: ' . $conn->error;
                    $swal_type = 'error';
                }
            }
        } else {
            // Initial submission (before confirmation), show summary for confirmation
            if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
                $swal_message = 'Please enter a valid positive amount.';
                $swal_type = 'error';
            } else {
                // Display summary and confirmation buttons
                $show_confirmation = true;
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
    <title>Record Expense - Executive Dashboard</title>
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
            justify-content: center;
            align-items: center;
            background-image: linear-gradient(to right bottom, #fee2e2, #fecaca); /* Reddish gradient */
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
            flex-grow: 1;
            width: 100%;
        }
        .form-input {
            transition: all 0.2s ease-in-out;
        }
        .form-input:focus {
            border-color: #ef4444; /* Red ring */
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.25); /* Red shadow */
        }
        /* Mobile adjustments */
        @media (max-width: 768px) {
            .input-card {
                padding: 1.5rem;
            }
            .input-card h2 {
                font-size: 1.75rem;
            }
            .dashboard-link {
                padding: 0.75rem 1.5rem;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="input-card bg-white p-8 rounded-xl shadow-2xl w-full max-w-md transform transition duration-300 ease-in-out hover:scale-105">
        <h2 class="text-3xl font-bold text-center text-gray-800 mb-6">Record New Expense</h2>
        <!-- PHP $message output removed; SweetAlert2 will handle display -->

        <?php if (isset($show_confirmation) && $show_confirmation): ?>
            <div class="bg-blue-50 p-6 rounded-lg mb-6 text-center shadow-inner">
                <p class="text-lg text-blue-800 mb-4">Please confirm your expense entry:</p>
                <p class="text-xl font-bold text-red-700 mb-2">Amount: <?php echo htmlspecialchars($currency); ?><?php echo number_format($amount, 2); ?></p>
                <p class="text-md text-gray-700">Description: <?php echo htmlspecialchars($description ?: 'N/A'); ?></p>
            </div>
            <form action="expense_input.php" method="POST" class="space-y-4">
                <input type="hidden" name="amount" value="<?php echo htmlspecialchars($amount); ?>">
                <input type="hidden" name="description" value="<?php echo htmlspecialchars($description); ?>">
                <input type="hidden" name="confirm_submission" value="true">
                <button type="submit"
                        class="w-full bg-red-600 text-white py-3 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-75 font-semibold transition duration-200 shadow-lg transform hover:scale-105">
                    Confirm & Record Expense
                </button>
                <button type="button" onclick="window.location.href='expense_input.php'"
                        class="w-full bg-gray-300 text-gray-800 py-3 rounded-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-opacity-75 font-semibold transition duration-200 shadow-md">
                    Go Back & Edit
                </button>
            </form>
        <?php else: ?>
            <form action="expense_input.php" method="POST" class="space-y-6">
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount (<?php echo htmlspecialchars($currency); ?>):</label>
                    <input type="number" id="amount" name="amount" step="0.01" required
                           value="<?php echo htmlspecialchars($amount); ?>"
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional):</label>
                    <input type="text" id="description" name="description"
                           value="<?php echo htmlspecialchars($description); ?>"
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none transition duration-200 shadow-sm">
                </div>
                <!-- Removed the manual date input field -->
                <button type="submit"
                        class="w-full bg-red-600 text-white py-3 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-75 font-semibold transition duration-200 shadow-lg transform hover:scale-105">
                    Preview & Confirm
                </button>
            </form>
        <?php endif; ?>
        <p class="mt-6 text-center text-gray-600 text-sm">
            <a href="dashboard.php" class="dashboard-link text-blue-600 hover:text-blue-800 font-medium transition duration-200">
                Back to Dashboard
            </a>
        </p>
    </div>

    <script>
        // SweetAlert2 integration - this runs after the DOM is fully loaded
        window.onload = function() {
            // Check for PHP-generated SweetAlert2 messages
            const phpSwalMessage = "<?php echo $swal_message; ?>";
            const phpSwalType = "<?php echo $swal_type; ?>";

            if (phpSwalMessage) {
                Swal.fire({
                    icon: phpSwalType,
                    title: phpSwalType.charAt(0).toUpperCase() + phpSwalType.slice(1), // Capitalize first letter
                    text: phpSwalMessage,
                    confirmButtonText: 'Okay',
                    customClass: {
                        confirmButton: 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg', // Red button for expense context
                    },
                    buttonsStyling: false
                }).then(() => {
                    // Remove the swal_message and swal_type from the URL after the alert is closed
                    // This prevents the alert from reappearing if the user refreshes the page
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
    </script>
</body>
</html>
