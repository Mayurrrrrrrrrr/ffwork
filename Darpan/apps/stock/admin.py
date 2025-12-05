"""
Admin configuration for Stock Transfer module.
"""

from django.contrib import admin
from .models import Product, StockTransfer, TransferItem, Inventory, ProductImage, ProductAttribute

@admin.register(Product)
class ProductAdmin(admin.ModelAdmin):
    list_display = ['name', 'sku', 'category', 'base_metal', 'sale_price', 'is_featured', 'is_new_arrival', 'is_active']
    list_filter = ['company', 'category', 'base_metal', 'is_featured', 'is_new_arrival', 'is_active']
    search_fields = ['name', 'sku', 'style_code', 'description']

@admin.register(ProductImage)
class ProductImageAdmin(admin.ModelAdmin):
    list_display = ['product', 'is_primary', 'display_order', 'uploaded_at']
    list_filter = ['is_primary', 'uploaded_at']

@admin.register(ProductAttribute)
class ProductAttributeAdmin(admin.ModelAdmin):
    list_display = ['product', 'attribute_name', 'attribute_value']
    list_filter = ['attribute_name']

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
