"""
Forms for Purchase Orders module.
"""

from django import forms
from django.forms import inlineformset_factory
from .models import Vendor, PurchaseOrder, POItem

class VendorForm(forms.ModelForm):
    class Meta:
        model = Vendor
        fields = ['name', 'contact_person', 'email', 'phone', 'address', 'tax_id']
        widgets = {
            'name': forms.TextInput(attrs={'class': 'form-control'}),
            'contact_person': forms.TextInput(attrs={'class': 'form-control'}),
            'email': forms.EmailInput(attrs={'class': 'form-control'}),
            'phone': forms.TextInput(attrs={'class': 'form-control'}),
            'address': forms.Textarea(attrs={'class': 'form-control', 'rows': 3}),
            'tax_id': forms.TextInput(attrs={'class': 'form-control'}),
        }

class PurchaseOrderForm(forms.ModelForm):
    class Meta:
        model = PurchaseOrder
        fields = ['vendor', 'order_date', 'expected_date', 'notes']
        widgets = {
            'vendor': forms.Select(attrs={'class': 'form-select'}),
            'order_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'expected_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'notes': forms.Textarea(attrs={'class': 'form-control', 'rows': 3}),
        }

    def __init__(self, *args, **kwargs):
        user = kwargs.pop('user', None)
        super().__init__(*args, **kwargs)
        if user:
            self.fields['vendor'].queryset = Vendor.objects.filter(company=user.company, is_active=True)

class POItemForm(forms.ModelForm):
    class Meta:
        model = POItem
        fields = ['description', 'quantity', 'unit_price']
        widgets = {
            'description': forms.TextInput(attrs={'class': 'form-control', 'placeholder': 'Item description'}),
            'quantity': forms.NumberInput(attrs={'class': 'form-control', 'min': '1'}),
            'unit_price': forms.NumberInput(attrs={'class': 'form-control', 'min': '0.01', 'step': '0.01'}),
        }

POItemFormSet = inlineformset_factory(
    PurchaseOrder, POItem, form=POItemForm,
    extra=1, can_delete=True
)

class POApprovalForm(forms.Form):
    action = forms.ChoiceField(
        choices=[('approve', 'Approve'), ('reject', 'Reject')],
        widget=forms.RadioSelect(attrs={'class': 'btn-check'})
    )
    comment = forms.CharField(
        required=False,
        widget=forms.Textarea(attrs={'class': 'form-control', 'rows': 3, 'placeholder': 'Comment (required for rejection)'})
    )

    def clean(self):
        cleaned_data = super().clean()
        action = cleaned_data.get('action')
        comment = cleaned_data.get('comment')
        if action == 'reject' and not comment:
            self.add_error('comment', "Comment is required when rejecting.")
        return cleaned_data
