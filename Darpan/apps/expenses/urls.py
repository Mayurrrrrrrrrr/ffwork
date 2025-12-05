"""
URL configuration for Expenses module.
"""

from django.urls import path
from . import views

app_name = 'expenses'

urlpatterns = [
    path('', views.ExpenseListView.as_view(), name='list'),
    path('create/', views.ExpenseCreateView.as_view(), name='create'),
    path('<int:pk>/', views.ExpenseDetailView.as_view(), name='detail'),
    path('<int:pk>/edit/', views.ExpenseUpdateView.as_view(), name='edit'),
    path('<int:pk>/submit/', views.ExpenseSubmitView.as_view(), name='submit'),
    path('<int:pk>/action/', views.ExpenseActionView.as_view(), name='action'),
]
