import os
import django
from datetime import date, timedelta

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User
from apps.purchasing.models import Vendor, PurchaseOrder, POItem

try:
    company = Company.objects.first()
    user = User.objects.filter(company=company).first()
    
    if not company or not user:
        print("No company or user found. Please run setup scripts first.")
        exit()

    # Create Vendors
    vendor1, created = Vendor.objects.get_or_create(
        company=company,
        name="TechSupply Solutions",
        defaults={
            'contact_person': "Rahul Sharma",
            'email': "rahul@techsupply.com",
            'phone': "9876543210",
            'address': "123, IT Park, Bangalore",
            'tax_id': "29ABCDE1234F1Z5"
        }
    )
    if created: print(f"Created vendor: {vendor1.name}")

    vendor2, created = Vendor.objects.get_or_create(
        company=company,
        name="Office Essentials Ltd",
        defaults={
            'contact_person': "Priya Singh",
            'email': "orders@officeessentials.com",
            'phone': "9876543211",
            'address': "45, Market Road, Mumbai",
            'tax_id': "27FGHIJ5678K1Z2"
        }
    )
    if created: print(f"Created vendor: {vendor2.name}")

    # Create a Draft PO
    po, created = PurchaseOrder.objects.get_or_create(
        company=company,
        user=user,
        vendor=vendor1,
        order_date=date.today(),
        defaults={
            'expected_date': date.today() + timedelta(days=7),
            'status': 'draft',
            'notes': 'Urgent requirement for new project'
        }
    )
    
    if created:
        POItem.objects.create(po=po, description="Dell Latitude Laptop", quantity=2, unit_price=65000)
        POItem.objects.create(po=po, description="Wireless Mouse", quantity=5, unit_price=850)
        print(f"Created PO: {po.po_number}")
    else:
        print(f"PO already exists: {po.po_number}")

    print("Sample purchasing data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
