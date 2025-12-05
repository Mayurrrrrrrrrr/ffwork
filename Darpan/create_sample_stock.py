import os
import django
from datetime import date, timedelta

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User, Store
from apps.stock.models import Product, StockTransfer, TransferItem

try:
    company = Company.objects.first()
    admin_user = User.objects.filter(company=company, roles__name='admin').first()
    if not admin_user:
        admin_user = User.objects.filter(company=company).first()
        
    store1 = Store.objects.filter(company=company).first()
    
    if not store1:
        store1 = Store.objects.create(company=company, name="Main Warehouse", address="123 Logistics Way")
        print(f"Created first store: {store1.name}")

    store2 = Store.objects.filter(company=company).exclude(id=store1.id).first()
    
    if not store2:
        store2 = Store.objects.create(company=company, name="City Branch", address="456 Market St")
        print(f"Created second store: {store2.name}")
    
    if not company or not admin_user:
        print("Missing required data (Company or User).")
        exit()

    # Create Products
    p1, created = Product.objects.get_or_create(
        company=company,
        sku="PROD-001",
        defaults={
            'name': "Wireless Headphones",
            'category': "Electronics",
            'unit': "pcs",
            'description': "Noise cancelling headphones"
        }
    )
    if created: print(f"Created product: {p1.name}")

    p2, created = Product.objects.get_or_create(
        company=company,
        sku="PROD-002",
        defaults={
            'name': "USB-C Cable",
            'category': "Accessories",
            'unit': "pcs",
            'description': "2m braided cable"
        }
    )
    if created: print(f"Created product: {p2.name}")

    # Create Stock Transfer Request
    iso, created = StockTransfer.objects.get_or_create(
        company=company,
        source_store=store1,
        destination_store=store2,
        status='requested',
        defaults={
            'requested_by': admin_user,
            'notes': 'Restocking for weekend sale'
        }
    )
    
    if created:
        TransferItem.objects.create(transfer=iso, product=p1, quantity_requested=10)
        TransferItem.objects.create(transfer=iso, product=p2, quantity_requested=50)
        print(f"Created ISO: {iso.iso_number}")
    else:
        print(f"ISO already exists: {iso.iso_number}")

    print("Sample stock data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
