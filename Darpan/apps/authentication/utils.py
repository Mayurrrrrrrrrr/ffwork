
import uuid
from django.core.mail import send_mail
from django.conf import settings
from django.urls import reverse

def send_verification_email(request, user):
    """
    Send verification email to the user.
    """
    token = str(uuid.uuid4())
    user.verification_token = token
    user.save()
    
    verify_url = request.build_absolute_uri(
        reverse('authentication:verify_email', args=[token])
    )
    
    subject = "Verify your email address - Darpan"
    message = f"Hi {user.full_name},\n\nPlease click the link below to verify your email address:\n{verify_url}\n\nIf you did not request this, please ignore this email."
    
    # In production, use send_mail. For now, print to console.
    print(f"--- EMAIL TO {user.email} ---\n{message}\n-----------------------------")
    
    # send_mail(subject, message, settings.DEFAULT_FROM_EMAIL, [user.email])

def verify_email_view(request, token):
    """
    Verify user's email address using the token.
    """
    from apps.core.models import User
    try:
        user = User.objects.get(verification_token=token)
        user.is_email_verified = True
        user.verification_token = None # Invalidate token
        user.save()
        messages.success(request, "Email verified successfully! You can now login.")
    except User.DoesNotExist:
        messages.error(request, "Invalid or expired verification link.")
        
    return redirect('authentication:login')
