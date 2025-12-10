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
from .forms import (
    UserForm, StoreForm, CompanyForm, CompanyAdminForm,
    CompanyModuleForm, UserModuleForm, CompanyDeleteForm, 
    DataPurgeForm, BackupRequestForm
)


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

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['requesting_user'] = self.request.user
        kwargs['company'] = self.request.user.company
        return kwargs

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

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['requesting_user'] = self.request.user
        kwargs['company'] = self.request.user.company
        return kwargs

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
    
    def get_available_locations(self):
        """Fetch unique locations from StockSnapshot and SalesRecord for the company."""
        from apps.analytics.models import StockSnapshot, SalesRecord
        
        company = self.request.user.company
        locations = set()
        
        # Get unique locations from StockSnapshot
        stock_locations = StockSnapshot.objects.filter(
            company=company
        ).exclude(location='').values_list('location', flat=True).distinct()
        locations.update(stock_locations)
        
        # Get unique regions from SalesRecord (used as locations)
        sales_regions = SalesRecord.objects.filter(
            company=company
        ).exclude(region='').values_list('region', flat=True).distinct()
        locations.update(sales_regions)
        
        # Sort and return as list
        return sorted([loc for loc in locations if loc])
    
    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['available_locations'] = self.get_available_locations()
        return kwargs
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['available_locations'] = self.get_available_locations()
        return context

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
    
    def get_available_locations(self):
        """Fetch unique locations from StockSnapshot and SalesRecord for the company."""
        from apps.analytics.models import StockSnapshot, SalesRecord
        
        company = self.request.user.company
        locations = set()
        
        # Get unique locations from StockSnapshot
        stock_locations = StockSnapshot.objects.filter(
            company=company
        ).exclude(location='').values_list('location', flat=True).distinct()
        locations.update(stock_locations)
        
        # Get unique regions from SalesRecord (used as locations)
        sales_regions = SalesRecord.objects.filter(
            company=company
        ).exclude(region='').values_list('region', flat=True).distinct()
        locations.update(sales_regions)
        
        # Sort and return as list
        return sorted([loc for loc in locations if loc])
    
    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['available_locations'] = self.get_available_locations()
        return kwargs
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['available_locations'] = self.get_available_locations()
        return context

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


# --- Platform Admin: Company Module Allocation ---

class CompanyModuleView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """Platform admin: Allocate modules to a company."""
    template_name = 'admin_portal/company_modules.html'
    
    def get_company(self):
        return get_object_or_404(Company, pk=self.kwargs['pk'])
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.get_company()
        context['company'] = company
        context['form'] = CompanyModuleForm(company=company)
        return context
    
    def post(self, request, *args, **kwargs):
        company = self.get_company()
        form = CompanyModuleForm(request.POST, company=company)
        if form.is_valid():
            form.save(allocated_by=request.user)
            messages.success(request, f"Module allocation updated for {company.name}!")
            return redirect('admin_portal:company_list')
        return self.render_to_response(self.get_context_data(form=form))


class CompanyDeleteView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """Platform admin: Delete a company (soft or hard delete)."""
    template_name = 'admin_portal/company_delete.html'
    
    def get_company(self):
        return get_object_or_404(Company, pk=self.kwargs['pk'])
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.get_company()
        context['company'] = company
        context['form'] = CompanyDeleteForm(company=company)
        # Show stats
        context['user_count'] = User.objects.filter(company=company).count()
        context['store_count'] = Store.objects.filter(company=company).count()
        return context
    
    def post(self, request, *args, **kwargs):
        from django.utils import timezone
        company = self.get_company()
        form = CompanyDeleteForm(request.POST, company=company)
        
        if form.is_valid():
            hard_delete = form.cleaned_data.get('hard_delete', False)
            
            if hard_delete:
                company_name = company.name
                company.delete()
                messages.success(request, f"Company '{company_name}' has been permanently deleted.")
            else:
                company.is_deleted = True
                company.deleted_at = timezone.now()
                company.deleted_by = request.user
                company.is_active = False
                company.save()
                messages.success(request, f"Company '{company.name}' has been soft-deleted. It can be restored if needed.")
            
            return redirect('admin_portal:company_list')
        
        return self.render_to_response(self.get_context_data(form=form))


class CompanyRestoreView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """Platform admin: Restore a soft-deleted company."""
    template_name = 'admin_portal/company_restore.html'
    
    def get_company(self):
        return get_object_or_404(Company, pk=self.kwargs['pk'], is_deleted=True)
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['company'] = self.get_company()
        return context
    
    def post(self, request, *args, **kwargs):
        company = self.get_company()
        company.is_deleted = False
        company.deleted_at = None
        company.deleted_by = None
        company.is_active = True
        company.save()
        messages.success(request, f"Company '{company.name}' has been restored!")
        return redirect('admin_portal:company_list')


# --- Platform Admin: Data Backup ---

class DataBackupView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """Platform admin: Request module data backup."""
    template_name = 'admin_portal/data_backup.html'
    
    def get_context_data(self, **kwargs):
        from apps.core.models import BackupTask, Module
        context = super().get_context_data(**kwargs)
        context['form'] = BackupRequestForm()
        context['modules'] = Module.objects.filter(is_active=True).order_by('order')
        context['recent_backups'] = BackupTask.objects.order_by('-created_at')[:20]
        return context
    
    def post(self, request, *args, **kwargs):
        from apps.core.models import BackupTask
        form = BackupRequestForm(request.POST)
        
        if form.is_valid():
            company = form.cleaned_data.get('company')
            module = form.cleaned_data.get('module')
            
            # Create backup task
            backup_task = BackupTask.objects.create(
                company=company,
                module=module,
                requested_by=request.user,
                status='pending'
            )
            
            # Trigger async backup (via Celery if available, otherwise sync)
            try:
                from apps.admin_portal.tasks import process_backup_task
                process_backup_task.delay(backup_task.id)
                messages.info(request, f"Backup task created. You'll be notified when it's ready.")
            except ImportError:
                # Celery not available, process synchronously
                self._process_backup_sync(backup_task)
                messages.success(request, f"Backup created successfully!")
            
            return redirect('admin_portal:data_backup')
        
        return self.render_to_response(self.get_context_data(form=form))
    
    def _process_backup_sync(self, backup_task):
        """Process backup synchronously (fallback if Celery not available)."""
        import json
        import os
        from django.conf import settings
        from django.utils import timezone
        from apps.core.models import Module
        
        backup_task.status = 'processing'
        backup_task.save()
        
        try:
            # Create backup directory
            backup_dir = os.path.join(settings.MEDIA_ROOT, 'backups')
            os.makedirs(backup_dir, exist_ok=True)
            
            # Generate filename
            timestamp = timezone.now().strftime('%Y%m%d_%H%M%S')
            company_code = backup_task.company.company_code if backup_task.company else 'all'
            module_code = backup_task.module.code if backup_task.module else 'all'
            filename = f"backup_{company_code}_{module_code}_{timestamp}.json"
            filepath = os.path.join(backup_dir, filename)
            
            # Export data based on module
            data = self._export_module_data(backup_task.company, backup_task.module)
            
            # Write to file
            with open(filepath, 'w') as f:
                json.dump(data, f, indent=2, default=str)
            
            # Update task status
            backup_task.status = 'completed'
            backup_task.file_path = filepath
            backup_task.file_size = os.path.getsize(filepath)
            backup_task.completed_at = timezone.now()
            backup_task.save()
            
        except Exception as e:
            backup_task.status = 'failed'
            backup_task.error_message = str(e)
            backup_task.save()
    
    def _export_module_data(self, company, module):
        """Export data for a specific module."""
        data = {
            'export_info': {
                'company': company.name if company else 'All Companies',
                'module': module.name if module else 'All Modules',
            },
            'data': {}
        }
        
        # Get module-specific data
        if not module or module.code == 'expenses':
            self._export_expenses(data, company)
        if not module or module.code == 'btl':
            self._export_btl(data, company)
        if not module or module.code == 'tasks':
            self._export_tasks(data, company)
        if not module or module.code == 'analytics':
            self._export_analytics(data, company)
        if not module or module.code == 'purchasing':
            self._export_purchasing(data, company)
        
        return data
    
    def _export_expenses(self, data, company):
        try:
            from apps.expenses.models import ExpenseReport, ExpenseItem
            qs = ExpenseReport.objects.all()
            if company:
                qs = qs.filter(company=company)
            data['data']['expenses'] = list(qs.values())
        except:
            pass
    
    def _export_btl(self, data, company):
        try:
            from apps.btl.models import BTLProposal
            qs = BTLProposal.objects.all()
            if company:
                qs = qs.filter(company=company)
            data['data']['btl'] = list(qs.values())
        except:
            pass
    
    def _export_tasks(self, data, company):
        try:
            from apps.tasks.models import Task
            qs = Task.objects.all()
            if company:
                qs = qs.filter(company=company)
            data['data']['tasks'] = list(qs.values())
        except:
            pass
    
    def _export_analytics(self, data, company):
        try:
            from apps.analytics.models import SalesRecord, StockSnapshot
            sales_qs = SalesRecord.objects.all()
            stock_qs = StockSnapshot.objects.all()
            if company:
                sales_qs = sales_qs.filter(company=company)
                stock_qs = stock_qs.filter(company=company)
            data['data']['sales'] = list(sales_qs.values())
            data['data']['stock'] = list(stock_qs.values())
        except:
            pass
    
    def _export_purchasing(self, data, company):
        try:
            from apps.purchasing.models import PurchaseOrder, POItem
            qs = PurchaseOrder.objects.all()
            if company:
                qs = qs.filter(company=company)
            data['data']['purchase_orders'] = list(qs.values())
        except:
            pass


class BackupDownloadView(LoginRequiredMixin, PlatformAdminRequiredMixin, TemplateView):
    """Download a completed backup file."""
    
    def get(self, request, *args, **kwargs):
        from django.http import FileResponse, Http404
        from apps.core.models import BackupTask
        import os
        
        backup_id = kwargs.get('pk')
        backup_task = get_object_or_404(BackupTask, pk=backup_id, status='completed')
        
        if not backup_task.file_path or not os.path.exists(backup_task.file_path):
            raise Http404("Backup file not found")
        
        response = FileResponse(open(backup_task.file_path, 'rb'), content_type='application/json')
        response['Content-Disposition'] = f'attachment; filename="{os.path.basename(backup_task.file_path)}"'
        return response


# --- Company Admin: User Module Allocation ---

class UserModuleView(LoginRequiredMixin, CompanyAdminRequiredMixin, TemplateView):
    """Company admin: Allocate modules to a user."""
    template_name = 'admin_portal/user_modules.html'
    
    def get_user_obj(self):
        user = get_object_or_404(User, pk=self.kwargs['pk'])
        # Ensure user belongs to the same company
        if user.company != self.request.user.company:
            raise Http404("User not found")
        return user
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user_obj = self.get_user_obj()
        context['user_obj'] = user_obj
        context['form'] = UserModuleForm(user_obj=user_obj, company=self.request.user.company)
        return context
    
    def post(self, request, *args, **kwargs):
        user_obj = self.get_user_obj()
        form = UserModuleForm(request.POST, user_obj=user_obj, company=request.user.company)
        if form.is_valid():
            form.save(allocated_by=request.user)
            messages.success(request, f"Module allocation updated for {user_obj.full_name}!")
            return redirect('admin_portal:user_list')
        return self.render_to_response(self.get_context_data(form=form))


# --- Company Admin: Data Purge ---

class DataPurgeView(LoginRequiredMixin, CompanyAdminRequiredMixin, TemplateView):
    """Company admin: Purge module data."""
    template_name = 'admin_portal/data_purge.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        context['company'] = company
        context['form'] = DataPurgeForm(company=company)
        return context
    
    def post(self, request, *args, **kwargs):
        company = request.user.company
        form = DataPurgeForm(request.POST, company=company)
        
        if form.is_valid():
            modules = form.cleaned_data.get('modules', [])
            purged = []
            
            for module_code in modules:
                if module_code == 'all':
                    self._purge_all(company)
                    purged.append('All Data')
                    break
                else:
                    self._purge_module(company, module_code)
                    purged.append(module_code)
            
            # Log the purge action
            AuditLog.objects.create(
                company=company,
                user=request.user,
                action_type='data_purge',
                target_type='company_data',
                target_id=str(company.id),
                log_message=f"Purged data for modules: {', '.join(purged)}"
            )
            
            messages.success(request, f"Data purged successfully for: {', '.join(purged)}")
            return redirect('admin_portal:dashboard')
        
        return self.render_to_response(self.get_context_data(form=form))
    
    def _purge_module(self, company, module_code):
        """Purge data for a specific module."""
        if module_code == 'expenses':
            try:
                from apps.expenses.models import ExpenseReport
                ExpenseReport.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'btl':
            try:
                from apps.btl.models import BTLProposal
                BTLProposal.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'tasks':
            try:
                from apps.tasks.models import Task
                Task.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'purchasing':
            try:
                from apps.purchasing.models import PurchaseOrder
                PurchaseOrder.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'analytics':
            try:
                from apps.analytics.models import SalesRecord, StockSnapshot
                SalesRecord.objects.filter(company=company).delete()
                StockSnapshot.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'stock':
            try:
                from apps.stock.models import TransferRequest
                TransferRequest.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'old_gold':
            try:
                from apps.old_gold.models import OldGoldPurchase
                OldGoldPurchase.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'referrals':
            try:
                from apps.referrals.models import Referral
                Referral.objects.filter(company=company).delete()
            except:
                pass
        elif module_code == 'customer_referrals':
            try:
                from apps.customer_referrals.models import CustomerReferral
                CustomerReferral.objects.filter(company=company).delete()
            except:
                pass
    
    def _purge_all(self, company):
        """Purge all data for a company."""
        modules = ['expenses', 'btl', 'tasks', 'purchasing', 'analytics', 
                   'stock', 'old_gold', 'referrals', 'customer_referrals']
        for module_code in modules:
            self._purge_module(company, module_code)


class SendCredentialsView(LoginRequiredMixin, CompanyAdminRequiredMixin, TemplateView):
    """Company admin: Send login credentials to a user via email."""
    template_name = 'admin_portal/send_credentials.html'
    
    def get_user_obj(self):
        user = get_object_or_404(User, pk=self.kwargs['pk'])
        # Ensure user belongs to the same company
        if user.company != self.request.user.company:
            raise Http404("User not found")
        return user
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['user_obj'] = self.get_user_obj()
        return context
    
    def post(self, request, *args, **kwargs):
        user_obj = self.get_user_obj()
        
        # Send credentials email with password reset link
        from apps.core.email_service import EmailService
        success = EmailService.send_credentials_email(user_obj, password=None, reset_link=True)
        
        if success:
            messages.success(request, f"Credentials email sent to {user_obj.email}!")
        else:
            messages.error(request, "Failed to send email. Please check email configuration.")
        
        return redirect('admin_portal:user_list')


