# Couponix

**Couponix** is a PHP and MySQL-based coupon management and marketplace system designed for both **users and retailers**.

The system allows users to browse, manage, and purchase coupons, while retailers can manage their coupons, stock, notifications, and related activities through a dedicated retailer interface.

---

## 📂 Project Structure

```text
couponix-site/
│
├── asset/
│   ├── style.css
│   ├── theme.css
│   ├── retailer-notify.js
│   
│
├── assets/
│   ├── style.css
│   └── app.js
│
│
│   ├── User Files
│   ├── Retailer Files
│   ├── Authentication Files
│   ├── Coupon Management Files
│   ├── Cart & Purchase Files
│   └── Other Application Files
│
├── couponix-database-v3.sql
│
└── README.md
```

---

## 🎨 Assets

Couponix uses two separate asset directories.

### `asset/`

The `asset` directory contains the **retailer-side assets**:

```text
asset/
├── style.css
├── theme.css
├── retailer.css
└── notify.js
```

| File           | Purpose                             |
| -------------- | ----------------------------------- |
| `style.css`    | Main retailer-side styling          |
| `theme.css`    | Retailer theme and visual styling   |
| `retailer.css` | Retailer-specific interface styling |
| `notify.js`    | Retailer notification functionality |

### `assets/`

The `assets` directory contains the **user-side assets**:

```text
assets/
├── style.css
└── app.js
```

| File        | Purpose                            |
| ----------- | ---------------------------------- |
| `style.css` | User-side styling                  |
| `app.js`    | User-side JavaScript functionality |

---

# 📁 `couponix-site`

The `couponix-site` directory contains the main application files.

It includes the PHP files for both:

* 👤 User
* 🏪 Retailer

The directory contains the application's authentication, coupon management, cart, purchase, dashboard, notification, and other backend/frontend files.

---

# 👤 User Features

Users can:

* Register and log in
* Browse available coupons
* View coupon details
* Add coupons to cart
* Change coupon quantities
* Purchase coupons
* View purchased coupons
* View used coupons
* Manage their account
* Receive system feedback and notifications

---

# 🏪 Retailer Features

Retailers have a dedicated interface for managing their coupon business.

Retailers can:

* Register and log in
* Access the retailer dashboard
* Add coupons
* Edit coupons
* Manage coupon information
* Manage coupon stock
* Manage coupon status
* Receive notifications
* Manage retailer-related activities

---

# 🗄️ Database

Couponix uses **MySQL/MariaDB**.

The project database is provided as:

```text
couponix-database-v3.sql
```

The database contains the required tables, relationships, seed data, constraints, triggers, and business rules required by the application.

---

# 🔧 Database Setup

### 1. Start XAMPP

Start:

```text
Apache
MySQL
```

### 2. Open phpMyAdmin

```text
http://localhost/phpmyadmin
```

### 3. Import the Database

Import:

```text
couponix-database-v3.sql
```

The database should be imported before running the application.

---

# 🔐 Database Protection

Couponix includes database-level protections to maintain data integrity and prevent invalid transactions.

## 📦 Stock Triggers

Database triggers are used to manage coupon stock automatically.

When a coupon is purchased, the stock is updated according to the purchased quantity.

For example:

```text
Available Stock = 5
Purchase Quantity = 2
        ↓
Purchase Allowed
        ↓
Remaining Stock = 3
```

The system prevents purchases when the available stock is insufficient.

---

## 🚫 Stock Protection

Couponix prevents:

* Selling more coupons than available
* Negative stock
* Purchasing unavailable coupons
* Invalid quantities

Stock validation is handled through application logic and database-level protection.

---

## 🔑 Primary Key Protection

Important database tables use **PRIMARY KEY** constraints to uniquely identify records.

This prevents duplicate identifiers and helps maintain relationships between tables.

Primary keys are used for major entities such as:

* Users
* Retailers
* Coupons
* Cart records
* Purchases
* Coupon listings
* Other related records

---

## 💰 Block Free Sales

Couponix prevents invalid free or negative-price sales.

A coupon intended for sale must have a valid positive price:

```text
Price > 0
```

The database/application prevents invalid values such as:

```text
Price = 0
Price < 0
```

This ensures that coupons cannot be accidentally or intentionally sold for free through an invalid transaction.

---

# 🛒 Purchase Flow

The general purchase process is:

```text
User
  │
  ▼
Browse Coupons
  │
  ▼
Select Coupon
  │
  ▼
Add to Cart
  │
  ▼
Select Quantity
  │
  ▼
Check Stock
  │
  ▼
Validate Price
  │
  ▼
Purchase
  │
  ▼
Update Stock
  │
  ▼
Purchase Completed
```

The application and database work together to validate the purchase.

---

# 🔔 Retailer Notifications

Retailer notifications are handled through:

```text
asset/notify.js
```

The notification system can provide retailers with information about relevant activities, such as:

* Coupon activity
* Stock-related events
* Coupon approval/rejection
* Listing activity
* Other retailer actions

---

# 🧩 Technologies Used

| Technology      | Purpose                             |
| --------------- | ----------------------------------- |
| PHP             | Backend and server-side logic       |
| MySQL / MariaDB | Database                            |
| HTML            | Page structure                      |
| CSS             | User and retailer interface styling |
| JavaScript      | Frontend functionality              |
| XAMPP           | Local development environment       |
| phpMyAdmin      | Database management                 |

---

# ⚙️ Requirements

Before running Couponix, install:

* XAMPP
* Apache
* MySQL/MariaDB
* PHP 8+
* Modern web browser

---

# 🚀 Installation

### Step 1 — Clone the Repository

```bash
git clone <YOUR-GITHUB-REPOSITORY-URL>
```

### Step 2 — Move the Project

Place the project inside the XAMPP `htdocs` directory.

Example:

```text
C:\xampp\htdocs\couponix
```

### Step 3 — Start XAMPP

Start:

```text
Apache
MySQL
```

### Step 4 — Import Database

Open:

```text
http://localhost/phpmyadmin
```

Import:

```text
couponix-database-v3.sql
```

### Step 5 — Configure Database Connection

Check the database connection file inside `couponix-site` and make sure the credentials match your local MySQL configuration.

Typical XAMPP configuration:

```php
$host = "localhost";
$user = "root";
$password = "";
$database = "couponix_db";
```

Use the actual database name defined inside `couponix-database-v3.sql`.

### Step 6 — Run Couponix

Open the project through your local XAMPP server.

Example:

```text
http://localhost/couponix/couponix-site/
```

---

# 🏗️ System Structure

```text
                         COUPONIX
                            │
             ┌──────────────┴──────────────┐
             │                             │
         USER SIDE                    RETAILER SIDE
             │                             │
             ▼                             ▼
       Browse Coupons                Manage Coupons
       Shopping Cart                 Manage Stock
       Purchase                      Notifications
       Used Coupons                  Retailer Dashboard
             │                             │
             └──────────────┬──────────────┘
                            │
                            ▼
                     PHP Application
                            │
                            ▼
                      MySQL Database
                            │
                  ┌─────────┴─────────┐
                  │                   │
             Constraints           Triggers
                  │                   │
                  └─────────┬─────────┘
                            ▼
                      Data Integrity
```

---

# 🔒 Data Integrity

Couponix uses multiple levels of validation:

```text
Frontend Validation
        ↓
PHP Validation
        ↓
Database Constraints
        ↓
Database Triggers
        ↓
Purchase Validation
```

This helps protect:

* Coupon stock
* Coupon prices
* Purchase records
* User records
* Retailer records
* Database relationships

---

# 📜 License

This project is licensed under the **MIT License**.

See the `LICENSE` file for the complete license terms.

---

## 👨‍💻 Couponix

**Couponix — Coupon Management & Marketplace System**

Built with **PHP, MySQL, HTML, CSS, and JavaScript**.
