"""
Minimal stock search view - no complexity, just search.
"""
from django.views.generic import ListView
from django.contrib.auth.mixins import LoginRequiredMixin
from apps.analytics.models import StockSnapshot
from django.db.models import Max, Q


class StockSearchView(LoginRequiredMixin, ListView):
    """Ultra-simple stock search - just search and display."""
    model = StockSnapshot
    template_name = 'tools/stock_search.html'
    context_object_name = 'items'
    paginate_by = 50
    
    def get_queryset(self):
        """Get stock items with optional search."""
        # Get latest stock snapshot
        latest_date = StockSnapshot.objects.aggregate(Max('snapshot_date'))['snapshot_date__max']
        
        if not latest_date:
            return StockSnapshot.objects.none()
        
        qs = StockSnapshot.objects.filter(snapshot_date=latest_date)
        
        # Simple search by style code or location
        search = self.request.GET.get('q', '').strip()
        if search:
            qs = qs.filter(
                Q(style_code__icontains=search) | 
                Q(location__icontains=search)
            )
        
        return qs.order_by('style_code', 'location')
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['search_query'] = self.request.GET.get('q', '')
        
        # Get latest snapshot date
        latest_date = StockSnapshot.objects.aggregate(Max('snapshot_date'))['snapshot_date__max']
        context['snapshot_date'] = latest_date
        
        return context
