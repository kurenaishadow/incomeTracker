<?php
session_start();
require_once("connections.php");

// Set the default timezone to Asia/Manila for all date/time operations
date_default_timezone_set('Asia/Manila');

// Initialize SweetAlert2 message variables
$swal_message = '';
$swal_type = ''; // Can be 'success', 'error', 'warning', 'info', 'question'

// Handle incoming SweetAlert2 messages from redirects (e.g., from login or other pages)
if (isset($_GET['swal_message']) && isset($_GET['swal_type'])) {
    $swal_message = htmlspecialchars($_GET['swal_message']);
    $swal_type = htmlspecialchars($_GET['swal_type']);
}

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$business_name = null;
$currency = '$'; // Default currency
$account_status = 'inactive'; // Default status
$expiration_date = null;
$needs_password_change = 0; // Default to no password change needed
$monthly_income_target = 0.00; // Initialize monthly income target
$monthly_expense_target = 0.00; // Initialize monthly expense target
// $message = ''; // This variable is no longer directly used for displaying messages on the dashboard.
$show_inventory_overview = 1; // Initialize user preference for inventory overview to default (visible)

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // For critical errors like DB connection, die() might still be acceptable,
    // or you could redirect to a static error page.
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Fetch comprehensive user data, including business name, currency, status, expiration, password change flag, monthly targets, and inventory overview preference
$sql_fetch_user = "SELECT business_name, currency, account_status, expiration_date, needs_password_change, monthly_income_target, monthly_expense_target, show_inventory_overview FROM users WHERE id = ?";
if ($stmt_fetch = $conn->prepare($sql_fetch_user)) {
    $stmt_fetch->bind_param('i', $user_id);
    if ($stmt_fetch->execute()) {
        $stmt_fetch->bind_result($fetched_business_name, $fetched_currency, $fetched_account_status, $fetched_expiration_date, $fetched_needs_password_change, $fetched_income_target, $fetched_expense_target, $fetched_show_inventory_overview);
        if ($stmt_fetch->fetch()) {
            $business_name = $fetched_business_name;
            $currency = $fetched_currency ?: '$'; // Use fetched currency or default
            $account_status = $fetched_account_status;
            $expiration_date = $fetched_expiration_date;
            $needs_password_change = $fetched_needs_password_change;
            $monthly_income_target = $fetched_income_target;
            $monthly_expense_target = $fetched_expense_target;
            $show_inventory_overview = $fetched_show_inventory_overview;

            // Update session variables for immediate use and future pages
            $_SESSION['business_name'] = $business_name;
            $_SESSION['currency'] = $currency;
            $_SESSION['account_status'] = $account_status;
            $_SESSION['expiration_date'] = $expiration_date;
            $_SESSION['needs_password_change'] = $needs_password_change;
            $_SESSION['monthly_income_target'] = $monthly_income_target;
            $_SESSION['monthly_expense_target'] = $monthly_expense_target;
            $_SESSION['show_inventory_overview'] = $show_inventory_overview;
        }
    } else {
        // $message .= '<p class="text-red-500 text-sm mt-2">Error fetching user data: ' . $stmt_fetch->error . '</p>';
        $swal_message = 'Error fetching user data: ' . $stmt_fetch->error;
        $swal_type = 'error';
    }
    $stmt_fetch->close();
}

// --- Critical Security Checks ---
// 1. Force password change if required by admin
if ($needs_password_change == 1) {
    header('Location: change_password_force.php');
    exit();
}

// 2. Check account status and expiration
if ($account_status === 'inactive') {
    $_SESSION = array(); // Clear session
    session_destroy();
    // Redirect with SweetAlert2 message
    header('Location: index.php?swal_message=' . urlencode('Your account is inactive. Please contact support.') . '&swal_type=error');
    exit();
}

if ($expiration_date && strtotime($expiration_date) < time()) {
    $_SESSION = array(); // Clear session
    session_destroy();
    // Redirect with SweetAlert2 message
    header('Location: index.php?swal_message=' . urlencode('Your account has expired. Please contact support to reactivate.') . '&swal_type=error');
    exit();
}
// --- End Critical Security Checks ---


// Handle form submissions (only for initial business name setup if empty)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_business_name'])) { // This block is only for the initial setup form
        $new_business_name = trim($_POST['business_name'] ?? '');
        $new_currency = trim($_POST['currency'] ?? '$');

        if (empty($new_business_name)) {
            // $message = '<p class="text-red-500 text-sm mt-2">Business name cannot be empty.</p>';
            $swal_message = 'Business name cannot be empty.';
            $swal_type = 'error';
        } else {
            $sql_update = "UPDATE users SET business_name = ?, currency = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param('ssi', $new_business_name, $new_currency, $user_id);
                if ($stmt_update->execute()) {
                    $_SESSION['business_name'] = $new_business_name;
                    $_SESSION['currency'] = $new_currency;
                    $business_name = $new_business_name;
                    $currency = $new_currency;
                    // $message = '<p class="text-green-500 text-sm mt-2">Business information updated successfully!</p>';
                    $swal_message = 'Business information updated successfully!';
                    $swal_type = 'success';
                } else {
                    // $message = '<p class="text-red-500 text-sm mt-2">Error updating business information: ' . $stmt_update->error . '</p>';
                    $swal_message = 'Error updating business information: ' . $stmt_update->error;
                    $swal_type = 'error';
                }
                $stmt_update->close();
            }
        }
    } else if (isset($_POST['update_business_info'])) { // This block is for updating from the toggleable form
        $new_business_name = trim($_POST['business_name'] ?? '');
        $new_currency = trim($_POST['currency'] ?? '$');

        if (empty($new_business_name)) {
            // $message = '<p class="text-red-500 text-sm mt-2">Business name cannot be empty.</p>';
            $swal_message = 'Business name cannot be empty.';
            $swal_type = 'error';
        } else {
            $sql_update = "UPDATE users SET business_name = ?, currency = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param('ssi', $new_business_name, $new_currency, $user_id);
                if ($stmt_update->execute()) {
                    $_SESSION['business_name'] = $new_business_name;
                    $_SESSION['currency'] = $new_currency;
                    $business_name = $new_business_name;
                    $currency = $new_currency;
                    // $message = '<p class="text-green-500 text-sm mt-2">Business information updated successfully!</p>';
                    $swal_message = 'Business information updated successfully!';
                    $swal_type = 'success';
                } else {
                    // $message = '<p class="text-red-500 text-sm mt-2">Error updating business information: ' . $stmt_update->error . '</p>';
                    $swal_message = 'Error updating business information: ' . $stmt_update->error;
                    $swal_type = 'error';
                }
                $stmt_update->close();
            }
        }
    }
}

// --- Dynamic Data for Dashboard (fetched only if business_name exists) ---
$total_sales_30_days = 0;
$total_expenses_30_days = 0;
$total_profit_30_days = 0;

$current_month_actual_income = 0;
$current_month_actual_expenses = 0;

// Initialize chart data arrays to empty to prevent "Undefined array key" warnings
$daily_chart_labels = [];
$final_daily_sales_data = [];
$final_daily_expenses_data = [];
$daily_profit_data = [];

$monthly_chart_labels = [];
$final_monthly_sales_data = [];
$final_monthly_expenses_data = [];
$monthly_profit_data = [];

$yearly_chart_labels = [];
$final_yearly_sales_data = [];
$final_yearly_expenses_data = [];
$yearly_profit_data = [];

$recent_transactions = [];

// Inventory Data Initialization (ONLY if show_inventory_overview is true)
$total_products = 0;
$low_stock_products_count = 0;
$low_stock_products_list = [];
$inventory_chart_labels = [];
$inventory_stock_data = [];
$inventory_min_stock_data = [];

// NEW: Scheduled Expenses Alert Initialization
$scheduled_expenses_alert = null;

if (!empty($business_name)) {
    // Fetch total income for the last 30 days
    $sql_income_30_days = "SELECT SUM(amount) AS total_income FROM income WHERE user_id = ? ";
    // AND income_date >= CURDATE() - INTERVAL 30 DAY -delete in line above-
    if ($stmt_income = $conn->prepare($sql_income_30_days)) {
        $stmt_income->bind_param('i', $user_id);
        if ($stmt_income->execute()) {
            $stmt_income->bind_result($total_sales_30_days_raw);
            $stmt_income->fetch();
            $total_sales_30_days = $total_sales_30_days_raw ?: 0;
        }
        $stmt_income->close();
    }

    // Fetch total expenses for the last 30 days
    $sql_expenses_30_days = "SELECT SUM(amount) AS total_expenses FROM expenses WHERE user_id = ? ";
    // AND expense_date >= CURDATE() - INTERVAL 30 DAY -deleted in line above-
    if ($stmt_expenses = $conn->prepare($sql_expenses_30_days)) {
        $stmt_expenses->bind_param('i', $user_id);
        if ($stmt_expenses->execute()) {
            $stmt_expenses->bind_result($total_expenses_30_days_raw);
            $stmt_expenses->fetch();
            $total_expenses_30_days = $total_expenses_30_days_raw ?: 0;
        }
        $stmt_expenses->close();
    }
    
    $total_profit_30_days = $total_sales_30_days - ($total_expenses_30_days ?: 0);


    // Fetch current month's actual income
    $sql_current_month_income = "SELECT SUM(amount) AS current_income FROM income WHERE user_id = ? AND MONTH(income_date) = MONTH(CURDATE()) AND YEAR(income_date) = YEAR(CURDATE())";
    if ($stmt_current_income = $conn->prepare($sql_current_month_income)) {
        $stmt_current_income->bind_param('i', $user_id);
        if ($stmt_current_income->execute()) {
            $stmt_current_income->bind_result($current_month_income_raw);
            $stmt_current_income->fetch();
            $current_month_actual_income = $current_month_income_raw ?: 0;
        }
        $stmt_current_income->close();
    }

    // Fetch current month's actual expenses
    $sql_current_month_expenses = "SELECT SUM(amount) AS current_expenses FROM expenses WHERE user_id = ? AND MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())";
    if ($stmt_current_expenses = $conn->prepare($sql_current_month_expenses)) {
        $stmt_current_expenses->bind_param('i', $user_id);
        if ($stmt_current_expenses->execute()) {
            $stmt_current_expenses->bind_result($current_month_expenses_raw);
            $stmt_current_expenses->fetch();
            $current_month_actual_expenses = $current_month_expenses_raw ?: 0;
        }
        $stmt_current_expenses->close();
    }
    
    // NEW: Fetch upcoming scheduled expenses
    $sql_scheduled_expenses = "SELECT bill_name, due_date FROM scheduled_expenses WHERE user_id = ? AND is_paid = 0 AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 5 DAY ORDER BY due_date ASC";
    if ($stmt_scheduled_expenses = $conn->prepare($sql_scheduled_expenses)) {
        $stmt_scheduled_expenses->bind_param('i', $user_id);
        if ($stmt_scheduled_expenses->execute()) {
            $result = $stmt_scheduled_expenses->get_result();
            if ($result->num_rows > 0) {
                $scheduled_expenses_alert = '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 mb-5 rounded-md">';
                $scheduled_expenses_alert .= '<div class="flex items-center">';
                $scheduled_expenses_alert .= '<div class="flex-shrink-0 text-yellow-700">';
                $scheduled_expenses_alert .= '<svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">';
                $scheduled_expenses_alert .= '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.506 2.766-1.506 3.531 0l4.394 8.673c.765 1.506-.356 3.33-2.031 3.33H5.894c-1.675 0-2.796-1.824-2.031-3.33L8.257 3.099zM10 13a1 1 0 100 2 1 1 0 000-2zm0-4a1 1 0 00-1 1v2a1 1 0 102 0v-2a1 1 0 00-1-1z" clip-rule="evenodd" />';
                $scheduled_expenses_alert .= '</svg>';
                $scheduled_expenses_alert .= '</div>';
                $scheduled_expenses_alert .= '<div class="ml-3 text-sm text-yellow-700">';
                $scheduled_expenses_alert .= '<p class="font-bold">Upcoming Bills Alert:</p>';
                $scheduled_expenses_alert .= '<ul class="list-disc list-inside mt-1">';
                while ($row = $result->fetch_assoc()) {
                    $due_date = new DateTime($row['due_date']);
                    $formatted_date = $due_date->format('F j, Y');
                    $scheduled_expenses_alert .= '<li><span class="font-bold">' . htmlspecialchars($row['bill_name']) . '</span> is due on ' . $formatted_date . '.</li>';
                }
                $scheduled_expenses_alert .= '</ul>';
                $scheduled_expenses_alert .= '</div>';
                $scheduled_expenses_alert .= '</div>';
                $scheduled_expenses_alert .= '</div>';
            }
        }
        $stmt_scheduled_expenses->close();
    }


    // NEW: Fetch Inventory Data (ONLY if show_inventory_overview is true)
    if ($show_inventory_overview) {
        $sql_fetch_inventory = "SELECT product_name, stock_quantity, min_stock_level FROM products WHERE user_id = ? ORDER BY product_name ASC";
        if ($stmt_inventory = $conn->prepare($sql_fetch_inventory)) {
            $stmt_inventory->bind_param('i', $user_id);
            if ($stmt_inventory->execute()) {
                $result_inventory = $stmt_inventory->get_result();
                while ($row = $result_inventory->fetch_assoc()) {
                    $total_products++;
                    $inventory_chart_labels[] = htmlspecialchars($row['product_name']);
                    $inventory_stock_data[] = $row['stock_quantity'];
                    $inventory_min_stock_data[] = $row['min_stock_level'];

                    if ($row['stock_quantity'] <= $row['min_stock_level']) {
                        $low_stock_products_count++;
                        $low_stock_products_list[] = htmlspecialchars($row['product_name']) . " (Current: " . $row['stock_quantity'] . ", Min: " . $row['min_stock_level'] . ")";
                    }
                }
            } else {
                error_log("Error fetching inventory: " . $stmt_inventory->error);
            }
            $stmt_inventory->close();
        } else {
            error_log("Error preparing fetch inventory statement: " . $conn->error);
        }
    }


    // --- Data for Daily Chart (Last 30 Days) ---
    $daily_sales_data_map = [];
    $daily_expenses_data_map = [];

    for ($i = 29; $i >= 0; $i--) {
        $date_key = date('Y-m-d', strtotime("-$i days"));
        $daily_chart_labels[] = date('n/j', strtotime("-$i days"));
        $daily_sales_data_map[$date_key] = 0;
        $daily_expenses_data_map[$date_key] = 0;
    }

    // Fetch daily income
    $sql_daily_income = "SELECT DATE_FORMAT(income_date, '%Y-%m-%d') AS date_key, SUM(amount) AS total_income FROM income WHERE user_id = ? AND income_date >= CURDATE() - INTERVAL 30 DAY GROUP BY date_key ORDER BY date_key ASC";
    if ($stmt_income_daily = $conn->prepare($sql_daily_income)) {
        $stmt_income_daily->bind_param('i', $user_id);
        if ($stmt_income_daily->execute()) {
            $result_income_daily = $stmt_income_daily->get_result();
            while ($row = $result_income_daily->fetch_assoc()) {
                $daily_sales_data_map[$row['date_key']] = (float)$row['total_income'];
            }
        }
        $stmt_income_daily->close();
    }

    // Fetch daily expenses
    $sql_daily_expenses = "SELECT DATE_FORMAT(expense_date, '%Y-%m-%d') AS date_key, SUM(amount) AS total_expenses FROM expenses WHERE user_id = ? AND expense_date >= CURDATE() - INTERVAL 30 DAY GROUP BY date_key ORDER BY date_key ASC";
    if ($stmt_expenses_daily = $conn->prepare($sql_daily_expenses)) {
        $stmt_expenses_daily->bind_param('i', $user_id);
        if ($stmt_expenses_daily->execute()) {
            $result_expenses_daily = $stmt_expenses_daily->get_result();
            while ($row = $result_expenses_daily->fetch_assoc()) {
                $daily_expenses_data_map[$row['date_key']] = (float)$row['total_expenses'];
            }
        }
        $stmt_expenses_daily->close();
    }

    foreach ($daily_chart_labels as $index => $label) {
        $date_key_for_lookup = date('Y-m-d', strtotime("-".(29-$index)." days")); // Corrected date key calculation
        $current_day_sales = $daily_sales_data_map[$date_key_for_lookup] ?? 0;
        $current_day_expenses = $daily_expenses_data_map[$date_key_for_lookup] ?? 0;

        $final_daily_sales_data[] = $current_day_sales;
        $final_daily_expenses_data[] = $current_day_expenses;
        $daily_profit_data[] = $current_day_sales - $current_day_expenses;
    }


    // --- Data for Monthly Chart (Current Year: January to December) ---
    $monthly_sales_data_map = [];
    $monthly_expenses_data_map = [];
    $current_year = date('Y');
    
    for ($i = 1; $i <= 12; $i++) {
        $month_key = $current_year . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $monthly_chart_labels[] = date('M', mktime(0, 0, 0, $i, 1, $current_year));
        $monthly_sales_data_map[$month_key] = 0;
        $monthly_expenses_data_map[$month_key] = 0;
    }

    // Fetch monthly income for the current year (January to December)
    $sql_monthly_income = "SELECT DATE_FORMAT(income_date, '%Y-%m') AS month_key, SUM(amount) AS total_income FROM income WHERE user_id = ? AND YEAR(income_date) = ? GROUP BY month_key ORDER BY month_key ASC";
    if ($stmt_income_monthly = $conn->prepare($sql_monthly_income)) {
        $stmt_income_monthly->bind_param('ii', $user_id, $current_year);
        if ($stmt_income_monthly->execute()) {
            $result_income_monthly = $stmt_income_monthly->get_result();
            while ($row = $result_income_monthly->fetch_assoc()) {
                $monthly_sales_data_map[$row['month_key']] = (float)$row['total_income'];
            }
        }
        $stmt_income_monthly->close();
    }

    // Fetch monthly expenses for the current year (January to December)
    $sql_monthly_expenses = "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key, SUM(amount) AS total_expenses FROM expenses WHERE user_id = ? AND YEAR(expense_date) = ? GROUP BY month_key ORDER BY month_key ASC";
    if ($stmt_expenses_monthly = $conn->prepare($sql_monthly_expenses)) {
        $stmt_expenses_monthly->bind_param('ii', $user_id, $current_year);
        if ($stmt_expenses_monthly->execute()) {
            $result_expenses_monthly = $stmt_expenses_monthly->get_result();
            while ($row = $result_expenses_monthly->fetch_assoc()) {
                $monthly_expenses_data_map[$row['month_key']] = (float)$row['total_expenses'];
            }
        }
        $stmt_expenses_monthly->close();
    }

    // Populate actual data arrays and calculate profit, safely accessing map
    foreach ($monthly_chart_labels as $index => $label) {
        $month_num = $index + 1;
        $month_key_for_lookup = $current_year . '-' . str_pad($month_num, 2, '0', STR_PAD_LEFT);
        
        $current_month_sales = $monthly_sales_data_map[$month_key_for_lookup] ?? 0;
        $current_month_expenses = $monthly_expenses_data_map[$month_key_for_lookup] ?? 0;
        
        $final_monthly_sales_data[] = $current_month_sales;
        $final_monthly_expenses_data[] = $current_month_expenses;
        $monthly_profit_data[] = $current_month_sales - $current_month_expenses;
    }
    
    // --- Data for Yearly Chart (Last 5 Years) ---
    $yearly_sales_data_map = [];
    $yearly_expenses_data_map = [];

    // Initialize arrays for the last 5 years with default 0 values
    for ($i = 4; $i >= 0; $i--) {
        $year_key = date('Y', strtotime("-$i years"));
        $yearly_chart_labels[] = $year_key;
        $yearly_sales_data_map[$year_key] = 0;
        $yearly_expenses_data_map[$year_key] = 0;
    }

    // Fetch yearly income
    $sql_yearly_income = "SELECT DATE_FORMAT(income_date, '%Y') AS year_key, SUM(amount) AS total_income FROM income WHERE user_id = ? AND income_date >= CURDATE() - INTERVAL 5 YEAR GROUP BY year_key ORDER BY year_key ASC";
    if ($stmt_income_yearly = $conn->prepare($sql_yearly_income)) {
        $stmt_income_yearly->bind_param('i', $user_id);
        if ($stmt_income_yearly->execute()) {
            $result_income_yearly = $stmt_income_yearly->get_result();
            while ($row = $result_income_yearly->fetch_assoc()) {
                $yearly_sales_data_map[$row['year_key']] = (float)$row['total_income'];
            }
        }
        $stmt_income_yearly->close();
    }

    // Fetch yearly expenses
    $sql_yearly_expenses = "SELECT DATE_FORMAT(expense_date, '%Y') AS year_key, SUM(amount) AS total_expenses FROM expenses WHERE user_id = ? AND expense_date >= CURDATE() - INTERVAL 5 YEAR GROUP BY year_key ORDER BY year_key ASC";
    if ($stmt_expenses_yearly = $conn->prepare($sql_yearly_expenses)) {
        $stmt_expenses_yearly->bind_param('i', $user_id);
        if ($stmt_expenses_yearly->execute()) {
            $result_expenses_yearly = $stmt_expenses_yearly->get_result();
            while ($row = $result_expenses_yearly->fetch_assoc()) {
                $yearly_expenses_data_map[$row['year_key']] = (float)$row['total_expenses'];
            }
        }
        $stmt_expenses_yearly->close();
    }

    // Populate actual data arrays and calculate profit, safely accessing map
    foreach ($yearly_chart_labels as $index => $label) {
        $year_key_for_lookup = date('Y', strtotime("-".(4-$index)." years")); // Corrected date key calculation
        $current_year_sales = $yearly_sales_data_map[$year_key_for_lookup] ?? 0;
        $current_year_expenses = $yearly_expenses_data_map[$year_key_for_lookup] ?? 0;

        $final_yearly_sales_data[] = $current_year_sales;
        $final_yearly_expenses_data[] = $current_year_expenses;
        $yearly_profit_data[] = $current_year_sales - $current_year_expenses;
    }

    // --- Fetch Recent Transactions ---
    $sql_recent_transactions = "
        (SELECT 'income' as type, id, amount, description, created_at as date FROM income WHERE user_id = ?)
        UNION ALL
        (SELECT 'expense' as type, id, amount, description, created_at as date FROM expenses WHERE user_id = ?)
        ORDER BY date DESC
        LIMIT 5
    ";
    if ($stmt_recent = $conn->prepare($sql_recent_transactions)) {
        $stmt_recent->bind_param('ii', $user_id, $user_id);
        if ($stmt_recent->execute()) {
            $result_recent = $stmt_recent->get_result();
            while ($row = $result_recent->fetch_assoc()) {
                $recent_transactions[] = $row;
            }
        } else {
            error_log("Error fetching recent transactions: " . $stmt_recent->error);
        }
        $stmt_recent->close();
    } else {
        error_log("Error preparing recent transactions statement: " . $conn->error);
    }
}


// Encode all data to JSON for JavaScript
$all_chart_data_json = json_encode([
    'daily' => [
        'labels' => $daily_chart_labels,
        'datasets' => [
            [
                'label' => 'Sales',
                'data' => $final_daily_sales_data,
                'borderColor' => 'rgb(59, 130, 246)',
                'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Expenses',
                'data' => $final_daily_expenses_data,
                'borderColor' => 'rgb(239, 68, 68)',
                'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Profit',
                'data' => $daily_profit_data,
                'borderColor' => 'rgb(34, 197, 94)',
                'backgroundColor' => 'rgba(34, 197, 94, 0.2)',
                'fill' => true,
                'tension' => 0.3
            ]
        ]
    ],
    'monthly' => [
        'labels' => $monthly_chart_labels,
        'datasets' => [
            [
                'label' => 'Sales',
                'data' => $final_monthly_sales_data,
                'borderColor' => 'rgb(59, 130, 246)',
                'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Expenses',
                'data' => $final_monthly_expenses_data,
                'borderColor' => 'rgb(239, 68, 68)',
                'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Profit',
                'data' => $monthly_profit_data,
                'borderColor' => 'rgb(34, 197, 94)',
                'backgroundColor' => 'rgba(34, 197, 94, 0.2)',
                'fill' => true,
                'tension' => 0.3
            ]
        ]
    ],
    'yearly' => [
        'labels' => $yearly_chart_labels,
        'datasets' => [
            [
                'label' => 'Sales',
                'data' => $final_yearly_sales_data,
                'borderColor' => 'rgb(59, 130, 246)',
                'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Expenses',
                'data' => $final_yearly_expenses_data,
                'borderColor' => 'rgb(239, 68, 68)',
                'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                'fill' => false,
                'tension' => 0.3
            ],
            [
                'label' => 'Profit',
                'data' => $yearly_profit_data,
                'borderColor' => 'rgb(34, 197, 94)',
                'backgroundColor' => 'rgba(34, 197, 94, 0.2)',
                'fill' => true,
                'tension' => 0.3
            ]
        ]
    ],
    'inventory_stock' => [
        'labels' => $inventory_chart_labels,
        'datasets' => [
            [
                'label' => 'Current Stock',
                'data' => $inventory_stock_data,
                'backgroundColor' => 'rgba(79, 70, 229, 0.8)', // Indigo-500
                'borderColor' => 'rgba(79, 70, 229, 1)',
                'borderWidth' => 1
            ],
            [
                'label' => 'Minimum Stock Level',
                'data' => $inventory_min_stock_data,
                'backgroundColor' => 'rgba(239, 68, 68, 0.5)', // Red-500 for threshold
                'borderColor' => 'rgba(239, 68, 68, 1)',
                'borderWidth' => 1
            ]
        ]
    ]
]);
// NEW: Fetch upcoming bills due in the next 5 days
$upcoming_bills = [];
$sql_upcoming_bills = "SELECT id, bill_name, amount, due_date FROM scheduled_expenses WHERE user_id = ? AND DATEDIFF(due_date, CURDATE()) <= 5 AND DATEDIFF(due_date, CURDATE()) >= 0 AND is_paid = 0 ORDER BY due_date ASC";
if ($stmt = $conn->prepare($sql_upcoming_bills)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $upcoming_bills[] = $row;
        }
    }
    $stmt->close();
}

// Fetch last month's actual income
                    $sql_last_month_income = "SELECT SUM(amount) AS last_month_income FROM income WHERE user_id = ? AND MONTH(income_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(income_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                    if ($stmt_last_income = $conn->prepare($sql_last_month_income)) {
                        $stmt_last_income->bind_param('i', $user_id);
                        if ($stmt_last_income->execute()) {
                            $stmt_last_income->bind_result($last_month_income_raw);
                            $stmt_last_income->fetch();
                            $last_month_actual_income = $last_month_income_raw ?: 0;
                        }
                        $stmt_last_income->close();
                    }

                    // Fetch last month's actual expenses
                    $sql_last_month_expenses = "SELECT SUM(amount) AS last_month_expenses FROM expenses WHERE user_id = ? AND MONTH(expense_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(expense_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                    if ($stmt_last_expenses = $conn->prepare($sql_last_month_expenses)) {
                        $stmt_last_expenses->bind_param('i', $user_id);
                        if ($stmt_last_expenses->execute()) {
                            $stmt_last_expenses->bind_result($last_month_expenses_raw);
                            $stmt_last_expenses->fetch();
                            $last_month_actual_expenses = $last_month_expenses_raw ?: 0;
                        }
                        $stmt_last_expenses->close();
                    }
                    $last_month_profit = $last_month_actual_income - $last_month_actual_expenses;

// Close connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- <meta name="viewport" content="width=device-width, initial-scale=0"> -->
    <meta name="viewport" content="width=1100">
    <title>Dashboard - Business Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f9ff;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem; /* Added padding for smaller screens */
        }
        .card {
            transition: all 0.3s ease-in-out;
            transform: translateY(0);
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08);
        }
        .form-input {
            transition: all 0.2s ease-in-out;
        }
        .form-input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25);
        }
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }
        .progress-bar-container {
            background-color: #e0e7ff;
            border-radius: 9999px;
            height: 1rem;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        .progress-bar {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.5s ease-out;
            text-align: center;
            color: white;
            font-size: 0.75rem;
            line-height: 1rem;
        }
        .hidden-edit-form {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s ease-out, opacity 0.5s ease-out, transform 0.5s ease-out;
            opacity: 0;
            transform: translateY(-10px);
        }
        .visible-edit-form {
            max-height: 500px; /* Adjust as needed */
            opacity: 1;
            transform: translateY(0);
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

        /* Mobile adjustments */
        @media (max-width: 768px) {
            .dashboard-actions {
                flex-direction: column;
                gap: 1rem;
                width: 100%;
            }
            .dashboard-actions a, .dashboard-actions button {
                width: 100%;
                text-align: center;
            }
            .card { padding: 1rem; }
            .card h3 { font-size: 1.25rem; }
            .card p:first-of-type { font-size: 2rem; }
            .chart-container { height: 250px; }
            h1.text-3xl { font-size: 1.75rem; }
            h2.text-4xl { font-size: 2rem; }
            p.text-xl { font-size: 1rem; }
            .bg-blue-50 p.text-lg { font-size: 0.95rem; }
            h3.text-2xl { font-size: 1.5rem; }
            p.text-lg.font-medium.text-gray-800.mb-2 { font-size: 0.95rem; }
            .progress-bar { font-size: 0.65rem; }
            .bg-white p.text-sm.mt-2 { font-size: 0.75rem; }
            table .text-sm { font-size: 0.75rem; }
            table .text-xs { font-size: 0.65rem; }
            footer p.text-lg { font-size: 0.8rem; }
        }
    </style>
</head>
<body class="bg-gray-100">

    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></div>
    <aside id="sidebar" class="sidebar-closed fixed top-0 left-0 h-full w-64 bg-gray-800 text-white p-5 z-50 flex flex-col">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold">Menu</h2>
            <button id="close-sidebar-btn" class="text-gray-400 hover:text-white">&times;</button>
        </div>
        
        <div class="mb-6">
            <p class="text-sm text-gray-400">Welcome,</p>
            <p class="font-semibold text-lg"><?php echo htmlspecialchars($username); ?></p>
        </div>

        <nav class="flex-grow">
             <h3 class="text-gray-400 text-sm font-semibold uppercase mt-4 mb-2">Dashboard Management</h3> 
            <a href="dashboard.php" class="block py-2.5 px-4 rounded transition duration-200 bg-gray-700 font-semibold mb-2">Dashboard</a>
            <a href="scheduled_expenses.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Scheduled Expenses</a>
            <a href="transactions.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Transactions</a>
            <a href="inventory_manage.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Inventory</a>

            <h3 class="text-gray-400 text-sm font-semibold uppercase mt-4 mb-2">Cash Flow Management</h3> 
            <a href="income_input.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Add Income</a>
            <a href="expense_input.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Add Expenses</a>
            <a href="savings.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">Savings Settings</a>
         
            <button type="button" onclick="toggleEditForm()" class="w-full sm:w-auto bg-blue-200 text-blue-700 px-3 py-2 rounded-full font-medium shadow-sm hover:bg-blue-300 transition duration-200"> Edit Business Info</button>
            <a href="user_settings.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">User Settings</a>
            
        </nav>

        <div class="mt-auto">
             <a href="logout.php" class="block w-full text-center bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 font-semibold transition duration-200">
                Logout
            </a>
        </div>
    </aside>
    <header class="bg-gradient-to-r from-blue-600 to-blue-500 p-4 shadow-lg text-white sticky top-0 z-30">
        <div class="container flex justify-between items-center">
            <button id="open-sidebar-btn" class="p-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <div class="text-lg font-bold">
                <?php echo htmlspecialchars($business_name ?: 'Dashboard'); ?>
            </div>
            <div></div>
        </div>
    </header>

    <main class="container py-6">
        <?php if (empty($business_name)): ?>
            <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-md mx-auto transform hover:scale-105 transition duration-300 ease-in-out">
                <h2 class="text-3xl font-bold text-center text-gray-800 mb-5">Enter Your Business Information</h2>
                <p class="text-gray-600 text-center mb-6">Let's get your dashboard personalized! Please provide your business name and preferred currency below.</p>
                <form action="dashboard.php" method="POST" class="space-y-5">
                    <div>
                        <label for="business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name:</label>
                        <input type="text" id="business_name" name="business_name" required value="<?php echo htmlspecialchars($business_name ?? ''); ?>" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-medium text-gray-700 mb-1">Currency Symbol:</label>
                        <select id="currency" name="currency" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm appearance-none bg-white">
                            <option value="$" <?php echo ($currency == '$') ? 'selected' : ''; ?>>$ (USD)</option>
                            <option value="€" <?php echo ($currency == '€') ? 'selected' : ''; ?>>€ (EUR)</option>
                            <option value="£" <?php echo ($currency == '£') ? 'selected' : ''; ?>>£ (GBP)</option>
                            <option value="¥" <?php echo ($currency == '¥') ? 'selected' : ''; ?>>¥ (JPY)</option>
                            <option value="₱" <?php echo ($currency == '₱') ? 'selected' : ''; ?>>₱ (PHP)</option>
                        </select>
                    </div>
                    <button type="submit" name="submit_business_name" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-75 font-semibold transition duration-200 shadow-lg transform hover:scale-105">
                        Create Dashboard
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-white p-6 rounded-xl shadow-2xl mb-6"> 
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5">
                    <h2 class="text-4xl font-extrabold text-gray-800 mb-3 sm:mb-0">Dashboard for <span class="text-blue-600"><?php echo htmlspecialchars($business_name); ?></span></h2>
                </div>
                <p class="text-gray-700 text-xl leading-relaxed mb-6">Welcome to your executive dashboard! Get a quick overview of your key business insights at a glance.</p>
                <div id="editBusinessInfoForm" class="hidden-edit-form bg-blue-50 p-4 rounded-lg shadow-md mb-4">
                    <h3 class="text-2xl font-bold text-blue-800 mb-4 text-center">Update Business Information</h3>
                    <form action="dashboard.php" method="POST" class="space-y-5">
                        <div>
                            <label for="edit_business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name:</label>
                            <input type="text" id="edit_business_name" name="business_name" required value="<?php echo htmlspecialchars($business_name ?? ''); ?>" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm">
                        </div>
                        <div>
                            <label for="edit_currency" class="block text-sm font-medium text-gray-700 mb-1">Currency Symbol:</label>
                            <select id="edit_currency" name="currency" class="form-input w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none transition duration-200 shadow-sm appearance-none bg-white">
                                <option value="$" <?php echo ($currency == '$') ? 'selected' : ''; ?>>$ (USD)</option>
                                <option value="€" <?php echo ($currency == '€') ? 'selected' : ''; ?>>€ (EUR)</option>
                                <option value="£" <?php echo ($currency == '£') ? 'selected' : ''; ?>>£ (GBP)</option>
                                <option value="¥" <?php echo ($currency == '¥') ? 'selected' : ''; ?>>¥ (JPY)</option>
                                <option value="₱" <?php echo ($currency == '₱') ? 'selected' : ''; ?>>₱ (PHP)</option>
                            </select>
                        </div>
                        <button type="submit" name="update_business_info" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-75 font-semibold transition duration-200 shadow-lg transform hover:scale-105">
                            Update Info
                        </button>
                    </form>
                </div>

                <div class="bg-blue-50 p-5 rounded-lg shadow-md mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        <p class="text-blue-800 text-base font-medium">Account Status:
                            <span class="<?php echo ($account_status === 'active') ? 'text-green-700' : 'text-red-700'; ?>">
                                <?php echo ucfirst(htmlspecialchars($account_status)); ?>
                            </span>
                        </p>
                    </div>
                    <?php if ($expiration_date): ?>
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <p class="text-blue-800 text-base font-medium">Expires:
                            <span class="<?php echo (strtotime($expiration_date) < time()) ? 'text-red-700' : 'text-gray-800'; ?>">
                                <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($expiration_date))); ?>
                            </span>
                            <?php if (strtotime($expiration_date) < time()): ?>
                                <span class="text-red-700 text-sm ml-2">(Expired)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($scheduled_expenses_alert): ?>
                    <?php echo $scheduled_expenses_alert; ?>
                <?php endif; ?>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-purple-100 mt-8">
                    <h3 class="text-xl font-semibold text-purple-700 mb-3 text-center">Monthly Targets Overview (Current Month)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <p class="text-base font-medium text-gray-800 mb-2">Income Progress:<br>
                                <span class="text-green-600"><?php echo htmlspecialchars($currency); ?><?php echo number_format($current_month_actual_income, 2); ?></span> /
                                <span class="text-green-700"><?php echo htmlspecialchars($currency); ?><?php echo number_format($monthly_income_target, 2); ?></span>
                            </p>
                            <div class="progress-bar-container">
                                <?php
                                $income_percentage = ($monthly_income_target > 0) ? min(100, ($current_month_actual_income / $monthly_income_target) * 100) : 0;
                                $income_bar_color = ($income_percentage >= 100) ? 'bg-green-500' : 'bg-green-400';
                                ?>
                                <div class="progress-bar <?php echo $income_bar_color; ?>" style="width: <?php echo $income_percentage; ?>%;">
                                    <?php echo round($income_percentage); ?>%
                                </div>
                            </div>
                            <?php if ($current_month_actual_income >= $monthly_income_target && $monthly_income_target > 0): ?>
                                <p class="text-green-500 text-sm mt-1">Target achieved! Well done!</p>
                            <?php elseif ($monthly_income_target > 0): ?>
                                <p class="text-blue-500 text-sm mt-1">Keep going to reach your income goal!</p>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm mt-1">Set an income target to track your progress.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="text-base font-medium text-gray-800 mb-2">Expense Progress:<br>
                                <span class="text-red-600"><?php echo htmlspecialchars($currency); ?><?php echo number_format($current_month_actual_expenses, 2); ?></span> /
                                <span class="text-red-700"><?php echo htmlspecialchars($currency); ?><?php echo number_format($monthly_expense_target, 2); ?></span>
                            </p>
                            <div class="progress-bar-container">
                                <?php
                                $expense_percentage = ($monthly_expense_target > 0) ? min(100, ($current_month_actual_expenses / $monthly_expense_target) * 100) : 0;
                                $expense_bar_color = ($expense_percentage <= 100) ? 'bg-red-500' : 'bg-red-400';
                                ?>
                                <div class="progress-bar <?php echo $expense_bar_color; ?>" style="width: <?php echo $expense_percentage; ?>%;">
                                    <?php echo round($expense_percentage); ?>%
                                </div>
                            </div>
                            <?php if ($current_month_actual_expenses <= $monthly_expense_target && $monthly_expense_target > 0): ?>
                                <p class="text-green-500 text-sm mt-1">Great job staying within your expense target!</p>
                            <?php elseif ($monthly_expense_target > 0): ?>
                                <p class="text-red-500 text-sm mt-1">You've exceeded your expense target!</p>
                            <?php else: ?>
                                <p class="text-gray-500 text-sm mt-1">Set an expense target to manage your spending.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div class="relative card bg-gradient-to-br from-blue-50 to-blue-100 p-6 rounded-xl shadow-lg border border-blue-200">
                        <a href="income_input.php" class="absolute top-1/2 right-6 -translate-y-1/2 bg-blue-600 text-white text-4xl rounded-full w-14 h-14 flex items-center justify-center shadow-lg hover:bg-blue-700 transition-all duration-200 focus:outline-none" aria-label="Add">+</a>
                        <h3 class="text-xl font-semibold text-blue-700 mb-2">This Month Sales</h3>
                        <p class="text-3xl font-bold text-blue-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($current_month_actual_income, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y'); ?></p>
                    </div>
                    <div class="relative card bg-gradient-to-br from-red-50 to-red-100 p-6 rounded-xl shadow-lg border border-red-200">
                        <a href="expense_input.php" class="absolute top-1/2 right-6 -translate-y-1/2 bg-red-600 text-white text-4xl rounded-full w-14 h-14 flex items-center justify-center shadow-lg hover:bg-red-700 transition-all duration-200 focus:outline-none" aria-label="Add">+</a>
                        <h3 class="text-xl font-semibold text-red-700 mb-2">This Month Expenses</h3>
                        <p class="text-3xl font-bold text-red-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($current_month_actual_expenses, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y'); ?></p>
                    </div>
                    <div class="card bg-gradient-to-br from-green-50 to-green-100 p-6 rounded-xl shadow-lg border border-green-200">
                        <h3 class="text-xl font-semibold text-green-700 mb-2">This Month Profit</h3>
                        <p class="text-3xl font-bold text-green-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($current_month_actual_income - $current_month_actual_expenses, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y'); ?></p>
                    </div>
                </div>

                <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div class="card bg-gradient-to-br from-blue-50 to-blue-100 p-6 rounded-xl shadow-lg border border-blue-200">
                        <h3 class="text-xl font-semibold text-blue-700 mb-2">Last Month Sales</h3>
                        <p class="text-3xl font-bold text-blue-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($last_month_actual_income, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y', strtotime('last month')); ?></p>
                        <br>
                        <h3 class="text-xl font-semibold text-blue-700 mb-2">Total Sales</h3>
                        <p class="text-3xl font-bold text-blue-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($total_sales_30_days, 2); ?></p>
                    </div>
                    <div class="card bg-gradient-to-br from-red-50 to-red-100 p-6 rounded-xl shadow-lg border border-red-200">
                        <h3 class="text-xl font-semibold text-red-700 mb-2">Last Month Expenses</h3>
                        <p class="text-3xl font-bold text-red-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($last_month_actual_expenses, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y', strtotime('last month')); ?></p>
                        <br>
                        <h3 class="text-xl font-semibold text-red-700 mb-2">Total Expenses</h3>
                        <p class="text-3xl font-bold text-red-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($total_expenses_30_days, 2); ?></p>
                    </div>
                    <div class="card bg-gradient-to-br from-green-50 to-green-100 p-6 rounded-xl shadow-lg border border-green-200">
                        <h3 class="text-xl font-semibold text-green-700 mb-2">Last Month Profit</h3>
                        <p class="text-3xl font-bold text-green-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($last_month_actual_income - $last_month_actual_expenses, 2); ?></p>
                        <p class="text-gray-500 text-sm"><?php echo date('F Y', strtotime('last month')); ?></p>
                        <br>
                        <h3 class="text-xl font-semibold text-green-700 mb-2">Total Profit</h3>
                        <p class="text-3xl font-bold text-green-600 mb-1"><?php echo htmlspecialchars($currency); ?><?php echo number_format($total_profit_30_days, 2); ?></p>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mt-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5">
                        <h3 id="chartTitle" class="text-2xl font-bold text-gray-800 text-center sm:text-left mb-3 sm:mb-0">Financial Overview</h3>
                        <select id="chartPeriod" onchange="updateChartPeriod()" class="form-input px-3 py-1 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none transition duration-200 shadow-sm appearance-none bg-white w-full sm:w-auto">
                            <option value="daily">Daily View</option>
                            <option value="monthly" selected>Monthly View</option>
                            <option value="yearly">Yearly View</option>
                        </select>
                    </div>
                    <div class="chart-container">
                        <canvas id="myBusinessChart"></canvas>
                    </div>
                </div>

                <?php if ($show_inventory_overview): ?>
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-purple-100 mt-8">
                        <h3 class="text-xl font-semibold text-purple-700 mb-3 text-center">Inventory Overview</h3>
                        <div class="flex flex-col md:flex-row justify-around items-center gap-4 mb-5">
                            <p class="text-base font-medium text-gray-800">Total Products: <span class="text-blue-600 font-bold"><?php echo $total_products; ?></span></p>
                            <p class="text-base font-medium text-gray-800">Products Low on Stock:
                                <span class="<?php echo ($low_stock_products_count > 0) ? 'text-red-600 font-bold' : 'text-green-600 font-bold'; ?>">
                                    <?php echo $low_stock_products_count; ?>
                                </span>
                            </p>
                        </div>

                        <?php if ($low_stock_products_count > 0): ?>
                            <div class="bg-red-50 border-l-4 border-red-400 p-3 mb-5 rounded-md">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 text-red-700">
                                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-12a1 1 0 10-2 0v4a1 1 0 102 0V6zm0 8a1 1 0 10-2 0h2z" clip-rule="evenodd" /></svg>
                                    </div>
                                    <div class="ml-3 text-sm text-red-700">
                                        <p class="font-bold">Low Stock Alert! Please reorder:</p>
                                        <ul class="list-disc list-inside mt-1">
                                            <?php foreach ($low_stock_products_list as $product_info): ?>
                                                <li><?php echo $product_info; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($total_products > 0): ?>
                            <div class="chart-container mt-5">
                                <canvas id="inventoryStockChart"></canvas>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-gray-600 text-base">Add products to your inventory to see stock levels here.</p>
                        <?php endif; ?>

                        <div class="text-center mt-5">
                            <a href="inventory_manage.php" class="bg-purple-600 text-white px-5 py-2 rounded-full font-semibold shadow-md hover:bg-purple-700 transition duration-300 ease-in-out transform hover:scale-105">
                                Go to Inventory Management
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mt-8">
                    <h3 class="text-2xl font-bold text-gray-800 mb-5 text-center">Recent Transactions</h3>
                    <?php if (empty($recent_transactions)): ?>
                        <p class="text-center text-gray-600 text-base">No recent transactions found.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                        <th scope="col" class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                        <th scope="col" class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <tr>
                                            <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('Y-m-d H:i', strtotime(htmlspecialchars($transaction['date']))); ?></td>
                                            <td class="px-5 py-4 whitespace-nowrap text-sm font-medium <?php echo ($transaction['type'] === 'income') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo ucfirst(htmlspecialchars($transaction['type'])); ?></td>
                                            <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                                            <td class="px-5 py-4 whitespace-nowrap text-sm text-right font-semibold <?php echo ($transaction['type'] === 'income') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($currency); ?><?php echo number_format($transaction['amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>
    </main>

    <footer class="bg-gray-900 text-white p-5 text-center mt-8 shadow-inner">
        <p class="text-base">&copy; <?php echo date('Y'); ?> Executive Dashboard. All rights reserved.</p>
    </footer>

    <script>
        let myChart; // Declare main financial chart globally
        let inventoryChart; // Declare inventory chart globally

        function toggleEditForm() {
            const form = document.getElementById('editBusinessInfoForm');
            form.classList.toggle('hidden-edit-form');
            form.classList.toggle('visible-edit-form');
            document.getElementById('edit_currency').value = '<?php echo htmlspecialchars($currency); ?>';
        }

        <?php if (!empty($business_name)): ?>
        const allChartData = <?php echo $all_chart_data_json; ?>;
        const currentCurrency = '<?php echo htmlspecialchars($currency); ?>';
        const currentYear = '<?php echo date('Y'); ?>';
        const showInventoryOverview = <?php echo json_encode((bool)$show_inventory_overview); ?>;

        function renderChart(period) {
            const dataToRender = allChartData[period];
            const ctx = document.getElementById('myBusinessChart').getContext('2d');
            const chartTitle = document.getElementById('chartTitle');

            if (myChart) {
                myChart.destroy();
            }

            myChart = new Chart(ctx, {
                type: 'line',
                data: dataToRender,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { font: { size: 14 }, color: '#4b5563' } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#6b7280' } },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e5e7eb' },
                            ticks: {
                                callback: function(value) { return currentCurrency + value.toLocaleString(); },
                                color: '#6b7280'
                            }
                        }
                    }
                }
            });

            if (period === 'monthly') {
                chartTitle.textContent = 'Financial Overview (Current Year ' + currentYear + ')';
            } else if (period === 'yearly') {
                chartTitle.textContent = 'Financial Overview (Last 5 Years)';
            } else if (period === 'daily') {
                chartTitle.textContent = 'Financial Overview (Last 30 Days)';
            }
        }

        function updateChartPeriod() {
            const selectedPeriod = document.getElementById('chartPeriod').value;
            renderChart(selectedPeriod);
        }

        function renderInventoryChart() {
            if (!showInventoryOverview || allChartData.inventory_stock.labels.length === 0) return;
            const ctxInventory = document.getElementById('inventoryStockChart');
            if (!ctxInventory) return;
            const invCtx = ctxInventory.getContext('2d');
            if (inventoryChart) inventoryChart.destroy();
            inventoryChart = new Chart(invCtx, {
                type: 'bar',
                data: allChartData.inventory_stock,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: { display: true, text: 'Product Stock Levels vs. Minimum Stock', font: { size: 18 }, color: '#374151' },
                        legend: { position: 'top', labels: { font: { size: 14 }, color: '#4b5563' } }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#6b7280' } },
                        y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#6b7280' } }
                    }
                }
            });
        }
        
        // Initial chart render on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Set default selection and render chart
            document.getElementById('chartPeriod').value = 'monthly';
            renderChart('monthly');
            if (showInventoryOverview) {
                renderInventoryChart();
            }
        });

        // SweetAlert2 integration
        window.onload = function() {
            const phpSwalMessage = "<?php echo $swal_message; ?>";
            const phpSwalType = "<?php echo $swal_type; ?>";
            if (phpSwalMessage) {
                Swal.fire({
                    icon: phpSwalType,
                    title: phpSwalType.charAt(0).toUpperCase() + phpSwalType.slice(1),
                    text: phpSwalMessage,
                    confirmButtonText: 'Okay',
                    customClass: { confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg' },
                    buttonsStyling: false
                }).then(() => {
                    window.history.replaceState({}, document.title, window.location.pathname);
                });
            }
        };
        <?php endif; ?>
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