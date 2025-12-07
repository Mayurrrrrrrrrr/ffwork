"""
Email service for Darpan portal.
Handles verification emails, credential emails, and password reset emails.
"""

from django.core.mail import send_mail
from django.template.loader import render_to_string
from django.utils.html import strip_tags
from django.conf import settings
from django.contrib.auth.tokens import default_token_generator
from django.utils.http import urlsafe_base64_encode
from django.utils.encoding import force_bytes
import logging

logger = logging.getLogger(__name__)


class EmailService:
    """Centralized email service for the portal."""
    
    @staticmethod
    def _send_email(subject, html_message, recipient_email):
        """Send an email with HTML and plain text versions."""
        try:
            plain_message = strip_tags(html_message)
            send_mail(
                subject=subject,
                message=plain_message,
                from_email=settings.DEFAULT_FROM_EMAIL,
                recipient_list=[recipient_email],
                html_message=html_message,
                fail_silently=False,
            )
            logger.info(f"Email sent successfully to {recipient_email}")
            return True
        except Exception as e:
            logger.error(f"Failed to send email to {recipient_email}: {str(e)}")
            return False
    
    @staticmethod
    def generate_token_link(user, view_name):
        """Generate a secure token link for the user."""
        from django.urls import reverse
        
        uid = urlsafe_base64_encode(force_bytes(user.pk))
        token = default_token_generator.make_token(user)
        
        relative_url = reverse(view_name, kwargs={'uidb64': uid, 'token': token})
        base_url = getattr(settings, 'BASE_URL', 'http://localhost:8000')
        return f"{base_url.rstrip('/')}{relative_url}"
    
    @classmethod
    def send_verification_email(cls, user):
        """Send email verification link to user."""
        verification_link = cls.generate_token_link(user, 'authentication:verify_email')
        
        context = {
            'user': user,
            'verification_link': verification_link,
            'company': user.company,
        }
        
        html_message = render_to_string('email/verification.html', context)
        subject = f"{settings.EMAIL_SUBJECT_PREFIX}Verify Your Email Address"
        
        return cls._send_email(subject, html_message, user.email)
    
    @classmethod
    def send_credentials_email(cls, user, password=None, reset_link=True):
        """Send login credentials to user."""
        password_reset_link = None
        if reset_link:
            password_reset_link = cls.generate_token_link(user, 'authentication:password_reset_confirm')
        
        context = {
            'user': user,
            'password': password,
            'password_reset_link': password_reset_link,
            'company': user.company,
            'login_url': f"{settings.BASE_URL.rstrip('/')}/login/",
        }
        
        html_message = render_to_string('email/credentials.html', context)
        subject = f"{settings.EMAIL_SUBJECT_PREFIX}Your Login Credentials"
        
        return cls._send_email(subject, html_message, user.email)
    
    @classmethod
    def send_password_reset_email(cls, user):
        """Send password reset link to user."""
        reset_link = cls.generate_token_link(user, 'authentication:password_reset_confirm')
        
        context = {
            'user': user,
            'reset_link': reset_link,
            'company': user.company,
        }
        
        html_message = render_to_string('email/password_reset.html', context)
        subject = f"{settings.EMAIL_SUBJECT_PREFIX}Password Reset Request"
        
        return cls._send_email(subject, html_message, user.email)
    
    @classmethod
    def send_welcome_email(cls, user):
        """Send welcome email to new user."""
        context = {
            'user': user,
            'company': user.company,
            'login_url': f"{settings.BASE_URL.rstrip('/')}/login/",
        }
        
        html_message = render_to_string('email/welcome.html', context)
        subject = f"{settings.EMAIL_SUBJECT_PREFIX}Welcome to Darpan Portal"
        
        return cls._send_email(subject, html_message, user.email)
