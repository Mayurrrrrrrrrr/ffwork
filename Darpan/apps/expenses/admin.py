from django.contrib import admin
from .models import ExpenseCategory, ExpenseReport, ExpenseItem

@admin.register(ExpenseCategory)
class ExpenseCategoryAdmin(admin.ModelAdmin):
    list_display = ('name', 'company', 'is_active')
    list_filter = ('company', 'is_active')
    search_fields = ('name', 'company__name')

class ExpenseItemInline(admin.TabularInline):
    model = ExpenseItem
    extra = 0
    readonly_fields = ('created_at', 'updated_at')

@admin.register(ExpenseReport)
class ExpenseReportAdmin(admin.ModelAdmin):
    list_display = ('title', 'user', 'company', 'total_amount', 'status', 'created_at')
    list_filter = ('status', 'company', 'created_at')
    search_fields = ('title', 'user__email', 'user__full_name')
    inlines = [ExpenseItemInline]
    readonly_fields = ('total_amount', 'created_at', 'updated_at', 'submitted_at', 'approved_at', 'paid_at')
    
    def save_model(self, request, obj, form, change):
        if not obj.pk:
            # Set defaults if creating via admin
            pass
        super().save_model(request, obj, form, change)
