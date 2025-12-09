"""
Enhanced stock search view with filters - clean implementation.
"""
from django.views.generic import ListView
from django.contrib.auth.mixins import LoginRequiredMixin
from apps.analytics.models import StockSnapshot
from django.db.models import Max, Q


class StockSearchView(LoginRequiredMixin, ListView):
    """Stock search with category, metal, size, and location filters."""
    model = StockSnapshot
    template_name = 'tools/stock_search.html'
    context_object_name = 'items'
    paginate_by = 50
    
    def get_queryset(self):
        """Get filtered stock items."""
        # Get latest stock snapshot
        latest_date = StockSnapshot.objects.aggregate(Max('snapshot_date'))['snapshot_date__max']
        
        if not latest_date:
            return StockSnapshot.objects.none()
        
        qs = StockSnapshot.objects.filter(snapshot_date=latest_date)
        
        # Apply filters
        search = self.request.GET.get('q', '').strip()
        category = self.request.GET.get('category', '').strip()
        metal = self.request.GET.get('metal', '').strip()
        size = self.request.GET.get('size', '').strip()
        location = self.request.GET.get('location', '').strip()
        
        if search:
            qs = qs.filter(
                Q(style_code__icontains=search) | 
                Q(location__icontains=search) |
                Q(category__icontains=search)
            )
        
        if category and category != 'all':
            qs = qs.filter(category=category)
        
        if metal and metal != 'all':
            qs = qs.filter(base_metal=metal)
        
        if size and size != 'all':
            qs = qs.filter(item_size=size)
        
        if location and location != 'all':
            qs = qs.filter(location=location)
        
        return qs.order_by('style_code', 'location')
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        
        # Get latest snapshot date
        latest_date = StockSnapshot.objects.aggregate(Max('snapshot_date'))['snapshot_date__max']
        context['snapshot_date'] = latest_date
        
        # Get filter values (current selections)
        context['current_q'] = self.request.GET.get('q', '')
        context['current_category'] = self.request.GET.get('category', 'all')
        context['current_metal'] = self.request.GET.get('metal', 'all')
        context['current_size'] = self.request.GET.get('size', 'all')
        context['current_location'] = self.request.GET.get('location', 'all')
        
        # Get filter options from latest snapshot
        if latest_date:
            base_qs = StockSnapshot.objects.filter(snapshot_date=latest_date)
            context['categories'] = list(
                base_qs.exclude(category='').values_list('category', flat=True).distinct().order_by('category')
            )
            context['metals'] = list(
                base_qs.exclude(base_metal='').values_list('base_metal', flat=True).distinct().order_by('base_metal')
            )
            context['sizes'] = list(
                base_qs.exclude(item_size='').values_list('item_size', flat=True).distinct().order_by('item_size')
            )
            context['locations'] = list(
                base_qs.exclude(location='').values_list('location', flat=True).distinct().order_by('location')
            )
        else:
            context['categories'] = []
            context['metals'] = []
            context['sizes'] = []
            context['locations'] = []
        
        # Build query string for pagination
        params = self.request.GET.copy()
        if 'page' in params:
            params.pop('page')
        context['query_params'] = '&' + params.urlencode() if params else ''
        
        return context
