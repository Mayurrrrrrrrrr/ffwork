"""
Views for Admin Portal module.
"""

from django.views.generic import TemplateView, ListView, CreateView, UpdateView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.urls import reverse_lazy
from django.contrib import messages
from django.shortcuts import redirect
from django.db.models import Count

from apps.core.models import User, Store, AuditLog, Company
from .forms import UserForm, StoreForm, CompanyForm

class AdminRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_role('admin')

class AdminDashboardView(LoginRequiredMixin, AdminRequiredMixin, TemplateView):
    template_name = 'admin_portal/dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        context['total_users'] = User.objects.filter(company=company).count()
        context['active_users'] = User.objects.filter(company=company, is_active=True).count()
        context['total_stores'] = Store.objects.filter(company=company).count()
        context['recent_logs'] = AuditLog.objects.filter(company=company).order_by('-timestamp')[:10]
        
        return context

# --- User Management ---
class UserListView(LoginRequiredMixin, AdminRequiredMixin, ListView):
    model = User
    template_name = 'admin_portal/user_list.html'
    context_object_name = 'users'
    paginate_by = 20

    def get_queryset(self):
        return User.objects.filter(company=self.request.user.company).order_by('full_name')

class UserCreateView(LoginRequiredMixin, AdminRequiredMixin, CreateView):
    model = User
    form_class = UserForm
    template_name = 'admin_portal/user_form.html'
    success_url = reverse_lazy('admin_portal:user_list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "User created successfully!")
        return super().form_valid(form)

class UserUpdateView(LoginRequiredMixin, AdminRequiredMixin, UpdateView):
    model = User
    form_class = UserForm
    template_name = 'admin_portal/user_form.html'
    success_url = reverse_lazy('admin_portal:user_list')

    def get_queryset(self):
        return User.objects.filter(company=self.request.user.company)

    def form_valid(self, form):
        messages.success(self.request, "User updated successfully!")
        return super().form_valid(form)

# --- Store Management ---
class StoreListView(LoginRequiredMixin, AdminRequiredMixin, ListView):
    model = Store
    template_name = 'admin_portal/store_list.html'
    context_object_name = 'stores'

    def get_queryset(self):
        return Store.objects.filter(company=self.request.user.company)

class StoreCreateView(LoginRequiredMixin, AdminRequiredMixin, CreateView):
    model = Store
    form_class = StoreForm
    template_name = 'admin_portal/store_form.html'
    success_url = reverse_lazy('admin_portal:store_list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "Store created successfully!")
        return super().form_valid(form)

class StoreUpdateView(LoginRequiredMixin, AdminRequiredMixin, UpdateView):
    model = Store
    form_class = StoreForm
    template_name = 'admin_portal/store_form.html'
    success_url = reverse_lazy('admin_portal:store_list')

    def get_queryset(self):
        return Store.objects.filter(company=self.request.user.company)

    def form_valid(self, form):
        messages.success(self.request, "Store updated successfully!")
        return super().form_valid(form)

# --- Platform Admin: Company Management ---
class SuperuserRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser

class CompanyListView(LoginRequiredMixin, SuperuserRequiredMixin, ListView):
    model = Company
    template_name = 'admin_portal/company_list.html'
    context_object_name = 'companies'

class CompanyCreateView(LoginRequiredMixin, SuperuserRequiredMixin, CreateView):
    model = Company
    form_class = CompanyForm
    template_name = 'admin_portal/company_form.html'
    success_url = reverse_lazy('admin_portal:company_list')

    def form_valid(self, form):
        messages.success(self.request, "Company created successfully!")
        return super().form_valid(form)

class CompanyUpdateView(LoginRequiredMixin, SuperuserRequiredMixin, UpdateView):
    model = Company
    form_class = CompanyForm
    template_name = 'admin_portal/company_form.html'
    success_url = reverse_lazy('admin_portal:company_list')

    def form_valid(self, form):
        messages.success(self.request, "Company updated successfully!")
        return super().form_valid(form)
