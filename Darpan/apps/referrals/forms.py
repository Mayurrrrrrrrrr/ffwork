"""
Forms for Referrals module.
"""

from django import forms
from .models import Candidate

class ReferralForm(forms.ModelForm):
    class Meta:
        model = Candidate
        fields = ['name', 'email', 'phone', 'position', 'resume', 'linkedin_url']
        widgets = {
            'notes': forms.Textarea(attrs={'rows': 3}),
        }

class ReferralStatusForm(forms.ModelForm):
    class Meta:
        model = Candidate
        fields = ['status', 'notes']
        widgets = {
            'notes': forms.Textarea(attrs={'rows': 3}),
        }
