from django.contrib import admin
from .models import Vendor, PurchaseOrder, POItem

@admin.register(Vendor)
class VendorAdmin(admin.ModelAdmin):
    list_display = ('name', 'company', 'contact_person', 'email', 'is_active')
    list_filter = ('company', 'is_active')
    search_fields = ('name', 'contact_person', 'email')

class POItemInline(admin.TabularInline):
    model = POItem
    extra = 1
    readonly_fields = ('total_price',)

@admin.register(PurchaseOrder)
class PurchaseOrderAdmin(admin.ModelAdmin):
    list_display = ('po_number', 'vendor', 'company', 'order_date', 'total_amount', 'status')
    list_filter = ('status', 'company', 'order_date')
    search_fields = ('po_number', 'vendor__name')
    inlines = [POItemInline]
    readonly_fields = ('po_number', 'total_amount', 'created_at', 'updated_at')
