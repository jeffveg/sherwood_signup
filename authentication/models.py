from django.db import models
from django.contrib.auth.models import User
from django.contrib import messages

# Create your models here.

class Team(models.Model):
    name = models.CharField(max_length=250)
    members = models.ManyToManyField(User, related_name='teams')
    captain = models.ForeignKey(User, related_name='captained_teams', on_delete=models.SET_NULL, null=True)
    membership_requests = models.ManyToManyField(User, related_name='requested_teams', blank=True)
    tournament_name = models.CharField(max_length=250, null=True)
    tournament_id = models.IntegerField(null=True)
    participant_id = models.IntegerField()
    original_name = models.CharField(max_length=200)
    max_members = models.IntegerField(default=4)

    def accept_membership_request(self, user):
        self.members.add(user)
        self.membership_requests.remove(user)
        Notification.objects.create(user=user, message = f'Your request to join team "{self.name}" has been accepted')
        self.save()
    
    def reject_membership_request(self, user):
        self.membership_requests.remove(user)
        Notification.objects.create(user=user, message = f'Your request to join team "{self.name}" has been rejected')
        self.save()
    
    def delete_member(self, user):
        self.members.remove(user)
        self.save()
    
    def __str__(self):
        return self.name

class Notification(models.Model):
    user = models.ForeignKey(User, related_name='notifications', on_delete=models.CASCADE)
    message = models.CharField(max_length=350)
    is_read = models.BooleanField(default=False)
    shown = models.BooleanField(default=False)