"""
Views for Admin Portal module.
Separated into:
- Platform Admin: Can only manage companies and create company admins
- Company Admin: Can manage users, stores within their company
"""

from django.views.generic import TemplateView, ListView, CreateView, UpdateView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.urls import reverse_lazy
from django.contrib import messages
from django.shortcuts import redirect, get_object_or_404
from django.db.models import Count
from django.http import Http404

from apps.core.models import User, Store, AuditLog, Company, Role
from .forms import UserForm, StoreForm, CompanyForm, CompanyAdminForm


# --- Mixins ---

class PlatformAdminRequiredMixin(UserPassesTestMixin):
    """Only allow platform admin (superuser without company)."""
    def test_func(self):
        return self.request.user.is_platform_admin
    
    def handle_no_permission(self):
        messages.error(self.request, "Access denied. Platform admin privileges required.")
        return redirect('dashboard:home')


class CompanyAdminRequiredMixin(UserPassesTestMixin):
    """Only allow company admin (has 'admin' role and belongs to a company)."""
    def test_func(self):
        user = self.request.user
        # Company admin: has admin role and belongs to a company
        return user.is_company_admin or (user.has_role('admin') and user.company is not None)
    
    def handle_no_permission(self):
        messages.error(self.request, "Access denied. Company admin privileges required.")
        return redirect('dashboard:home')


class AdminRequiredMixin(UserPassesTestMixin):
    """Allow both platform admin and company admin."""
    def test_func(self):
        user = self.request.user
        return user.is_platform_admin or user.is_company_admin or user.has_role('admin')


# --- Dashboard Views ---

class AdminDashboardView(LoginRequiredMixin, AdminRequiredMixin, TemplateView):
    """
    Routing dashboard - redirects to appropriate dashboard based on user type.
    """
    template_name = 'admin_portal/dashboard.html'
    
    def dispatch(self, request, *args, **kwargs):
        # Platform admin goes to platform dashboard
        if request.user.is_authenticated and request.user.is_platform_admin:
            return redirect('admin_portal:platform_dashboard')
        return super().dispatch(request, *args, **kwargs)

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if company:
            context['total_users'] = User.objects.filter(company=company).count()
            context['active_users'] = User.objects.filter(company=company, is_active=True).count()
            context['total_stores'] = Store.objects.filter(company=company).count()
            context['recent_logs'] = AuditLog.objects.filter(company=company).order_by('-timestamp')[:10]
        else:
            context['total_users'] = 0
            context['active_users'] = 0
            context['total_stores'] = 0
            context['recent_logs'] = []
        
        return context


class PlatformAdminDashboardView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """
    Dashboard for platform admin - shows companies overview only.
    No access to internal company data.
    """
    template_name = 'admin_portal/platform_dashboard.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        
        # Companies with user counts (aggregated, no individual user data)
        # Use defer to exclude BLOB field for Oracle compatibility
        companies = Company.objects.defer('encryption_key').annotate(
            user_count=Count('users', distinct=True),
            store_count=Count('stores', distinct=True)
        ).order_by('-created_at')
        
        context['companies'] = companies
        context['total_companies'] = companies.count()
        context['active_companies'] = companies.filter(is_active=True).count()
        
        return context


# --- Company Admin: User Management ---

class UserListView(LoginRequiredMixin, CompanyAdminRequiredMixin, ListView):
    """Company admin: List users in their company."""
    model = User
    template_name = 'admin_portal/user_list.html'
    context_object_name = 'users'
    paginate_by = 20

    def get_queryset(self):
        return User.objects.filter(company=self.request.user.company).order_by('full_name')


class UserCreateView(LoginRequiredMixin, CompanyAdminRequiredMixin, CreateView):
    """Company admin: Create a new user in their company."""
    model = User
    form_class = UserForm
    template_name = 'admin_portal/user_form.html'
    success_url = reverse_lazy('admin_portal:user_list')

    def get_form(self, form_class=None):
        form = super().get_form(form_class)
        # Filter stores to only show company stores
        if self.request.user.company:
            form.fields['store'].queryset = Store.objects.filter(company=self.request.user.company)
        return form

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "User created successfully!")
        return super().form_valid(form)


class UserUpdateView(LoginRequiredMixin, CompanyAdminRequiredMixin, UpdateView):
    """Company admin: Update a user in their company."""
    model = User
    form_class = UserForm
    template_name = 'admin_portal/user_form.html'
    success_url = reverse_lazy('admin_portal:user_list')

    def get_queryset(self):
        return User.objects.filter(company=self.request.user.company)

    def get_form(self, form_class=None):
        form = super().get_form(form_class)
        # Filter stores to only show company stores
        if self.request.user.company:
            form.fields['store'].queryset = Store.objects.filter(company=self.request.user.company)
        return form

    def form_valid(self, form):
        messages.success(self.request, "User updated successfully!")
        return super().form_valid(form)


# --- Company Admin: Store Management ---

class StoreListView(LoginRequiredMixin, CompanyAdminRequiredMixin, ListView):
    """Company admin: List stores in their company."""
    model = Store
    template_name = 'admin_portal/store_list.html'
    context_object_name = 'stores'

    def get_queryset(self):
        return Store.objects.filter(company=self.request.user.company)


class StoreCreateView(LoginRequiredMixin, CompanyAdminRequiredMixin, CreateView):
    """Company admin: Create a new store in their company."""
    model = Store
    form_class = StoreForm
    template_name = 'admin_portal/store_form.html'
    success_url = reverse_lazy('admin_portal:store_list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "Store created successfully!")
        return super().form_valid(form)


class StoreUpdateView(LoginRequiredMixin, CompanyAdminRequiredMixin, UpdateView):
    """Company admin: Update a store in their company."""
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

class CompanyListView(LoginRequiredMixin, PlatformAdminRequiredMixin, ListView):
    """Platform admin: List all companies."""
    model = Company
    template_name = 'admin_portal/company_list.html'
    context_object_name = 'companies'
    
    def get_queryset(self):
        # Use defer to exclude BLOB field for Oracle compatibility
        return Company.objects.defer('encryption_key').order_by('name')


class CompanyCreateView(LoginRequiredMixin, PlatformAdminRequiredMixin, CreateView):
    """Platform admin: Create a new company."""
    model = Company
    form_class = CompanyForm
    template_name = 'admin_portal/company_form.html'
    success_url = reverse_lazy('admin_portal:company_list')

    def form_valid(self, form):
        messages.success(self.request, "Company created successfully! Now create an admin for this company.")
        response = super().form_valid(form)
        # Redirect to create admin for this company
        return redirect('admin_portal:company_admin_create', pk=self.object.pk)


class CompanyUpdateView(LoginRequiredMixin, PlatformAdminRequiredMixin, UpdateView):
    """Platform admin: Update a company."""
    model = Company
    form_class = CompanyForm
    template_name = 'admin_portal/company_form.html'
    success_url = reverse_lazy('admin_portal:company_list')

    def form_valid(self, form):
        messages.success(self.request, "Company updated successfully!")
        return super().form_valid(form)


class CompanyAdminCreateView(LoginRequiredMixin, PlatformAdminRequiredMixin, CreateView):
    """Platform admin: Create an admin for a company."""
    model = User
    form_class = CompanyAdminForm
    template_name = 'admin_portal/company_admin_form.html'
    
    def get_company(self):
        return get_object_or_404(Company, pk=self.kwargs['pk'])
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['company'] = self.get_company()
        # Show existing admins for this company
        admin_role = Role.objects.filter(name='admin').first()
        if admin_role:
            context['existing_admins'] = User.objects.filter(
                company=context['company'],
                roles=admin_role
            )
        else:
            context['existing_admins'] = []
        return context
    
    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['company'] = self.get_company()
        return kwargs
    
    def form_valid(self, form):
        company = self.get_company()
        messages.success(self.request, f"Admin created successfully for {company.name}!")
        return super().form_valid(form)
    
    def get_success_url(self):
        return reverse_lazy('admin_portal:company_list')
