"""
Models for Customer Affiliate Program.
Separated from internal User model for security and logical isolation.
"""

from django.db import models
from django.contrib.auth.hashers import make_password, check_password
from apps.core.models import Company, Store

class Affiliate(models.Model):
    """
    External customer who refers others.
    Uses mobile number for login.
    """
    full_name = models.CharField(max_length=255)
    mobile_number = models.CharField(max_length=10, unique=True, help_text="10-digit mobile number")
    password_hash = models.CharField(max_length=255)
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='affiliates', default=1) # Default to main company
    created_at = models.DateTimeField(auto_now_add=True)
    is_active = models.BooleanField(default=True)

    class Meta:
        db_table = 'affiliates'
        ordering = ['full_name']

    def __str__(self):
        return f"{self.full_name} ({self.mobile_number})"

    def set_password(self, raw_password):
        self.password_hash = make_password(raw_password)

    def check_password(self, raw_password):
        return check_password(raw_password, self.password_hash)

class CustomerReferral(models.Model):
    """
    Lead referred by an Affiliate.
    """
    STATUS_CHOICES = [
        ('Pending', 'Pending'),
        ('Contacted', 'Contacted'),
        ('Converted', 'Converted'),
        ('Rejected', 'Rejected'),
    ]
    
    affiliate = models.ForeignKey(Affiliate, on_delete=models.CASCADE, related_name='referrals')
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='customer_referrals')
    store = models.ForeignKey(Store, on_delete=models.SET_NULL, null=True, related_name='customer_referrals')
    
    invitee_name = models.CharField(max_length=255)
    invitee_contact = models.CharField(max_length=10)
    invitee_address = models.TextField(blank=True)
    invitee_age = models.IntegerField(null=True, blank=True)
    invitee_gender = models.CharField(max_length=20, blank=True)
    interested_items = models.CharField(max_length=255, blank=True)
    remarks = models.TextField(blank=True)
    
    referral_code = models.CharField(max_length=20, unique=True)
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='Pending')
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'customer_referrals'
        ordering = ['-created_at']
        
    def __str__(self):
        return f"{self.invitee_name} (by {self.affiliate.full_name})"
