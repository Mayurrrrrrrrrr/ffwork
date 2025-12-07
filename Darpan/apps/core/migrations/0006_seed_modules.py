# Generated data migration to seed initial modules

from django.db import migrations


def seed_modules(apps, schema_editor):
    """Seed the initial system modules."""
    Module = apps.get_model('core', 'Module')
    
    modules = [
        {
            'code': 'expenses',
            'name': 'Expenses',
            'description': 'Expense management and reimbursements',
            'icon': 'credit-card',
            'url_name': 'expenses:list',
            'color_class': 'primary',
            'order': 1,
        },
        {
            'code': 'btl',
            'name': 'BTL Marketing',
            'description': 'Below-the-line marketing campaigns',
            'icon': 'megaphone',
            'url_name': 'btl:list',
            'color_class': 'success',
            'order': 2,
        },
        {
            'code': 'learning',
            'name': 'Learning',
            'description': 'Training and course management',
            'icon': 'graduation-cap',
            'url_name': 'learning:catalog',
            'color_class': 'info',
            'order': 3,
        },
        {
            'code': 'purchasing',
            'name': 'Purchasing',
            'description': 'Purchase order management',
            'icon': 'shopping-cart',
            'url_name': 'purchasing:po_list',
            'color_class': 'warning',
            'order': 4,
        },
        {
            'code': 'tasks',
            'name': 'Tasks',
            'description': 'Task management and tracking',
            'icon': 'list-checks',
            'url_name': 'tasks:list',
            'color_class': 'secondary',
            'order': 5,
        },
        {
            'code': 'reports',
            'name': 'Reports',
            'description': 'Analytics and reports',
            'icon': 'bar-chart-2',
            'url_name': 'reports:dashboard',
            'color_class': 'danger',
            'order': 6,
        },
        {
            'code': 'stock',
            'name': 'Stock',
            'description': 'Inventory and stock management',
            'icon': 'package',
            'url_name': 'stock:inventory',
            'color_class': 'primary',
            'order': 7,
        },
        {
            'code': 'analytics',
            'name': 'Analytics',
            'description': 'Sales analytics and MIS dashboard',
            'icon': 'line-chart',
            'url_name': 'analytics:dashboard',
            'color_class': 'info',
            'order': 8,
        },
        {
            'code': 'tools',
            'name': 'Tools',
            'description': 'Utility tools and lookups',
            'icon': 'wrench',
            'url_name': 'tools:stock_lookup',
            'color_class': 'secondary',
            'order': 9,
        },
        {
            'code': 'referrals',
            'name': 'Referrals',
            'description': 'Employee referral program',
            'icon': 'users',
            'url_name': 'referrals:list',
            'color_class': 'success',
            'order': 10,
        },
        {
            'code': 'old_gold',
            'name': 'Old Gold',
            'description': 'Old gold purchase management',
            'icon': 'gem',
            'url_name': 'old_gold:list',
            'color_class': 'warning',
            'order': 11,
        },
        {
            'code': 'customer_referrals',
            'name': 'Affiliate Program',
            'description': 'Customer referral and affiliate program',
            'icon': 'share-2',
            'url_name': 'customer_referrals:dashboard',
            'color_class': 'primary',
            'order': 12,
        },
    ]
    
    for module_data in modules:
        Module.objects.get_or_create(
            code=module_data['code'],
            defaults=module_data
        )


def reverse_seed(apps, schema_editor):
    """Reverse: remove seeded modules."""
    Module = apps.get_model('core', 'Module')
    Module.objects.filter(code__in=[
        'expenses', 'btl', 'learning', 'purchasing', 'tasks', 
        'reports', 'stock', 'analytics', 'tools', 'referrals', 
        'old_gold', 'customer_referrals'
    ]).delete()


class Migration(migrations.Migration):

    dependencies = [
        ('core', '0005_module_allocation'),
    ]

    operations = [
        migrations.RunPython(seed_modules, reverse_seed),
    ]
