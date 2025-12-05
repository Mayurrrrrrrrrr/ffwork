from django import forms
from .models import OldGoldTransaction

class OldGoldForm(forms.ModelForm):
    class Meta:
        model = OldGoldTransaction
        fields = [
            'transaction_date', 'customer_name', 'customer_mobile', 'customer_pan', 'customer_address',
            'item_description', 'gross_weight_before', 'purity_before',
            'gross_weight_after', 'purity_after', 'deduction_addition', 
            'gold_rate_applied', 'final_value', 'remarks'
        ]
        widgets = {
            'transaction_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'customer_address': forms.Textarea(attrs={'rows': 2, 'class': 'form-control'}),
            'remarks': forms.Textarea(attrs={'rows': 2, 'class': 'form-control'}),
            'customer_name': forms.TextInput(attrs={'class': 'form-control'}),
            'customer_mobile': forms.TextInput(attrs={'class': 'form-control', 'pattern': '[0-9]{10}', 'title': '10 digit mobile number'}),
            'customer_pan': forms.TextInput(attrs={'class': 'form-control', 'style': 'text-transform: uppercase'}),
            'item_description': forms.TextInput(attrs={'class': 'form-control'}),
            'gross_weight_before': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.001'}),
            'purity_before': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.01'}),
            'gross_weight_after': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.001', 'required': 'true'}),
            'purity_after': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.01', 'required': 'true'}),
            'deduction_addition': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.001'}),
            'gold_rate_applied': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.01', 'required': 'true'}),
            'final_value': forms.NumberInput(attrs={'class': 'form-control', 'step': '0.01', 'readonly': 'readonly'}),
        }
