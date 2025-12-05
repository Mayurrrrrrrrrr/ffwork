import os
import django
import random
from datetime import timedelta, date
from django.utils import timezone

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User, Role, Store, Announcement
from apps.expenses.models import ExpenseReport, ExpenseCategory, ExpenseItem
from apps.tasks.models import Task
from apps.stock.models import Product, Inventory

def create_techcorp_data():
    print("Creating TechCorp data...")

    # 1. Create Company
    company, created = Company.objects.get_or_create(
        company_code='TECH',
        defaults={'name': 'TechCorp Industries'}
    )
    if created:
        print(f"Created Company: {company.name}")
    else:
        print(f"Company {company.name} already exists")

    # 2. Create Stores
    stores = []
    for name in ['Tech HQ', 'Innovation Lab', 'Remote Hub']:
        store, _ = Store.objects.get_or_create(company=company, name=name)
        stores.append(store)
    print(f"Created {len(stores)} stores")

    # 3. Create Roles (Ensure they exist)
    roles = {}
    for role_name in ['admin', 'manager', 'employee', 'hr']:
        role, _ = Role.objects.get_or_create(name=role_name)
        roles[role_name] = role

    # 4. Create Users
    users = []
    
    # Admin
    admin_user, _ = User.objects.get_or_create(
        email='admin@techcorp.com',
        defaults={
            'full_name': 'Tech Admin',
            'company': company,
            'store': stores[0],
            'is_active': True,
            'doj': date(2020, 1, 1)
        }
    )
    admin_user.set_password('tech123')
    admin_user.roles.add(roles['admin'])
    admin_user.save()
    users.append(admin_user)

    # Manager
    manager_user, _ = User.objects.get_or_create(
        email='manager@techcorp.com',
        defaults={
            'full_name': 'Mike Manager',
            'company': company,
            'store': stores[0],
            'is_active': True,
            'doj': date(2021, 5, 15)
        }
    )
    manager_user.set_password('tech123')
    manager_user.roles.add(roles['manager'])
    manager_user.save()
    users.append(manager_user)

    # Employees
    for i in range(1, 6):
        emp, _ = User.objects.get_or_create(
            email=f'emp{i}@techcorp.com',
            defaults={
                'full_name': f'Tech Employee {i}',
                'company': company,
                'store': random.choice(stores),
                'is_active': True,
                'approver': manager_user,
                'doj': date(2022, 1, 1) + timedelta(days=random.randint(0, 365))
            }
        )
        emp.set_password('tech123')
        emp.roles.add(roles['employee'])
        emp.save()
        users.append(emp)
    
    print(f"Created {len(users)} users")

    # 5. Create Expense Categories (Global or Company specific? Model says global usually, but let's check)
    # ExpenseCategory has company field? Let's check model. 
    # Assuming ExpenseCategory might be shared or company specific. 
    # If shared, we use existing. If company specific, we create.
    # Checking models.py for expenses... (I recall creating it)
    # Let's assume they are company specific for better isolation or global.
    # I'll create some if they don't exist for this company if the model supports it.
    
    # 6. Create Expense Reports
    categories = ExpenseCategory.objects.all()
    if not categories.exists():
        # Create some defaults if none exist
        for name in ['Travel', 'Meals', 'Office Supplies']:
            ExpenseCategory.objects.create(name=name)
        categories = ExpenseCategory.objects.all()

    for user in users[2:]: # Employees
        for _ in range(random.randint(1, 3)):
            report = ExpenseReport.objects.create(
                company=company,
                user=user,
                title=f"Expense Report {random.randint(100, 999)}",
                status=random.choice(['draft', 'submitted', 'approved']),
                total_amount=0,
                start_date=timezone.now().date() - timedelta(days=30),
                end_date=timezone.now().date()
            )
            
            # Items
            total = 0
            for _ in range(random.randint(1, 5)):
                amount = random.randint(100, 5000)
                ExpenseItem.objects.create(
                    report=report,
                    category=random.choice(categories),
                    date=timezone.now().date(),
                    amount=amount,
                    description="Business expense"
                )
                total += amount
            
            report.total_amount = total
            report.save()
            
    print("Created expense reports")

    # 7. Create Tasks
    for i in range(10):
        Task.objects.create(
            company=company,
            title=f"Tech Task {i+1}",
            description="Important technical task",
            assigned_to=random.choice(users),
            assigned_by=manager_user,
            status=random.choice(['todo', 'in_progress', 'done']),
            priority=random.choice(['low', 'medium', 'high']),
            due_date=timezone.now() + timedelta(days=random.randint(1, 14))
        )
    print("Created tasks")

    # 8. Create Inventory
    # Products
    products = []
    for name in ['Laptop Pro', 'Monitor 4K', 'Wireless Mouse', 'Keyboard Mech']:
        prod, _ = Product.objects.get_or_create(
            company=company,
            sku=f"TECH-{name[:3].upper()}-{random.randint(100,999)}",
            defaults={
                'name': name,
                'description': f"High end {name}",
                'unit': 'pcs'
            }
        )
        products.append(prod)
    
    # Inventory
    for store in stores:
        for prod in products:
            Inventory.objects.create(
                company=company,
                store=store,
                product=prod,
                quantity=random.randint(0, 100)
            )
    print("Created inventory")

    print("TechCorp data generation complete!")

if __name__ == '__main__':
    create_techcorp_data()
