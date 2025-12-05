"""
Forms for Employee Expenses module.
"""

from django import forms
from django.forms import inlineformset_factory
from .models import ExpenseReport, ExpenseItem, ExpenseCategory

class ExpenseReportForm(forms.ModelForm):
    """
    Form for creating/editing the expense report header.
    """
    class Meta:
        model = ExpenseReport
        fields = ['title', 'start_date', 'end_date']
        widgets = {
            'start_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'end_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'title': forms.TextInput(attrs={'class': 'form-control', 'placeholder': 'e.g., Client Visit to Mumbai'}),
        }

class ExpenseItemForm(forms.ModelForm):
    """
    Form for individual expense items.
    """
    class Meta:
        model = ExpenseItem
        fields = ['date', 'category', 'description', 'amount', 'receipt']
        widgets = {
            'date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'category': forms.Select(attrs={'class': 'form-select'}),
            'description': forms.TextInput(attrs={'class': 'form-control', 'placeholder': 'Description'}),
            'amount': forms.NumberInput(attrs={'class': 'form-control', 'min': '0.01', 'step': '0.01'}),
            'receipt': forms.FileInput(attrs={'class': 'form-control'}),
        }

    def __init__(self, *args, **kwargs):
        user = kwargs.pop('user', None)
        super().__init__(*args, **kwargs)
        if user and user.company:
            # Filter categories by user's company
            self.fields['category'].queryset = ExpenseCategory.objects.filter(
                company=user.company, is_active=True
            )

# Formset for managing multiple items in one report
ExpenseItemFormSet = inlineformset_factory(
    ExpenseReport,
    ExpenseItem,
    form=ExpenseItemForm,
    extra=1,
    can_delete=True,
    min_num=1,
    validate_min=True
)

class ApprovalForm(forms.Form):
    """
    Form for approvers to accept/reject a report.
    """
    action = forms.ChoiceField(
        choices=[('approve', 'Approve'), ('reject', 'Reject')],
        widget=forms.RadioSelect(attrs={'class': 'btn-check'})
    )
    comment = forms.CharField(
        required=False,
        widget=forms.Textarea(attrs={
            'class': 'form-control', 
            'rows': 3, 
            'placeholder': 'Add a comment (required for rejection)'
        })
    )

    def clean(self):
        cleaned_data = super().clean()
        action = cleaned_data.get('action')
        comment = cleaned_data.get('comment')

        if action == 'reject' and not comment:
            self.add_error('comment', "Comment is required when rejecting a report.")
        
        return cleaned_data
