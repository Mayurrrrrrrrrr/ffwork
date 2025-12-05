"""
URL configuration for dashboard module.
"""

from django.urls import path
from . import views

app_name = 'dashboard'

urlpatterns = [
    path('', views.portal_home, name='home'),
]
