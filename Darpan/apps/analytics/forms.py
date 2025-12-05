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
    file_type = forms.ChoiceField(choices=[
        ('sales', 'Sales Data (CSV)'),
        ('stock', 'Stock Data (JSON)'),
        ('opening_stock', 'Opening Stock (JSON)'),
        ('cost', 'Cost Data (JSON)'),
        ('collection', 'Collection Master (CSV)'),
    ], widget=forms.Select(attrs={'class': 'form-select'}))
    
    data_file = forms.FileField(widget=forms.FileInput(attrs={'class': 'form-control', 'accept': '.csv,.json'}))
    
    snapshot_month = forms.DateField(required=False, widget=forms.DateInput(attrs={'type': 'month', 'class': 'form-control'}))

class GoldRateForm(forms.ModelForm):
    class Meta:
        model = GoldRate
        fields = ['rate_per_gram']
        widgets = {
            'rate_per_gram': forms.NumberInput(attrs={'step': '0.01', 'class': 'form-control'}),
        }
