"""
Core models for the Darpan application.
Contains base models used across all modules: User, Company, Role, Store, etc.
"""

from django.db import models
from django.contrib.auth.models import AbstractUser, BaseUserManager
from django.utils.translation import gettext_lazy as _


class Company(models.Model):
    """
    Company model for multi-tenancy.
    Each company has a unique company_code used during login.
    """
    company_code = models.CharField(max_length=20, unique=True, db_index=True)
    name = models.CharField(max_length=255)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    is_active = models.BooleanField(default=True)
    encryption_key = models.BinaryField(null=True, blank=True, help_text="Fernet key for data encryption")
    
    class Meta:
        db_table = 'companies'
        verbose_name_plural = 'Companies'
        ordering = ['name']
    
    def __str__(self):
        return f"{self.name} ({self.company_code})"


class Role(models.Model):
    """
    Role model for permission management.
    Examples: admin, approver, order_team, purchase_team, trainer, etc.
    """
    name = models.CharField(max_length=50, unique=True)
    description = models.TextField(blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'roles'
        ordering = ['name']
    
    def __str__(self):
        return self.name


class Store(models.Model):
    """
    Store/Location model.
    Stores belong to companies and can have GATI location names for stock transfer.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='stores')
    name = models.CharField(max_length=255)
    gati_location_name = models.CharField(max_length=255, null=True, blank=True,
                                          help_text="GATI location name for stock transfer")
    address = models.TextField(blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    is_active = models.BooleanField(default=True)
    
    class Meta:
        db_table = 'stores'
        ordering = ['name']
        indexes = [
            models.Index(fields=['company', 'name']),
        ]
    
    def __str__(self):
        return f"{self.name} - {self.company.name}"


class UserManager(BaseUserManager):
    """Custom user manager for email-based authentication."""
    
    def create_user(self, email, password=None, **extra_fields):
        if not email:
            raise ValueError(_('The Email field must be set'))
        email = self.normalize_email(email)
        user = self.model(email=email, **extra_fields)
        user.set_password(password)
        user.save(using=self._db)
        return user
    
    def create_superuser(self, email, password=None, **extra_fields):
        extra_fields.setdefault('is_staff', True)
        extra_fields.setdefault('is_superuser', True)
        extra_fields.setdefault('is_active', True)
        
        if extra_fields.get('is_staff') is not True:
            raise ValueError(_('Superuser must have is_staff=True.'))
        if extra_fields.get('is_superuser') is not True:
            raise ValueError(_('Superuser must have is_superuser=True.'))
        
        return self.create_user(email, password, **extra_fields)


class User(AbstractUser):
    """
    Custom User model extending Django's AbstractUser.
    Supports multi-tenancy with company association and role-based permissions.
    """
    # Remove username, use email for authentication
    username = None
    email = models.EmailField(_('email address'), unique=True)
    
    # User details
    full_name = models.CharField(max_length=255)
    
    # Multi-tenancy
    company = models.ForeignKey(Company, on_delete=models.CASCADE, 
                                related_name='users', null=True, blank=True,
                                help_text="Null for platform admin")
    
    # Store association
    store = models.ForeignKey(Store, on_delete=models.SET_NULL, null=True, blank=True,
                              related_name='users')
    
    # Approver for expense reports
    approver = models.ForeignKey('self', on_delete=models.SET_NULL, null=True, blank=True,
                                 related_name='employees')
    
    # Roles (many-to-many)
    roles = models.ManyToManyField(Role, related_name='users', blank=True)
    
    # Personal information
    dob = models.DateField(_('date of birth'), null=True, blank=True)
    doj = models.DateField(_('date of joining'), null=True, blank=True)
    phone = models.CharField(max_length=20, blank=True)
    
    # Email Verification
    is_email_verified = models.BooleanField(default=False)
    verification_token = models.CharField(max_length=100, null=True, blank=True)
    
    # Timestamps
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    objects = UserManager()
    
    USERNAME_FIELD = 'email'
    REQUIRED_FIELDS = ['full_name']
    
    class Meta:
        db_table = 'users'
        ordering = ['full_name']
        indexes = [
            models.Index(fields=['email']),
            models.Index(fields=['company', 'email']),
        ]
    
    def __str__(self):
        return f"{self.full_name} ({self.email})"
    
    def get_full_name(self):
        return self.full_name
    
    def get_short_name(self):
        return self.full_name.split()[0] if self.full_name else self.email
    
    def has_role(self, role_name):
        """Check if user has a specific role."""
        return self.roles.filter(name__iexact=role_name).exists()
    
    def has_any_role(self, role_names):
        """Check if user has any of the specified roles."""
        if not role_names:
            return False
        q_obj = models.Q()
        for role in role_names:
            q_obj |= models.Q(name__iexact=role)
        return self.roles.filter(q_obj).exists()
    
    @property
    def is_platform_admin(self):
        """Check if user is platform admin (superuser without company)."""
        return self.is_superuser and self.company is None
    
    @property
    def is_company_admin(self):
        """Check if user is a company admin."""
        return self.has_role('admin') and self.company is not None


class Announcement(models.Model):
    """
    Company-wide announcements displayed on the portal home.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='announcements')
    title = models.CharField(max_length=255)
    content = models.TextField()
    post_date = models.DateTimeField(auto_now_add=True)
    is_active = models.BooleanField(default=True)
    created_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, 
                                   related_name='announcements_created')
    
    class Meta:
        db_table = 'announcements'
        ordering = ['-post_date']
        indexes = [
            models.Index(fields=['company', 'is_active', '-post_date']),
        ]
    
    def __str__(self):
        return f"{self.title} - {self.company.name}"


class AuditLog(models.Model):
    """
    Comprehensive audit logging for all actions across the application.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='audit_logs',
                                null=True, blank=True)
    user = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, blank=True,
                             related_name='audit_logs')
    action_type = models.CharField(max_length=50, db_index=True,
                                   help_text="e.g., create, update, delete, approve, reject")
    target_type = models.CharField(max_length=50, null=True, blank=True,
                                   help_text="e.g., expense_report, purchase_order, btl_proposal")
    target_id = models.CharField(max_length=50, null=True, blank=True,
                                 help_text="ID of the target object")
    log_message = models.TextField()
    ip_address = models.GenericIPAddressField(null=True, blank=True)
    timestamp = models.DateTimeField(auto_now_add=True, db_index=True)
    
    class Meta:
        db_table = 'audit_logs'
        ordering = ['-timestamp']
        indexes = [
            models.Index(fields=['company', '-timestamp']),
            models.Index(fields=['user', '-timestamp']),
            models.Index(fields=['action_type', '-timestamp']),
        ]
    
    def __str__(self):
        user_str = self.user.full_name if self.user else "System"
        return f"{user_str} - {self.action_type} - {self.timestamp}"
