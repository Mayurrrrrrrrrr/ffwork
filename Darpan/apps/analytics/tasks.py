"""
Celery tasks for analytics module.
Handles async processing of large imports and reports.
"""
from celery import shared_task
from django.core.cache import cache
import logging

logger = logging.getLogger(__name__)


@shared_task(bind=True, max_retries=3)
def process_large_import(self, file_path, file_type, company_id, user_id):
    """
    Process large CSV imports asynchronously.
    For files > 10MB or > 50,000 rows.
    """
    try:
        from apps.analytics.flexible_importer import FlexibleImporter
        from apps.core.models import User, Company
        
        company = Company.objects.get(id=company_id)
        user = User.objects.get(id=user_id)
        
        importer = FlexibleImporter(company=company, user=user)
        
        with open(file_path, 'rb') as f:
            if file_type == 'sales':
                result = importer.import_sales(f)
            elif file_type == 'stock':
                result = importer.import_stock(f)
            else:
                result = {'error': f'Unknown file type: {file_type}'}
        
        # Clear related caches after import
        cache.delete(f'sales_kpis_{company_id}')
        cache.delete(f'stock_kpis_{company_id}')
        cache.delete(f'dashboard_data_{company_id}')
        
        logger.info(f"Async import completed: {result}")
        return result
        
    except Exception as exc:
        logger.error(f"Import task failed: {exc}")
        raise self.retry(exc=exc, countdown=60)


@shared_task
def refresh_kpi_cache(company_id):
    """
    Pre-calculate and cache KPI data for faster dashboard loads.
    Run periodically via Celery Beat.
    """
    try:
        from apps.core.models import Company
        from apps.analytics.models import SalesRecord, StockSnapshot
        from django.db.models import Sum, Count, Max, F
        
        company = Company.objects.get(id=company_id)
        
        # Calculate Sales KPIs
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale')
        
        sales_kpis = {
            'total_revenue': float(sales_qs.aggregate(t=Sum('final_amount'))['t'] or 0),
            'sales_count': sales_qs.count(),
            'total_margin': float(sales_qs.aggregate(t=Sum('gross_margin'))['t'] or 0),
        }
        cache.set(f'sales_kpis_{company_id}', sales_kpis, 300)
        
        # Calculate Stock KPIs
        stock_qs = StockSnapshot.objects.filter(company=company)
        latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        if latest_date:
            stock_qs = stock_qs.filter(snapshot_date=latest_date)
        
        stock_kpis = {
            'total_skus': stock_qs.values('style_code').distinct().count(),
            'stock_qty': stock_qs.aggregate(t=Sum('quantity'))['t'] or 0,
            'stock_value': float(stock_qs.aggregate(t=Sum(F('quantity') * F('sale_price')))['t'] or 0),
        }
        cache.set(f'stock_kpis_{company_id}', stock_kpis, 300)
        
        logger.info(f"KPI cache refreshed for company {company_id}")
        return {'sales': sales_kpis, 'stock': stock_kpis}
        
    except Exception as e:
        logger.error(f"KPI cache refresh failed: {e}")
        return None


@shared_task
def cleanup_old_imports():
    """
    Delete old import logs and temporary files.
    Run weekly via Celery Beat.
    """
    from datetime import timedelta
    from django.utils import timezone
    from apps.analytics.models import ImportLog
    import os
    
    # Keep only last 90 days of import logs
    cutoff = timezone.now() - timedelta(days=90)
    deleted, _ = ImportLog.objects.filter(imported_at__lt=cutoff).delete()
    
    logger.info(f"Cleaned up {deleted} old import logs")
    return {'deleted_logs': deleted}
