from django.db import models
from django.conf import settings
from apps.core.models import Company, Store

class OldGoldTransaction(models.Model):
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='old_gold_transactions')
    store = models.ForeignKey(Store, on_delete=models.CASCADE, related_name='old_gold_transactions')
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, null=True, related_name='processed_old_gold')
    
    transaction_date = models.DateField()
    bill_of_supply_no = models.CharField(max_length=50, unique=True)
    
    # Customer Details
    customer_name = models.CharField(max_length=255)
    customer_mobile = models.CharField(max_length=15)
    customer_pan = models.CharField(max_length=10)
    customer_address = models.TextField(blank=True)
    
    # Item Details
    item_description = models.CharField(max_length=255)
    gross_weight_before = models.DecimalField(max_digits=10, decimal_places=3, null=True, blank=True)
    purity_before = models.DecimalField(max_digits=5, decimal_places=2, null=True, blank=True)
    
    # Melting Details
    gross_weight_after = models.DecimalField(max_digits=10, decimal_places=3)
    purity_after = models.DecimalField(max_digits=5, decimal_places=2)
    deduction_addition = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    
    # Calculations
    net_weight_calculated = models.DecimalField(max_digits=10, decimal_places=3)
    gold_rate_applied = models.DecimalField(max_digits=10, decimal_places=2)
    final_value = models.DecimalField(max_digits=12, decimal_places=2)
    
    remarks = models.TextField(blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ['-transaction_date', '-created_at']

    def __str__(self):
        return f"{self.bill_of_supply_no} - {self.customer_name}"
