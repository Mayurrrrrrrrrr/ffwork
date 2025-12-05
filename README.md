# Darpan - Enterprise Work Portal

Darpan is a comprehensive, multi-tenant enterprise work management portal designed to streamline business operations. It integrates various modules including Expense Management, Purchasing, Task Tracking, Learning Management, and Business-Below-The-Line (BTL) marketing activities into a single, unified platform.

## 🚀 Features

*   **Multi-Tenancy**: Secure isolation of data for different companies/tenants.
*   **Role-Based Access Control (RBAC)**: Granular permissions for Admins, Managers, and Employees.
*   **Dashboard**: Real-time overview of pending tasks, expenses, and key metrics.
*   **Expense Management**: Submit, review, and approve expense reports with receipt uploads.
*   **Purchasing**: Manage vendors, purchase orders (POs), and stock entries.
*   **Inventory & Stock**: Track stock levels across multiple stores/locations.
*   **Learning Management System (LMS)**: Create courses, quizzes, and track employee training progress.
*   **BTL Marketing**: Manage marketing proposals, execution, and verification.
*   **Task Management**: Assign, track, and complete tasks with priority levels.
*   **Old Gold Management**: Specialized module for tracking old gold exchange and valuation.
*   **Analytics**: Visual reports and data import capabilities.

## 🛠️ Technology Stack

*   **Backend**: Django 4.2 (Python 3.12)
*   **Database**: MySQL 8.0
*   **Frontend**: HTML5, Bootstrap 5, JavaScript
*   **Deployment**: Nginx, Gunicorn, Linux (Ubuntu)
*   **Security**: SSL/TLS (Let's Encrypt), CSRF Protection, Encrypted Data Fields

## 📦 Installation & Setup

### Prerequisites
*   Python 3.12+
*   MySQL Server
*   Git

### Local Development Setup

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/Mayurrrrrrrrrr/ffwork.git
    cd ffwork/Darpan
    ```

2.  **Create and activate a virtual environment:**
    ```bash
    python3 -m venv venv
    source venv/bin/activate  # On Windows: venv\Scripts\activate
    ```

3.  **Install dependencies:**
    ```bash
    pip install -r requirements.txt
    ```

4.  **Configure Environment Variables:**
    *   Copy `.env.example` to `.env`.
    *   Update database credentials and other settings in `.env`.

5.  **Run Database Migrations:**
    ```bash
    python manage.py migrate
    ```

6.  **Create a Superuser:**
    ```bash
    python manage.py createsuperuser
    ```

7.  **Start the Development Server:**
    ```bash
    python manage.py runserver
    ```
    Visit `http://localhost:8000` in your browser.

## 📚 Documentation

*   [User Guide](docs/USER_GUIDE.md)
*   [Privacy Policy](docs/PRIVACY_POLICY.md)
*   [Contact Information](docs/CONTACT.md)

## 🔒 Security

This project uses standard Django security features.
*   **Debug Mode**: Disabled in production.
*   **HTTPS**: Enforced via Nginx and Let's Encrypt.
*   **Data Protection**: Sensitive company data is logically isolated.

## 📄 License

Proprietary Software. All rights reserved.
