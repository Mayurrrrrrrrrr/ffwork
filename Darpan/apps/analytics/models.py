from django.db import models
from apps.core.models import Company, User

class SalesRecord(models.Model):
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='sales_records')
    
    # Core Transaction Fields
    transaction_no = models.CharField(max_length=100, blank=True, null=True)
    transaction_date = models.DateField()
    
    # Client Details
    client_name = models.CharField(max_length=255, blank=True)
    client_mobile = models.CharField(max_length=20, blank=True)
    pan_no = models.CharField(max_length=20, blank=True, null=True)
    gst_no = models.CharField(max_length=50, blank=True, null=True)
    
    # Product Details
    jewel_code = models.CharField(max_length=100, blank=True)
    style_code = models.CharField(max_length=100, blank=True)
    product_name = models.CharField(max_length=255, blank=True) # Mapped from ProductCategory/Subcategory
    product_category = models.CharField(max_length=100, blank=True)
    product_subcategory = models.CharField(max_length=100, blank=True)
    collection = models.CharField(max_length=100, blank=True)
    base_metal = models.CharField(max_length=50, blank=True)
    
    # Weights & Counts
    gross_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    net_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    free_gold_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    
    solitaire_pieces = models.IntegerField(default=0)
    solitaire_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    total_diamond_pieces = models.IntegerField(default=0)
    total_diamond_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    color_stone_pieces = models.IntegerField(default=0)
    color_stone_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    
    # Financials
    quantity = models.IntegerField(default=1)
    revenue = models.DecimalField(max_digits=15, decimal_places=2, default=0) # NetSales
    gross_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    discount_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    gst_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    final_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    
    # Metadata
    region = models.CharField(max_length=100, blank=True) # Location
    store_code = models.CharField(max_length=50, blank=True)
    entry_type = models.CharField(max_length=20, blank=True)
    
    created_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, related_name='sales_entries')
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        ordering = ['-transaction_date']
        indexes = [
            models.Index(fields=['company', 'transaction_date']),
            models.Index(fields=['company', 'style_code']),
        ]

    def __str__(self):
        return f"{self.transaction_no} - {self.product_name}"


class GoldRate(models.Model):
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='gold_rates')
    rate_per_gram = models.DecimalField(max_digits=10, decimal_places=2)
    updated_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        verbose_name = "Gold Rate"
        verbose_name_plural = "Gold Rates"

    def __str__(self):
        return f"{self.company.name}: {self.rate_per_gram}"


class CollectionMaster(models.Model):
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='collections')
    style_code = models.CharField(max_length=100, db_index=True)
    collection_name = models.CharField(max_length=100, blank=True)
    collection_group = models.CharField(max_length=100, blank=True)
    product_name = models.CharField(max_length=255, blank=True)
    description = models.TextField(blank=True)
    
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        unique_together = ['company', 'style_code']
        verbose_name = "Collection Master"

    def __str__(self):
        return f"{self.style_code} - {self.collection_name}"
