"""
Forms for Admin Portal module.
"""

from django import forms
from apps.core.models import User, Store, Company, Module, CompanyModule, UserModule

class UserForm(forms.ModelForm):
    password = forms.CharField(widget=forms.PasswordInput(), required=False, help_text="Leave blank to keep current password")
    
    class Meta:
        model = User
        fields = ['email', 'full_name', 'phone', 'store', 'roles', 'dob', 'doj', 'is_active', 'password']
        widgets = {
            'dob': forms.DateInput(attrs={'type': 'date'}),
            'doj': forms.DateInput(attrs={'type': 'date'}),
            'roles': forms.CheckboxSelectMultiple(),
        }

    def save(self, commit=True):
        user = super().save(commit=False)
        password = self.cleaned_data.get('password')
        if password:
            user.set_password(password)
        if commit:
            user.save()
            self.save_m2m()
        return user

class StoreForm(forms.ModelForm):
    class Meta:
        model = Store
        fields = ['name', 'gati_location_name', 'address', 'is_active']
        widgets = {
            'address': forms.Textarea(attrs={'rows': 3}),
        }

class CompanyForm(forms.ModelForm):
    class Meta:
        model = Company
        fields = ['name', 'company_code', 'is_active']


class CompanyAdminForm(forms.ModelForm):
    """Form for platform admin to create a company admin."""
    password = forms.CharField(widget=forms.PasswordInput(), required=True, help_text="Set the initial password")
    
    class Meta:
        model = User
        fields = ['email', 'full_name', 'phone', 'password']
    
    def __init__(self, *args, company=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.company = company
    
    def save(self, commit=True):
        user = super().save(commit=False)
        user.company = self.company
        user.set_password(self.cleaned_data['password'])
        user.is_active = True
        if commit:
            user.save()
            # Assign admin role
            from apps.core.models import Role
            admin_role, _ = Role.objects.get_or_create(
                name='admin', 
                defaults={'description': 'Company Administrator'}
            )
            user.roles.add(admin_role)
        return user


class CompanyModuleForm(forms.Form):
    """Form for platform admin to allocate modules to a company."""
    
    def __init__(self, *args, company=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.company = company
        
        # Get all active modules
        modules = Module.objects.filter(is_active=True).order_by('order')
        
        # Get currently enabled modules for this company
        enabled_module_ids = []
        if company:
            enabled_module_ids = list(CompanyModule.objects.filter(
                company=company, is_enabled=True
            ).values_list('module_id', flat=True))
        
        # Create checkbox field for each module
        for module in modules:
            self.fields[f'module_{module.id}'] = forms.BooleanField(
                required=False,
                initial=module.id in enabled_module_ids,
                label=module.name,
                help_text=module.description
            )
    
    def save(self, allocated_by=None):
        """Save module allocations for the company."""
        modules = Module.objects.filter(is_active=True)
        
        for module in modules:
            field_name = f'module_{module.id}'
            is_enabled = self.cleaned_data.get(field_name, False)
            
            obj, created = CompanyModule.objects.update_or_create(
                company=self.company,
                module=module,
                defaults={
                    'is_enabled': is_enabled,
                    'allocated_by': allocated_by
                }
            )


class UserModuleForm(forms.Form):
    """Form for company admin to allocate modules to a user."""
    
    def __init__(self, *args, user_obj=None, company=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.user_obj = user_obj
        self.company = company
        
        # Get only modules enabled for the company
        company_module_ids = CompanyModule.objects.filter(
            company=company, is_enabled=True
        ).values_list('module_id', flat=True)
        
        modules = Module.objects.filter(
            id__in=company_module_ids, is_active=True
        ).order_by('order')
        
        # Get currently enabled modules for this user
        enabled_module_ids = []
        if user_obj:
            enabled_module_ids = list(UserModule.objects.filter(
                user=user_obj, is_enabled=True
            ).values_list('module_id', flat=True))
        
        # If user has no allocations, they have access to all company modules by default
        has_allocations = UserModule.objects.filter(user=user_obj).exists() if user_obj else False
        
        # Create checkbox field for each module
        for module in modules:
            initial = module.id in enabled_module_ids if has_allocations else True
            self.fields[f'module_{module.id}'] = forms.BooleanField(
                required=False,
                initial=initial,
                label=module.name,
                help_text=module.description
            )
    
    def save(self, allocated_by=None):
        """Save module allocations for the user."""
        company_module_ids = CompanyModule.objects.filter(
            company=self.company, is_enabled=True
        ).values_list('module_id', flat=True)
        
        modules = Module.objects.filter(id__in=company_module_ids, is_active=True)
        
        for module in modules:
            field_name = f'module_{module.id}'
            is_enabled = self.cleaned_data.get(field_name, False)
            
            UserModule.objects.update_or_create(
                user=self.user_obj,
                module=module,
                defaults={
                    'is_enabled': is_enabled,
                    'allocated_by': allocated_by
                }
            )


class CompanyDeleteForm(forms.Form):
    """Form for platform admin to delete a company."""
    confirm_code = forms.CharField(
        max_length=50,
        label="Type company code to confirm",
        help_text="Type the company code exactly to confirm deletion"
    )
    hard_delete = forms.BooleanField(
        required=False,
        initial=False,
        label="Permanently delete (cannot be restored)",
        help_text="If unchecked, company will be soft-deleted and can be restored"
    )
    
    def __init__(self, *args, company=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.company = company
    
    def clean_confirm_code(self):
        code = self.cleaned_data.get('confirm_code', '')
        if self.company and code != self.company.company_code:
            raise forms.ValidationError("Company code does not match. Please type the exact company code.")
        return code


class DataPurgeForm(forms.Form):
    """Form for company admin to purge module data."""
    MODULE_CHOICES = [
        ('expenses', 'Expenses'),
        ('btl', 'BTL Marketing'),
        ('tasks', 'Tasks'),
        ('purchasing', 'Purchase Orders'),
        ('analytics', 'Analytics/Sales Data'),
        ('stock', 'Stock Data'),
        ('old_gold', 'Old Gold'),
        ('referrals', 'Referrals'),
        ('customer_referrals', 'Customer Referrals'),
        ('all', 'ALL DATA (Dangerous!)'),
    ]
    
    modules = forms.MultipleChoiceField(
        choices=MODULE_CHOICES,
        widget=forms.CheckboxSelectMultiple,
        label="Select modules to purge"
    )
    confirm_code = forms.CharField(
        max_length=50,
        label="Type company code to confirm",
        help_text="Type your company code exactly to confirm data purge"
    )
    confirm_checkbox = forms.BooleanField(
        required=True,
        label="I understand this action is IRREVERSIBLE and all selected data will be permanently deleted"
    )
    
    def __init__(self, *args, company=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.company = company
    
    def clean_confirm_code(self):
        code = self.cleaned_data.get('confirm_code', '')
        if self.company and code != self.company.company_code:
            raise forms.ValidationError("Company code does not match.")
        return code


class BackupRequestForm(forms.Form):
    """Form for platform admin to request module data backup."""
    company = forms.ModelChoiceField(
        queryset=Company.objects.filter(is_deleted=False, is_active=True),
        required=True,
        label="Select Company"
    )
    module = forms.ModelChoiceField(
        queryset=Module.objects.filter(is_active=True),
        required=False,
        label="Select Module (leave empty for all modules)",
        empty_label="All Modules"
    )

