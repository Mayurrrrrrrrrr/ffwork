"""
Models for Learning Management System (LMS) module.
"""

from django.db import models
from django.utils.translation import gettext_lazy as _
from django.core.validators import MinValueValidator, MaxValueValidator
from apps.core.models import User, Company

class Course(models.Model):
    """
    Top-level container for a training course.
    """
    company = models.ForeignKey(Company, on_delete=models.CASCADE, related_name='courses')
    title = models.CharField(max_length=255)
    description = models.TextField()
    thumbnail = models.ImageField(upload_to='courses/thumbnails/', null=True, blank=True)
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'lms_courses'
        ordering = ['-created_at']
        unique_together = ['company', 'title']
    
    def __str__(self):
        return self.title

class Module(models.Model):
    """
    A section or chapter within a course.
    """
    course = models.ForeignKey(Course, on_delete=models.CASCADE, related_name='modules')
    title = models.CharField(max_length=255)
    description = models.TextField(blank=True)
    order = models.PositiveIntegerField(default=0)
    
    class Meta:
        db_table = 'lms_modules'
        ordering = ['order']
    
    def __str__(self):
        return f"{self.course.title} - {self.title}"

class Lesson(models.Model):
    """
    Individual learning content unit.
    """
    CONTENT_TYPES = [
        ('video', 'Video'),
        ('text', 'Text/Article'),
        ('pdf', 'PDF Document'),
    ]
    
    module = models.ForeignKey(Module, on_delete=models.CASCADE, related_name='lessons')
    title = models.CharField(max_length=255)
    content_type = models.CharField(max_length=20, choices=CONTENT_TYPES)
    
    # Content fields
    video_url = models.URLField(blank=True, help_text="YouTube/Vimeo URL")
    text_content = models.TextField(blank=True)
    file_upload = models.FileField(upload_to='courses/materials/', null=True, blank=True)
    
    duration_minutes = models.PositiveIntegerField(default=10, help_text="Estimated duration")
    order = models.PositiveIntegerField(default=0)
    
    class Meta:
        db_table = 'lms_lessons'
        ordering = ['order']
    
    def __str__(self):
        return self.title

class Quiz(models.Model):
    """
    Assessment attached to a module.
    """
    module = models.ForeignKey(Module, on_delete=models.CASCADE, related_name='quizzes')
    title = models.CharField(max_length=255)
    passing_score = models.PositiveIntegerField(default=70, validators=[MaxValueValidator(100)])
    is_active = models.BooleanField(default=True)
    
    class Meta:
        db_table = 'lms_quizzes'
    
    def __str__(self):
        return self.title

class Question(models.Model):
    """
    Question for a quiz.
    """
    quiz = models.ForeignKey(Quiz, on_delete=models.CASCADE, related_name='questions')
    text = models.TextField()
    
    # Simple multiple choice: Store options as JSON or separate model?
    # For simplicity, let's use fixed 4 options or JSON. 
    # Let's use JSON for flexibility.
    options = models.JSONField(default=list, help_text="List of options strings")
    correct_option_index = models.PositiveIntegerField(help_text="Index of correct option (0-based)")
    
    order = models.PositiveIntegerField(default=0)
    
    class Meta:
        db_table = 'lms_questions'
        ordering = ['order']
    
    def __str__(self):
        return self.text[:50]

# --- Progress Tracking ---

class UserCourseProgress(models.Model):
    """
    Tracks a user's overall progress in a course.
    """
    STATUS_CHOICES = [
        ('not_started', 'Not Started'),
        ('in_progress', 'In Progress'),
        ('completed', 'Completed'),
    ]
    
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='course_progress')
    course = models.ForeignKey(Course, on_delete=models.CASCADE, related_name='student_progress')
    status = models.CharField(max_length=20, choices=STATUS_CHOICES, default='not_started')
    progress_percent = models.PositiveIntegerField(default=0, validators=[MaxValueValidator(100)])
    started_at = models.DateTimeField(auto_now_add=True)
    completed_at = models.DateTimeField(null=True, blank=True)
    last_accessed = models.DateTimeField(auto_now=True)
    
    class Meta:
        db_table = 'lms_user_course_progress'
        unique_together = ['user', 'course']
    
    def __str__(self):
        return f"{self.user.full_name} - {self.course.title} ({self.progress_percent}%)"
    
    def update_progress(self):
        """Recalculate progress based on completed lessons."""
        total_lessons = Lesson.objects.filter(module__course=self.course).count()
        if total_lessons == 0:
            self.progress_percent = 100 if self.status == 'completed' else 0
            self.save()
            return

        completed_lessons = UserLessonProgress.objects.filter(
            user=self.user, 
            lesson__module__course=self.course,
            completed=True
        ).count()
        
        self.progress_percent = int((completed_lessons / total_lessons) * 100)
        if self.progress_percent == 100 and self.status != 'completed':
            self.status = 'completed'
            from django.utils import timezone
            self.completed_at = timezone.now()
        elif self.progress_percent > 0 and self.status == 'not_started':
            self.status = 'in_progress'
            
        self.save()

class UserLessonProgress(models.Model):
    """
    Tracks completion of individual lessons.
    """
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='lesson_progress')
    lesson = models.ForeignKey(Lesson, on_delete=models.CASCADE)
    completed = models.BooleanField(default=False)
    completed_at = models.DateTimeField(null=True, blank=True)
    
    class Meta:
        db_table = 'lms_user_lesson_progress'
        unique_together = ['user', 'lesson']

class UserQuizAttempt(models.Model):
    """
    Tracks user attempts at quizzes.
    """
    user = models.ForeignKey(User, on_delete=models.CASCADE, related_name='quiz_attempts')
    quiz = models.ForeignKey(Quiz, on_delete=models.CASCADE)
    score = models.PositiveIntegerField()
    passed = models.BooleanField(default=False)
    attempted_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'lms_user_quiz_attempts'
        ordering = ['-attempted_at']
