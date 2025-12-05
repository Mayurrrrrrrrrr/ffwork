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
    
    # New fields for Advanced Product Finder
    style_code = models.CharField(max_length=100, blank=True)
    base_metal = models.CharField(max_length=50, blank=True)
    size = models.CharField(max_length=50, blank=True)
    sale_price = models.DecimalField(max_digits=12, decimal_places=2, default=0.00)
    
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'stock_products'
        ordering = ['name']
        unique_together = ['company', 'sku']
    
    def __str__(self):
        return f"{self.name} ({self.sku})"

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
