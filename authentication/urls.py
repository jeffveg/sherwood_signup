from django.urls import path, include
from django.contrib.auth import views as auth_views
from . import views

urlpatterns = [
    path('', views.home, name="home"),
    path('', include('django.contrib.auth.urls')),
    path('register/', views.register, name="register"),
    path('edit/', views.edit_user, name='edit_user'),
    path('tournaments/<int:tournament_id>/', views.tournament_detail, name='tournament_detail'),
    path('register-team/<str:tournament_name>/<int:tournament_id>/<int:participant_id>/<str:original_name>/', views.team_registration, name='team_registration'),
    path('deregister-team/<int:team_id>/', views.deregister_team, name='deregister_team'),
    path('team-detail/<int:pk>/', views.team_detail, name='team_detail'),
    path('manage-teams/', views.manage_teams, name="manage_teams"),
    path('join-team/<int:pk>/', views.join_team, name="join_team"),
    path('my-teams/', views.my_teams, name="my_teams"),
    path('accept-request/<int:team_id>/<int:user_id>/', views.accept_request, name="accept_request"),
    path('reject-request/<int:team_id>/<int:user_id>/', views.reject_request, name="reject_request"),
    path('delete-member/<int:team_id>/<int:user_id>/', views.delete_member, name="delete_member"),
    path('all-teams/', views.all_teams, name="all_teams"),
]