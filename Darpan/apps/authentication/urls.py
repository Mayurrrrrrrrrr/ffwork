"""
URL configuration for authentication module.
"""

from django.urls import path
from . import views

app_name = 'authentication'

urlpatterns = [
    path('login/', views.login_view, name='login'),
    path('logout/', views.logout_view, name='logout'),
    path('change-password/', views.change_password_view, name='change_password'),
    path('verify-email/<str:token>/', views.verify_email_view, name='verify_email'),
]
