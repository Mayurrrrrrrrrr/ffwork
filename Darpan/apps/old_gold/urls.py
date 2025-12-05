from django.urls import path
from . import views

app_name = 'old_gold'

urlpatterns = [
    path('', views.OldGoldListView.as_view(), name='list'),
    path('create/', views.OldGoldCreateView.as_view(), name='create'),
    path('<int:pk>/print/', views.OldGoldDetailView.as_view(), name='print_bos'),
]
