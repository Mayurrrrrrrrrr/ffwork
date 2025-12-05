import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from django.contrib.auth import get_user_model
from apps.core.models import Role

User = get_user_model()

try:
    user = User.objects.get(email='platform@admin.com')
    
    # Create admin role if it doesn't exist
    admin_role, created = Role.objects.get_or_create(
        name='admin',
        defaults={'description': 'Platform Administrator'}
    )
    
    if created:
        print("Created 'admin' role.")
    
    # Assign role to user
    if not user.roles.filter(name='admin').exists():
        user.roles.add(admin_role)
        print(f"Assigned 'admin' role to {user.email}")
    else:
        print(f"User {user.email} already has 'admin' role.")

except User.DoesNotExist:
    print("User platform@admin.com does not exist.")
