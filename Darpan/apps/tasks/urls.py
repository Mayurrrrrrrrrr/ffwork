"""
URL configuration for Tasks module.
"""

from django.urls import path
from . import views

app_name = 'tasks'

urlpatterns = [
    path('', views.TaskListView.as_view(), name='list'),
    path('create/', views.TaskCreateView.as_view(), name='create'),
    path('<int:pk>/', views.TaskDetailView.as_view(), name='detail'),
    path('<int:pk>/edit/', views.TaskUpdateView.as_view(), name='edit'),
    path('<int:pk>/comment/', views.TaskCommentView.as_view(), name='comment'),
    path('<int:pk>/status/', views.TaskStatusUpdateView.as_view(), name='status_update'),
]
