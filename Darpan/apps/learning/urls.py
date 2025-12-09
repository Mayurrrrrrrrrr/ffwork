"""
URL configuration for Learning Management module.
"""

from django.urls import path
from . import views

app_name = 'learning'

urlpatterns = [
    path('', views.CourseListView.as_view(), name='catalog'),
    path('course/<int:pk>/', views.CourseDetailView.as_view(), name='course_detail'),
    path('lesson/<int:pk>/', views.LessonView.as_view(), name='lesson'),
    path('quiz/<int:pk>/', views.QuizView.as_view(), name='quiz'),
    path('certificate/<int:course_id>/', views.GenerateCertificateView.as_view(), name='generate_certificate'),
    path('leaderboard/', views.LeaderboardView.as_view(), name='leaderboard'),
]
