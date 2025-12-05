import os
import django
from datetime import date, timedelta

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company, User
from apps.tasks.models import Task, TaskComment

try:
    company = Company.objects.first()
    admin_user = User.objects.filter(company=company, roles__name='admin').first()
    if not admin_user:
        # Fallback to any user if admin not found
        admin_user = User.objects.filter(company=company).first()
    
    if not company or not admin_user:
        print("No company or admin user found. Please run setup scripts first.")
        exit()

    # Create a task assigned to self
    task1, created = Task.objects.get_or_create(
        company=company,
        title="Review Q4 Marketing Plan",
        defaults={
            'description': "Please review the proposed budget and channels for Q4 BTL activities.",
            'assigned_to': admin_user,
            'assigned_by': admin_user,
            'due_date': date.today() + timedelta(days=2),
            'priority': 'high',
            'status': 'todo'
        }
    )
    if created: print(f"Created task: {task1.title}")

    # Add a comment
    if created:
        TaskComment.objects.create(
            task=task1,
            user=admin_user,
            comment="I will start working on this tomorrow."
        )
        print("Added comment to task.")

    # Create another task
    task2, created = Task.objects.get_or_create(
        company=company,
        title="Update Employee Handbook",
        defaults={
            'description': "Update the leave policy section.",
            'assigned_to': admin_user,
            'assigned_by': admin_user,
            'due_date': date.today() + timedelta(days=10),
            'priority': 'medium',
            'status': 'in_progress'
        }
    )
    if created: print(f"Created task: {task2.title}")

    print("Sample task data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
