"""
URL configuration for Admin Portal module.
"""

from django.urls import path
from . import views

app_name = 'admin_portal'

urlpatterns = [
    # Main dashboard (routes based on user type)
    path('', views.AdminDashboardView.as_view(), name='dashboard'),
    
    # Platform Admin: Platform Dashboard
    path('platform/', views.PlatformAdminDashboardView.as_view(), name='platform_dashboard'),
    
    # Platform Admin: Company Management
    path('companies/', views.CompanyListView.as_view(), name='company_list'),
    path('companies/add/', views.CompanyCreateView.as_view(), name='company_create'),
    path('companies/<int:pk>/edit/', views.CompanyUpdateView.as_view(), name='company_update'),
    path('companies/<int:pk>/create-admin/', views.CompanyAdminCreateView.as_view(), name='company_admin_create'),
    path('companies/<int:pk>/modules/', views.CompanyModuleView.as_view(), name='company_modules'),
    path('companies/<int:pk>/delete/', views.CompanyDeleteView.as_view(), name='company_delete'),
    path('companies/<int:pk>/restore/', views.CompanyRestoreView.as_view(), name='company_restore'),
    
    # Platform Admin: Data Backup
    path('backup/', views.DataBackupView.as_view(), name='data_backup'),
    path('backup/<int:pk>/download/', views.BackupDownloadView.as_view(), name='backup_download'),
    
    # Company Admin: User Management
    path('users/', views.UserListView.as_view(), name='user_list'),
    path('users/create/', views.UserCreateView.as_view(), name='user_create'),
    path('users/<int:pk>/update/', views.UserUpdateView.as_view(), name='user_update'),
    path('users/<int:pk>/modules/', views.UserModuleView.as_view(), name='user_modules'),
    
    # Company Admin: Store Management
    path('stores/', views.StoreListView.as_view(), name='store_list'),
    path('stores/add/', views.StoreCreateView.as_view(), name='store_create'),
    path('stores/<int:pk>/edit/', views.StoreUpdateView.as_view(), name='store_update'),
    
    # Company Admin: Data Purge
    path('purge/', views.DataPurgeView.as_view(), name='data_purge'),
]

