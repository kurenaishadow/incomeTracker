# 📊 IncomeTracker

IncomeTracker is a **personal finance tracking system** built with **PHP** and **MySQL**.  
It allows users to record income, manage expenses, monitor savings, and track transactions.  
Admins can oversee users and system activities with dedicated tools.

---

## 🚀 Features

- **User Accounts**
  - Registration & login
  - Password management (with forced change option)
- **Income & Expense Tracking**
  - Add income and expenses
  - Manage scheduled expenses
  - Transaction history
- **Savings Management**
  - Track savings goals and progress
- **Inventory Management**
  - Optional module to manage items
- **Admin Dashboard**
  - Manage users
  - Monitor activities

---

## 📂 Project Structure

```
incomeTracker/
├── index.php                 # Landing page / login
├── register.php              # User registration
├── dashboard.php             # Main user dashboard
├── income_input.php          # Add income
├── expense_input.php         # Add expenses
├── savings.php               # Manage savings
├── transactions.php          # Transaction history
├── scheduled_expenses.php    # Recurring expenses
├── admin_dashboard.php       # Admin panel
├── admin_users.php           # Manage users
├── inventory_manage.php      # Inventory module
├── user_settings.php         # Profile & settings
├── change_password.php       # Password update
├── change_password_force.php # Forced password change
├── connections.php           # DB connection (edit this)
├── connections_sample.php    # DB connection sample
├── migration.sql             # Database schema
├── updated_migration.sql     # Updated schema
├── logout.php                # Session logout
└── .gitignore
```

---

## ⚙️ Installation

### 1. Clone the Repository
```bash
git clone https://github.com/your-username/incomeTracker.git
cd incomeTracker
```

### 2. Database Setup
- Import the schema into MySQL:
```bash
mysql -u youruser -p yourdb < migration.sql
```
- Alternatively, use `updated_migration.sql` for the latest version.

### 3. Configure Database Connection
- Copy `connections_sample.php` → `connections.php`
- Update credentials inside `connections.php`:
```php
$host = "localhost";
$user = "your_db_user";
$password = "your_db_password";
$dbname = "your_db_name";
```

### 4. Run the Application
- Place the project inside your web server directory (e.g., `htdocs` for XAMPP).
- Start Apache & MySQL.
- Open in browser:
```
http://localhost/incomeTracker
```

---

## 🔐 Default Login
- If no account exists, register via `register.php`.
- Admin accounts must be created manually in the database.

---

## 🛠 Requirements
- PHP 7.4+
- MySQL 5.7+ or MariaDB
- Apache/Nginx server (XAMPP, WAMP, or LAMP recommended)

---

## 📌 Notes
- Add `.env` or secure config in production.
- Make sure to hash passwords and secure DB before deploying publicly.
- Can be extended with APIs or a mobile app.

---

## 📸 Optional (Screenshots/Demo)
You can add screenshots by placing them in a `/screenshots` folder and embedding like:

```markdown
![Dashboard](screenshots/dashboard.png)
```

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

---

## 👨‍💻 Author
Developed with ❤️ to make income and expense tracking simple and efficient.
