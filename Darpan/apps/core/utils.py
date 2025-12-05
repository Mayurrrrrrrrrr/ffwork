"""
Utility functions for the Darpan application.
Helper functions used across multiple modules.
"""

from django.contrib.auth import get_user_model
from .models import AuditLog

User = get_user_model()


def get_current_company_id(request):
    """
    Get the current user's company ID from the session/request.
    Returns None for platform admin users.
    """
    if hasattr(request, 'user') and request.user.is_authenticated:
        return request.user.company_id
    return None


def check_role(user, role_name):
    """
    Check if a user has a specific role.
    
    Args:
        user: User object
        role_name: String name of the role
        
    Returns:
        Boolean indicating if user has the role
    """
    if not user or not user.is_authenticated:
        return False
    return user.has_role(role_name)


def has_any_role(user, role_names):
    """
    Check if a user has any of the specified roles.
    
    Args:
        user: User object
        role_names: List of role names
        
    Returns:
        Boolean indicating if user has any of the roles
    """
    if not user or not user.is_authenticated:
        return False
    return user.has_any_role(role_names)


def log_audit_action(request, action_type, log_message, target_type=None, 
                     target_id=None, company=None, user=None):
    """
    Create an audit log entry.
    
    Args:
        request: HTTP request object (used to get user and IP)
        action_type: Type of action (e.g., 'create', 'update', 'delete', 'approve')
        log_message: Description of the action
        target_type: Type of object affected (e.g., 'expense_report', 'purchase_order')
        target_id: ID of the affected object
        company: Company object (optional, will use request.user.company if not provided)
        user: User object (optional, will use request.user if not provided)
        
    Returns:
        AuditLog object or None if creation failed
    """
    try:
        # Get user from request if not provided
        if user is None and hasattr(request, 'user') and request.user.is_authenticated:
            user = request.user
        
        # Get company from user if not provided
        if company is None and user and hasattr(user, 'company'):
            company = user.company
        
        # Get IP address
        ip_address = get_client_ip(request)
        
        # Convert target_id to string if it's not None
        target_id_str = str(target_id) if target_id is not None else None
        
        # Create audit log
        audit_log = AuditLog.objects.create(
            company=company,
            user=user,
            action_type=action_type,
            target_type=target_type,
            target_id=target_id_str,
            log_message=log_message,
            ip_address=ip_address
        )
        
        return audit_log
        
    except Exception as e:
        # Log error but don't fail the request
        import logging
        logger = logging.getLogger(__name__)
        logger.error(f"Failed to create audit log: {str(e)}")
        return None


def get_client_ip(request):
    """
    Get the client's IP address from the request.
    
    Args:
        request: HTTP request object
        
    Returns:
        String IP address or None
    """
    x_forwarded_for = request.META.get('HTTP_X_FORWARDED_FOR')
    if x_forwarded_for:
        ip = x_forwarded_for.split(',')[0]
    else:
        ip = request.META.get('REMOTE_ADDR')
    return ip


def get_status_badge_class(status):
    """
    Get Bootstrap badge class for expense report status.
    
    Args:
        status: Status string
        
    Returns:
        String Bootstrap class name
    """
    status_map = {
        'draft': 'bg-secondary',
        'pending_approval': 'bg-warning text-dark',
        'approved': 'bg-success',
        'rejected': 'bg-danger',
        'pending_verification': 'bg-info text-dark',
        'paid': 'bg-success',
    }
    return status_map.get(status.lower(), 'bg-secondary')


def get_po_status_badge_class(status):
    """
    Get Bootstrap badge class for purchase order status.
    
    Args:
        status: Status string
        
    Returns:
        String Bootstrap class name
    """
    status_map = {
        'New Request': 'bg-info text-dark',
        'Order Placed': 'bg-primary',
        'Goods Received': 'bg-secondary',
        'Inward Complete': 'bg-secondary',
        'QC Passed': 'bg-success',
        'QC Failed': 'bg-danger',
        'Imaging Complete': 'bg-dark',
        'Purchase File Generated': 'bg-warning text-dark',
        'Accounts Verified': 'bg-success',
        'Invoice Received': 'bg-primary',
        'Purchase Complete': 'bg-success',
        'Customer Delivered': 'bg-light text-dark border',
    }
    return status_map.get(status, 'bg-light text-dark')


def get_btl_status_badge_class(status):
    """
    Get Bootstrap badge class for BTL proposal status.
    
    Args:
        status: Status string
        
    Returns:
        String Bootstrap class name
    """
    status_map = {
        'Draft': 'bg-secondary',
        'Pending L1 Approval': 'bg-warning text-dark',
        'Pending L2 Approval': 'bg-info text-dark',
        'Approved': 'bg-success',
        'Rejected': 'bg-danger',
    }
    return status_map.get(status, 'bg-secondary')
