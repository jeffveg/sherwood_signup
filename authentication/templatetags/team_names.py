from django import template
from authentication.models import Team
from django.db.models import Count


register = template.Library()

@register.filter(name='is_elimination_team')
def is_elimination_team(team_name):
  """
  This filter checks if the provided team name matches the pattern "Elimination Team\d{2}".
  """
  import re
  pattern = r"Elimination Team\d{2}"
  return bool(re.match(pattern, team_name))

@register.simple_tag(takes_context=True)
def is_captain(context, team_id):
  team = Team.objects.get(id=team_id)
  request = context['request']
  return team.captain == request.user

@register.simple_tag(takes_context=True)
def is_member(context, team_id):
  team = Team.objects.get(id=team_id)
  request = context['request']
  user = request.user
  if team.membership_requests.filter(id=user.id).exists():
    return 'Requested'
  elif team.members.filter(id=user.id).exists():
    return 'Joined'
  else:
    return 'Join Team'

@register.simple_tag(takes_context=True)
def membership_requests_count(context):
  request = context['request']
  user = request.user
  count = Team.objects.filter(captain=user).aggregate(total_requests=Count('membership_requests'))['total_requests']
  return count

# Usage example in your template
'''{% if team.name|is_elimination_team %}
  {% else %}
  {% endif %}'''
