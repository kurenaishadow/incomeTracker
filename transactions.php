<?php
session_start();

// Database configuration
require_once("connections.php");

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
    header('Location: login.php'); // Assuming login.php handles its own messages
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$currency = $_SESSION['currency'] ?? '$'; // Get currency from session, default to '$'

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // For a critical DB connection error, we usually wouldn't proceed.
    // However, to display the Swal, we'll continue rendering the page, but without data.
    // In a production app, you might redirect to a static error page here.
    $transactions = []; // Ensure transactions array is empty if DB fails
} else {
    // Fetch user's currency (only if connection is successful)
    $sql_fetch_currency = "SELECT currency FROM users WHERE id = ?";
    if ($stmt_fetch_currency = $conn->prepare($sql_fetch_currency)) {
        $stmt_fetch_currency->bind_param('i', $user_id);
        if ($stmt_fetch_currency->execute()) {
            $stmt_fetch_currency->bind_result($fetched_currency);
            if ($stmt_fetch_currency->fetch()) {
                $currency = $fetched_currency ?: '$';
                $_SESSION['currency'] = $currency; // Update session currency
            }
        } else {
            $swal_message = 'Error fetching user currency: ' . $stmt_fetch_currency->error;
            $swal_type = 'error';
        }
        $stmt_fetch_currency->close();
    } else {
        $swal_message = 'Error preparing currency statement: ' . $conn->error;
        $swal_type = 'error';
    }

    // Initialize variables for filtering and sorting
    $filter_type = $_GET['type'] ?? 'all'; // 'all', 'income', 'expense'
    $sort_by = $_GET['sort_by'] ?? 'date'; // 'date', 'amount'
    $sort_order = $_GET['sort_order'] ?? 'desc'; // 'asc', 'desc'
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $transactions = [];
    $sql_transactions = "";
    $params = [];
    $types = "";

    // Base query for both income and expenses - NOW USING 'created_at' for timestamp
    $sql_base_income = "SELECT 'income' as type, id, amount, description, created_at as date FROM income WHERE user_id = ?";
    $sql_base_expense = "SELECT 'expense' as type, id, amount, description, created_at as date FROM expenses WHERE user_id = ?";

    // Build the WHERE clause for date range
    $date_filter_clause = "";
    if (!empty($start_date) && !empty($end_date)) {
        // For DATETIME columns, filtering by DATE only will still work fine, but you could extend to DATETIME if needed.
        $date_filter_clause = " AND DATE(date) BETWEEN ? AND ?";
    } elseif (!empty($start_date)) {
        $date_filter_clause = " AND DATE(date) >= ?";
    } elseif (!empty($end_date)) {
        $date_filter_clause = " AND DATE(date) <= ?";
    }

    // Combine queries based on filter_type
    if ($filter_type === 'income') {
        $sql_transactions = $sql_base_income . $date_filter_clause;
        $types = "i"; // For user_id
        $params[] = $user_id;
    } elseif ($filter_type === 'expense') {
        $sql_transactions = $sql_base_expense . $date_filter_clause;
        $types = "i"; // For user_id
        $params[] = $user_id;
    } else { // 'all'
        $sql_transactions = "(" . $sql_base_income . $date_filter_clause . ") UNION ALL (" . $sql_base_expense . $date_filter_clause . ")";
        $types = "i"; // For user_id in income
        $params[] = $user_id;
        // Add date parameters for the first part of the union if they exist
        if (!empty($start_date)) { $types .= "s"; $params[] = $start_date; }
        if (!empty($end_date)) { $types .= "s"; $params[] = $end_date; }

        $types .= "i"; // For user_id in expense
        $params[] = $user_id;
    }

    // Add date parameters for the second part of the union (if 'all' and they exist)
    if ($filter_type === 'all') {
        if (!empty($start_date)) { $types .= "s"; $params[] = $start_date; }
        if (!empty($end_date)) { $types .= "s"; $params[] = $end_date; }
    } else {
        // If not 'all', but one of the specific types, date params are only added once
        if (!empty($start_date) && empty($end_date)) { $types .= "s"; $params[] = $start_date; } // For start_date only
        if (empty($start_date) && !empty($end_date)) { $types .= "s"; $params[] = $end_date; } // For end_date only
        if (!empty($start_date) && !empty($end_date)) { $types .= "ss"; $params[] = $start_date; $params[] = $end_date; } // For both
    }


    // Add ORDER BY clause
    $order_clause = "";
    if ($sort_by === 'amount') {
        $order_clause = " ORDER BY amount " . ($sort_order === 'asc' ? 'ASC' : 'DESC') . ", date DESC";
    } else { // default to 'date'
        $order_clause = " ORDER BY date " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
    }

    $sql_transactions .= $order_clause;

    // Prepare and execute the statement
    if ($stmt_transactions = $conn->prepare($sql_transactions)) {
        if (!empty($params)) {
            $stmt_transactions->bind_param($types, ...$params);
        }
        
        if ($stmt_transactions->execute()) {
            $result_transactions = $stmt_transactions->get_result();
            while ($row = $result_transactions->fetch_assoc()) {
                $transactions[] = $row;
            }
        } else {
            $swal_message = "Error executing statement: " . $stmt_transactions->error;
            $swal_type = 'error';
        }
        $stmt_transactions->close();
    } else {
        $swal_message = "Error preparing statement: " . $conn->error;
        $swal_type = 'error';
    }

    // Close connection
    $conn->close();
} // End of else (DB connection successful)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
    <meta name="viewport" content="width=1100">
    <title>Transaction Log - Executive Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #e2e8f0; /* Light blue-gray for a modern feel */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: linear-gradient(to right bottom, #e0f2fe, #bfdbfe); /* Subtle gradient */
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
            flex-grow: 1;
        }
        .nav-link:hover {
            transform: scale(1.05);
            color: #dbeafe; /* Lighter blue on hover */
        }
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%236B7280'%3e%3cpath d='M7 7l3-3 3 3m0 6l-3 3-3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        /* Mobile adjustments */
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
                padding: 0.75rem 0; /* Adjust padding for full width buttons */
            }
            .filter-form {
                flex-direction: column;
                gap: 1rem;
            }
            .filter-form > div {
                width: 100%;
            }
            table {
                font-size: 0.875rem; /* Smaller text for tables on mobile */
            }
            th, td {
                padding: 0.5rem; /* Smaller padding */
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-gradient-to-r from-blue-700 to-blue-500 p-4 shadow-lg">
        <div class="container flex justify-between items-center nav-header">
            <h1 class="text-white text-3xl font-bold tracking-wide">Executive Dashboard</h1>
            <div class="flex items-center space-x-6 nav-user-controls">
                <span class="text-white text-lg font-medium">Welcome, <?php echo htmlspecialchars($username); ?>!</span>
                <a href="dashboard.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Dashboard
                </a>
                <a href="logout.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-10">
        <h2 class="text-4xl font-extrabold text-gray-800 mb-8 text-center">Transaction Log</h2>

        <!-- Filtering and Sorting Form -->
        <div class="bg-white p-6 rounded-xl shadow-lg mb-8">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">Filter & Sort Transactions</h3>
            <form action="transactions.php" method="GET" class="flex flex-wrap items-end gap-4 filter-form">
                <div>
                    <label for="filter_type" class="block text-sm font-medium text-gray-700 mb-1">Type:</label>
                    <select id="filter_type" name="type" class="form-select w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
                        <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="income" <?php echo ($filter_type == 'income') ? 'selected' : ''; ?>>Income</option>
                        <option value="expense" <?php echo ($filter_type == 'expense') ? 'selected' : ''; ?>>Expense</option>
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date:</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($start_date); ?>"
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date:</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($end_date); ?>"
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
                </div>
                <div>
                    <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-1">Sort By:</label>
                    <select id="sort_by" name="sort_by" class="form-select w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
                        <option value="date" <?php echo ($sort_by == 'date') ? 'selected' : ''; ?>>Date & Time</option>
                        <option value="amount" <?php echo ($sort_by == 'amount') ? 'selected' : ''; ?>>Amount</option>
                    </select>
                </div>
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">Order:</label>
                    <select id="sort_order" name="sort_order" class="form-select w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200">
                        <option value="desc" <?php echo ($sort_order == 'desc') ? 'selected' : ''; ?>>Descending</option>
                        <option value="asc" <?php echo ($sort_order == 'asc') ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-75 font-semibold transition duration-200">
                    Apply Filters
                </button>
                <a href="transactions.php" class="bg-gray-300 text-gray-800 px-6 py-2 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-opacity-75 font-semibold transition duration-200">
                    Reset
                </a>
            </form>
        </div>


        <div class="bg-white p-6 rounded-xl shadow-lg">
            <?php if (empty($transactions)): ?>
                <p class="text-center text-gray-600 text-lg">No transactions found for the selected criteria.</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('Y-m-d H:i', strtotime(htmlspecialchars($transaction['date']))); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo ($transaction['type'] === 'income') ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo ucfirst(htmlspecialchars($transaction['type'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold <?php echo ($transaction['type'] === 'income') ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo htmlspecialchars($currency); ?><?php echo number_format($transaction['amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="bg-gray-900 text-white p-6 text-center mt-auto shadow-inner">
        <p class="text-lg">&copy; <?php echo date('Y'); ?> Executive Dashboard. All rights reserved.</p>
    </footer>

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
                        confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg',
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
