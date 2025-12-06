"""
URL configuration for Analytics module.
"""

from django.urls import path
from . import views

app_name = 'analytics'

urlpatterns = [
    path('', views.AnalyticsDashboardView.as_view(), name='dashboard'),
    path('advanced/', views.AdvancedAnalyticsView.as_view(), name='advanced_dashboard'),
    path('records/', views.SalesRecordListView.as_view(), name='list'),
    path('import/', views.SalesImportView.as_view(), name='import'),
    path('import/history/', views.ImportLogListView.as_view(), name='import_history'),
    path('gold-rate/', views.GoldRateUpdateView.as_view(), name='gold_rate_update'),
    path('sales-kpis/', views.SalesKPIView.as_view(), name='sales_kpis'),
    path('stock-kpis/', views.StockKPIView.as_view(), name='stock_kpis'),
]
