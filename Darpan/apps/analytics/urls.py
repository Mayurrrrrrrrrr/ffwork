"""
URL configuration for Analytics module.
"""

from django.urls import path
from . import views

app_name = 'analytics'

urlpatterns = [
    path('', views.AnalyticsDashboardView.as_view(), name='dashboard'),
    path('records/', views.SalesRecordListView.as_view(), name='list'),
    path('import/', views.SalesImportView.as_view(), name='import'),
    path('gold-rate/', views.GoldRateUpdateView.as_view(), name='gold_rate_update'),
]
