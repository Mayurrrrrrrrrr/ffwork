"""
Django admin configuration for Core models.
"""

from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as BaseUserAdmin
from django.utils.translation import gettext_lazy as _
from .models import Company, Role, Store, User, Announcement, AuditLog


@admin.register(Company)
class CompanyAdmin(admin.ModelAdmin):
    list_display = ('company_code', 'name', 'is_active', 'created_at')
    list_filter = ('is_active', 'created_at')
    search_fields = ('company_code', 'name')
    ordering = ('name',)


@admin.register(Role)
class RoleAdmin(admin.ModelAdmin):
    list_display = ('name', 'description', 'created_at')
    search_fields = ('name', 'description')
    ordering = ('name',)


@admin.register(Store)
class StoreAdmin(admin.ModelAdmin):
    list_display = ('name', 'company', 'gati_location_name', 'is_active')
    list_filter = ('company', 'is_active')
    search_fields = ('name', 'gati_location_name', 'address')
    ordering = ('company', 'name')


@admin.register(User)
class UserAdmin(BaseUserAdmin):
    list_display = ('email', 'full_name', 'company', 'store', 'is_active', 'is_staff')
    list_filter = ('is_active', 'is_staff', 'is_superuser', 'company', 'roles')
    search_fields = ('email', 'full_name', 'phone')
    ordering = ('full_name',)
    
    fieldsets = (
        (None, {'fields': ('email', 'password')}),
        (_('Personal info'), {'fields': ('full_name', 'phone', 'dob', 'doj')}),
        (_('Organization'), {'fields': ('company', 'store', 'approver', 'roles')}),
        (_('Permissions'), {
            'fields': ('is_active', 'is_staff', 'is_superuser', 'groups', 'user_permissions'),
        }),
        (_('Important dates'), {'fields': ('last_login', 'date_joined')}),
    )
    
    add_fieldsets = (
        (None, {
            'classes': ('wide',),
            'fields': ('email', 'full_name', 'company', 'password1', 'password2'),
        }),
    )
    
    filter_horizontal = ('roles', 'groups', 'user_permissions')


@admin.register(Announcement)
class AnnouncementAdmin(admin.ModelAdmin):
    list_display = ('title', 'company', 'is_active', 'post_date', 'created_by')
    list_filter = ('company', 'is_active', 'post_date')
    search_fields = ('title', 'content')
    ordering = ('-post_date',)
    raw_id_fields = ('created_by',)


@admin.register(AuditLog)
class AuditLogAdmin(admin.ModelAdmin):
    list_display = ('timestamp', 'user', 'company', 'action_type', 'target_type', 'target_id')
    list_filter = ('action_type', 'target_type', 'company', 'timestamp')
    search_fields = ('log_message', 'user__full_name', 'user__email', 'ip_address')
    ordering = ('-timestamp',)
    readonly_fields = ('timestamp', 'ip_address')
    raw_id_fields = ('user', 'company')
    
    def has_add_permission(self, request):
        # Audit logs should not be manually created
        return False
    
    def has_change_permission(self, request, obj=None):
        # Audit logs should not be modified
        return False
