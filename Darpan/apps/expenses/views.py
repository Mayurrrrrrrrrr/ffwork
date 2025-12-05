"""
Views for Employee Expenses module.
"""

from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from django.contrib.auth.mixins import LoginRequiredMixin
from django.views.generic import ListView, DetailView, CreateView, UpdateView, View
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils import timezone
from django.db.models import Q, Sum
from django.db import transaction

from .models import ExpenseReport, ExpenseItem
from .forms import ExpenseReportForm, ExpenseItemFormSet, ApprovalForm
from apps.core.utils import log_audit_action

class ExpenseListView(LoginRequiredMixin, ListView):
    """
    List view for expenses.
    Shows 'My Expenses' and 'To Approve' based on context.
    """
    model = ExpenseReport
    template_name = 'expenses/list.html'
    context_object_name = 'reports'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        view_type = self.request.GET.get('view', 'my')
        
        if view_type == 'approve':
            # Show reports waiting for this user's approval
            return ExpenseReport.objects.filter(
                current_approver=user,
                status='submitted'
            ).select_related('user', 'company')
        elif view_type == 'team':
            # Show reports from team members (if manager)
            # This logic depends on hierarchy, for now showing direct reports
            return ExpenseReport.objects.filter(
                user__approver=user
            ).select_related('user', 'company')
        else:
            # Default: My expenses
            return ExpenseReport.objects.filter(user=user).order_by('-created_at')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        
        # Counts for tabs
        context['my_pending_count'] = ExpenseReport.objects.filter(
            user=user, status__in=['draft', 'submitted']
        ).count()
        
        context['approval_count'] = ExpenseReport.objects.filter(
            current_approver=user, status='submitted'
        ).count()
        
        context['current_view'] = self.request.GET.get('view', 'my')
        return context

class ExpenseCreateView(LoginRequiredMixin, CreateView):
    """
    Create a new expense report with items.
    """
    model = ExpenseReport
    form_class = ExpenseReportForm
    template_name = 'expenses/form.html'
    success_url = reverse_lazy('expenses:list')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        if self.request.POST:
            context['items'] = ExpenseItemFormSet(self.request.POST, self.request.FILES)
        else:
            context['items'] = ExpenseItemFormSet(form_kwargs={'user': self.request.user})
        return context

    def form_valid(self, form):
        context = self.get_context_data()
        items = context['items']
        
        if items.is_valid():
            with transaction.atomic():
                self.object = form.save(commit=False)
                self.object.user = self.request.user
                self.object.company = self.request.user.company
                self.object.status = 'draft'
                self.object.save()
                
                items.instance = self.object
                items.save()
                
                # Calculate total
                self.object.calculate_total()
                
                log_audit_action(
                    self.request, 'create', 
                    f"Created expense report: {self.object.title}", 
                    'expense_report', self.object.id
                )
                
            messages.success(self.request, "Expense report created successfully.")
            return redirect(self.success_url)
        else:
            return self.render_to_response(self.get_context_data(form=form))

class ExpenseDetailView(LoginRequiredMixin, DetailView):
    """
    View details of an expense report.
    """
    model = ExpenseReport
    template_name = 'expenses/detail.html'
    context_object_name = 'report'

    def get_queryset(self):
        # Users can see their own, or ones they need to approve, or if they are admin/accounts
        user = self.request.user
        return ExpenseReport.objects.filter(
            Q(user=user) | 
            Q(current_approver=user) |
            Q(user__company=user.company, user__approver=user) # Manager visibility
        )

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['items'] = self.object.items.all()
        
        # Add approval form if user is the current approver
        if self.object.status == 'submitted' and self.object.current_approver == self.request.user:
            context['approval_form'] = ApprovalForm()
            
        return context

class ExpenseUpdateView(LoginRequiredMixin, UpdateView):
    """
    Edit a draft expense report.
    """
    model = ExpenseReport
    form_class = ExpenseReportForm
    template_name = 'expenses/form.html'
    success_url = reverse_lazy('expenses:list')

    def get_queryset(self):
        # Only allow editing own draft reports
        return ExpenseReport.objects.filter(user=self.request.user, status='draft')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        if self.request.POST:
            context['items'] = ExpenseItemFormSet(self.request.POST, self.request.FILES, instance=self.object)
        else:
            context['items'] = ExpenseItemFormSet(instance=self.object, form_kwargs={'user': self.request.user})
        return context

    def form_valid(self, form):
        context = self.get_context_data()
        items = context['items']
        
        if items.is_valid():
            with transaction.atomic():
                self.object = form.save()
                items.save()
                self.object.calculate_total()
                
                log_audit_action(
                    self.request, 'update', 
                    f"Updated expense report: {self.object.title}", 
                    'expense_report', self.object.id
                )
                
            messages.success(self.request, "Expense report updated successfully.")
            return redirect(self.success_url)
        else:
            return self.render_to_response(self.get_context_data(form=form))

class ExpenseSubmitView(LoginRequiredMixin, View):
    """
    Submit a draft report for approval.
    """
    def post(self, request, pk):
        report = get_object_or_404(ExpenseReport, pk=pk, user=request.user, status='draft')
        
        if report.items.count() == 0:
            messages.error(request, "Cannot submit an empty report. Please add items.")
            return redirect('expenses:detail', pk=pk)
            
        # Determine approver
        # Logic: User's approver -> If none, Company Admin?
        approver = request.user.approver
        
        if not approver:
            # Fallback: Find an admin in the company
            # In real app, might need specific logic for 'Accounts' role
            pass
            
        if not approver:
             messages.error(request, "No approver assigned to you. Please contact HR.")
             return redirect('expenses:detail', pk=pk)
             
        report.status = 'submitted'
        report.current_approver = approver
        report.submitted_at = timezone.now()
        report.save()
        
        log_audit_action(
            request, 'submit', 
            f"Submitted expense report: {report.title} to {approver.full_name}", 
            'expense_report', report.id
        )
        
        messages.success(request, f"Report submitted to {approver.full_name} for approval.")
        return redirect('expenses:list')

class ExpenseActionView(LoginRequiredMixin, View):
    """
    Handle approval/rejection actions.
    """
    def post(self, request, pk):
        report = get_object_or_404(ExpenseReport, pk=pk, current_approver=request.user, status='submitted')
        form = ApprovalForm(request.POST)
        
        if form.is_valid():
            action = form.cleaned_data['action']
            comment = form.cleaned_data['comment']
            
            if action == 'approve':
                # Logic for multi-level approval could go here
                # For now, simple 1-level approval -> Approved (Ready for Payment)
                report.status = 'approved'
                report.approved_at = timezone.now()
                report.current_approver = None # No longer waiting on anyone specific (moves to Accounts pool)
                # In future: assign to 'Accounts' role queue
                
                msg = "Report approved."
                
            elif action == 'reject':
                report.status = 'rejected'
                report.rejection_reason = comment
                report.current_approver = None # Returns to user
                msg = "Report rejected."
            
            report.save()
            
            log_audit_action(
                request, action, 
                f"{action.title()} expense report: {report.title}. Comment: {comment}", 
                'expense_report', report.id
            )
            
            messages.success(request, msg)
        else:
            messages.error(request, "Invalid action.")
            
        return redirect('expenses:list')
