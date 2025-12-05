"""
URL configuration for Tools module.
"""

from django.urls import path
from . import views

app_name = 'tools'

urlpatterns = [
    path('', views.ToolsIndexView.as_view(), name='index'),
    path('stock-lookup/', views.StockLookupView.as_view(), name='stock_lookup'),
    path('emi-calculator/', views.EMICalculatorView.as_view(), name='emi_calculator'),
    path('scheme-calculator/', views.SchemeCalculatorView.as_view(), name='scheme_calculator'),
    path('certificate-generator/', views.CertificateGeneratorView.as_view(), name='certificate_generator'),
]
