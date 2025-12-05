"""
Views for BTL Marketing module.
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

from .models import BTLProposal, BTLImage
from .forms import BTLProposalForm, BTLImageForm, BTLApprovalForm
from apps.core.utils import log_audit_action

class BTLListView(LoginRequiredMixin, ListView):
    """
    List view for BTL proposals.
    """
    model = BTLProposal
    template_name = 'btl/list.html'
    context_object_name = 'proposals'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        view_type = self.request.GET.get('view', 'my')
        
        if view_type == 'approve':
            return BTLProposal.objects.filter(
                current_approver=user,
                status='submitted'
            ).select_related('user', 'company')
        elif view_type == 'team':
            return BTLProposal.objects.filter(
                user__approver=user
            ).select_related('user', 'company')
        else:
            return BTLProposal.objects.filter(user=user).order_by('-created_at')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        
        context['my_pending_count'] = BTLProposal.objects.filter(
            user=user, status__in=['draft', 'submitted']
        ).count()
        
        context['approval_count'] = BTLProposal.objects.filter(
            current_approver=user, status='submitted'
        ).count()
        
        context['current_view'] = self.request.GET.get('view', 'my')
        return context

class BTLCreateView(LoginRequiredMixin, CreateView):
    """
    Create a new BTL proposal.
    """
    model = BTLProposal
    form_class = BTLProposalForm
    template_name = 'btl/form.html'
    success_url = reverse_lazy('btl:list')

    def form_valid(self, form):
        self.object = form.save(commit=False)
        self.object.user = self.request.user
        self.object.company = self.request.user.company
        self.object.status = 'draft'
        self.object.save()
        
        log_audit_action(
            self.request, 'create', 
            f"Created BTL proposal: {self.object.title}", 
            'btl_proposal', self.object.id
        )
        
        messages.success(self.request, "BTL proposal created successfully.")
        return redirect(self.success_url)

class BTLDetailView(LoginRequiredMixin, DetailView):
    """
    View details of a BTL proposal.
    """
    model = BTLProposal
    template_name = 'btl/detail.html'
    context_object_name = 'proposal'

    def get_queryset(self):
        user = self.request.user
        return BTLProposal.objects.filter(
            Q(user=user) | 
            Q(current_approver=user) |
            Q(user__company=user.company, user__approver=user)
        )

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['images'] = self.object.images.all()
        context['image_form'] = BTLImageForm()
        
        if self.object.status == 'submitted' and self.object.current_approver == self.request.user:
            context['approval_form'] = BTLApprovalForm()
            
        return context

class BTLUpdateView(LoginRequiredMixin, UpdateView):
    """
    Edit a draft BTL proposal.
    """
    model = BTLProposal
    form_class = BTLProposalForm
    template_name = 'btl/form.html'
    success_url = reverse_lazy('btl:list')

    def get_queryset(self):
        return BTLProposal.objects.filter(user=self.request.user, status='draft')

    def form_valid(self, form):
        response = super().form_valid(form)
        log_audit_action(
            self.request, 'update', 
            f"Updated BTL proposal: {self.object.title}", 
            'btl_proposal', self.object.id
        )
        messages.success(self.request, "Proposal updated successfully.")
        return response

class BTLSubmitView(LoginRequiredMixin, View):
    """
    Submit a draft proposal for approval.
    """
    def post(self, request, pk):
        proposal = get_object_or_404(BTLProposal, pk=pk, user=request.user, status='draft')
        
        approver = request.user.approver
        if not approver:
             messages.error(request, "No approver assigned. Please contact HR.")
             return redirect('btl:detail', pk=pk)
             
        proposal.status = 'submitted'
        proposal.current_approver = approver
        proposal.submitted_at = timezone.now()
        proposal.save()
        
        log_audit_action(
            request, 'submit', 
            f"Submitted BTL proposal: {proposal.title} to {approver.full_name}", 
            'btl_proposal', proposal.id
        )
        
        messages.success(request, f"Proposal submitted to {approver.full_name}.")
        return redirect('btl:list')

class BTLActionView(LoginRequiredMixin, View):
    """
    Handle approval/rejection actions.
    """
    def post(self, request, pk):
        proposal = get_object_or_404(BTLProposal, pk=pk, current_approver=request.user, status='submitted')
        form = BTLApprovalForm(request.POST)
        
        if form.is_valid():
            action = form.cleaned_data['action']
            comment = form.cleaned_data['comment']
            
            if action == 'approve':
                proposal.status = 'approved'
                proposal.approved_at = timezone.now()
                proposal.current_approver = None
                msg = "Proposal approved."
                
            elif action == 'reject':
                proposal.status = 'rejected'
                proposal.rejection_reason = comment
                proposal.current_approver = None
                msg = "Proposal rejected."
            
            proposal.save()
            
            log_audit_action(
                request, action, 
                f"{action.title()} BTL proposal: {proposal.title}. Comment: {comment}", 
                'btl_proposal', proposal.id
            )
            
            messages.success(request, msg)
        else:
            messages.error(request, "Invalid action.")
            
        return redirect('btl:list')

class BTLImageUploadView(LoginRequiredMixin, View):
    """
    Upload images for a proposal.
    """
    def post(self, request, pk):
        proposal = get_object_or_404(BTLProposal, pk=pk)
        
        # Permission check: Owner or Approver can upload? Usually owner.
        if proposal.user != request.user:
             messages.error(request, "Permission denied.")
             return redirect('btl:detail', pk=pk)

        form = BTLImageForm(request.POST, request.FILES)
        if form.is_valid():
            image = form.save(commit=False)
            image.proposal = proposal
            image.save()
            messages.success(request, "Image uploaded successfully.")
        else:
            messages.error(request, "Error uploading image.")
            
        return redirect('btl:detail', pk=pk)
