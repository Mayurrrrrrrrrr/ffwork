"""
URL configuration for BTL Marketing module.
"""

from django.urls import path
from . import views

app_name = 'btl'

urlpatterns = [
    path('', views.BTLListView.as_view(), name='list'),
    path('create/', views.BTLCreateView.as_view(), name='create'),
    path('<int:pk>/', views.BTLDetailView.as_view(), name='detail'),
    path('<int:pk>/edit/', views.BTLUpdateView.as_view(), name='edit'),
    path('<int:pk>/submit/', views.BTLSubmitView.as_view(), name='submit'),
    path('<int:pk>/action/', views.BTLActionView.as_view(), name='action'),
    path('<int:pk>/upload-image/', views.BTLImageUploadView.as_view(), name='upload_image'),
]
