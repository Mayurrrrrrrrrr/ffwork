"""
URL configuration for Purchase Orders module.
"""

from django.urls import path
from . import views

app_name = 'purchasing'

urlpatterns = [
    # Vendors
    path('vendors/', views.VendorListView.as_view(), name='vendor_list'),
    path('vendors/create/', views.VendorCreateView.as_view(), name='vendor_create'),
    path('vendors/<int:pk>/edit/', views.VendorUpdateView.as_view(), name='vendor_edit'),
    
    # POs
    path('', views.POListView.as_view(), name='po_list'),
    path('create/', views.POCreateView.as_view(), name='po_create'),
    path('<int:pk>/', views.PODetailView.as_view(), name='po_detail'),
    path('<int:pk>/submit/', views.POSubmitView.as_view(), name='po_submit'),
    path('<int:pk>/action/', views.POActionView.as_view(), name='po_action'),
]
