<?php
session_start();
require_once("connections.php");

// Set the default timezone
date_default_timezone_set('Asia/Manila');

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$currency = $_SESSION['currency'] ?? '$'; // Get currency from session, default to '$'

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = '';

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// --- NEW LOGIC: AUTOMATIC BILL REACTIVATION ---
// This block runs every time the page is loaded to check for bills to reactivate.
$current_date = date('Y-m-d');
$sql_reactivate = "SELECT id, due_date, recurrence FROM scheduled_expenses WHERE user_id = ? AND is_paid = 1 AND recurrence <> 'once'";
if ($stmt_reactivate = $conn->prepare($sql_reactivate)) {
    $stmt_reactivate->bind_param("i", $user_id);
    $stmt_reactivate->execute();
    $result_reactivate = $stmt_reactivate->get_result();

    $bills_to_update = [];
    while ($row = $result_reactivate->fetch_assoc()) {
        $next_due_date = new DateTime($row['due_date']);
        $reactivation_date = (clone $next_due_date)->modify('-5 days');
        
        // Check if the current date is on or after the reactivation date
        if (new DateTime($current_date) >= $reactivation_date) {
            $bills_to_update[] = $row['id'];
        }
    }
    $stmt_reactivate->close();

    // Perform the bulk update if there are bills to reactivate
    if (!empty($bills_to_update)) {
        $placeholders = implode(',', array_fill(0, count($bills_to_update), '?'));
        $sql_update_bulk = "UPDATE scheduled_expenses SET is_paid = 0 WHERE id IN ($placeholders) AND user_id = ?";
        if ($stmt_update = $conn->prepare($sql_update_bulk)) {
            $types = str_repeat('i', count($bills_to_update)) . 'i';
            $params = array_merge($bills_to_update, [$user_id]);
            $stmt_update->bind_param($types, ...$params);
            $stmt_update->execute();
            $stmt_update->close();
        }
    }
}
// --- END OF NEW LOGIC ---


// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        $bill_name = trim($_POST['bill_name']);
        $amount = floatval($_POST['amount']);
        $due_date = trim($_POST['due_date']);
        $recurrence = trim($_POST['recurrence']);
        $description = trim($_POST['description']);
        $is_paid = 0; // New scheduled expenses are never paid by default

        if (!empty($bill_name) && $amount > 0 && !empty($due_date)) {
            $sql = "INSERT INTO scheduled_expenses (user_id, bill_name, amount, due_date, recurrence, description, is_paid) VALUES (?, ?, ?, ?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("isdsssi", $user_id, $bill_name, $amount, $due_date, $recurrence, $description, $is_paid);
                if ($stmt->execute()) {
                    $swal_message = "Scheduled expense added successfully!";
                    $swal_type = "success";
                } else {
                    $swal_message = "Error adding expense: " . $stmt->error;
                    $swal_type = "error";
                }
                $stmt->close();
            }
        } else {
            $swal_message = "Please fill in all required fields.";
            $swal_type = "error";
        }
    } elseif (isset($_POST['edit_expense'])) {
        $expense_id = intval($_POST['edit_expense_id']);

        if ($expense_id > 0) {
            // Fetch current data to preserve unchanged values
            $sql_fetch_current = "SELECT bill_name, amount, due_date, recurrence, description FROM scheduled_expenses WHERE id = ? AND user_id = ?";
            if ($stmt_fetch = $conn->prepare($sql_fetch_current)) {
                $stmt_fetch->bind_param("ii", $expense_id, $user_id);
                $stmt_fetch->execute();
                $result = $stmt_fetch->get_result();
                $current_expense = $result->fetch_assoc();
                $stmt_fetch->close();

                if ($current_expense) {
                    // Use new POST values if they exist, otherwise use current values
                    $bill_name = !empty($_POST['bill_name']) ? trim($_POST['bill_name']) : $current_expense['bill_name'];
                    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : $current_expense['amount'];
                    $due_date = !empty($_POST['due_date']) ? trim($_POST['due_date']) : $current_expense['due_date'];
                    $recurrence = !empty($_POST['recurrence']) ? trim($_POST['recurrence']) : $current_expense['recurrence'];
                    $description = trim($_POST['description']);
                    $is_paid = isset($_POST['is_paid']) ? 1 : 0;
                    
                    $sql = "UPDATE scheduled_expenses SET bill_name = ?, amount = ?, due_date = ?, recurrence = ?, description = ?, is_paid = ? WHERE id = ? AND user_id = ?";
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bind_param("sdsissii", $bill_name, $amount, $due_date, $recurrence, $description, $is_paid, $expense_id, $user_id);
                        if ($stmt->execute()) {
                            $swal_message = "Scheduled expense updated successfully!";
                            $swal_type = "success";
                        } else {
                            $swal_message = "Error updating expense: " . $stmt->error;
                            $swal_type = "error";
                        }
                        $stmt->close();
                    }
                } else {
                    $swal_message = "Expense not found.";
                    $swal_type = "error";
                }
            }
        } else {
            $swal_message = "Invalid expense ID.";
            $swal_type = "error";
        }
    } elseif (isset($_POST['delete_expense'])) {
        $expense_id = intval($_POST['delete_expense_id']);

        if ($expense_id > 0) {
            $sql = "DELETE FROM scheduled_expenses WHERE id = ? AND user_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ii", $expense_id, $user_id);
                if ($stmt->execute()) {
                    $swal_message = "Scheduled expense deleted successfully!";
                    $swal_type = "success";
                } else {
                    $swal_message = "Error deleting expense: " . $stmt->error;
                    $swal_type = "error";
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['mark_paid'])) {
        $expense_id = intval($_POST['mark_paid_id']);
        $amount_to_add = floatval($_POST['amount_to_add']);
        $bill_name = trim($_POST['bill_name']);
        $recurrence = trim($_POST['recurrence']);
        $due_date = trim($_POST['due_date']);

        if ($expense_id > 0 && $amount_to_add > 0) {
            // Use a transaction to ensure both operations succeed or fail together
            $conn->begin_transaction();
            try {
                // 1. Add the expense to the regular 'expenses' table
                $sql_add_expense = "INSERT INTO expenses (user_id, amount, expense_date, description) VALUES (?, ?, ?, ?)";
                $current_date = date('Y-m-d');
                $description = "Paid: " . $bill_name;

                if ($stmt_add = $conn->prepare($sql_add_expense)) {
                    $stmt_add->bind_param("idss", $user_id, $amount_to_add, $current_date, $description);
                    $stmt_add->execute();
                    $stmt_add->close();
                } else {
                    throw new Exception("Error preparing add expense statement: " . $conn->error);
                }

                // 2. Mark the scheduled expense as paid and handle recurrence
                if ($recurrence === 'once') {
                    // Delete one-time bills
                    $sql_update_scheduled = "DELETE FROM scheduled_expenses WHERE id = ? AND user_id = ?";
                    if ($stmt_update = $conn->prepare($sql_update_scheduled)) {
                        $stmt_update->bind_param("ii", $expense_id, $user_id);
                        $stmt_update->execute();
                        $stmt_update->close();
                    } else {
                        throw new Exception("Error preparing delete scheduled statement: " . $conn->error);
                    }
                } else {
                    // This is the bug fix: Mark the bill as PAID (is_paid = 1) and update the due date.
                    $next_due_date = '';
                    switch ($recurrence) {
                        case 'weekly':
                            $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 week'));
                            break;
                        case 'monthly':
                            $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
                            break;
                        case 'yearly':
                            $next_due_date = date('Y-m-d', strtotime($due_date . ' +1 year'));
                            break;
                    }
                    // Crucial change: Update the due date here.
                    $sql_update_scheduled = "UPDATE scheduled_expenses SET is_paid = 1, due_date = ? WHERE id = ? AND user_id = ?";
                    if ($stmt_update = $conn->prepare($sql_update_scheduled)) {
                        $stmt_update->bind_param("sii", $next_due_date, $expense_id, $user_id);
                        $stmt_update->execute();
                        $stmt_update->close();
                    } else {
                        throw new Exception("Error preparing update scheduled statement: " . $conn->error);
                    }
                }
                
                $conn->commit();
                $swal_message = "Bill marked as paid and added to your expenses!";
                $swal_type = "success";
            } catch (Exception $e) {
                $conn->rollback();
                $swal_message = "Transaction failed: " . $e->getMessage();
                $swal_type = "error";
            }
        }
    }
}

// Fetch all scheduled expenses for the logged-in user
$scheduled_expenses = [];
$sql_fetch = "SELECT id, bill_name, amount, due_date, recurrence, description, is_paid, created_at FROM scheduled_expenses WHERE user_id = ? ORDER BY due_date ASC";
if ($stmt = $conn->prepare($sql_fetch)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $scheduled_expenses[] = $row;
        }
    } else {
        $swal_message = "Error fetching scheduled expenses: " . $stmt->error;
        $swal_type = "error";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
    <meta name="viewport" content="width=1100">
    <title>Scheduled Expenses - Business Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f9ff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: linear-gradient(to right bottom, #f0f9ff, #e0f7fa);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            flex-grow: 1;
        }
        .nav-link:hover {
            transform: scale(1.05);
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.2);
        }
        .form-input {
            transition: all 0.2s ease-in-out;
        }
        .form-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25);
        }
        .card {
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
            transform: translateY(0);
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08);
        }
        .modal {
            display: none;
        }
        .modal.is-active {
            display: flex;
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
                padding: 0.75rem 0;
            }
            h1.text-3xl {
                font-size: 1.75rem;
            }
            .nav-user-controls span.text-lg,
            .nav-user-controls a.text-lg {
                font-size: 0.9rem;
            }
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .main-header h2 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-gradient-to-r from-blue-600 to-blue-500 p-4 shadow-lg">
        <div class="container flex justify-between items-center nav-header">
            <h1 class="text-white text-3xl font-bold tracking-wide">Scheduled Expenses</h1>
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

    <main class="container py-8 flex-grow">
        <div class="bg-white p-6 rounded-xl shadow-2xl mb-8">
            <div class="flex justify-between items-center mb-6 main-header">
                <h2 class="text-3xl font-extrabold text-gray-800">Your Scheduled Bills</h2>
                <button onclick="openModal('addExpenseModal')"
                        class="bg-green-500 text-white px-6 py-2 rounded-full font-semibold shadow-md hover:bg-green-600 transition duration-300 ease-in-out transform hover:scale-105">
                    + Add New Bill
                </button>
            </div>

            <?php if (empty($scheduled_expenses)): ?>
                <p class="text-center text-gray-600 text-lg py-10">You have no scheduled expenses. Add your first bill above!</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($scheduled_expenses as $expense): ?>
                        <div class="card bg-gray-50 p-6 rounded-xl shadow-lg border-2 border-transparent hover:border-blue-300 relative <?php echo $expense['is_paid'] ? 'opacity-70' : ''; ?>">
                            <?php if ($expense['is_paid']): ?>
                                <span class="absolute top-0 right-0 mt-4 mr-4 bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-full shadow-lg">PAID</span>
                            <?php endif; ?>
                            <h3 class="text-xl font-bold text-gray-800 mb-2">
                                <?php echo htmlspecialchars($expense['bill_name']); ?>
                            </h3>
                            <p class="text-gray-700 text-base mb-1">
                                <span class="font-semibold">Amount:</span> <?php echo htmlspecialchars($currency); ?><?php echo number_format($expense['amount'], 2); ?>
                            </p>
                            <p class="text-gray-700 text-base mb-1">
                                <span class="font-semibold">Due Date:</span> <?php echo date('F j, Y', strtotime(htmlspecialchars($expense['due_date']))); ?>
                            </p>
                            <p class="text-gray-700 text-base mb-4">
                                <span class="font-semibold">Recurrence:</span> <?php echo htmlspecialchars(ucfirst($expense['recurrence'])); ?>
                            </p>
                            <?php if (!empty($expense['description'])): ?>
                                <p class="text-gray-600 text-sm italic border-l-2 border-gray-300 pl-2 mb-4">
                                    <?php echo htmlspecialchars($expense['description']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                                <?php if (!$expense['is_paid']): ?>
                                    <button onclick="markPaid(event, <?php echo $expense['id']; ?>, '<?php echo htmlspecialchars($expense['bill_name']); ?>', <?php echo $expense['amount']; ?>, '<?php echo htmlspecialchars($expense['recurrence']); ?>', '<?php echo htmlspecialchars($expense['due_date']); ?>')"
                                            class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-semibold shadow-lg hover:bg-blue-700 transition duration-200 transform hover:scale-105">
                                        Mark as Paid
                                    </button>
                                <?php endif; ?>
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($expense)); ?>)"
                                        class="flex-1 bg-yellow-500 text-white py-2 rounded-lg font-semibold shadow-lg hover:bg-yellow-600 transition duration-200 transform hover:scale-105">
                                    Edit
                                </button>
                                <button onclick="deleteExpense(<?php echo $expense['id']; ?>)"
                                        class="flex-1 bg-red-600 text-white py-2 rounded-lg font-semibold shadow-lg hover:bg-red-700 transition duration-200 transform hover:scale-105">
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="bg-gray-900 text-white p-5 text-center mt-auto shadow-inner">
        <p class="text-base">&copy; <?php echo date('Y'); ?> Executive Dashboard. All rights reserved.</p>
    </footer>

    <!-- Add Expense Modal -->
    <div id="addExpenseModal" class="modal fixed inset-0 bg-gray-900 bg-opacity-50 items-center justify-center p-4 z-50">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
            <h3 class="text-2xl font-bold text-center text-gray-800 mb-6">Add New Scheduled Bill</h3>
            <form action="scheduled_expenses.php" method="POST" class="space-y-4">
                <div>
                    <label for="bill_name" class="block text-sm font-medium text-gray-700 mb-1">Bill Name:</label>
                    <input type="text" id="bill_name" name="bill_name" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount (<?php echo htmlspecialchars($currency); ?>):</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date:</label>
                    <input type="date" id="due_date" name="due_date" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="recurrence" class="block text-sm font-medium text-gray-700 mb-1">Recurrence:</label>
                    <select id="recurrence" name="recurrence" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm appearance-none bg-white">
                        <option value="monthly">Monthly</option>
                        <option value="weekly">Weekly</option>
                        <option value="yearly">Yearly</option>
                        <option value="once">Once</option>
                    </select>
                </div>
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional):</label>
                    <textarea id="description" name="description" rows="3" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm"></textarea>
                </div>
                <div class="flex justify-between items-center mt-6">
                    <button type="button" onclick="closeModal('addExpenseModal')"
                            class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg font-semibold hover:bg-gray-400 transition duration-200">
                        Cancel
                    </button>
                    <button type="submit" name="add_expense"
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-700 transition duration-200">
                        Add Bill
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Expense Modal -->
    <div id="editExpenseModal" class="modal fixed inset-0 bg-gray-900 bg-opacity-50 items-center justify-center p-4 z-50">
        <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
            <h3 class="text-2xl font-bold text-center text-gray-800 mb-6">Edit Scheduled Bill</h3>
            <form action="scheduled_expenses.php" method="POST" class="space-y-4">
                <input type="hidden" name="edit_expense_id" id="edit_expense_id">
                <div>
                    <label for="edit_bill_name" class="block text-sm font-medium text-gray-700 mb-1">Bill Name:</label>
                    <input type="text" id="edit_bill_name" name="bill_name" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="edit_amount" class="block text-sm font-medium text-gray-700 mb-1">Amount (<?php echo htmlspecialchars($currency); ?>):</label>
                    <input type="number" id="edit_amount" name="amount" step="0.01" min="0" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="edit_due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date:</label>
                    <input type="date" id="edit_due_date" name="due_date" required class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                </div>
                <div>
                    <label for="edit_recurrence" class="block text-sm font-medium text-gray-700 mb-1">Recurrence:</label>
                    <select id="edit_recurrence" name="recurrence" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm appearance-none bg-white">
                        <option value="monthly">Monthly</option>
                        <option value="weekly">Weekly</option>
                        <option value="yearly">Yearly</option>
                        <option value="once">Once</option>
                    </select>
                </div>
                <div>
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional):</label>
                    <textarea id="edit_description" name="description" rows="3" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm"></textarea>
                </div>
                <div class="flex items-center space-x-2 mt-4">
                    <input type="checkbox" id="edit_is_paid" name="is_paid" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="edit_is_paid" class="text-sm font-medium text-gray-700">Mark as Paid</label>
                </div>
                <div class="flex justify-between items-center mt-6">
                    <button type="button" onclick="closeModal('editExpenseModal')"
                            class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg font-semibold hover:bg-gray-400 transition duration-200">
                        Cancel
                    </button>
                    <button type="submit" name="edit_expense"
                            class="bg-blue-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-blue-700 transition duration-200">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('is-active');
            modal.querySelector('div').classList.remove('scale-95', 'opacity-0');
            modal.querySelector('div').classList.add('scale-100', 'opacity-100');
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.querySelector('div').classList.remove('scale-100', 'opacity-100');
            modal.querySelector('div').classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.remove('is-active');
            }, 300); // Wait for the transition to finish
        }

        function openEditModal(expense) {
            document.getElementById('edit_expense_id').value = expense.id;
            document.getElementById('edit_bill_name').value = expense.bill_name;
            document.getElementById('edit_amount').value = expense.amount;
            document.getElementById('edit_due_date').value = expense.due_date;
            document.getElementById('edit_recurrence').value = expense.recurrence;
            document.getElementById('edit_description').value = expense.description;
            document.getElementById('edit_is_paid').checked = expense.is_paid == 1;
            openModal('editExpenseModal');
        }

        function deleteExpense(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!',
                customClass: {
                    confirmButton: 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg mr-2',
                    cancelButton: 'bg-gray-300 hover:bg-gray-400 text-gray-700 font-bold py-2 px-4 rounded-lg',
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'scheduled_expenses.php';
                    const hiddenField = document.createElement('input');
                    hiddenField.type = 'hidden';
                    hiddenField.name = 'delete_expense_id';
                    hiddenField.value = id;
                    const hiddenSubmit = document.createElement('input');
                    hiddenSubmit.type = 'hidden';
                    hiddenSubmit.name = 'delete_expense';
                    hiddenSubmit.value = '1';
                    form.appendChild(hiddenField);
                    form.appendChild(hiddenSubmit);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function markPaid(event, id, billName, amount, recurrence, dueDate) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This will add " + '<?php echo htmlspecialchars($currency); ?>' + amount.toFixed(2) + " to your expenses and mark this bill as paid. It will be re-activated 5 days before the next due date.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3B82F6',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Mark as Paid',
                customClass: {
                    confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg mr-2',
                    cancelButton: 'bg-gray-300 hover:bg-gray-400 text-gray-700 font-bold py-2 px-4 rounded-lg',
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    // Disable the button and change text immediately for visual feedback
                    const button = event.target;
                    button.disabled = true;
                    button.textContent = 'Processing...';
                    button.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                    button.classList.add('bg-gray-500', 'cursor-not-allowed');

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'scheduled_expenses.php';

                    const hiddenId = document.createElement('input');
                    hiddenId.type = 'hidden';
                    hiddenId.name = 'mark_paid_id';
                    hiddenId.value = id;

                    const hiddenAmount = document.createElement('input');
                    hiddenAmount.type = 'hidden';
                    hiddenAmount.name = 'amount_to_add';
                    hiddenAmount.value = amount;
                    
                    const hiddenName = document.createElement('input');
                    hiddenName.type = 'hidden';
                    hiddenName.name = 'bill_name';
                    hiddenName.value = billName;
                    
                    const hiddenRecurrence = document.createElement('input');
                    hiddenRecurrence.type = 'hidden';
                    hiddenRecurrence.name = 'recurrence';
                    hiddenRecurrence.value = recurrence;
                    
                    const hiddenDueDate = document.createElement('input');
                    hiddenDueDate.type = 'hidden';
                    hiddenDueDate.name = 'due_date';
                    hiddenDueDate.value = dueDate;

                    const hiddenSubmit = document.createElement('input');
                    hiddenSubmit.type = 'hidden';
                    hiddenSubmit.name = 'mark_paid';
                    hiddenSubmit.value = '1';

                    form.appendChild(hiddenId);
                    form.appendChild(hiddenAmount);
                    form.appendChild(hiddenName);
                    form.appendChild(hiddenRecurrence);
                    form.appendChild(hiddenDueDate);
                    form.appendChild(hiddenSubmit);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // SweetAlert2 integration - this runs after the DOM is fully loaded
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
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
    </script>
</body>
</html>
