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
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
// $message = ''; // This variable is no longer directly used for displaying messages on the page.
$currency = $_SESSION['currency'] ?? '$'; // Get currency from session

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    $swal_message = "ERROR: Could not connect to database. " . $conn->connect_error;
    $swal_type = 'error';
    // For critical DB connection errors, we don't proceed with other DB operations
    // We will let the page render to display the Swal error.
} else {

    // --- Handle Form Submissions ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_product') {
            $product_name = trim($_POST['product_name'] ?? '');
            $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
            $min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
            $price = (float)($_POST['price'] ?? 0.00);

            if (empty($product_name) || $stock_quantity < 0 || $min_stock_level < 0 || $price < 0) {
                $swal_message = 'Please provide valid product details. Quantity, min stock, and price cannot be negative.';
                $swal_type = 'error';
            } else {
                // Check for duplicate product name for this user
                $sql_check_duplicate = "SELECT id FROM products WHERE user_id = ? AND product_name = ?";
                if ($stmt_check = $conn->prepare($sql_check_duplicate)) {
                    $stmt_check->bind_param('is', $user_id, $product_name);
                    $stmt_check->execute();
                    $stmt_check->store_result();
                    if ($stmt_check->num_rows > 0) {
                        $swal_message = 'A product with this name already exists for your business.';
                        $swal_type = 'warning';
                    } else {
                        $sql_insert = "INSERT INTO products (user_id, product_name, stock_quantity, min_stock_level, price) VALUES (?, ?, ?, ?, ?)";
                        if ($stmt = $conn->prepare($sql_insert)) {
                            $stmt->bind_param('isidd', $user_id, $product_name, $stock_quantity, $min_stock_level, $price);
                            if ($stmt->execute()) {
                                header('Location: inventory_manage.php?swal_message=' . urlencode('Product added successfully!') . '&swal_type=success');
                                exit();
                            } else {
                                $swal_message = 'Error adding product: ' . $stmt->error;
                                $swal_type = 'error';
                            }
                            $stmt->close();
                        } else {
                            $swal_message = 'Error preparing add product statement: ' . $conn->error;
                            $swal_type = 'error';
                        }
                    }
                    $stmt_check->close();
                } else {
                   $swal_message = 'Error preparing duplicate check: ' . $conn->error;
                   $swal_type = 'error';
                }
            }
        } elseif ($action === 'edit_product') {
            $product_id = (int)($_POST['product_id'] ?? 0);
            $product_name = trim($_POST['product_name'] ?? '');
            $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
            $min_stock_level = (int)($_POST['min_stock_level'] ?? 10);
            $price = (float)($_POST['price'] ?? 0.00);

            if ($product_id <= 0 || empty($product_name) || $stock_quantity < 0 || $min_stock_level < 0 || $price < 0) {
                $swal_message = 'Invalid product ID or details for update.';
                $swal_type = 'error';
            } else {
                // Check for duplicate product name for this user, excluding the current product being edited
                $sql_check_duplicate = "SELECT id FROM products WHERE user_id = ? AND product_name = ? AND id != ?";
                if ($stmt_check = $conn->prepare($sql_check_duplicate)) {
                    $stmt_check->bind_param('isi', $user_id, $product_name, $product_id);
                    $stmt_check->execute();
                    $stmt_check->store_result();
                    if ($stmt_check->num_rows > 0) {
                        $swal_message = 'A product with this name already exists for your business (excluding the current one).';
                        $swal_type = 'warning';
                    } else {
                        $sql_update = "UPDATE products SET product_name = ?, stock_quantity = ?, min_stock_level = ?, price = ? WHERE id = ? AND user_id = ?";
                        if ($stmt = $conn->prepare($sql_update)) {
                            $stmt->bind_param('sidddi', $product_name, $stock_quantity, $min_stock_level, $price, $product_id, $user_id);
                            if ($stmt->execute()) {
                                header('Location: inventory_manage.php?swal_message=' . urlencode('Product updated successfully!') . '&swal_type=success');
                                exit();
                            } else {
                                $swal_message = 'Error updating product: ' . $stmt->error;
                                $swal_type = 'error';
                            }
                            $stmt->close();
                        } else {
                            $swal_message = 'Error preparing update product statement: ' . $conn->error;
                            $swal_type = 'error';
                        }
                    }
                    $stmt_check->close();
                } else {
                    $swal_message = 'Error preparing duplicate check: ' . $conn->error;
                    $swal_type = 'error';
                }
            }
        } elseif ($action === 'delete_product') {
            $product_id = (int)($_POST['product_id'] ?? 0);

            if ($product_id <= 0) {
                $swal_message = 'Invalid product ID for deletion.';
                $swal_type = 'error';
            } else {
                $sql_delete = "DELETE FROM products WHERE id = ? AND user_id = ?";
                if ($stmt = $conn->prepare($sql_delete)) {
                    $stmt->bind_param('ii', $product_id, $user_id);
                    if ($stmt->execute()) {
                        header('Location: inventory_manage.php?swal_message=' . urlencode('Product deleted successfully!') . '&swal_type=success');
                        exit();
                    } else {
                        $swal_message = 'Error deleting product: ' . $stmt->error;
                        $swal_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $swal_message = 'Error preparing delete product statement: ' . $conn->error;
                    $swal_type = 'error';
                }
            }
        }
    }

    // --- Fetch Products for Display ---
    $products = [];
    $sql_fetch_products = "SELECT id, product_name, stock_quantity, min_stock_level, price FROM products WHERE user_id = ? ORDER BY product_name ASC";
    if ($stmt_fetch = $conn->prepare($sql_fetch_products)) {
        $stmt_fetch->bind_param('i', $user_id);
        if ($stmt_fetch->execute()) {
            $result = $stmt_fetch->get_result();
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
        } else {
            // Error logging for backend, user message via SweetAlert
            error_log("Error fetching products: " . $stmt_fetch->error);
            $swal_message = 'Failed to load inventory products. Please try again.';
            $swal_type = 'error';
        }
        $stmt_fetch->close();
    } else {
        error_log("Error preparing fetch products statement: " . $conn->error);
        $swal_message = 'Failed to prepare statement for fetching inventory. Please contact support.';
        $swal_type = 'error';
    }

    $conn->close();
} // End of else (DB connection successful)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Executive Dashboard</title>
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
            padding: 1.5rem;
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
        .action-button {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem; /* rounded-md */
            font-weight: 600; /* font-semibold */
            transition: all 0.2s ease-in-out;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* shadow-sm */
        }
        .action-button-edit {
            background-color: #3b82f6; /* blue-500 */
            color: white;
        }
        .action-button-edit:hover {
            background-color: #2563eb; /* blue-600 */
            transform: translateY(-1px);
        }
        .action-button-delete {
            background-color: #ef4444; /* red-500 */
            color: white;
        }
        .action-button-delete:hover {
            background-color: #dc2626; /* red-600 */
            transform: translateY(-1px);
        }
        .action-button-add {
            background-color: #22c55e; /* green-500 */
            color: white;
        }
        .action-button-add:hover {
            background-color: #16a34a; /* green-600 */
            transform: translateY(-1px);
        }
        /* Hidden form transition */
        .hidden-form-section {
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-10px);
            transition: max-height 0.5s ease-out, opacity 0.5s ease-out, transform 0.5s ease-out;
        }
        .visible-form-section {
            max-height: 600px; /* Adjust as needed */
            opacity: 1;
            transform: translateY(0);
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
            .product-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            .product-actions button {
                width: 100%;
            }
            table {
                font-size: 0.8rem;
            }
            th, td {
                padding: 0.5rem 0.75rem;
            }
            .add-product-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-gradient-to-r from-blue-600 to-blue-500 p-4 shadow-lg">
        <div class="container flex justify-between items-center nav-header">
            <h1 class="text-white text-3xl font-bold tracking-wide">Executive Dashboard</h1>
            <div class="flex items-center space-x-6 nav-user-controls">
                <span class="text-white text-lg font-medium">Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>!</span>
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
        <h2 class="text-4xl font-extrabold text-gray-800 mb-8 text-center">Inventory Management</h2>
        <!-- PHP $message output removed; SweetAlert2 will handle display -->

        <!-- Add New Product Button & Form -->
        <div class="bg-white p-6 rounded-xl shadow-lg mb-8 text-center">
            <button type="button" onclick="toggleAddProductForm()" class="add-product-btn action-button action-button-add px-8 py-3 text-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-75 transform hover:scale-105">
                Add New Product
            </button>

            <div id="addProductForm" class="hidden-form-section mt-6 p-6 bg-green-50 rounded-lg text-left shadow-inner">
                <h3 class="text-2xl font-bold text-green-800 mb-4">Add Product Details</h3>
                <form action="inventory_manage.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_product">
                    <div>
                        <label for="product_name" class="block text-sm font-medium text-gray-700 mb-1">Product Name:</label>
                        <input type="text" id="product_name" name="product_name" required
                               class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label for="stock_quantity" class="block text-sm font-medium text-gray-700 mb-1">Stock Quantity:</label>
                        <input type="number" id="stock_quantity" name="stock_quantity" min="0" required
                               class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label for="min_stock_level" class="block text-sm font-medium text-gray-700 mb-1">Minimum Stock Level:</label>
                        <input type="number" id="min_stock_level" name="min_stock_level" min="0" required
                               value="10"
                               class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-green-500">
                    </div>
                    <div>
                        <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price (<?php echo htmlspecialchars($currency); ?>):</label>
                        <input type="number" id="price" name="price" step="0.01" min="0" required
                               class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-green-500">
                    </div>
                    <button type="submit" class="action-button action-button-add w-full py-2">
                        Add Product
                    </button>
                </form>
            </div>
        </div>

        <!-- Product List Table -->
        <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
            <h3 class="text-2xl font-bold text-gray-800 mb-4 text-center">Your Products</h3>
            <?php if (empty($products)): ?>
                <p class="text-center text-gray-600 text-lg">No products found. Add some to get started!</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Min Stock</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($products as $product): ?>
                                <tr class="<?php echo ($product['stock_quantity'] <= $product['min_stock_level']) ? 'bg-red-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['product_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($product['stock_quantity']); ?>
                                        <?php if ($product['stock_quantity'] <= $product['min_stock_level']): ?>
                                            <span class="text-red-600 font-semibold text-xs ml-1">(LOW!)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($product['min_stock_level']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?php echo htmlspecialchars($currency); ?><?php echo number_format($product['price'], 2); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium product-actions">
                                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($product)); ?>)" class="action-button action-button-edit mr-2">Edit</button>
                                        <button onclick="confirmDelete(<?php echo htmlspecialchars($product['id']); ?>)" class="action-button action-button-delete">Delete</button>
                                        <form id="deleteForm_<?php echo htmlspecialchars($product['id']); ?>" action="inventory_manage.php" method="POST" class="hidden">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id']); ?>">
                                        </form>
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

    <!-- Edit Product Modal -->
    <div id="editProductModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-8 rounded-lg shadow-2xl w-full max-w-md">
            <h3 class="text-2xl font-bold text-gray-800 mb-6 text-center">Edit Product</h3>
            <form action="inventory_manage.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" id="edit_product_id" name="product_id">
                <div>
                    <label for="edit_product_name" class="block text-sm font-medium text-gray-700 mb-1">Product Name:</label>
                    <input type="text" id="edit_product_name" name="product_name" required
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_stock_quantity" class="block text-sm font-medium text-gray-700 mb-1">Stock Quantity:</label>
                    <input type="number" id="edit_stock_quantity" name="stock_quantity" min="0" required
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_min_stock_level" class="block text-sm font-medium text-gray-700 mb-1">Minimum Stock Level:</label>
                    <input type="number" id="edit_min_stock_level" name="min_stock_level" min="0" required
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_price" class="block text-sm font-medium text-gray-700 mb-1">Price (<?php echo htmlspecialchars($currency); ?>):</label>
                    <input type="number" id="edit_price" name="price" step="0.01" min="0" required
                           class="form-input w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500">
                </div>
                <div class="flex justify-end space-x-4 mt-6">
                    <button type="button" onclick="closeEditModal()" class="action-button bg-gray-300 text-gray-800 hover:bg-gray-400">Cancel</button>
                    <button type="submit" class="action-button action-button-edit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAddProductForm() {
            const form = document.getElementById('addProductForm');
            form.classList.toggle('hidden-form-section');
            form.classList.toggle('visible-form-section');
        }

        function openEditModal(product) {
            document.getElementById('edit_product_id').value = product.id;
            document.getElementById('edit_product_name').value = product.product_name;
            document.getElementById('edit_stock_quantity').value = product.stock_quantity;
            document.getElementById('edit_min_stock_level').value = product.min_stock_level;
            document.getElementById('edit_price').value = product.price;
            document.getElementById('editProductModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editProductModal').classList.add('hidden');
        }

        // SweetAlert2 confirmation for delete
        function confirmDelete(productId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626', // Red color
                cancelButtonColor: '#6b7280', // Gray color
                confirmButtonText: 'Yes, delete it!',
                customClass: {
                    confirmButton: 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg mr-2',
                    cancelButton: 'bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg',
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    // If confirmed, submit the hidden form
                    document.getElementById('deleteForm_' + productId).submit();
                }
            });
        }

        // Close modal if clicking outside (optional, but good UX)
        window.onclick = function(event) {
            const modal = document.getElementById('editProductModal');
            if (event.target == modal) {
                modal.classList.add('hidden');
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
                        confirmButton: (phpSwalType === 'success' || phpSwalType === 'info') ? 'bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg' : 'bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg',
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
