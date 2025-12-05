"""
Forms for Stock Transfer module.
"""

from django import forms
from django.forms import inlineformset_factory
from .models import Product, StockTransfer, TransferItem
from apps.core.models import Store

class ProductForm(forms.ModelForm):
    class Meta:
        model = Product
        fields = ['name', 'sku', 'category', 'unit', 'description']
        widgets = {
            'name': forms.TextInput(attrs={'class': 'form-control'}),
            'sku': forms.TextInput(attrs={'class': 'form-control'}),
            'category': forms.TextInput(attrs={'class': 'form-control'}),
            'unit': forms.TextInput(attrs={'class': 'form-control'}),
            'description': forms.Textarea(attrs={'class': 'form-control', 'rows': 3}),
        }

class StockTransferForm(forms.ModelForm):
    class Meta:
        model = StockTransfer
        fields = ['source_store', 'destination_store', 'notes']
        widgets = {
            'source_store': forms.Select(attrs={'class': 'form-select'}),
            'destination_store': forms.Select(attrs={'class': 'form-select'}),
            'notes': forms.Textarea(attrs={'class': 'form-control', 'rows': 3}),
        }

    def __init__(self, *args, **kwargs):
        user = kwargs.pop('user', None)
        super().__init__(*args, **kwargs)
        if user:
            self.fields['source_store'].queryset = Store.objects.filter(company=user.company, is_active=True)
            self.fields['destination_store'].queryset = Store.objects.filter(company=user.company, is_active=True)

class TransferItemForm(forms.ModelForm):
    class Meta:
        model = TransferItem
        fields = ['product', 'quantity_requested']
        widgets = {
            'product': forms.Select(attrs={'class': 'form-select'}),
            'quantity_requested': forms.NumberInput(attrs={'class': 'form-control', 'min': '1'}),
        }

    def __init__(self, *args, **kwargs):
        user = kwargs.pop('user', None)
        super().__init__(*args, **kwargs)
        if user:
            self.fields['product'].queryset = Product.objects.filter(company=user.company, is_active=True)

TransferItemFormSet = inlineformset_factory(
    StockTransfer, TransferItem, form=TransferItemForm,
    extra=1, can_delete=True
)

class ReceiveForm(forms.ModelForm):
    class Meta:
        model = TransferItem
        fields = ['quantity_received']
        widgets = {
            'quantity_received': forms.NumberInput(attrs={'class': 'form-control', 'min': '0'}),
        }

ReceiveFormSet = inlineformset_factory(
    StockTransfer, TransferItem, form=ReceiveForm,
    extra=0, can_delete=False
)
