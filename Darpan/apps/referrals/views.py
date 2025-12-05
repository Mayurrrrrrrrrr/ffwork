"""
Views for Referrals module.
"""

from django.views.generic import ListView, CreateView, UpdateView, DetailView
from django.contrib.auth.mixins import LoginRequiredMixin
from django.urls import reverse_lazy
from django.contrib import messages
from django.shortcuts import redirect

from .models import Candidate
from .forms import ReferralForm, ReferralStatusForm
from apps.core.utils import check_role

class ReferralListView(LoginRequiredMixin, ListView):
    model = Candidate
    template_name = 'referrals/list.html'
    context_object_name = 'referrals'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        # If admin/hr, show all. If regular user, show only their referrals.
        if user.has_any_role(['admin', 'hr']):
            return Candidate.objects.filter(company=user.company)
        return Candidate.objects.filter(company=user.company, referred_by=user)

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['is_hr'] = self.request.user.has_any_role(['admin', 'hr'])
        return context

class ReferralCreateView(LoginRequiredMixin, CreateView):
    model = Candidate
    form_class = ReferralForm
    template_name = 'referrals/form.html'
    success_url = reverse_lazy('referrals:list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        form.instance.referred_by = self.request.user
        messages.success(self.request, "Referral submitted successfully!")
        return super().form_valid(form)

class ReferralUpdateView(LoginRequiredMixin, UpdateView):
    model = Candidate
    form_class = ReferralStatusForm
    template_name = 'referrals/form.html'
    success_url = reverse_lazy('referrals:list')

    def dispatch(self, request, *args, **kwargs):
        if not request.user.has_any_role(['admin', 'hr']):
            messages.error(request, "You do not have permission to update status.")
            return redirect('referrals:list')
        return super().dispatch(request, *args, **kwargs)

    def form_valid(self, form):
        messages.success(self.request, "Referral status updated!")
        return super().form_valid(form)
