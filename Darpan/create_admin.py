import os
import django

import oracledb
import sys

# Shim for Django < 5.0
try:
    oracledb.install_as_cx_oracle()
except AttributeError:
    pass
    
# Manual patch if install_as_cx_oracle is missing/fails
if "cx_Oracle" not in sys.modules:
    sys.modules["cx_Oracle"] = oracledb

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from django.contrib.auth import get_user_model

User = get_user_model()
Role = django.apps.apps.get_model('core', 'Role')

email = 'platform@admin.com'
password = 'admin'

# Create Role
role, created = Role.objects.get_or_create(name='platform_admin', defaults={'description': 'Platform Administrator'})
if created:
    print("Created 'platform_admin' role.")

if not User.objects.filter(email=email).exists():
    print(f"Creating superuser {email}...")
    user = User.objects.create_superuser(email=email, password=password)
    user.roles.add(role)
    print("Superuser created successfully and role assigned.")
else:
    print(f"Superuser {email} already exists.")
    user = User.objects.get(email=email)
    if not user.roles.filter(name='platform_admin').exists():
        user.roles.add(role)
        print("Assigned 'platform_admin' role to existing user.")
