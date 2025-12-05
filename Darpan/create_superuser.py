import os
import django
from django.contrib.auth import get_user_model

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

User = get_user_model()

if not User.objects.filter(email='platform@admin.com').exists():
    print("Creating superuser...")
    User.objects.create_superuser(
        email='platform@admin.com',
        password='admin_password_123',
        full_name='Platform Admin'
    )
    print("Superuser created: platform@admin.com / admin_password_123")
else:
    print("Superuser already exists.")
