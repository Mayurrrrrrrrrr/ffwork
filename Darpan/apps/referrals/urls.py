"""
URL configuration for Referrals module.
"""

from django.urls import path
from . import views

app_name = 'referrals'

urlpatterns = [
    path('', views.ReferralListView.as_view(), name='list'),
    path('create/', views.ReferralCreateView.as_view(), name='create'),
    path('<int:pk>/update/', views.ReferralUpdateView.as_view(), name='update'),
]
