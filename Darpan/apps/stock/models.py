"""
Models for Stock Transfer (ISO) module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from apps.core.models import User, Company, Store

class Product(models.Model):
    """
    Item to be transferred/managed.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='products')
    name = models.CharField(max_length=255)
    sku = models.CharField(max_length=100, help_text="Stock Keeping Unit")
    description = models.TextField(blank=True)
    category = models.CharField(max_length=100, blank=True)
    unit = models.CharField(max_length=20, default='pcs', help_text="e.g., pcs, box, kg")
    
    # Advanced Product Finder fields
    style_code = models.CharField(max_length=100, blank=True, db_index=True)
    base_metal = models.CharField(max_length=50, blank=True)
    size = models.CharField(max_length=50, blank=True)
    sale_price = models.DecimalField(max_digits=12, decimal_places=2, default=0.00)
    
    # E-commerce enhancements
    seo_title = models.CharField(max_length=200, blank=True)
    seo_description = models.TextField(blank=True)
    tags = models.JSONField(default=list, blank=True)  # ['trending', 'wedding', 'bridal']
    
    # Rich metadata
    is_featured = models.BooleanField(default=False)
    is_new_arrival = models.BooleanField(default=False)
    trending_score = models.IntegerField(default=0, help_text="For recommendation engine")
    
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'stock_products'
        ordering = ['-trending_score', 'name']
        unique_together = ['company', 'sku']
        indexes = [
            models.Index(fields=['company', '-trending_score', '-created_at']),
            models.Index(fields=['company', 'style_code']),
            models.Index(fields=['company', 'category']),
        ]
    
    def __str__(self):
        return f"{self.name} ({self.sku})"


class ProductImage(models.Model):
    """
    Product images for e-commerce display.
    """
    product = models.ForeignKey(Product, on_delete=models.CASCADE, related_name='images')
    image_url = models.URLField(help_text="S3 URL or media path")
    is_primary = models.BooleanField(default=False)
    alt_text = models.CharField(max_length=200, blank=True)
    display_order = models.IntegerField(default=0)
    
    uploaded_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'stock_product_images'
        ordering = ['display_order', 'id']
        indexes = [
            models.Index(fields=['product', 'display_order']),
        ]
    
    def __str__(self):
        return f"Image for {self.product.name}"


class ProductAttribute(models.Model):
    """
    Dynamic attributes for jewelry products.
    Examples: Diamond Quality, Gemstone Type, etc.
    """
    product = models.ForeignKey(Product, on_delete=models.CASCADE, related_name='attributes')
    attribute_name = models.CharField(max_length=50)  # 'Diamond Quality', 'Gemstone Type'
    attribute_value = models.CharField(max_length=100)
    
    class Meta:
        db_table = 'stock_product_attributes'
        unique_together = ['product', 'attribute_name']
    
    def __str__(self):
        return f"{self.product.name}: {self.attribute_name} = {self.attribute_value}"


class StockTransfer(models.Model):
    """
    Header for an Internal Stock Order (ISO).
    """
    STATUS_CHOICES = [
        ('draft', 'Draft'),
        ('requested', 'Requested'),
        ('approved', 'Approved'),
        ('shipped', 'Shipped'),
        ('received', 'Received'),
        ('cancelled', 'Cancelled'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='stock_transfers')
    iso_number = models.CharField(max_length=50, unique=True, editable=False)
    
    source_store = models.ForeignKey(Store, on_delete=models.CASCADE, related_name='outgoing_transfers')
    destination_store = models.ForeignKey(Store, on_delete=models.CASCADE, related_name='incoming_transfers')
    
    requested_by = models.ForeignKey(User, on_delete=models.CASCADE, related_name='requested_transfers')
    approved_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, blank=True, related_name='approved_transfers')
    
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='draft')
    notes = models.TextField(blank=True)
    
    request_date = models.DateField(auto_now_add=True)
    ship_date = models.DateField(null=True, blank=True)
    receive_date = models.DateField(null=True, blank=True)
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'stock_transfers'
        ordering = ['-created_at']
    
    def __str__(self):
        return f"{self.iso_number} ({self.source_store.name} -> {self.destination_store.name})"
    
    def save(self, *args, **kwargs):
        if not self.iso_number:
            # Generate ISO Number: ISO-YYYYMMDD-XXXX
            from django.utils import timezone
            import random
            today = timezone.now().strftime('%Y%m%d')
            rand = random.randint(1000, 9999)
            self.iso_number = f"ISO-{today}-{rand}"
        super().save(*args, **kwargs)

class TransferItem(models.Model):
    """
    Line items for a stock transfer.
    """
    transfer = models.ForeignKey(StockTransfer, on_delete=models.CASCADE, related_name='items')
    product = models.ForeignKey(Product, on_delete=models.CASCADE)
    
    quantity_requested = models.PositiveIntegerField(default=1)
    quantity_shipped = models.PositiveIntegerField(default=0)
    quantity_received = models.PositiveIntegerField(default=0)
    
    class Meta:
        db_table = 'stock_transfer_items'
    
    def __str__(self):
        return f"{self.product.name} - {self.quantity_requested}"

class Inventory(models.Model):
    """
    Current stock level of a product in a store.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='inventory')
    store = models.ForeignKey(Store, on_delete=models.CASCADE, related_name='inventory_items')
    product = models.ForeignKey(Product, on_delete=models.CASCADE, related_name='inventory_entries')
    quantity = models.PositiveIntegerField(default=0)
    
    last_updated = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'stock_inventory'
        unique_together = ['store', 'product']
        verbose_name_plural = 'Inventory'
        indexes = [
            models.Index(fields=['company', 'product']),
            models.Index(fields=['store', 'product']),
        ]
    
    def __str__(self):
        return f"{self.product.name} in {self.store.name}: {self.quantity}"
