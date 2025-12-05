"""
Views for Purchase Orders module.
"""

from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from django.contrib.auth.mixins import LoginRequiredMixin
from django.views.generic import ListView, DetailView, CreateView, UpdateView, View
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils import timezone
from django.db.models import Q
from django.db import transaction

from .models import Vendor, PurchaseOrder, POItem
from .forms import VendorForm, PurchaseOrderForm, POItemFormSet, POApprovalForm
from apps.core.utils import log_audit_action

# --- Vendor Views ---

class VendorListView(LoginRequiredMixin, ListView):
    model = Vendor
    template_name = 'purchasing/vendor_list.html'
    context_object_name = 'vendors'

    def get_queryset(self):
        return Vendor.objects.filter(company=self.request.user.company)

class VendorCreateView(LoginRequiredMixin, CreateView):
    model = Vendor
    form_class = VendorForm
    template_name = 'purchasing/vendor_form.html'
    success_url = reverse_lazy('purchasing:vendor_list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "Vendor created successfully.")
        return super().form_valid(form)

class VendorUpdateView(LoginRequiredMixin, UpdateView):
    model = Vendor
    form_class = VendorForm
    template_name = 'purchasing/vendor_form.html'
    success_url = reverse_lazy('purchasing:vendor_list')

    def get_queryset(self):
        return Vendor.objects.filter(company=self.request.user.company)

# --- Purchase Order Views ---

class POListView(LoginRequiredMixin, ListView):
    model = PurchaseOrder
    template_name = 'purchasing/po_list.html'
    context_object_name = 'orders'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        view_type = self.request.GET.get('view', 'my')
        
        if view_type == 'approve':
            return PurchaseOrder.objects.filter(
                current_approver=user,
                status='pending'
            ).select_related('vendor', 'user')
        else:
            return PurchaseOrder.objects.filter(user=user).select_related('vendor').order_by('-created_at')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        context['approval_count'] = PurchaseOrder.objects.filter(
            current_approver=user, status='pending'
        ).count()
        context['current_view'] = self.request.GET.get('view', 'my')
        return context

class POCreateView(LoginRequiredMixin, CreateView):
    model = PurchaseOrder
    form_class = PurchaseOrderForm
    template_name = 'purchasing/po_form.html'
    success_url = reverse_lazy('purchasing:po_list')

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['user'] = self.request.user
        return kwargs

    def get_context_data(self, **kwargs):
        data = super().get_context_data(**kwargs)
        if self.request.POST:
            data['items'] = POItemFormSet(self.request.POST)
        else:
            data['items'] = POItemFormSet()
        return data

    def form_valid(self, form):
        context = self.get_context_data()
        items = context['items']
        with transaction.atomic():
            self.object = form.save(commit=False)
            self.object.user = self.request.user
            self.object.company = self.request.user.company
            self.object.status = 'draft'
            self.object.save()
            
            if items.is_valid():
                items.instance = self.object
                items.save()
                self.object.calculate_total()
            else:
                return self.form_invalid(form)
                
        log_audit_action(self.request, 'create', f"Created PO {self.object.po_number}", 'purchase_order', self.object.id)
        messages.success(self.request, "Purchase Order created successfully.")
        return redirect(self.success_url)

class PODetailView(LoginRequiredMixin, DetailView):
    model = PurchaseOrder
    template_name = 'purchasing/po_detail.html'
    context_object_name = 'po'

    def get_queryset(self):
        user = self.request.user
        return PurchaseOrder.objects.filter(
            Q(user=user) | 
            Q(current_approver=user) |
            Q(user__company=user.company, user__approver=user)
        )

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        if self.object.status == 'pending' and self.object.current_approver == self.request.user:
            context['approval_form'] = POApprovalForm()
        return context

class POSubmitView(LoginRequiredMixin, View):
    def post(self, request, pk):
        po = get_object_or_404(PurchaseOrder, pk=pk, user=request.user, status='draft')
        approver = request.user.approver
        
        if not approver:
            messages.error(request, "No approver assigned.")
            return redirect('purchasing:po_detail', pk=pk)
            
        po.status = 'pending'
        po.current_approver = approver
        po.save()
        
        log_audit_action(request, 'submit', f"Submitted PO {po.po_number}", 'purchase_order', po.id)
        messages.success(request, "PO submitted for approval.")
        return redirect('purchasing:po_list')

class POActionView(LoginRequiredMixin, View):
    def post(self, request, pk):
        po = get_object_or_404(PurchaseOrder, pk=pk, current_approver=request.user, status='pending')
        form = POApprovalForm(request.POST)
        
        if form.is_valid():
            action = form.cleaned_data['action']
            comment = form.cleaned_data['comment']
            
            if action == 'approve':
                po.status = 'approved'
                po.current_approver = None
                msg = "PO Approved."
            elif action == 'reject':
                po.status = 'rejected'
                po.rejection_reason = comment
                po.current_approver = None
                msg = "PO Rejected."
                
            po.save()
            log_audit_action(request, action, f"{action.title()} PO {po.po_number}", 'purchase_order', po.id)
            messages.success(request, msg)
        
        return redirect('purchasing:po_list')
