"""
Views for dashboard module.
Portal home with pending actions, celebrations, and announcements.
"""

from django.shortcuts import render
from django.contrib.auth.decorators import login_required
from django.db.models import Count, Q, Sum, Max, F
from datetime import date, timedelta
from apps.core.models import User, Announcement
from apps.core.utils import check_role, has_any_role


@login_required
def portal_home(request):
    """
    Main dashboard/portal home view.
    Shows pending actions, birthdays, anniversaries, and announcements.
    """
    user = request.user
    company = user.company
    
    # Get current month and day for celebrations
    current_month = date.today().month
    current_day = date.today().day
    
    # Fetch birthdays this month
    birthdays_today = []
    birthdays_this_month = []
    
    if company:
        birthdays = User.objects.filter(
            company=company,
            dob__month=current_month
        ).exclude(dob__isnull=True).order_by('dob__day')
        
        for person in birthdays:
            if person.dob.day == current_day:
                birthdays_today.append(person)
            else:
                birthdays_this_month.append(person)
    
    # Fetch anniversaries this month
    anniversaries_today = []
    anniversaries_this_month = []
    
    if company:
        anniversaries = User.objects.filter(
            company=company,
            doj__month=current_month
        ).exclude(doj__isnull=True).order_by('doj__day')
        
        for person in anniversaries:
            # Calculate years of service
            years = date.today().year - person.doj.year
            if years > 0:  # Only show if they have at least 1 year
                person.years_of_service = years
                if person.doj.day == current_day:
                    anniversaries_today.append(person)
                else:
                    anniversaries_this_month.append(person)
    
    # Fetch announcements
    announcements = []
    if company:
        announcements = Announcement.objects.filter(
            company=company,
            is_active=True
        ).order_by('-post_date')[:3]
    
    # ============== REAL KPI DATA ==============
    total_revenue = 0
    sales_count = 0
    stock_value = 0
    tasks_complete = 0
    tasks_pending = 0
    
    if company:
        # Get sales revenue from analytics
        try:
            from apps.analytics.models import SalesRecord, StockSnapshot
            
            # Sales data - this month
            month_start = date.today().replace(day=1)
            sales_qs = SalesRecord.objects.filter(
                company=company,
                transaction_type='sale',
                transaction_date__gte=month_start
            )
            total_revenue = sales_qs.aggregate(total=Sum('final_amount'))['total'] or 0
            sales_count = sales_qs.count()
            
            # Stock value
            stock_qs = StockSnapshot.objects.filter(company=company)
            latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
            if latest_date:
                stock_value = stock_qs.filter(snapshot_date=latest_date).aggregate(
                    total=Sum(F('quantity') * F('sale_price'))
                )['total'] or 0
        except:
            pass
        
        # Get tasks data
        try:
            from apps.tasks.models import Task
            user_tasks = Task.objects.filter(assigned_to=user)
            tasks_complete = user_tasks.filter(status='completed').count()
            tasks_pending = user_tasks.exclude(status='completed').count()
        except:
            pass
    
    # Calculate pending actions
    pending_reports_count = 0
    reports_to_verify_count = 0
    pending_btl_count = 0
    pending_po_count = 0
    pending_stock_incoming_count = 0
    pending_stock_shipped_count = 0
    
    total_pending_actions = (
        pending_reports_count + 
        reports_to_verify_count + 
        pending_btl_count + 
        pending_po_count + 
        pending_stock_incoming_count + 
        pending_stock_shipped_count +
        tasks_pending
    )
    
    # Check user roles
    is_admin = user.has_role('admin')
    is_approver = user.has_role('approver')
    is_btl_approver = user.has_any_role(['marketing_manager', 'admin'])
    is_order_team = user.has_any_role(['order_team', 'admin'])
    is_purchase_team = user.has_any_role(['purchase_team', 'admin'])
    can_manage_referrals = user.has_any_role(['admin', 'accounts', 'approver', 'sales_team'])
    
    context = {
        'page_title': 'Portal Home',
        'birthdays_today': birthdays_today,
        'birthdays_this_month': birthdays_this_month,
        'total_birthdays': len(birthdays_today) + len(birthdays_this_month),
        'anniversaries_today': anniversaries_today,
        'anniversaries_this_month': anniversaries_this_month,
        'total_anniversaries': len(anniversaries_today) + len(anniversaries_this_month),
        'announcements': announcements,
        'total_pending_actions': total_pending_actions,
        'pending_reports_count': pending_reports_count,
        'reports_to_verify_count': reports_to_verify_count,
        'pending_btl_count': pending_btl_count,
        'pending_po_count': pending_po_count,
        'pending_stock_incoming_count': pending_stock_incoming_count,
        'pending_stock_shipped_count': pending_stock_shipped_count,
        'is_admin': is_admin,
        'is_approver': is_approver,
        'is_btl_approver': is_btl_approver,
        'is_order_team': is_order_team,
        'is_purchase_team': is_purchase_team,
        'can_manage_referrals': can_manage_referrals,
        # Real KPI data
        'total_revenue': total_revenue,
        'sales_count': sales_count,
        'stock_value': stock_value,
        'tasks_complete': tasks_complete,
        'tasks_pending': tasks_pending,
    }
    
    return render(request, 'dashboard/portal_home.html', context)
