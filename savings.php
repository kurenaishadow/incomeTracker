<?php
session_start();
require_once("connections.php");

// Set the default timezone
date_default_timezone_set('Asia/Manila');

// Check if the database connection object exists
if (!isset($conn) || $conn->connect_error) {
    // Attempt to create a new connection if it doesn't exist
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("Database connection failed. Please check your connections.php file. Error: " . $conn->connect_error);
    }
}

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = '';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$business_name = $_SESSION['business_name'] ?? 'Dashboard'; // Fetch from session

// Handle all form submissions and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_goal':
                $goal_name = $_POST['goal_name'];
                $target_amount = floatval($_POST['target_amount']);
                $current_amount = 0.00;

                if ($target_amount <= 0) {
                    $swal_message = "Target amount must be a positive number.";
                    $swal_type = "error";
                } else {
                    $stmt = $conn->prepare("INSERT INTO savings_goals (user_id, goal_name, target_amount, current_amount) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isdd", $user_id, $goal_name, $target_amount, $current_amount);
                    if ($stmt->execute()) {
                        $swal_message = "New savings goal added successfully!";
                        $swal_type = "success";
                    } else {
                        $swal_message = "Error: " . $stmt->error;
                        $swal_type = "error";
                    }
                    $stmt->close();
                }
                break;

            case 'deposit':
                $goal_id = intval($_POST['goal_id']);
                $deposit_amount = floatval($_POST['deposit_amount']);
                
                // Get goal name for description
                $goal_name_query = $conn->query("SELECT goal_name FROM savings_goals WHERE id = $goal_id AND user_id = $user_id");
                $goal_name_row = $goal_name_query->fetch_assoc();
                $goal_name = $goal_name_row ? $goal_name_row['goal_name'] : 'Unknown Goal';

                // Start transaction to ensure data integrity
                $conn->begin_transaction();

                try {
                    // Correctly calculate current Main Wallet balance before the deposit
                    $income_sum_query = $conn->query("SELECT SUM(amount) as total_income FROM income WHERE user_id = $user_id");
                    $expenses_sum_query = $conn->query("SELECT SUM(amount) as total_expenses FROM expenses WHERE user_id = $user_id");
                    $total_income = $income_sum_query->fetch_assoc()['total_income'] ?? 0;
                    $total_expenses = $expenses_sum_query->fetch_assoc()['total_expenses'] ?? 0;
                    $main_wallet_balance = $total_income - $total_expenses;

                    if ($main_wallet_balance < $deposit_amount) {
                        $swal_message = "Insufficient funds in Main Wallet. Available: ₱ " . number_format($main_wallet_balance, 2);
                        $swal_type = "error";
                        $conn->rollback();
                        break;
                    }

                    // Update the savings goal
                    $stmt = $conn->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("dii", $deposit_amount, $goal_id, $user_id);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        // Record the deposit as an expense
                        $expense_stmt = $conn->prepare("INSERT INTO expenses (user_id, amount, description, expense_date) VALUES (?, ?, ?, CURDATE())");
                        $description = "Deposit to savings goal: " . $goal_name;
                        $expense_stmt->bind_param("ids", $user_id, $deposit_amount, $description);
                        $expense_stmt->execute();
                        $expense_stmt->close();

                        // Add a log entry for the savings deposit
                        $log_stmt = $conn->prepare("INSERT INTO savings_logs (user_id, goal_id, log_type, amount, description) VALUES (?, ?, ?, ?, ?)");
                        $log_type = 'Deposit';
                        $log_description = "Deposited PHP " . number_format($deposit_amount, 2) . " to '{$goal_name}'";
                        $log_stmt->bind_param("iisds", $user_id, $goal_id, $log_type, $deposit_amount, $log_description);
                        $log_stmt->execute();
                        $log_stmt->close();

                        $swal_message = "Successfully deposited to savings goal!";
                        $swal_type = "success";
                        $conn->commit();
                    } else {
                        $swal_message = "Error: Could not deposit. Goal not found or no change.";
                        $swal_type = "error";
                        $conn->rollback();
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $swal_message = "Transaction failed: " . $e->getMessage();
                    $swal_type = "error";
                }
                break;

            case 'delete_goal':
                $goal_id = intval($_POST['goal_id']);
                
                // Start transaction
                $conn->begin_transaction();

                try {
                    // Get the amount and name from the goal before deleting
                    $goal_query = $conn->prepare("SELECT current_amount, goal_name FROM savings_goals WHERE id = ? AND user_id = ?");
                    $goal_query->bind_param("ii", $goal_id, $user_id);
                    $goal_query->execute();
                    $result = $goal_query->get_result();
                    $goal_data = $result->fetch_assoc();
                    $amount_to_return = $goal_data['current_amount'];
                    $goal_name = $goal_data['goal_name'];
                    $goal_query->close();

                    // Delete the savings goal
                    $delete_stmt = $conn->prepare("DELETE FROM savings_goals WHERE id = ? AND user_id = ?");
                    $delete_stmt->bind_param("ii", $goal_id, $user_id);
                    $delete_stmt->execute();

                    if ($delete_stmt->affected_rows > 0) {
                        // Return the amount to the main wallet by creating an income entry
                        if ($amount_to_return > 0) {
                           $return_stmt = $conn->prepare("INSERT INTO income (user_id, amount, description, income_date) VALUES (?, ?, ?, CURDATE())");
                            $description = "Amount returned from deleted savings goal: " . $goal_name;
                            $return_stmt->bind_param("ids", $user_id, $amount_to_return, $description);
                            $return_stmt->execute();
                            $return_stmt->close();
                        }

                        // Add a log entry for the returned amount
                        $log_stmt = $conn->prepare("INSERT INTO savings_logs (user_id, log_type, amount, description) VALUES (?, ?, ?, ?)");
                        $log_type = 'Return';
                        $log_description = "Amount returned (PHP " . number_format($amount_to_return, 2) . ") from deleted goal: '{$goal_name}'";
                        $log_stmt->bind_param("isds", $user_id, $log_type, $amount_to_return, $log_description);
                        $log_stmt->execute();
                        $log_stmt->close();

                        $swal_message = "Savings goal deleted and amount returned to Main Wallet.";
                        $swal_type = "success";
                        $conn->commit();
                    } else {
                        $swal_message = "Error: Could not delete savings goal.";
                        $swal_type = "error";
                        $conn->rollback();
                    }
                    $delete_stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $swal_message = "Transaction failed: " . $e->getMessage();
                    $swal_type = "error";
                }
                break;
        }
    }
}

// Re-calculate all balances after all POST actions are finished
$income_sum_query = $conn->query("SELECT SUM(amount) as total_income FROM income WHERE user_id = $user_id");
$expenses_sum_query = $conn->query("SELECT SUM(amount) as total_expenses FROM expenses WHERE user_id = $user_id");
$savings_sum_query = $conn->query("SELECT SUM(current_amount) as total_savings FROM savings_goals WHERE user_id = $user_id");
$total_income = $income_sum_query->fetch_assoc()['total_income'] ?? 0;
$total_expenses = $expenses_sum_query->fetch_assoc()['total_expenses'] ?? 0;
$total_savings = $savings_sum_query->fetch_assoc()['total_savings'] ?? 0;
$main_wallet_balance = $total_income - $total_expenses;
$total_account_balance = $main_wallet_balance; // Main wallet balance already reflects the total profit after savings deposits are expensed.

// Fetch all savings goals for the current user for display
$goals_result = $conn->query("SELECT * FROM savings_goals WHERE user_id = $user_id ORDER BY id DESC");

// --- Start of Pagination Logic ---
$records_per_page = 25;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Count total records for pagination
$count_query = $conn->query("SELECT COUNT(*) as total FROM savings_logs WHERE user_id = $user_id");
$total_records = $count_query->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch savings logs with pagination
$logs_result = $conn->query("SELECT * FROM savings_logs WHERE user_id = $user_id ORDER BY log_date DESC LIMIT $offset, $records_per_page");
// --- End of Pagination Logic ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-500: #2563eb;
            --primary-600: #1d4ed8;
            --surface-100: #f3f4f6;
            --surface-900: #111827;
            --card-background: #ffffff;
        }
        
        body {
            background-color: var(--surface-100);
            font-family: 'Inter', sans-serif;
        }
        
        .container {
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
            padding: 1rem; /* Add padding for spacing on all screen sizes */
        }

        .card {
            background-color: var(--card-background);
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .btn-primary {
            background-color: var(--primary-500);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background-color 0.2s, transform 0.2s;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-600);
            transform: translateY(-1px);
        }

        .progress-bar {
            height: 8px;
            border-radius: 9999px;
            background-color: #e5e7eb;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease-in-out;
        }

        /* --- NEW SIDEBAR STYLES --- */
        #sidebar {
            transition: transform 0.3s ease-in-out;
        }
        #sidebar.sidebar-closed {
            transform: translateX(-100%);
        }
        #sidebar.sidebar-open {
            transform: translateX(0);
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">

    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></div>
    <aside id="sidebar" class="sidebar-closed fixed top-0 left-0 h-full w-64 bg-gray-800 text-white p-5 z-50 flex flex-col">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold">Menu</h2>
            <button id="close-sidebar-btn" class="text-3xl leading-none text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-400">Welcome,</p>
            <p class="font-semibold text-lg"><?php echo htmlspecialchars($username); ?></p>
        </div>

        <nav class="flex-grow">
            <h3 class="text-gray-400 text-sm font-semibold uppercase mt-4 mb-2">Dashboard</h3> 
            <a href="dashboard.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Dashboard</a>
            <a href="transactions.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Transactions</a>

            <h3 class="text-gray-400 text-sm font-semibold uppercase mt-4 mb-2">Management</h3> 
            <a href="income_input.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Add Income</a>
            <a href="expense_input.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Add Expense</a>
            <a href="scheduled_expenses.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Scheduled Expenses</a>
            <a href="inventory_manage.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Inventory</a>
            <a href="savings.php" class="block py-2.5 px-4 rounded transition duration-200 bg-gray-700 font-semibold">Savings</a>
         
            <h3 class="text-gray-400 text-sm font-semibold uppercase mt-4 mb-2">Settings</h3>
            <a href="user_settings.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">User Settings</a>
        </nav>

        <div class="mt-auto">
             <a href="logout.php" class="block w-full text-center bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 font-semibold transition duration-200">
                Logout
            </a>
        </div>
    </aside>
    <header class="bg-gradient-to-r from-blue-600 to-blue-500 p-4 shadow-lg text-white sticky top-0 z-30">
        <div class="container flex justify-between items-center mx-auto">
            <button id="open-sidebar-btn" class="p-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <div class="text-lg font-bold">
                <?php echo htmlspecialchars($business_name); ?>
            </div>
            <div></div>
        </div>
    </header>
    <main class="container py-8">
        <div class="space-y-8">

            <div class="flex justify-between items-center">
                <h3 class="text-3xl font-bold text-gray-800">Savings Details</h3>
                
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6 text-center">
                    <h2 class="text-xl md:text-2xl font-semibold text-gray-800">Main Wallet (Cash on Hand)</h2>
                    <p class="text-4xl md:text-5xl font-bold text-blue-600 mt-2">
                        <?php echo htmlspecialchars('₱ ' . number_format($main_wallet_balance, 2)); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">Total cash available for deposits.</p>
                </div>
                <div class="card p-6 text-center">
                    <h2 class="text-xl md:text-2xl font-semibold text-gray-800">Total Savings</h2>
                    <p class="text-4xl md:text-5xl font-bold text-green-600 mt-2">
                        <?php echo htmlspecialchars('₱ ' . number_format($total_savings, 2)); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">Sum of all savings goals.</p>
                </div>
            </div>

            <div class="flex justify-center mt-8">
                <button onclick="showAddGoalModal()" class="btn-primary flex items-center space-x-2 shadow-lg">
                    <span>+ Add New Savings Goal</span>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-8">
                <?php if ($goals_result->num_rows > 0) : ?>
                    <?php while ($goal = $goals_result->fetch_assoc()) : ?>
                        <div class="card p-6 flex flex-col justify-between space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($goal['goal_name']); ?></h3>
                                <button onclick="confirmDelete(<?php echo $goal['id']; ?>, '<?php echo htmlspecialchars(addslashes($goal['goal_name'])); ?>')" class="text-red-500 hover:text-red-700 transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                            
                            <?php
                                $progress = ($goal['target_amount'] > 0) ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
                                $progress = min(100, $progress); // Cap at 100%
                                $progress_color = $progress >= 100 ? 'bg-green-500' : 'bg-blue-500';
                            ?>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $progress_color; ?>" style="width: <?php echo $progress; ?>%;"></div>
                            </div>

                            <div class="flex justify-between text-sm text-gray-600 font-medium">
                                <span>Current: <span class="text-gray-800 font-bold">₱ <?php echo number_format($goal['current_amount'], 2); ?></span></span>
                                <span>Target: <span class="text-gray-800 font-bold">₱ <?php echo number_format($goal['target_amount'], 2); ?></span></span>
                            </div>

                            <button onclick="showDepositModal(<?php echo $goal['id']; ?>, '<?php echo htmlspecialchars(addslashes($goal['goal_name'])); ?>')" class="btn-primary w-full mt-2">
                                Deposit
                            </button>
                        </div>
                    <?php endwhile; ?>
                <?php else : ?>
                    <div class="col-span-1 md:col-span-2 lg:col-span-3 card p-6 text-center text-gray-500">
                        <p>No savings goals created yet. Click 'Add New Savings Goal' to get started!</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <hr class="my-8 border-gray-200">

            <div class="space-y-4 mt-8">
                <h2 class="text-2xl font-bold text-gray-800">Savings Transaction History</h2>
                <div class="card p-6">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-gray-600">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                <tr>
                                    <th scope="col" class="py-3 px-6">Date</th>
                                    <th scope="col" class="py-3 px-6">Type</th>
                                    <th scope="col" class="py-3 px-6 text-right">Amount</th>
                                    <th scope="col" class="py-3 px-6">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($logs_result->num_rows > 0) : ?>
                                    <?php while ($log = $logs_result->fetch_assoc()) : ?>
                                        <tr class="bg-white border-b hover:bg-gray-50">
                                            <td class="py-4 px-6 font-medium text-gray-900 whitespace-nowrap">
                                                <?php echo date('M d, Y h:i A', strtotime($log['log_date'])); ?>
                                            </td>
                                            <td class="py-4 px-6">
                                                <?php
                                                    $type_color = ($log['log_type'] === 'Deposit') ? 'text-blue-500' : 'text-green-500';
                                                    echo '<span class="' . $type_color . ' font-bold">' . htmlspecialchars($log['log_type']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="py-4 px-6 text-right font-semibold">
                                                ₱ <?php echo number_format($log['amount'], 2); ?>
                                            </td>
                                            <td class="py-4 px-6 max-w-xs truncate" title="<?php echo htmlspecialchars($log['description']); ?>">
                                                <?php echo htmlspecialchars($log['description']); ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else : ?>
                                    <tr class="bg-white">
                                        <td colspan="4" class="py-4 px-6 text-center text-gray-500">No transactions recorded yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="flex justify-center items-center pt-6">
                    <ul class="flex items-center space-x-2">
                        <li>
                            <a href="?page=<?php echo max(1, $current_page - 1); ?>" class="flex items-center justify-center h-10 px-4 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 hover:text-gray-700 <?php echo $current_page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                                Prev
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                            <li>
                                <a href="?page=<?php echo $i; ?>" class="flex items-center justify-center h-10 px-4 text-sm font-medium border rounded-lg <?php echo $i === $current_page ? 'text-blue-600 bg-blue-50 border-blue-300' : 'text-gray-500 bg-white border-gray-300 hover:bg-gray-100 hover:text-gray-700'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li>
                            <a href="?page=<?php echo min($total_pages, $current_page + 1); ?>" class="flex items-center justify-center h-10 px-4 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 hover:text-gray-700 <?php echo $current_page >= $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">
                                Next
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function showAddGoalModal() {
            Swal.fire({
                title: 'Add New Savings Goal',
                html: `
                    <form id="addGoalForm" method="POST" action="savings.php" class="text-left">
                        <input type="hidden" name="action" value="add_goal">
                        <label for="swal-goal-name" class="swal2-label">Goal Name</label>
                        <input id="swal-goal-name" type="text" name="goal_name" placeholder="e.g., New Laptop" class="swal2-input" required>
                        <label for="swal-target-amount" class="swal2-label">Target Amount</label>
                        <input id="swal-target-amount" type="number" name="target_amount" placeholder="e.g., 50000.00" class="swal2-input" step="0.01" min="0.01" required>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Goal',
                confirmButtonColor: '#2563eb',
                preConfirm: () => {
                    document.getElementById('addGoalForm').submit();
                }
            });
        }

        function showDepositModal(goalId, goalName) {
            Swal.fire({
                title: `Deposit to ${goalName}`,
                html: `
                    <form id="depositForm" method="POST" action="savings.php" class="text-left">
                        <input type="hidden" name="action" value="deposit">
                        <input type="hidden" name="goal_id" value="${goalId}">
                        <label for="swal-deposit-amount" class="swal2-label">Amount</label>
                        <input id="swal-deposit-amount" type="number" name="deposit_amount" placeholder="Amount to deposit" class="swal2-input" step="0.01" min="0.01" required>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Deposit',
                confirmButtonColor: '#2563eb',
                preConfirm: () => {
                    const amount = parseFloat(document.getElementById('swal-deposit-amount').value);
                    if (isNaN(amount) || amount <= 0) {
                        Swal.showValidationMessage('Please enter a valid amount to deposit.');
                        return false;
                    }
                    document.getElementById('depositForm').submit();
                }
            });
        }

        function confirmDelete(goalId, goalName) {
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete the goal "${goalName}". The current amount will be returned to your Main Wallet. This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'savings.php';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_goal">
                        <input type="hidden" name="goal_id" value="${goalId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Check for PHP-generated SweetAlert2 messages on page load
        window.onload = function() {
            const phpSwalMessage = "<?php echo $swal_message; ?>";
            const phpSwalType = "<?php echo $swal_type; ?>";
            if (phpSwalMessage) {
                Swal.fire({
                    icon: phpSwalType,
                    title: phpSwalType.charAt(0).toUpperCase() + phpSwalType.slice(1),
                    text: phpSwalMessage,
                    confirmButtonText: 'Okay',
                    confirmButtonColor: '#2563eb',
                }).then(() => {
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const openBtn = document.getElementById('open-sidebar-btn');
            const closeBtn = document.getElementById('close-sidebar-btn');

            function openSidebar() {
                sidebar.classList.remove('sidebar-closed');
                sidebar.classList.add('sidebar-open');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                sidebar.classList.remove('sidebar-open');
                sidebar.classList.add('sidebar-closed');
                overlay.classList.add('hidden');
            }

            if (openBtn) {
                openBtn.addEventListener('click', openSidebar);
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', closeSidebar);
            }
            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>