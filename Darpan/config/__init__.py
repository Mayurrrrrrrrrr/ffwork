# Load Celery when Django starts (if installed)
try:
    from .celery import app as celery_app
    __all__ = ('celery_app',)
except ImportError:
    # Celery not installed - app will work without async tasks
    celery_app = None
    __all__ = ()
