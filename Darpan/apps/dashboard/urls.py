"""
URL configuration for dashboard module.
"""

from django.urls import path
from . import views

app_name = 'dashboard'

urlpatterns = [
    path('', views.portal_home, name='home'),
    path('api/chat/', views.chat_with_ai, name='ai_chat'),  # AI Chatbot endpoint
]
