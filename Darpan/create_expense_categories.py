import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company
from apps.expenses.models import ExpenseCategory

try:
    # Get the first company (created during setup/testing)
    company = Company.objects.first()
    
    if company:
        categories = [
            'Travel - Airfare',
            'Travel - Train/Bus',
            'Travel - Taxi/Cab',
            'Lodging/Hotel',
            'Food & Meals',
            'Client Entertainment',
            'Office Supplies',
            'Phone/Internet',
            'Miscellaneous'
        ]
        
        print(f"Creating categories for company: {company.name}")
        
        for cat_name in categories:
            category, created = ExpenseCategory.objects.get_or_create(
                company=company,
                name=cat_name,
                defaults={'description': f'Expenses related to {cat_name}'}
            )
            if created:
                print(f"Created category: {cat_name}")
            else:
                print(f"Category already exists: {cat_name}")
                
    else:
        print("No company found. Please create a company first.")

except Exception as e:
    print(f"Error: {str(e)}")
