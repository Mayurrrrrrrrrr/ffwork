from django import forms
from .models import Course, Module, Lesson

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

class LessonForm(forms.ModelForm):
    """Form for creating/editing lessons with different content types."""
    
    class Meta:
        model = Lesson
        fields = ['title', 'content_type', 'video_url', 'text_content', 'file_upload', 'duration_minutes', 'order']
        widgets = {
            'title': forms.TextInput(attrs={'class': 'form-control'}),
            'content_type': forms.Select(attrs={'class': 'form-select', 'id': 'id_content_type'}),
            'video_url': forms.URLInput(attrs={'class': 'form-control', 'placeholder': 'https://www.youtube.com/watch?v=...'}),
            'text_content': forms.Textarea(attrs={'class': 'form-control', 'rows': 8}),
            'file_upload': forms.FileInput(attrs={'class': 'form-control', 'accept': '.pdf,.docx,.pptx,.html'}),
            'duration_minutes': forms.NumberInput(attrs={'class': 'form-control', 'min': 1}),
            'order': forms.NumberInput(attrs={'class': 'form-control', 'min': 0}),
        }
    
    def clean(self):
        cleaned_data = super().clean()
        content_type = cleaned_data.get('content_type')
        
        # Validate required fields based on content type
        if content_type == 'video':
            if not cleaned_data.get('video_url'):
                self.add_error('video_url', 'Video URL is required for video lessons.')
        elif content_type == 'text':
            if not cleaned_data.get('text_content'):
                self.add_error('text_content', 'Text content is required for text/article lessons.')
        elif content_type in ['pdf', 'word', 'ppt', 'html']:
            if not cleaned_data.get('file_upload') and not self.instance.file_upload:
                self.add_error('file_upload', f'File upload is required for {content_type.upper()} lessons.')
        
        return cleaned_data
