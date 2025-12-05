"""
URL Configuration for Darpan project.
"""
from django.contrib import admin
from django.urls import path, include
from django.conf import settings
from django.conf.urls.static import static

urlpatterns = [
    path('admin/', admin.site.urls),
    
    # App URLs - will be added as we create modules
    path('', include('apps.authentication.urls')),
    path('', include('apps.dashboard.urls')),
    path('expenses/', include('apps.expenses.urls')),
    path('btl/', include('apps.btl.urls')),
    path('learning/', include('apps.learning.urls')),
    path('purchasing/', include('apps.purchasing.urls')),
    path('tasks/', include('apps.tasks.urls')),
    path('stock/', include('apps.stock.urls')),
    path('reports/', include('apps.reports.urls')),
    path('tools/', include('apps.tools.urls')),
    path('referrals/', include('apps.referrals.urls')),
    path('admin-portal/', include('apps.admin_portal.urls')),
    path('analytics/', include('apps.analytics.urls')),
    path('old-gold/', include('apps.old_gold.urls')),
    path('affiliate/', include('apps.customer_referrals.urls')),
]

# Serve media files in development
if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
    urlpatterns += static(settings.STATIC_URL, document_root=settings.STATIC_ROOT)
