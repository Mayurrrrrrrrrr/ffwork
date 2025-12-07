"""
Views for authentication module.
"""

from django.shortcuts import render, redirect
from django.contrib.auth import login as auth_login, logout as auth_logout
from django.contrib.auth.decorators import login_required
from django.contrib import messages
from django.views.decorators.http import require_http_methods
from apps.core.utils import log_audit_action
from .forms import LoginForm, ChangePasswordForm, PasswordResetRequestForm


@require_http_methods(["GET", "POST"])
def login_view(request):
    """
    Multi-tenant login view.
    Supports both platform admin and regular company users.
    """
    # Redirect if already  logged in
    if request.user.is_authenticated:
        return redirect('dashboard:home')
    
    if request.method == 'POST':
        form = LoginForm(request.POST)
        if form.is_valid():
            user = form.get_user()
            
            # Check email verification
            if not user.is_email_verified and not user.is_superuser:
                messages.error(request, "Please verify your email address before logging in.")
                return redirect('authentication:login')

            # Log the user in
            auth_login(request, user)
            
            # Store additional session data
            request.session['company_id'] = user.company_id
            request.session['store_id'] = user.store_id
            request.session['full_name'] = user.full_name
            request.session['roles'] = list(user.roles.values_list('name', flat=True))
            
            # Audit log
            log_audit_action(
                request=request,
                action_type='login',
                log_message=f"User logged in: {user.email}",
                user=user
            )
            
            # Redirect to dashboard
            messages.success(request, f"Welcome back, {user.get_short_name()}!")
            return redirect('dashboard:home')
    else:
        form = LoginForm()
    
    context = {
        'form': form,
        'page_title': 'Login - Company Workportal'
    }
    return render(request, 'authentication/login.html', context)


@login_required
def logout_view(request):
    """Logout view."""
    user_name = request.user.get_short_name() if request.user.is_authenticated else "User"
    
    # Audit log before logout
    log_audit_action(
        request=request,
        action_type='logout',
        log_message=f"User logged out: {request.user.email}"
    )
    
    # Logout
    auth_logout(request)
    
    messages.info(request, f"Goodbye, {user_name}! You have been logged out.")
    return redirect('authentication:login')


@login_required
@require_http_methods(["GET", "POST"])
def change_password_view(request):
    """Change password for logged-in users."""
    if request.method == 'POST':
        form = ChangePasswordForm(request.user, request.POST)
        if form.is_valid():
            new_password = form.cleaned_data['new_password']
            request.user.set_password(new_password)
            request.user.save()
            
            # Audit log
            log_audit_action(
                request=request,
                action_type='password_change',
                log_message=f"User changed password: {request.user.email}"
            )
            
            messages.success(request, "Your password has been changed successfully!")
            return redirect('dashboard:home')
    else:
        form = ChangePasswordForm(request.user)
    
    context = {
        'form': form,
        'page_title': 'Change Password'
    }
    return render(request, 'authentication/change_password.html', context)


@require_http_methods(["GET", "POST"])
def password_reset_request_view(request):
    """Handle password reset request - sends email with reset link."""
    if request.user.is_authenticated:
        return redirect('dashboard:home')
    
    if request.method == 'POST':
        form = PasswordResetRequestForm(request.POST)
        if form.is_valid():
            email = form.cleaned_data['email']
            from apps.core.models import User
            
            try:
                user = User.objects.get(email=email)
                # Send password reset email
                from apps.core.email_service import EmailService
                EmailService.send_password_reset_email(user)
                messages.success(request, "If an account exists with this email, you will receive a password reset link.")
            except User.DoesNotExist:
                # Don't reveal if email exists
                messages.success(request, "If an account exists with this email, you will receive a password reset link.")
            
            return redirect('authentication:login')
    else:
        form = PasswordResetRequestForm()
    
    context = {
        'form': form,
        'page_title': 'Reset Password'
    }
    return render(request, 'authentication/password_reset_request.html', context)


# Import views from utils
from .utils import verify_email_view, password_reset_confirm_view


