from django import forms
from .models import SalesRecord, GoldRate, CollectionMaster

class SalesRecordForm(forms.ModelForm):
    class Meta:
        model = SalesRecord
        exclude = ['company', 'created_by', 'created_at']
        widgets = {
            'transaction_date': forms.DateInput(attrs={'type': 'date'}),
            'revenue': forms.NumberInput(attrs={'step': '0.01'}),
        }

class CSVImportForm(forms.Form):
    FILE_TYPES = [
        ('sales', 'Sales Data (sales.csv)'),
        ('stock', 'Stock/Inventory Data (stock.csv)'),
        ('crm', 'CRM Report (crmreport.csv)'),
        ('collection', 'Collection Master'),
    ]
    
    file_type = forms.ChoiceField(
        choices=FILE_TYPES,
        widget=forms.Select(attrs={'class': 'form-select', 'id': 'id_file_type'})
    )
    
    stock_date = forms.DateField(
        required=False,
        widget=forms.DateInput(attrs={
            'class': 'form-control',
            'type': 'date',
            'id': 'id_stock_date'
        }),
        help_text='Specify stock date (overrides date in file if provided)'
    )
    
    data_file = forms.FileField(
        widget=forms.FileInput(attrs={
            'class': 'form-control',
            'accept': '.csv,.xlsx,.xls'
        }),
        help_text='Upload CSV or Excel file'
    )

class GoldRateForm(forms.ModelForm):
    class Meta:
        model = GoldRate
        fields = ['rate_per_gram']
        widgets = {
            'rate_per_gram': forms.NumberInput(attrs={'step': '0.01', 'class': 'form-control'}),
        }
