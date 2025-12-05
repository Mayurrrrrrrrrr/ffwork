"""
Views for Customer Referrals module.
"""

import random
import binascii
from django.shortcuts import render, redirect, get_object_or_404
from django.views.generic import TemplateView, CreateView, FormView, ListView
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils.decorators import method_decorator
from django.views.decorators.cache import never_cache

from .models import Affiliate, CustomerReferral
from .forms import AffiliateRegistrationForm, AffiliateLoginForm, CustomerReferralForm
from apps.core.models import Company

# --- Middleware/Decorator for Affiliate Auth ---
def affiliate_login_required(view_func):
    def wrapper(request, *args, **kwargs):
        if not request.session.get('affiliate_id'):
            return redirect('customer_referrals:login')
        return view_func(request, *args, **kwargs)
    return wrapper

class AffiliateRegisterView(CreateView):
    model = Affiliate
    form_class = AffiliateRegistrationForm
    template_name = 'customer_referrals/register.html'
    success_url = reverse_lazy('customer_referrals:login')

    def form_valid(self, form):
        affiliate = form.save(commit=False)
        affiliate.set_password(form.cleaned_data['password'])
        # Assign default company (assuming ID 1 or first available)
        company = Company.objects.first()
        if company:
            affiliate.company = company
        affiliate.save()
        messages.success(self.request, "Registration successful! Please login.")
        return super().form_valid(form)

class AffiliateLoginView(FormView):
    form_class = AffiliateLoginForm
    template_name = 'customer_referrals/login.html'
    success_url = reverse_lazy('customer_referrals:dashboard')

    def form_valid(self, form):
        mobile = form.cleaned_data['mobile_number']
        password = form.cleaned_data['password']
        
        try:
            affiliate = Affiliate.objects.get(mobile_number=mobile)
            if affiliate.check_password(password):
                # Manual Session Management for Affiliate
                self.request.session['affiliate_id'] = affiliate.id
                self.request.session['affiliate_name'] = affiliate.full_name
                return super().form_valid(form)
            else:
                form.add_error(None, "Invalid password")
        except Affiliate.DoesNotExist:
            form.add_error(None, "Mobile number not registered")
            
        return self.form_invalid(form)

@method_decorator([never_cache, affiliate_login_required], name='dispatch')
class AffiliateDashboardView(TemplateView):
    template_name = 'customer_referrals/dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        affiliate_id = self.request.session.get('affiliate_id')
        affiliate = Affiliate.objects.get(id=affiliate_id)
        
        context['affiliate'] = affiliate
        context['referrals'] = CustomerReferral.objects.filter(affiliate=affiliate).order_by('-created_at')
        context['total_referrals'] = context['referrals'].count()
        context['pending_referrals'] = context['referrals'].filter(status='Pending').count()
        context['converted_referrals'] = context['referrals'].filter(status='Converted').count()
        
        return context

@method_decorator([never_cache, affiliate_login_required], name='dispatch')
class ReferralCreateView(CreateView):
    model = CustomerReferral
    form_class = CustomerReferralForm
    template_name = 'customer_referrals/add_referral.html'
    success_url = reverse_lazy('customer_referrals:dashboard')

    def form_valid(self, form):
        affiliate_id = self.request.session.get('affiliate_id')
        affiliate = Affiliate.objects.get(id=affiliate_id)
        
        referral = form.save(commit=False)
        referral.affiliate = affiliate
        referral.company = affiliate.company
        
        # Generate Code: FF-XXXXXX
        random_hex = binascii.b2a_hex(random.randbytes(3)).decode().upper()
        referral.referral_code = f"FF-{random_hex}"
        
        referral.save()
        messages.success(self.request, f"Referral created! Code: {referral.referral_code}")
        return super().form_valid(form)

def affiliate_logout(request):
    request.session.flush()
    return redirect('customer_referrals:login')
