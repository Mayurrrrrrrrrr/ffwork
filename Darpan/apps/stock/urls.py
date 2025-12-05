"""
URL configuration for Stock Transfer module.
"""

from django.urls import path
from . import views

app_name = 'stock'

urlpatterns = [
    # Products
    path('products/', views.ProductListView.as_view(), name='product_list'),
    path('products/create/', views.ProductCreateView.as_view(), name='product_create'),
    path('products/search/', views.AdvancedProductSearchView.as_view(), name='product_search'),
    
    # Transfers
    path('', views.TransferListView.as_view(), name='transfer_list'),
    path('create/', views.TransferCreateView.as_view(), name='transfer_create'),
    path('<int:pk>/', views.TransferDetailView.as_view(), name='transfer_detail'),
    path('<int:pk>/action/', views.TransferActionView.as_view(), name='transfer_action'),
    path('quick-request/', views.QuickTransferRequestView.as_view(), name='quick_request'),
]
