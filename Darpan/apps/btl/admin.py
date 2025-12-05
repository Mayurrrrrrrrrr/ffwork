from django.contrib import admin
from .models import BTLProposal, BTLImage

class BTLImageInline(admin.TabularInline):
    model = BTLImage
    extra = 1
    readonly_fields = ('uploaded_at',)

@admin.register(BTLProposal)
class BTLProposalAdmin(admin.ModelAdmin):
    list_display = ('title', 'user', 'company', 'budget', 'status', 'proposed_date')
    list_filter = ('status', 'company', 'proposed_date')
    search_fields = ('title', 'user__email', 'location')
    inlines = [BTLImageInline]
    readonly_fields = ('created_at', 'updated_at', 'submitted_at', 'approved_at', 'completed_at')
