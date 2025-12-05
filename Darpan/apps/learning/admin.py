from django.contrib import admin
from .models import Course, Module, Lesson, Quiz, Question, UserCourseProgress

class ModuleInline(admin.StackedInline):
    model = Module
    extra = 1

@admin.register(Course)
class CourseAdmin(admin.ModelAdmin):
    list_display = ('title', 'company', 'is_active', 'created_at')
    list_filter = ('company', 'is_active')
    search_fields = ('title', 'description')
    inlines = [ModuleInline]

class LessonInline(admin.StackedInline):
    model = Lesson
    extra = 1

class QuizInline(admin.StackedInline):
    model = Quiz
    extra = 0

@admin.register(Module)
class ModuleAdmin(admin.ModelAdmin):
    list_display = ('title', 'course', 'order')
    list_filter = ('course__company', 'course')
    search_fields = ('title', 'course__title')
    inlines = [LessonInline, QuizInline]

class QuestionInline(admin.StackedInline):
    model = Question
    extra = 1

@admin.register(Quiz)
class QuizAdmin(admin.ModelAdmin):
    list_display = ('title', 'module', 'passing_score', 'is_active')
    list_filter = ('module__course', 'is_active')
    inlines = [QuestionInline]

@admin.register(UserCourseProgress)
class UserCourseProgressAdmin(admin.ModelAdmin):
    list_display = ('user', 'course', 'status', 'progress_percent', 'last_accessed')
    list_filter = ('status', 'course')
    search_fields = ('user__email', 'course__title')
    readonly_fields = ('progress_percent', 'started_at', 'completed_at', 'last_accessed')
