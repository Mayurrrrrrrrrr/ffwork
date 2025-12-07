# Generated migration to create default roles
from django.db import migrations

def create_default_roles(apps, schema_editor):
    Role = apps.get_model('core', 'Role')
    
    default_roles = [
        ('platform_admin', 'Platform Administrator - Full system access'),
        ('admin', 'Company Administrator'),
        ('store_manager', 'Store Manager - Manage store operations'),
        ('sales', 'Sales Executive - Sales and customer facing'),
        ('accounts', 'Accounts Team - Finance and billing'),
        ('area_manager', 'Area Manager - Oversee multiple stores'),
        ('data_admin', 'Data Admin - Import/export and data management'),
        ('crm_user', 'CRM User - Customer relationship management'),
        ('logistics', 'Logistics - Stock transfers and inventory'),
        ('approver', 'Approver - Can approve expenses and requests'),
    ]
    
    for name, description in default_roles:
        Role.objects.get_or_create(name=name, defaults={'description': description})


def reverse_roles(apps, schema_editor):
    pass  # Don't delete roles on reverse


class Migration(migrations.Migration):

    dependencies = [
        ('core', '0004_module_usermodule_companymodule'),  # Adjust to your latest migration
    ]

    operations = [
        migrations.RunPython(create_default_roles, reverse_roles),
    ]
