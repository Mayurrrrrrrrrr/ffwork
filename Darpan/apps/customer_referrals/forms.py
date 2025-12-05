"""
Forms for Customer Referrals module.
"""

from django import forms
from .models import Affiliate, CustomerReferral

class AffiliateRegistrationForm(forms.ModelForm):
    password = forms.CharField(widget=forms.PasswordInput)
    confirm_password = forms.CharField(widget=forms.PasswordInput)
    
    class Meta:
        model = Affiliate
        fields = ['full_name', 'mobile_number']
        
    def clean(self):
        cleaned_data = super().clean()
        password = cleaned_data.get("password")
        confirm_password = cleaned_data.get("confirm_password")

        if password != confirm_password:
            raise forms.ValidationError("Passwords do not match")
            
        return cleaned_data

class AffiliateLoginForm(forms.Form):
    mobile_number = forms.CharField(max_length=10)
    password = forms.CharField(widget=forms.PasswordInput)

class CustomerReferralForm(forms.ModelForm):
    class Meta:
        model = CustomerReferral
        fields = ['invitee_name', 'invitee_contact', 'store', 'invitee_address', 
                  'invitee_age', 'invitee_gender', 'interested_items', 'remarks']
        widgets = {
            'invitee_address': forms.Textarea(attrs={'rows': 2}),
            'remarks': forms.Textarea(attrs={'rows': 2}),
            'invitee_gender': forms.Select(choices=[
                ('', '-- Select --'),
                ('Male', 'Male'),
                ('Female', 'Female'),
                ('Other', 'Other'),
            ]),
        }
