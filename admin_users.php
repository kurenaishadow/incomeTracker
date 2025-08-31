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

// Check if the user is logged in AND is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    // If not logged in as admin, redirect to login page with an error message
    header('Location: index.php?swal_message=' . urlencode('You do not have administrative access.') . '&swal_type=error');
    exit();
}

$username = $_SESSION['username'];

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // No further processing if DB connection fails
} else {

    // --- Handle User Management Actions ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $target_user_id = $_POST['user_id'] ?? null;
        $action = $_POST['action'] ?? null; // 'toggle_status', 'set_expiration', 'set_admin', 'change_password', 'toggle_password_change'

        // IMPORTANT: Always prevent modifying the primary admin (ID 1)
        if ($target_user_id == 1 && $action !== null) {
            header('Location: admin_users.php?swal_message=' . urlencode('Cannot modify the primary admin (ID 1).') . '&swal_type=error');
            exit();
        }

        if ($target_user_id) {
            $success_message = '';
            $error_message = '';

            if ($action === 'toggle_status') {
                $current_status = $_POST['current_status'] ?? 'inactive';
                $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                $sql_update_status = "UPDATE users SET account_status = ? WHERE id = ?";
                if ($stmt = $conn->prepare($sql_update_status)) {
                    $stmt->bind_param('si', $new_status, $target_user_id);
                    if ($stmt->execute()) {
                        $success_message = 'User account status updated to ' . ucfirst($new_status) . ' successfully!';
                    } else {
                        $error_message = 'Error updating status: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = 'Error preparing toggle status statement: ' . $conn->error;
                }
            } elseif ($action === 'set_expiration') {
                $expiration_datetime_str = $_POST['expiration_datetime'] ?? null;
                $expiration_datetime = null;
                if (!empty($expiration_datetime_str)) {
                    $expiration_datetime = date('Y-m-d H:i:s', strtotime($expiration_datetime_str));
                }

                $sql_update_exp = "UPDATE users SET expiration_date = ? WHERE id = ?";
                if ($stmt = $conn->prepare($sql_update_exp)) {
                    $stmt->bind_param('si', $expiration_datetime, $target_user_id);
                    if ($stmt->execute()) {
                        $success_message = 'User expiration date updated successfully!';
                    } else {
                        $error_message = 'Error updating expiration date: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = 'Error preparing set expiration statement: ' . $conn->error;
                }
            } elseif ($action === 'set_admin') {
                $is_admin_value = (int)($_POST['is_admin_value'] ?? 0); // 0 or 1
                $sql_update_admin = "UPDATE users SET is_admin = ? WHERE id = ?";
                if ($stmt = $conn->prepare($sql_update_admin)) {
                    $stmt->bind_param('ii', $is_admin_value, $target_user_id);
                    if ($stmt->execute()) {
                        $success_message = 'User admin status updated successfully!';
                    } else {
                        $error_message = 'Error updating admin status: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = 'Error preparing set admin statement: ' . $conn->error;
                }
            } elseif ($action === 'change_password') {
                $new_password = $_POST['new_password'] ?? '';
                if (!empty($new_password) && strlen($new_password) >= 6) {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql_update_password = "UPDATE users SET password_hash = ?, needs_password_change = 0 WHERE id = ?";
                    if ($stmt = $conn->prepare($sql_update_password)) {
                        $stmt->bind_param('si', $new_password_hash, $target_user_id);
                        if ($stmt->execute()) {
                            $success_message = 'User password changed successfully!';
                        } else {
                            $error_message = 'Error changing password: ' . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = 'Error preparing change password statement: ' . $conn->error;
                    }
                } else {
                    $error_message = 'New password must be at least 6 characters long.';
                }
            } elseif ($action === 'toggle_password_change') {
                $current_needs_password_change = (int)($_POST['current_needs_password_change'] ?? 0);
                $new_needs_password_change = ($current_needs_password_change == 1) ? 0 : 1;
                $sql_update_needs_pwd = "UPDATE users SET needs_password_change = ? WHERE id = ?";
                if ($stmt = $conn->prepare($sql_update_needs_pwd)) {
                    $stmt->bind_param('ii', $new_needs_password_change, $target_user_id);
                    if ($stmt->execute()) {
                        $success_message = 'User password change requirement updated!';
                    } else {
                        $error_message = 'Error updating password change requirement: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = 'Error preparing toggle password change statement: ' . $conn->error;
                }
            }

            // Redirect with SweetAlert2 message after action
            if (!empty($success_message)) {
                header('Location: admin_users.php?swal_message=' . urlencode($success_message) . '&swal_type=success');
                exit();
            } elseif (!empty($error_message)) {
                header('Location: admin_users.php?swal_message=' . urlencode($error_message) . '&swal_type=error');
                exit();
            }
        }
    }

    // --- Fetch All Users ---
    $users = [];
    $sql_fetch_users = "SELECT id, username, email, is_admin, account_status, expiration_date, needs_password_change FROM users ORDER BY id ASC";
    if ($result = $conn->query($sql_fetch_users)) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $result->free();
    } else {
        $swal_message = 'Error fetching users: ' . $conn->error;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Dashboard</title>
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
        .form-input {
            transition: all 0.2s ease-in-out;
            padding: 0.5rem 0.75rem; /* Smaller padding for table inputs */
            font-size: 0.875rem; /* Smaller font for table inputs */
            border-radius: 0.375rem; /* rounded-md */
        }
        .form-input:focus {
            border-color: #3b82f6; /* Blue ring */
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); /* Blue shadow */
        }
        /* Dropdown styles */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 200px; /* Adjust width as needed */
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 10;
            border-radius: 0.5rem;
            overflow: hidden; /* For rounded corners on items */
            right: 0; /* Align dropdown to the right of the button */
        }
        .dropdown-content button {
            color: black;
            padding: 8px 16px;
            text-decoration: none;
            display: block;
            width: 100%;
            text-align: left;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }
        .dropdown-content button:hover {
            background-color: #e2e8f0;
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }
        /* Adjusted button styles for actions within dropdown */
        .action-dropdown-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .action-dropdown-item .action-button-mini {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border-radius: 0.3rem;
            font-weight: 500;
            white-space: nowrap;
        }
        /* Specific colors for action buttons within dropdown */
        .btn-toggle-status-active { background-color: #ef4444; } /* red-500 */
        .btn-toggle-status-active:hover { background-color: #dc2626; } /* red-600 */
        .btn-toggle-status-inactive { background-color: #22c55e; } /* green-500 */
        .btn-toggle-status-inactive:hover { background-color: #16a34a; } /* green-600 */
        .btn-set-admin { background-color: #8b5cf6; } /* purple-500 */
        .btn-set-admin:hover { background-color: #7c3aed; } /* purple-600 */
        .btn-remove-admin { background-color: #f97316; } /* orange-500 */
        .btn-remove-admin:hover { background-color: #ea580c; } /* orange-600 */
        .btn-change-pass { background-color: #4f46e5; } /* indigo-600 */
        .btn-change-pass:hover { background-color: #4338ca; } /* indigo-700 */
        .btn-set-expiration { background-color: #3b82f6; } /* blue-500 */
        .btn-set-expiration:hover { background-color: #2563eb; } /* blue-600 */
        .btn-toggle-pass-req-true { background-color: #eab308; } /* yellow-500 */
        .btn-toggle-pass-req-true:hover { background-color: #d97706; } /* yellow-600 */
        .btn-toggle-pass-req-false { background-color: #3b82f6; } /* blue-500 */
        .btn-toggle-pass-req-false:hover { background-color: #2563eb; } /* blue-600 */


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
            .user-table th, .user-table td {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
            .user-table .actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .user-table .actions button, .user-table .actions input[type="datetime-local"], .user-table .actions input[type="password"] {
                width: 100%;
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
                <a href="admin_dashboard.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Admin Home
                </a>
                <a href="dashboard.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    User Dashboard
                </a>
                <a href="logout.php" class="nav-link bg-white text-blue-700 px-5 py-2 rounded-full font-semibold shadow-md hover:bg-blue-50 transition duration-300 ease-in-out transform">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-2">
        <h2 class="text-4xl font-extrabold text-gray-800 mb-8 text-center">Manage Users</h2>
        <!-- SweetAlert2 messages will be displayed by the JS at the bottom -->

        <div class="bg-white p-6 rounded-xl shadow-lg">
            <?php if (empty($users)): ?>
                <p class="text-center text-gray-600 text-lg">No users registered yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 user-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiration</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin?</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pass Change Req?</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <tr class="odd:bg-white even:bg-gray-50 hover:bg-blue-100 transition-colors duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo ($user['account_status'] === 'active') ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo ucfirst(htmlspecialchars($user['account_status'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $user['expiration_date'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($user['expiration_date']))) : 'N/A'; ?>
                                        <?php if ($user['expiration_date'] && strtotime($user['expiration_date']) < time()): ?>
                                            <span class="text-red-500 text-xs">(Expired)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $user['is_admin'] ? 'Yes' : 'No'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $user['needs_password_change'] ? 'Yes' : 'No'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <?php if ($user['id'] != 1): // Actions for all users except primary admin ?>
                                            <div class="dropdown">
                                                <button class="px-4 py-2 bg-blue-500 text-white rounded-md font-semibold hover:bg-blue-600 transition duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-75">
                                                    Actions <span class="ml-1">&#9660;</span>
                                                </button>
                                                <div class="dropdown-content">
                                                    <!-- Toggle Status -->
                                                    <button onclick="handleAdminAction(<?php echo htmlspecialchars($user['id']); ?>, 'toggle_status', '<?php echo htmlspecialchars($user['account_status']); ?>')"
                                                            class="action-dropdown-item <?php echo ($user['account_status'] === 'active') ? 'btn-toggle-status-active' : 'btn-toggle-status-inactive'; ?> text-white">
                                                        <?php echo ($user['account_status'] === 'active') ? 'Deactivate' : 'Activate'; ?> User
                                                    </button>

                                                    <!-- Set Expiration Date -->
                                                    <button onclick="handleAdminAction(<?php echo htmlspecialchars($user['id']); ?>, 'set_expiration', '<?php echo htmlspecialchars($user['expiration_date'] ? date('Y-m-d\TH:i', strtotime($user['expiration_date'])) : ''); ?>')"
                                                            class="action-dropdown-item btn-set-expiration text-white">
                                                        Set/Update Expiration
                                                    </button>

                                                    <!-- Change Password -->
                                                    <button onclick="handleAdminAction(<?php echo htmlspecialchars($user['id']); ?>, 'change_password')"
                                                            class="action-dropdown-item btn-change-pass text-white">
                                                        Change Password
                                                    </button>

                                                    <!-- Toggle Admin Status -->
                                                    <button onclick="handleAdminAction(<?php echo htmlspecialchars($user['id']); ?>, 'set_admin', '<?php echo htmlspecialchars($user['is_admin']); ?>')"
                                                            class="action-dropdown-item <?php echo $user['is_admin'] ? 'btn-remove-admin' : 'btn-set-admin'; ?> text-white">
                                                        <?php echo $user['is_admin'] ? 'Remove Admin' : 'Make Admin'; ?>
                                                    </button>

                                                    <!-- Toggle Password Change Requirement -->
                                                    <button onclick="handleAdminAction(<?php echo htmlspecialchars($user['id']); ?>, 'toggle_password_change', '<?php echo htmlspecialchars($user['needs_password_change']); ?>')"
                                                            class="action-dropdown-item <?php echo ($user['needs_password_change'] == 1) ? 'btn-toggle-pass-req-true' : 'btn-toggle-pass-req-false'; ?> text-white">
                                                        <?php echo ($user['needs_password_change'] == 1) ? 'Remove Pass Change Req' : 'Require Pass Change'; ?>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">Admin Actions Locked</span>
                                        <?php endif; ?>
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
        <p class="text-lg">&copy; <?php echo date('Y'); ?> Executive Dashboard Admin. All rights reserved.</p>
    </footer>

    <script>
        // Global function to handle admin actions via SweetAlert2
        async function handleAdminAction(userId, actionType, currentValue = null) {
            const adminIdLockedMessage = 'Cannot modify the primary admin (ID 1).';
            if (userId === 1) { // Client-side check for primary admin
                Swal.fire({
                    icon: 'error',
                    title: 'Action Forbidden',
                    text: adminIdLockedMessage,
                    confirmButtonText: 'Okay',
                    customClass: {
                        confirmButton: 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                });
                return;
            }

            let title = '';
            let text = '';
            let icon = 'question';
            let confirmButtonText = '';
            let inputOptions = {}; // For SweetAlert2 input types
            let inputValue = currentValue; // Default input value

            switch (actionType) {
                case 'toggle_status':
                    const newStatus = (currentValue === 'active') ? 'inactive' : 'active';
                    title = `Confirm ${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)} User?`;
                    text = `Are you sure you want to set this user's account status to '${newStatus}'?`;
                    confirmButtonText = `Yes, ${newStatus} it!`;
                    icon = 'warning';
                    break;
                case 'set_expiration':
                    title = 'Set/Update Account Expiration';
                    text = 'Enter the new expiration date and time for this user.';
                    icon = 'info';
                    inputOptions = {
                        input: 'datetime-local',
                        inputValue: currentValue,
                        showCancelButton: true,
                        confirmButtonText: 'Set Expiration',
                        cancelButtonText: 'Cancel',
                        reverseButtons: true,
                        preConfirm: (value) => {
                            if (!value) {
                                Swal.showValidationMessage('Please select a date and time.');
                            }
                            return value;
                        }
                    };
                    break;
                case 'change_password':
                    title = 'Change User Password';
                    text = 'Enter the new password for this user (at least 6 characters).';
                    icon = 'warning';
                    inputOptions = {
                        input: 'password',
                        inputPlaceholder: 'New Password (min 6 chars)',
                        inputAttributes: {
                            minlength: 6,
                            required: true
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Change Password',
                        cancelButtonText: 'Cancel',
                        reverseButtons: true,
                        preConfirm: (value) => {
                            if (!value || value.length < 6) {
                                Swal.showValidationMessage('Password must be at least 6 characters long.');
                            }
                            return value;
                        }
                    };
                    break;
                case 'set_admin':
                    const newAdminStatus = (currentValue == '1') ? 'Remove' : 'Make';
                    title = `Confirm ${newAdminStatus} Admin?`;
                    text = `Are you sure you want to ${newAdminStatus.toLowerCase()} admin privileges for this user?`;
                    confirmButtonText = `Yes, ${newAdminStatus} Admin!`;
                    icon = (newAdminStatus === 'Remove') ? 'warning' : 'info';
                    break;
                case 'toggle_password_change':
                    const newPassChangeReq = (currentValue == '1') ? 'Remove' : 'Require';
                    title = `${newPassChangeReq} Password Change Requirement?`;
                    text = `Are you sure you want to ${newPassChangeReq.toLowerCase()} a password change for this user?`;
                    confirmButtonText = `Yes, ${newPassChangeReq} it!`;
                    icon = 'info';
                    break;
                default:
                    console.error('Unknown action type:', actionType);
                    return;
            }

            let result;
            if (Object.keys(inputOptions).length > 0) {
                result = await Swal.fire({
                    title: title,
                    text: text,
                    icon: icon,
                    ...inputOptions, // Spread input-specific options
                    customClass: {
                        confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg mr-2',
                        cancelButton: 'bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                });
                inputValue = result.value; // Get the input value
            } else {
                result = await Swal.fire({
                    title: title,
                    text: text,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonColor: (actionType === 'toggle_status' && currentValue === 'active') || (actionType === 'set_admin' && currentValue === '1') ? '#dc2626' : '#3085d6', // Red for deactivate/remove admin, blue otherwise
                    cancelButtonColor: '#d33',
                    confirmButtonText: confirmButtonText,
                    customClass: {
                        confirmButton: 'bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg mr-2',
                        cancelButton: 'bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg',
                    },
                    buttonsStyling: false
                });
            }

            if (result.isConfirmed && (Object.keys(inputOptions).length === 0 || inputValue)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'admin_users.php';
                form.style.display = 'none'; // Hide the form

                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                form.appendChild(userIdInput);

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = actionType;
                form.appendChild(actionInput);

                if (actionType === 'toggle_status') {
                    const currentStatusInput = document.createElement('input');
                    currentStatusInput.type = 'hidden';
                    currentStatusInput.name = 'current_status';
                    currentStatusInput.value = currentValue;
                    form.appendChild(currentStatusInput);
                } else if (actionType === 'set_expiration') {
                    const expirationInput = document.createElement('input');
                    expirationInput.type = 'hidden';
                    expirationInput.name = 'expiration_datetime';
                    expirationInput.value = inputValue; // The value from SweetAlert2 input
                    form.appendChild(expirationInput);
                } else if (actionType === 'change_password') {
                    const newPasswordInput = document.createElement('input');
                    newPasswordInput.type = 'hidden';
                    newPasswordInput.name = 'new_password';
                    newPasswordInput.value = inputValue; // The value from SweetAlert2 input
                    form.appendChild(newPasswordInput);
                } else if (actionType === 'set_admin') {
                    const newAdminValue = (currentValue == '1') ? '0' : '1';
                    const adminInput = document.createElement('input');
                    adminInput.type = 'hidden';
                    adminInput.name = 'is_admin_value';
                    adminInput.value = newAdminValue;
                    form.appendChild(adminInput);
                } else if (actionType === 'toggle_password_change') {
                    const currentNeedsPassChangeInput = document.createElement('input');
                    currentNeedsPassChangeInput.type = 'hidden';
                    currentNeedsPassChangeInput.name = 'current_needs_password_change';
                    currentNeedsPassChangeInput.value = currentValue;
                    form.appendChild(currentNeedsPassChangeInput);
                }

                document.body.appendChild(form);
                form.submit();
            }
        }

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
