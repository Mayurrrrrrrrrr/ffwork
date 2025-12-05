"""
Forms for BTL Marketing module.
"""

from django import forms
from .models import BTLProposal, BTLImage

class BTLProposalForm(forms.ModelForm):
    """
    Form for creating/editing a BTL proposal.
    """
    class Meta:
        model = BTLProposal
        fields = ['title', 'description', 'proposed_date', 'location', 'budget']
        widgets = {
            'title': forms.TextInput(attrs={'class': 'form-control', 'placeholder': 'e.g., Weekend Mall Activation'}),
            'description': forms.Textarea(attrs={'class': 'form-control', 'rows': 4}),
            'proposed_date': forms.DateInput(attrs={'type': 'date', 'class': 'form-control'}),
            'location': forms.TextInput(attrs={'class': 'form-control'}),
            'budget': forms.NumberInput(attrs={'class': 'form-control', 'min': '0.01', 'step': '0.01'}),
        }

class BTLImageForm(forms.ModelForm):
    """
    Form for uploading BTL images.
    """
    class Meta:
        model = BTLImage
        fields = ['image', 'caption', 'image_type']
        widgets = {
            'image': forms.FileInput(attrs={'class': 'form-control'}),
            'caption': forms.TextInput(attrs={'class': 'form-control', 'placeholder': 'Optional caption'}),
            'image_type': forms.Select(attrs={'class': 'form-select'}),
        }

class BTLApprovalForm(forms.Form):
    """
    Form for approvers to accept/reject a proposal.
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
            self.add_error('comment', "Comment is required when rejecting a proposal.")
        
        return cleaned_data
