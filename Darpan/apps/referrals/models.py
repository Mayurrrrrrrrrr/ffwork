"""
Models for Employee Referrals module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from apps.core.models import User, Company

class Candidate(models.Model):
    """
    Candidate referred by an employee.
    """
    STATUS_CHOICES = [
        ('submitted', 'Submitted'),
        ('under_review', 'Under Review'),
        ('interview', 'Interview Scheduled'),
        ('hired', 'Hired'),
        ('rejected', 'Rejected'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='referrals')
    referred_by = models.ForeignKey(User, on_delete=models.CASCADE, related_name='referrals')
    
    name = models.CharField(max_length=255)
    email = models.EmailField()
    phone = models.CharField(max_length=20)
    position = models.CharField(max_length=255, help_text="Position referred for")
    resume = models.FileField(upload_to='resumes/', blank=True, null=True)
    linkedin_url = models.URLField(blank=True, null=True)
    
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='submitted')
    notes = models.TextField(blank=True, help_text="HR notes")
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'referrals_candidates'
        ordering = ['-created_at']
    
    def __str__(self):
        return f"{self.name} - {self.position}"
