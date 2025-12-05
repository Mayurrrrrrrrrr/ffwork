import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User

try:
    # Create default company
    company, created = Company.objects.get_or_create(
        company_code='DARPAN01',
        defaults={'name': 'Darpan Tech Solutions'}
    )
    
    if created:
        print(f"Created company: {company.name}")
    else:
        print(f"Company already exists: {company.name}")
        
    # Assign superuser to this company
    user = User.objects.get(email='platform@admin.com')
    if not user.company:
        user.company = company
        user.save()
        print(f"Assigned {user.email} to {company.name}")
    else:
        print(f"User {user.email} already assigned to {user.company.name}")

except Exception as e:
    print(f"Error: {str(e)}")
