from django.contrib import admin
from .models import Candidate

@admin.register(Candidate)
class CandidateAdmin(admin.ModelAdmin):
    list_display = ('name', 'position', 'referred_by', 'status', 'created_at')
    list_filter = ('status', 'company', 'created_at')
    search_fields = ('name', 'email', 'position', 'referred_by__full_name')
