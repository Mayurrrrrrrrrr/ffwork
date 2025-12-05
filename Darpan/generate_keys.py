import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company
from apps.core.encryption import EncryptionManager

def generate_keys():
    print("Generating encryption keys for companies...")
    
    companies = Company.objects.all()
    count = 0
    
    for company in companies:
        if not company.encryption_key:
            print(f"Generating key for {company.name} ({company.company_code})...")
            company.encryption_key = EncryptionManager.generate_key()
            company.save()
            count += 1
        else:
            print(f"Key already exists for {company.name}")
            
    print(f"Generated keys for {count} companies.")

if __name__ == '__main__':
    generate_keys()
