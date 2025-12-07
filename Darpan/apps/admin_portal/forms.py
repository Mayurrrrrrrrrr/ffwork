"""
Forms for Admin Portal module.
"""

from django import forms
from django import forms
from apps.core.models import User, Store, Company

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
