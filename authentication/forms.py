from django import forms
from django.contrib.auth.models import User

class UserRegistrationForm(forms.ModelForm):
    password = forms.CharField(label='password', widget=forms.PasswordInput)
    password2 = forms.CharField(label='Repeat Password', widget=forms.PasswordInput)

    class Meta:
        model = User
        fields = ('username', 'first_name', 'email')
    
    def clean_password2(self):
        cd = self.cleaned_data
        if cd["password"] != cd["password2"]:
            raise forms.ValidationError('Password dont\'t macth')
        return cd['password2']

class UserEditForm(forms.ModelForm):
    class Meta:
        model = User
        fields = ('username', 'email')

class TeamRegistrationForm(forms.Form):
    name = forms.CharField(label="Team name", max_length=250)

class TeamUpdateForm(forms.Form):
    name = forms.CharField(label="Team name", max_length=250)
    max_members = forms.IntegerField(label="max number of players")