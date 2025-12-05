import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import User

def verify_existing_users():
    print("Verifying existing users...")
    count = User.objects.filter(is_email_verified=False).update(is_email_verified=True)
    print(f"Updated {count} users to verified status.")

if __name__ == '__main__':
    verify_existing_users()
