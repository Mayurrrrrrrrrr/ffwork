from django.db import models
from apps.core.models import Company, User


class ImportLog(models.Model):
    """Track each import with column mapping information"""
    FILE_TYPE_CHOICES = [
        ('sales', 'Sales Data'),
        ('stock', 'Stock Data'),
        ('crm', 'CRM Data'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='import_logs')
    file_type = models.CharField(max_length=20, choices=FILE_TYPE_CHOICES)
    file_name = models.CharField(max_length=255)
    rows_imported = models.IntegerField(default=0)
    rows_skipped = models.IntegerField(default=0)
    rows_ignored = models.IntegerField(default=0)  # For RI/RR transactions
    columns_mapped = models.JSONField(default=list)
    columns_unmapped = models.JSONField(default=list)
    errors = models.JSONField(default=list)
    imported_at = models.DateTimeField(auto_now_add=True)
    imported_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True)
    
    class Meta:
        ordering = ['-imported_at']
        verbose_name = "Import Log"
    
    def __str__(self):
        return f"{self.file_type} import - {self.file_name} ({self.imported_at.strftime('%Y-%m-%d %H:%M')})"


class SalesRecord(models.Model):
    TRANSACTION_TYPE_CHOICES = [
        ('sale', 'Sale'),
        ('return', 'Return'),
        ('ignored', 'Ignored'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='sales_records')
    
    # Core Transaction Fields
    transaction_no = models.CharField(max_length=100, blank=True, null=True)
    transaction_date = models.DateField()
    transaction_type = models.CharField(max_length=20, choices=TRANSACTION_TYPE_CHOICES, default='sale')
    
    # Client Details
    client_name = models.CharField(max_length=255, blank=True)
    client_mobile = models.CharField(max_length=20, blank=True)
    pan_no = models.CharField(max_length=20, blank=True, null=True)
    gst_no = models.CharField(max_length=50, blank=True, null=True)
    
    # Product Details
    jewel_code = models.CharField(max_length=100, blank=True)
    style_code = models.CharField(max_length=100, blank=True)
    product_name = models.CharField(max_length=255, blank=True)
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
    revenue = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    gross_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    discount_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    discount_percentage = models.DecimalField(max_digits=5, decimal_places=2, default=0)
    gst_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    final_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    gross_margin = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    
    # Metadata
    region = models.CharField(max_length=100, blank=True)
    store_code = models.CharField(max_length=50, blank=True)
    sales_person = models.CharField(max_length=100, blank=True)
    entry_type = models.CharField(max_length=20, blank=True)
    
    created_by = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, related_name='sales_entries')
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        ordering = ['-transaction_date']
        indexes = [
            models.Index(fields=['company', 'transaction_date']),
            models.Index(fields=['company', 'style_code']),
            models.Index(fields=['company', 'transaction_type']),
            models.Index(fields=['company', 'region']),
        ]

    def __str__(self):
        return f"{self.transaction_no} - {self.product_name}"
    
    @property
    def image_url(self):
        """Generate S3 image URL from style code"""
        if self.style_code:
            prefix = self.style_code[:3]
            return f"https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{prefix}/{self.style_code}.jpg"
        return None


class StockSnapshot(models.Model):
    """Imported stock/inventory data"""
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='stock_snapshots')
    
    jewel_code = models.CharField(max_length=100)
    style_code = models.CharField(max_length=100, db_index=True)
    location = models.CharField(max_length=100)
    
    category = models.CharField(max_length=100, blank=True)
    sub_category = models.CharField(max_length=100, blank=True)
    base_metal = models.CharField(max_length=50, blank=True)
    item_size = models.CharField(max_length=20, blank=True)
    
    # Certificate info
    certificate_no = models.CharField(max_length=100, blank=True)  # Jewelry CertificateNo
    
    quantity = models.IntegerField(default=0)
    gross_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    net_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    pure_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    
    diamond_pieces = models.IntegerField(default=0)
    diamond_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    color_stone_pieces = models.IntegerField(default=0)
    color_stone_weight = models.DecimalField(max_digits=10, decimal_places=3, default=0)
    
    sale_price = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    
    # Opening stock period
    stock_month = models.CharField(max_length=20, blank=True)  # e.g., "Oct", "Nov"
    stock_year = models.IntegerField(null=True, blank=True)  # e.g., 2024
    
    snapshot_date = models.DateField()
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        ordering = ['-snapshot_date', 'style_code']
        indexes = [
            models.Index(fields=['company', 'style_code']),
            models.Index(fields=['company', 'location']),
            models.Index(fields=['company', 'category']),
            models.Index(fields=['company', 'snapshot_date']),
        ]
    
    def __str__(self):
        return f"{self.style_code} @ {self.location} (Qty: {self.quantity})"
    
    @property
    def image_url(self):
        if self.style_code:
            prefix = self.style_code[:3]
            return f"https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{prefix}/{self.style_code}.jpg"
        return None


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
