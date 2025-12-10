from django import forms
from .models import Course, Module

class CourseForm(forms.ModelForm):
    class Meta:
        model = Course
        fields = ['title', 'description', 'thumbnail', 'is_active']
        widgets = {
            'description': forms.Textarea(attrs={'rows': 4}),
        }

class ModuleForm(forms.ModelForm):
    class Meta:
        model = Module
        fields = ['title', 'description', 'order']
        widgets = {
            'description': forms.Textarea(attrs={'rows': 3}),
        }
