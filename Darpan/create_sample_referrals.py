import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User
from apps.referrals.models import Candidate

try:
    company = Company.objects.first()
    user = User.objects.filter(company=company).first()
    
    if not company or not user:
        print("Missing required data (Company or User).")
        exit()

    # Create Sample Referrals
    candidates = [
        {
            'name': "Rahul Sharma",
            'email': "rahul.sharma@example.com",
            'phone': "9876543210",
            'position': "Sales Executive",
            'status': 'submitted'
        },
        {
            'name': "Priya Singh",
            'email': "priya.singh@example.com",
            'phone': "9876543211",
            'position': "Store Manager",
            'status': 'interview'
        },
        {
            'name': "Amit Patel",
            'email': "amit.patel@example.com",
            'phone': "9876543212",
            'position': "Accountant",
            'status': 'hired'
        }
    ]
    
    print(f"Creating referrals for {company.name} by {user.full_name}...")
    
    for data in candidates:
        candidate, created = Candidate.objects.get_or_create(
            company=company,
            email=data['email'],
            defaults={
                'name': data['name'],
                'phone': data['phone'],
                'position': data['position'],
                'referred_by': user,
                'status': data['status'],
                'notes': 'Sample referral data'
            }
        )
        
        if created:
            print(f"Created referral: {candidate.name} ({candidate.status})")
        else:
            print(f"Referral already exists: {candidate.name}")

    print("Sample referral data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
