"""
Models for Purchase Orders module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from django.core.validators import MinValueValidator
from apps.core.models import User, Company

class Vendor(models.Model):
    """
    Supplier/Vendor details.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='vendors')
    name = models.CharField(max_length=255)
    contact_person = models.CharField(max_length=255, blank=True)
    email = models.EmailField(blank=True)
    phone = models.CharField(max_length=50, blank=True)
    address = models.TextField(blank=True)
    tax_id = models.CharField(max_length=50, blank=True, help_text="GSTIN or Tax ID")
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'purchasing_vendors'
        ordering = ['name']
        unique_together = ['company', 'name']
    
    def __str__(self):
        return self.name

class PurchaseOrder(models.Model):
    """
    Header model for a Purchase Order.
    """
    STATUS_CHOICES = [
        ('draft', 'Draft'),
        ('pending', 'Pending Approval'),
        ('approved', 'Approved'),
        ('rejected', 'Rejected'),
        ('issued', 'Issued to Vendor'),
        ('received', 'Goods Received'),
        ('cancelled', 'Cancelled'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='purchase_orders')
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='created_pos')
    vendor = models.ForeignKey(Vendor, on_delete=models.CASCADE, related_name='purchase_orders')
    
    po_number = models.CharField(max_length=50, unique=True, editable=False)
    order_date = models.DateField()
    expected_date = models.DateField(null=True, blank=True)
    
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='draft')
    total_amount = models.DecimalField(max_digits=12, decimal_places=2, default=0.00)
    notes = models.TextField(blank=True)
    
    # Workflow
    current_approver = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, blank=True,
                                         related_name='pos_to_approve')
    rejection_reason = models.TextField(blank=True)
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'purchasing_orders'
        ordering = ['-created_at']
    
    def __str__(self):
        return f"{self.po_number} - {self.vendor.name}"
    
    def save(self, *args, **kwargs):
        if not self.po_number:
            # Generate PO Number: PO-YYYYMMDD-XXXX
            from django.utils import timezone
            import random
            today = timezone.now().strftime('%Y%m%d')
            rand = random.randint(1000, 9999)
            self.po_number = f"PO-{today}-{rand}"
        super().save(*args, **kwargs)
        
    def calculate_total(self):
        total = sum(item.total_price for item in self.items.all())
        self.total_amount = total
        self.save()

class POItem(models.Model):
    """
    Line items for a Purchase Order.
    """
    po = models.ForeignKey(PurchaseOrder, on_delete=models.CASCADE, related_name='items')
    description = models.CharField(max_length=255, help_text="Product or Service description")
    quantity = models.PositiveIntegerField(default=1)
    unit_price = models.DecimalField(max_digits=10, decimal_places=2)
    total_price = models.DecimalField(max_digits=12, decimal_places=2, editable=False)
    
    class Meta:
        db_table = 'purchasing_po_items'
    
    def __str__(self):
        return f"{self.description} ({self.quantity})"
    
    def save(self, *args, **kwargs):
        self.total_price = self.quantity * self.unit_price
        super().save(*args, **kwargs)
        self.po.calculate_total()
        
    def delete(self, *args, **kwargs):
        super().delete(*args, **kwargs)
        self.po.calculate_total()
