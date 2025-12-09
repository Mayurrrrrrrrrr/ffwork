"""
Certificate model for Learning Management System.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from apps.core.models import User
from .models import Course
import uuid
from datetime import datetime


class CourseCertificate(models.Model):
    """
    Generated certificate when user completes a course.
    """
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='certificates')
    course = models.ForeignKey(Course, on_delete=models.CASCADE, related_name='certificates')
    certificate_number = models.CharField(max_length=50, unique=True, editable=False)
    issued_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'lms_certificates'
        unique_together = ['user', 'course']
        ordering = ['-issued_at']
    
    def __str__(self):
        return f"Certificate {self.certificate_number} - {self.user.full_name} - {self.course.title}"
    
    def save(self, *args, **kwargs):
        if not self.certificate_number:
            # Generate unique certificate number
            timestamp = datetime.now().strftime('%Y%m%d%H%M%S')
            unique_id = str(uuid.uuid4())[:8].upper()
            self.certificate_number = f"CERT-{self.course.id}-{self.user.id}-{timestamp}-{unique_id}"
        super().save(*args, **kwargs)
