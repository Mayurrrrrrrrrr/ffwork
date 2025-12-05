"""
Views for Reports module.
Aggregates data from Expenses, Purchasing, Stock, and Tasks.
"""

from django.views.generic import TemplateView
from django.contrib.auth.mixins import LoginRequiredMixin
from django.db.models import Sum, Count, Q
from django.utils import timezone
from datetime import timedelta

from apps.expenses.models import ExpenseReport, ExpenseItem
from apps.purchasing.models import PurchaseOrder
from apps.stock.models import Product, StockTransfer
from apps.tasks.models import Task

class ReportsDashboardView(LoginRequiredMixin, TemplateView):
    template_name = 'reports/dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        today = timezone.now().date()
        last_30_days = today - timedelta(days=30)

        # --- Expenses ---
        # Total approved expenses in last 30 days
        expenses_30d = ExpenseReport.objects.filter(
            company=company, 
            status='approved',
            submitted_at__gte=last_30_days
        ).aggregate(total=Sum('total_amount'))['total'] or 0
        
        context['expenses_total'] = expenses_30d
        
        # Expense distribution by category (for chart)
        expense_by_category = ExpenseItem.objects.filter(
            report__company=company,
            report__status='approved'
        ).values('category__name').annotate(total=Sum('amount')).order_by('-total')[:5]
        
        context['expense_labels'] = [item['category__name'] for item in expense_by_category]
        context['expense_data'] = [float(item['total']) for item in expense_by_category]

        # --- Purchasing ---
        # Pending POs
        pending_pos = PurchaseOrder.objects.filter(
            company=company,
            status__in=['draft', 'pending_approval']
        ).count()
        context['pending_pos_count'] = pending_pos
        
        # Recent POs
        context['recent_pos'] = PurchaseOrder.objects.filter(
            company=company
        ).order_by('-created_at')[:5]

        # --- Stock ---
        # Low stock alerts (arbitrary threshold < 10 for demo)
        # Note: In a real app, threshold would be on Product model
        # We don't have quantity on Product, only on TransferItems. 
        # For this demo, we'll just show recent transfers.
        
        context['recent_transfers'] = StockTransfer.objects.filter(
            company=company
        ).order_by('-created_at')[:5]
        
        # --- Tasks ---
        # Task completion stats
        total_tasks = Task.objects.filter(company=company).count()
        completed_tasks = Task.objects.filter(company=company, status='done').count()
        
        context['total_tasks'] = total_tasks
        context['completed_tasks'] = completed_tasks
        context['task_completion_rate'] = int((completed_tasks / total_tasks * 100) if total_tasks > 0 else 0)
        
        return context
