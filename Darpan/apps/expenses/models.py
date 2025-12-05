"""
Models for Employee Expenses module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from django.core.validators import MinValueValidator
from apps.core.models import User, Company


class ExpenseCategory(models.Model):
    """
    Master data for expense types (e.g., Travel, Food, Lodging).
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='expense_categories')
    name = models.CharField(max_length=100)
    description = models.TextField(blank=True)
    is_active = models.BooleanField(default=True)
    
    class Meta:
        db_table = 'expense_categories'
        verbose_name_plural = 'Expense Categories'
        ordering = ['name']
        unique_together = ['company', 'name']
    
    def __str__(self):
        return self.name


class ExpenseReport(models.Model):
    """
    Header model for an expense claim/report.
    """
    STATUS_CHOICES = [
        ('draft', 'Draft'),
        ('submitted', 'Submitted'),
        ('approved', 'Approved'),
        ('rejected', 'Rejected'),
        ('paid', 'Paid'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='expense_reports')
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='expense_reports')
    title = models.CharField(max_length=255, help_text="e.g., Client Visit to Mumbai")
    
    # Date range for the report
    start_date = models.DateField()
    end_date = models.DateField()
    
    # Financials
    total_amount = models.DecimalField(max_digits=10, decimal_places=2, default=0)
    
    # Workflow
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='draft')
    current_approver = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, blank=True,
                                         related_name='expenses_to_approve')
    rejection_reason = models.TextField(blank=True)
    
    # Timestamps
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    submitted_at = models.DateTimeField(null=True, blank=True)
    approved_at = models.DateTimeField(null=True, blank=True)
    paid_at = models.DateTimeField(null=True, blank=True)
    
    class Meta:
        db_table = 'expense_reports'
        ordering = ['-created_at']
        indexes = [
            models.Index(fields=['company', 'status']),
            models.Index(fields=['user', '-created_at']),
        ]
    
    def __str__(self):
        return f"{self.title} ({self.get_status_display()})"
    
    def calculate_total(self):
        """Recalculate total amount from items."""
        total = self.items.aggregate(total=models.Sum('amount'))['total'] or 0
        self.total_amount = total
        self.save(update_fields=['total_amount'])


class ExpenseItem(models.Model):
    """
    Line items for an expense report.
    """
    report = models.ForeignKey(ExpenseReport, on_delete=models.CASCADE, related_name='items')
    category = models.ForeignKey(ExpenseCategory, on_delete=models.PROTECT)
    date = models.DateField()
    description = models.CharField(max_length=255)
    amount = models.DecimalField(max_digits=10, decimal_places=2, validators=[MinValueValidator(0.01)])
    receipt = models.ImageField(upload_to='receipts/%Y/%m/', null=True, blank=True)
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'expense_items'
        ordering = ['date']
    
    def __str__(self):
        return f"{self.category.name}: {self.amount}"
    
    def save(self, *args, **kwargs):
        super().save(*args, **kwargs)
        # Update report total on save
        self.report.calculate_total()
    
    def delete(self, *args, **kwargs):
        report = self.report
        super().delete(*args, **kwargs)
        # Update report total on delete
        report.calculate_total()
