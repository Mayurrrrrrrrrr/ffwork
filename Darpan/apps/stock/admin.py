from django.contrib import admin
from .models import Product, StockTransfer, TransferItem

@admin.register(Product)
class ProductAdmin(admin.ModelAdmin):
    list_display = ('name', 'sku', 'company', 'category', 'unit', 'is_active')
    list_filter = ('company', 'is_active', 'category')
    search_fields = ('name', 'sku')

class TransferItemInline(admin.TabularInline):
    model = TransferItem
    extra = 1

@admin.register(StockTransfer)
class StockTransferAdmin(admin.ModelAdmin):
    list_display = ('iso_number', 'source_store', 'destination_store', 'status', 'request_date')
    list_filter = ('status', 'company', 'source_store', 'destination_store')
    search_fields = ('iso_number',)
    inlines = [TransferItemInline]
    readonly_fields = ('iso_number', 'created_at', 'updated_at')
