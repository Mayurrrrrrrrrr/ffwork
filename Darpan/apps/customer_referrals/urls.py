"""
URL configuration for Customer Referrals module.
"""

from django.urls import path
from . import views

app_name = 'customer_referrals'

urlpatterns = [
    path('register/', views.AffiliateRegisterView.as_view(), name='register'),
    path('login/', views.AffiliateLoginView.as_view(), name='login'),
    path('logout/', views.affiliate_logout, name='logout'),
    path('dashboard/', views.AffiliateDashboardView.as_view(), name='dashboard'),
    path('add/', views.ReferralCreateView.as_view(), name='add_referral'),
]
