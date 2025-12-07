"""
URL configuration for Analytics module.
"""

from django.urls import path
from . import views
from . import reports

app_name = 'analytics'

urlpatterns = [
    # Dashboard
    path('', views.AnalyticsDashboardView.as_view(), name='dashboard'),
    path('advanced/', views.AdvancedAnalyticsView.as_view(), name='advanced_dashboard'),
    
    # Data Management
    path('records/', views.SalesRecordListView.as_view(), name='list'),
    path('import/', views.SalesImportView.as_view(), name='import'),
    path('import/history/', views.ImportLogListView.as_view(), name='import_history'),
    path('gold-rate/', views.GoldRateUpdateView.as_view(), name='gold_rate_update'),
    
    # KPI Views
    path('sales-kpis/', views.SalesKPIView.as_view(), name='sales_kpis'),
    path('stock-kpis/', views.StockKPIView.as_view(), name='stock_kpis'),
    
    # Reports
    path('reports/', reports.ReportsMenuView.as_view(), name='reports_menu'),
    path('reports/sales/', reports.SalesPerformanceReport.as_view(), name='report_sales'),
    path('reports/products/', reports.ProductAnalysisReport.as_view(), name='report_products'),
    path('reports/sellthrough/', reports.SellThroughReport.as_view(), name='report_sellthrough'),
    path('reports/customers/', reports.CustomerInsightsReport.as_view(), name='report_customers'),
    path('reports/stock/', reports.StockSummaryReport.as_view(), name='report_stock'),
    path('reports/combined/', reports.CombinedInsightsReport.as_view(), name='report_combined'),
    path('reports/exhibition/', reports.ExhibitionReport.as_view(), name='report_exhibition'),
    path('reports/salesperson/', reports.SalespersonScorecardReport.as_view(), name='report_salesperson'),
]

