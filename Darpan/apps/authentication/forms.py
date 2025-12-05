"""
Forms for authentication module.
"""

from django import forms
from django.contrib.auth import get_user_model, authenticate
from django.core.exceptions import ValidationError
from apps.core.models import Company

User = get_user_model()


class LoginForm(forms.Form):
    """
    Multi-tenant login form with company code, email, and password.
    Company code field is hidden for platform admin login.
    """
    company_code = forms.CharField(
        max_length=20,
        required=False,  # Not required for platform admin
        widget=forms.TextInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter company code',
            'id': 'company_code_input'
        })
    )
    
    email = forms.EmailField(
        widget=forms.EmailInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter email address',
            'id': 'email_input',
            'oninput': 'checkPlatformAdmin(this.value)'
        })
    )
    
    password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter password'
        })
    )
    
    def __init__(self, *args, **kwargs):
        self.user = None
        super().__init__(*args, **kwargs)
    
    def clean(self):
        cleaned_data = super().clean()
        email = cleaned_data.get('email')
        password = cleaned_data.get('password')
        company_code = cleaned_data.get('company_code')
        
        if not email or not password:
            return cleaned_data
        
        # Check if this is platform admin login
        is_platform_admin = (email.lower() == 'platform@admin.com')
        
        if is_platform_admin:
            # Platform admin login - no company code needed
            try:
                user = User.objects.get(email=email, company__isnull=True)
            except User.DoesNotExist:
                raise ValidationError("Invalid email or password.")
        else:
            # Regular user login - company code required
            if not company_code:
                raise ValidationError("Please enter company code.")
            
            # Validate company exists
            try:
                company = Company.objects.get(company_code__iexact=company_code)
            except Company.DoesNotExist:
                raise ValidationError("Invalid company code.")
            
            # Get user by email and company
            try:
                user = User.objects.get(email=email, company=company)
            except User.DoesNotExist:
                raise ValidationError("Invalid email or password for this company.")
        
        # Verify password
        if not user.check_password(password):
            raise ValidationError("Invalid email or password.")
        
        # Check if user is active
        if not user.is_active:
            raise ValidationError("This account is inactive.")
        
        # Check if user has roles
        if not user.roles.exists():
            raise ValidationError("Account has no permissions assigned.")
        
        # Store user for later use
        self.user = user
        
        return cleaned_data
    
    def get_user(self):
        """Return authenticated user."""
        return self.user


class PasswordResetRequestForm(forms.Form):
    """Form for requesting password reset."""
    email = forms.EmailField(
        widget=forms.EmailInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter your email address'
        })
    )


class PasswordResetConfirmForm(forms.Form):
    """Form for confirming password reset with new password."""
    new_password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter new password'
        })
    )
    
    confirm_password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Confirm new password'
        })
    )
    
    def clean(self):
        cleaned_data = super().clean()
        new_password = cleaned_data.get('new_password')
        confirm_password = cleaned_data.get('confirm_password')
        
        if new_password and confirm_password:
            if new_password != confirm_password:
                raise ValidationError("Passwords do not match.")
        
        return cleaned_data


class ChangePasswordForm(forms.Form):
    """Form for changing password when logged in."""
    current_password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter current password'
        })
    )
    
    new_password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Enter new password'
        })
    )
    
    confirm_password = forms.CharField(
        widget=forms.PasswordInput(attrs={
            'class': 'form-control',
            'placeholder': 'Confirm new password'
        })
    )
    
    def __init__(self, user, *args, **kwargs):
        self.user = user
        super().__init__(*args, **kwargs)
    
    def clean_current_password(self):
        current_password = self.cleaned_data.get('current_password')
        if not self.user.check_password(current_password):
            raise ValidationError("Current password is incorrect.")
        return current_password
    
    def clean(self):
        cleaned_data = super().clean()
        new_password = cleaned_data.get('new_password')
        confirm_password = cleaned_data.get('confirm_password')
        
        if new_password and confirm_password:
            if new_password != confirm_password:
                raise ValidationError("New passwords do not match.")
        
        return cleaned_data
