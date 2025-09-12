<?php
session_start();
require_once("connections.php");

// Check if the database connection object exists
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please check your connections.php file.");
}

date_default_timezone_set('Asia/Manila');

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = '';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

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
                $goal_name = $goal_name_query->fetch_assoc()['goal_name'];

                // Start transaction to ensure data integrity
                $conn->begin_transaction();

                try {
                    // Correctly calculate current Main Wallet balance before the deposit
                    // The main wallet balance is simply the total income minus the total expenses.
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
                        $log_description = "Deposited PHP " . number_format($deposit_amount, 0) . " to '{$goal_name}'";
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
                        $return_stmt = $conn->prepare("INSERT INTO income (user_id, amount, description, income_date) VALUES (?, ?, ?, CURDATE())");
                        $description = "Amount returned from deleted savings goal: " . $goal_name;
                        $return_stmt->bind_param("ids", $user_id, $amount_to_return, $description);
                        $return_stmt->execute();
                        $return_stmt->close();

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
$total_account_balance = $main_wallet_balance + $total_savings;

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
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
    <title>Savings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /*
        ** New Styles for a Top-Tier Look **
        
        Colors:
        --primary-500: #2563eb;  (Rich Blue)
        --primary-600: #1d4ed8;  (Darker Blue)
        --secondary-300: #d1d5db; (Light Gray)
        --secondary-500: #6b7280; (Medium Gray)
        --surface-100: #f3f4f6;   (Light Background)
        --surface-200: #e5e7eb;   (Border/Separator)
        --surface-900: #111827;   (Dark Background/Text)
        --card-background: #ffffff;
        
        Shadows & Shapes:
        - Smoother, rounded corners.
        - Subtle box-shadows for elevation.
        */
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
            padding: 0;
        }

        /* This rule applies ONLY when the screen is 768px wide or smaller */
        @media (max-width: 768px) {
            .container {
                /* Set padding to zero on all sides */
                padding: 0;
            }
        }
        
        .card {
            background-color: var(--card-background);
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease-in-out;
        }
        
        .card:hover {
            transform: translateY(-5px);
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
        
        .btn-secondary {
            background-color: transparent;
            color: #dc2626; /* Red text */
            border: 2px solid #dc2626; /* Red border */
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background-color 0.2s, transform 0.2s;
        }

        .btn-secondary:hover {
            background-color: #dc2626;
            color: white;
            transform: translateY(-1px);
        }

        .btn-link {
            color: var(--primary-500);
            transition: color 0.2s;
        }
        
        .btn-link:hover {
            color: var(--primary-600);
            text-decoration: underline;
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
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="min-h-screen flex flex-col items-center py-8">
        <div class="container space-y-8">

            <div class="flex justify-between items-center py-4">
                <a href="dashboard.php" class="text-blue-600 hover:underline transition duration-300">
                    &larr; Back to Dashboard
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card p-6 text-center">
                    <h2 class="text-xl md:text-2xl font-semibold text-gray-800">Cash On Hand</h2>
                    <p class="text-4xl md:text-5xl font-bold text-blue-600 mt-2">
                        <?php echo htmlspecialchars('₱ ' . number_format($main_wallet_balance, 2)); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">Total cash available for deposits.</p>
                </div>
                <div class="card p-6 text-center">
                    <h2 class="text-xl md:text-2xl font-semibold text-gray-800">Total Profit Balance</h2>
                    <p class="text-4xl md:text-5xl font-bold text-red-800 mt-2">
                        <?php echo htmlspecialchars('₱ ' . number_format($total_account_balance, 2)); ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-1">Main wallet + all savings goals.</p>
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
                                <button onclick="confirmDelete(<?php echo $goal['id']; ?>, '<?php echo htmlspecialchars($goal['goal_name']); ?>')" class="text-red-500 hover:text-red-700 transition-colors duration-300">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                            
                            <?php
                                $progress = ($goal['current_amount'] / $goal['target_amount']) * 100;
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

                            <button onclick="showDepositModal(<?php echo $goal['id']; ?>, '<?php echo htmlspecialchars($goal['goal_name']); ?>')" class="btn-primary w-full mt-2">
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
            
            <hr class="my-8">

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
                                                ₱ <?php echo number_format($log['amount'], 0); ?>
                                            </td>
                                            <td class="py-4 px-6 max-w-xs truncate">
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

                <nav class="flex justify-center items-center mt-6">
                    <ul class="flex items-center space-x-2">
                        <li>
                            <a href="?page=<?php echo max(1, $current_page - 1); ?>" class="flex items-center justify-center h-10 px-4 text-sm font-medium text-gray-500 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 hover:text-gray-700 <?php echo $current_page <= 1 ? 'pointer-events-none opacity-50' : ''; ?>">
                                <svg class="w-3.5 h-3.5 mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5H1m0 0 4 4m-4-4 4-4"/>
                                </svg>
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
                                <svg class="w-3.5 h-3.5 ml-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5h12m0 0L9 1m4 4L9 9"/>
                                </svg>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <script>
        // Function to show the "Add New Goal" modal
        function showAddGoalModal() {
            Swal.fire({
                title: 'Add New Savings Goal',
                html: `
                    <form id="addGoalForm" method="POST" action="savings.php">
                        <input type="hidden" name="action" value="add_goal">
                        <input type="text" name="goal_name" placeholder="Goal Name (e.g., New Laptop)" class="swal2-input" required>
                        <input type="number" name="target_amount" placeholder="Target Amount (e.g., 50000.00)" class="swal2-input" step="0.01" min="0.01" required>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Goal',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    document.getElementById('addGoalForm').submit();
                    return false; // Prevent SweetAlert from closing
                },
                customClass: {
                    confirmButton: 'btn-primary',
                    cancelButton: 'btn-secondary'
                },
                buttonsStyling: false
            });
        }

        // Function to show the "Deposit" modal
        function showDepositModal(goalId, goalName) {
            Swal.fire({
                title: `Deposit to ${goalName}`,
                html: `
                    <form id="depositForm" method="POST" action="savings.php">
                        <input type="hidden" name="action" value="deposit">
                        <input type="hidden" name="goal_id" value="${goalId}">
                        <input type="number" name="deposit_amount" placeholder="Amount to deposit" class="swal2-input" step="0.01" min="0.01" required>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Deposit',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const amount = parseFloat(document.querySelector('input[name="deposit_amount"]').value);
                    if (isNaN(amount) || amount <= 0) {
                        Swal.showValidationMessage('Please enter a valid amount to deposit.');
                        return false;
                    }
                    document.getElementById('depositForm').submit();
                    return false; // Prevent SweetAlert from closing
                },
                customClass: {
                    confirmButton: 'btn-primary',
                    cancelButton: 'btn-secondary'
                },
                buttonsStyling: false
            });
        }

        // Function to confirm deletion
        function confirmDelete(goalId, goalName) {
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete the goal "${goalName}". The current amount will be returned to your Main Wallet.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form dynamically and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'savings.php';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_goal';
                    form.appendChild(actionInput);
                    
                    const goalIdInput = document.createElement('input');
                    goalIdInput.type = 'hidden';
                    goalIdInput.name = 'goal_id';
                    goalIdInput.value = goalId;
                    form.appendChild(goalIdInput);
                    
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
                    customClass: {
                        confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                }).then(() => {
                    // Optional: remove query params after alert is dismissed
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
    </script>
</body>
</html>
<?php
$conn->close();
?>