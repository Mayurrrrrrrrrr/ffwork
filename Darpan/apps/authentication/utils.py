"""
Authentication utilities including email verification and password reset.
"""

from django.shortcuts import redirect
from django.contrib import messages
from django.contrib.auth.tokens import default_token_generator
from django.utils.http import urlsafe_base64_decode
from django.utils.encoding import force_str
from apps.core.models import User


def verify_email_view(request, uidb64, token):
    """
    Verify user's email address using the secure token.
    """
    try:
        uid = force_str(urlsafe_base64_decode(uidb64))
        user = User.objects.get(pk=uid)
    except (TypeError, ValueError, OverflowError, User.DoesNotExist):
        user = None
    
    if user is not None and default_token_generator.check_token(user, token):
        user.is_email_verified = True
        user.save()
        messages.success(request, "Email verified successfully! You can now login.")
    else:
        messages.error(request, "Invalid or expired verification link.")
    
    return redirect('authentication:login')


def password_reset_confirm_view(request, uidb64, token):
    """
    Handle password reset confirmation with token validation.
    """
    from django.shortcuts import render
    from .forms import SetPasswordForm
    
    try:
        uid = force_str(urlsafe_base64_decode(uidb64))
        user = User.objects.get(pk=uid)
    except (TypeError, ValueError, OverflowError, User.DoesNotExist):
        user = None
    
    valid_link = user is not None and default_token_generator.check_token(user, token)
    
    if request.method == 'POST' and valid_link:
        form = SetPasswordForm(request.POST)
        if form.is_valid():
            user.set_password(form.cleaned_data['new_password'])
            user.is_email_verified = True  # Also verify email if resetting password
            user.save()
            messages.success(request, "Password set successfully! You can now login.")
            return redirect('authentication:login')
    else:
        form = SetPasswordForm()
    
    context = {
        'form': form,
        'valid_link': valid_link,
        'page_title': 'Set New Password'
    }
    return render(request, 'authentication/password_reset_confirm.html', context)
