"""
URL configuration for Admin Portal module.
"""

from django.urls import path
from . import views

app_name = 'admin_portal'

urlpatterns = [
    path('', views.AdminDashboardView.as_view(), name='dashboard'),
    
    # Users
    path('users/', views.UserListView.as_view(), name='user_list'),
    path('users/create/', views.UserCreateView.as_view(), name='user_create'),
    path('users/<int:pk>/update/', views.UserUpdateView.as_view(), name='user_update'),
    
    # Stores
    path('stores/', views.StoreListView.as_view(), name='store_list'),
    path('stores/add/', views.StoreCreateView.as_view(), name='store_create'),
    path('stores/<int:pk>/edit/', views.StoreUpdateView.as_view(), name='store_update'),
    
    # Platform Admin
    path('companies/', views.CompanyListView.as_view(), name='company_list'),
    path('companies/add/', views.CompanyCreateView.as_view(), name='company_create'),
    path('companies/<int:pk>/edit/', views.CompanyUpdateView.as_view(), name='company_update'),
]
