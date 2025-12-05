import os
import django
import random

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, Store
from apps.stock.models import Product, Inventory

try:
    company = Company.objects.first()
    stores = Store.objects.filter(company=company)
    products = Product.objects.filter(company=company)
    
    if not company or not stores.exists() or not products.exists():
        print("Missing required data (Company, Stores, or Products).")
        exit()

    print(f"Updating inventory for {company.name}...")
    
    for store in stores:
        for product in products:
            # Random quantity between 0 and 50
            qty = random.randint(0, 50)
            
            inventory, created = Inventory.objects.update_or_create(
                company=company,
                store=store,
                product=product,
                defaults={'quantity': qty}
            )
            
            action = "Created" if created else "Updated"
            print(f"{action} inventory: {product.name} in {store.name} = {qty}")

    print("Sample inventory data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
