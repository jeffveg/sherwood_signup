from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from .forms import UserRegistrationForm,UserEditForm, TeamRegistrationForm, TeamUpdateForm
from django.contrib import messages
from .models import Team, Notification
from django.contrib.auth.models import User
import challonge
import re


# Create your views here.
challonge_username = "SherwoodAdventure"
challonge_key = "v6fQDM72P88S8LqLPilpTmvPQEOfbHw472q3Ze8I"

challonge.set_credentials(challonge_username, challonge_key)

@login_required
def edit_user(request):
    if request.method == 'POST':
        user_form = UserEditForm(instance=request.user, data = request.POST)

        if user_form.is_valid():
            user_form.save()
            messages.success(request, 'Profile updated successfully')
        else:
             messages.error(request, 'Error updating your profile')
    else:
        user_form = UserEditForm(instance=request.user)
    
    return render(request, 'authentication/edit_user.html', {'user_form': user_form})
        

@login_required
def home(request):
    all_tournaments = challonge.tournaments.index(state="pending")
    print(request.user.id)
    notifications = Notification.objects.filter(user=request.user.id, is_read=False)
    shown_notifications = notifications.filter(shown=True)
    if shown_notifications:
        shown_notifications.update(is_read=True)
    notifications.update(shown=True)
    print("Noti :", notifications)
    return render(request, 'authentication/home.html', {'tournaments': all_tournaments, 'notifications': notifications})
 
# Define a view function for the registration page
def register(request):
    if request.method=='POST':
        user_form = UserRegistrationForm(request.POST)
        if user_form.is_valid():
            new_user = user_form.save(commit=False)
            new_user.set_password(user_form.cleaned_data['password'])
            new_user.save()
            return render(request, 'authentication/register_done.html', {'new_user':new_user})
    else:
        user_form = UserRegistrationForm()
    return render(request, 'authentication/register.html', {'user_form':user_form})


def tournament_detail(request, tournament_id):
    tournament = None
    participants = None
    elim_pattern = r"Team\d{2}"
    robin_pattern = r"Group Time\s\d{2}:\d{2}\sTeam\s\d+"
    try:
        tournament = challonge.tournaments.show(tournament_id)
        participants = challonge.participants.index(tournament_id)
    except Exception as e:
        print(e)
    
    if tournament is not None:
        #tournament_type = tournament['tournament_type']
        if 'Round Robin' in tournament['description']:
            tournament_type =  'round robin'
        else:
            tournament_type =  tournament['tournament_type']
        registered_teams = Team.objects.filter(tournament_id=tournament_id)
        
        empty_slots = []
        for team in participants:
            if 'elimination' in tournament_type:
                match = re.search(elim_pattern, team['name'], re.IGNORECASE)
            elif 'round robin' in tournament_type:
                match = re.search(robin_pattern, team['name'], re.IGNORECASE)
            if match is not None:
                empty_slots.append(team)
    
    return render(request, 'authentication/tournament_detail.html', {'tournament': tournament, 'empty_slots': empty_slots, 'registered_teams': registered_teams})


#Team_registration to challonge

def team_registration(request, tournament_name, tournament_id, participant_id, original_name):
    user = request.user
    filtered_team = Team.objects.filter(tournament_id=tournament_id, participant_id=participant_id)
    team = None
    if len(filtered_team) > 0:
        team = filtered_team[0]
    print("The user is: ", user, tournament_id, participant_id)

    if team is None:
        print("got here")
        if request.method == "POST":
            form = TeamRegistrationForm(request.POST)
            if form.is_valid():
                updated = False
                try:
                    challonge.participants.update(tournament_id, participant_id, name=form.data['name'])
                    updated = True
                except Exception as e:
                    updated = False
                    print(e)
                
                if updated:
                    team = Team.objects.create(
                                        name=form.data['name'], 
                                        captain = user, 
                                        tournament_name = tournament_name, 
                                        tournament_id = tournament_id, 
                                        participant_id = participant_id,
                                        original_name = original_name)

                return redirect("team_detail", pk=team.id)
                #return redirect("team_detail", pk=team.id)
    
    else:
        redirect('/')
    
    tournament = challonge.tournaments.show(tournament_id)
    form = TeamRegistrationForm()
    return render(request, 'authentication/team_registration.html', {"form": form, 'tournament' : tournament})


#Deregesting from tournament

def deregister_team(request, team_id):
    team = get_object_or_404(Team, id=team_id)

    if team:
        original_name = team.original_name
        tournament_id = team.tournament_id
        participant_id = team.participant_id
        team.delete()
        try:
            # Change from create destory to update
            challonge.participants.update(tournament_id, participant_id, name=original_name)
            # challonge.participants.destroy(tournament_id, participant_id)
            # challonge.participants.create(tournament_id, name=original_name)
            messages.success(request, "You have unregistered from the tournament successfully")
        except Exception as e:
            print(e)
    return redirect('manage_teams')


def team_detail(request, pk):
    team = get_object_or_404(Team, pk=pk)
    tournament_detail = None
    if team:
        tournament_detail = challonge.tournaments.show(team.tournament_id)
    
    if request.method == 'POST':
        form = TeamUpdateForm(request.POST)
        if form.is_valid:
            if team:
                tournament_id = team.tournament_id
                participant_id = team.participant_id
                updated = False
                try:
                    challonge.participants.update(tournament_id, participant_id, name=form.data['name'])
                    updated = True
                except Exception as e:
                    updated = False
                
                if updated:
                    team.name = form.data['name']
                    # Not user configurable 
                    # team.max_members = form.data['max_members']
                    team.save()
                    messages.success(request, 'Team details updated successfully!')
                    return redirect('team_detail', pk=team.id)
                
    else:
        form = TeamUpdateForm()
    return render(request, 'authentication/team_detail.html', {'team': team, 'tournament_detail':tournament_detail, 'form':form})

def manage_teams(request):
    user = request.user
    captained_teams = user.captained_teams.all()

    return render(request, 'authentication/manage_teams.html', {'captained_teams': captained_teams})

def join_team(request, pk):
    user = request.user
    print("got join request")
    team = get_object_or_404(Team, id=pk)
    if team:
        if len(team.members.all()) < team.max_members:
            team.membership_requests.add(user)
            team.save()
            messages.success(request, f'Join request sent to {team.name}!')
            Notification.objects.create(user=team.captain, message = f'Someone sent a request to join your team "{team.name}"')
            return redirect('my_teams')
        else:
            messages.warning(request, "No empty slot. Can't join team")
            return redirect('tournament_detail', tournament_id = team.tournament_id)
    
    return redirect('/')
    

def my_teams(request):
    user = request.user
    return render(request, 'authentication/my_teams.html', {'user': user})


def accept_request(request, team_id, user_id):
    team = get_object_or_404(Team, id=team_id)
    player = get_object_or_404(User, id=user_id)

    if team is not None and player is not None:
        team.accept_membership_request(player)
        messages.success(request, f'Accepted request of "{player.username}"')
    return redirect('team_detail', pk=team.id)


def reject_request(request, team_id, user_id):
    team = get_object_or_404(Team, id=team_id)
    player = get_object_or_404(User, id=user_id)

    if team is not None and player is not None:
        team.reject_membership_request(player)
        messages.warning(request, f'Rejected request of "{player.username}"')
    return redirect('team_detail', pk=team.id)

def delete_member(request, team_id, user_id):
    team = get_object_or_404(Team, id=team_id)
    player = get_object_or_404(User, id=user_id)

    if team is not None and player is not None:
        team.delete_member(player)
        messages.warning(request, "Created Empty Slot")
    return redirect('team_detail', pk=team.id)

def all_teams(request):
    #team = get_object_or_404(Team, pk=16)
    #team = Team.objects.get(pk=15)
    team = Team.objects.all()
    return render(request, 'all_teams.html', {'team': team})