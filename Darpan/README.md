# Darpan - Enterprise Management System

## Overview
Darpan is a comprehensive enterprise management system built with Django, designed to replace the legacy PHP-based "ffwork" application. It manages multi-tenant operations for jewelry retail, including Sales Analytics, Stock Management, Old Gold Purchase, Expenses, and HR/Learning.

## Key Features
- **Multi-Tenancy:** Single instance supports multiple companies with strict data isolation.
- **Sales Analytics:** Advanced dashboard with daily sales reporting, gold rate management, and trend analysis.
- **Stock Management:**
    - **Advanced Product Finder:** Filter by category, metal, size, price, and location.
    - **Stock Transfer (ISO):** Request and manage internal stock transfers between stores.
    - **Inventory:** Real-time stock tracking.
- **Old Gold Management:** Purchase entry, Bill of Supply (BOS) generation, and net weight calculations.
- **HR & Learning:** Employee onboarding, course management, and progress tracking.
- **Tools:** EMI Calculator, Scheme Calculator, Certificate Generator.
- **Role-Based Access Control:** Granular permissions for Admin, Platform Admin, Store Manager, and Employees.

## Tech Stack
- **Backend:** Python 3.12, Django 5.0
- **Database:** SQLite (Development) / MySQL (Production ready)
- **Frontend:** Bootstrap 5, Chart.js, Lucide Icons
- **Security:** Fernet Encryption for sensitive sales data.

## Setup Instructions

### 1. Prerequisites
- Python 3.12+
- Virtualenv

### 2. Installation
```bash
# Clone repository (if applicable)
cd /var/www/html/ffwork/Darpan

# Create and activate virtual environment
python3 -m venv venv
source venv/bin/activate

# Install dependencies
pip install -r requirements.txt
```

### 3. Database Setup
```bash
# Run migrations
python manage.py migrate

# Create superuser (Platform Admin)
python manage.py createsuperuser
```

### 4. Running the Server
```bash
python manage.py runserver 0.0.0.0:8000
```

## Legacy Migration Notes
This project replaces the legacy PHP codebase located in `/var/www/html/ffwork/`.
All critical features have been migrated:
- **Old Gold:** Replaces `old_gold_v2.php`.
- **Sales Reports:** Replaces `reports/sales.php` with `apps.analytics`.
- **Stock Lookup:** Replaces `productfinder.php` with `apps.tools.StockLookupView`.
- **Certificates:** Replaces `certificate_generator.php`.

## User Roles
- **Platform Admin:** Full system access, company management.
- **Admin:** Company-level administration.
- **Store Manager:** Store-level access, Stock Lookup, Transfer Requests.
- **Employee:** Basic access (Expenses, Learning, Tasks).

## Directory Structure
- `apps/`: Django apps (analytics, core, stock, old_gold, etc.)
- `config/`: Project settings and URL configuration.
- `templates/`: HTML templates organized by app.
- `static/`: CSS, JS, and images.
- `media/`: User uploads (symlinked to legacy uploads where necessary).
