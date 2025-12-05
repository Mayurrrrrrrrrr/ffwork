"""
Models for BTL (Below The Line) Marketing module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from django.core.validators import MinValueValidator
from apps.core.models import User, Company


class BTLProposal(models.Model):
    """
    Header model for a BTL marketing proposal.
    """
    STATUS_CHOICES = [
        ('draft', 'Draft'),
        ('submitted', 'Submitted'),
        ('approved', 'Approved'),
        ('rejected', 'Rejected'),
        ('completed', 'Completed'),
    ]
    
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='btl_proposals')
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='btl_proposals')
    title = models.CharField(max_length=255)
    description = models.TextField()
    
    # Planning
    proposed_date = models.DateField()
    location = models.CharField(max_length=255, help_text="Venue/Location for the activity")
    budget = models.DecimalField(max_digits=10, decimal_places=2, validators=[MinValueValidator(0.01)])
    
    # Workflow
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='draft')
    current_approver = models.ForeignKey(User, on_delete=models.SET_NULL, null=True, blank=True,
                                         related_name='btl_to_approve')
    rejection_reason = models.TextField(blank=True)
    
    # Timestamps
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    submitted_at = models.DateTimeField(null=True, blank=True)
    approved_at = models.DateTimeField(null=True, blank=True)
    completed_at = models.DateTimeField(null=True, blank=True)
    
    class Meta:
        db_table = 'btl_proposals'
        ordering = ['-created_at']
        indexes = [
            models.Index(fields=['company', 'status']),
            models.Index(fields=['user', '-created_at']),
        ]
    
    def __str__(self):
        return f"{self.title} ({self.get_status_display()})"


class BTLImage(models.Model):
    """
    Images related to the BTL activity (Plan, Execution, Result).
    """
    TYPE_CHOICES = [
        ('plan', 'Plan/Setup'),
        ('execution', 'Execution'),
        ('result', 'Result/Outcome'),
    ]
    
    proposal = models.ForeignKey(BTLProposal, on_delete=models.CASCADE, related_name='images')
    image = models.ImageField(upload_to='btl/%Y/%m/')
    caption = models.CharField(max_length=255, blank=True)
    image_type = models.CharField(max_length=20, choices=TYPE_CHOICES, default='execution')
    uploaded_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'btl_images'
        ordering = ['uploaded_at']
    
    def __str__(self):
        return f"{self.get_image_type_display()} - {self.proposal.title}"
