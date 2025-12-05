from django.contrib import admin
from .models import Task, TaskComment

class TaskCommentInline(admin.TabularInline):
    model = TaskComment
    extra = 0
    readonly_fields = ('created_at',)

@admin.register(Task)
class TaskAdmin(admin.ModelAdmin):
    list_display = ('title', 'assigned_to', 'assigned_by', 'priority', 'status', 'due_date')
    list_filter = ('status', 'priority', 'company')
    search_fields = ('title', 'description', 'assigned_to__email')
    inlines = [TaskCommentInline]
    readonly_fields = ('created_at', 'updated_at', 'completed_at')
