"""
Views for Tools module.
"""

from django.views.generic import TemplateView, ListView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.db.models import Q, Max
from apps.analytics.models import StockSnapshot


class ToolsIndexView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/index.html'


class StockAccessRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        # Allow admin, platform_admin, and store_manager
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])


class StockLookupView(LoginRequiredMixin, StockAccessRequiredMixin, ListView):
    """Stock lookup using imported StockSnapshot data"""
    model = StockSnapshot
    template_name = 'tools/stock_lookup.html'
    context_object_name = 'stock_items'
    paginate_by = 24

    def get_queryset(self):
        company = self.request.user.company
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        if not company:
            return StockSnapshot.objects.none()
        
        queryset = StockSnapshot.objects.filter(company=company)
        
        # Get latest snapshot date
        latest_date = queryset.aggregate(Max('snapshot_date'))['snapshot_date__max']
        if latest_date:
            queryset = queryset.filter(snapshot_date=latest_date)
        
        # Search Query (Style Code, Jewel Code, Category, Location)
        query = self.request.GET.get('q')
        if query:
            queryset = queryset.filter(
                Q(style_code__icontains=query) | 
                Q(jewel_code__icontains=query) |
                Q(category__icontains=query) |
                Q(location__icontains=query) |
                Q(certificate_no__icontains=query)
            )
            
        # Advanced Filters
        category = self.request.GET.get('category')
        if category and category != 'all':
            queryset = queryset.filter(category=category)
            
        metal = self.request.GET.get('metal')
        if metal and metal != 'all':
            queryset = queryset.filter(base_metal=metal)
            
        size = self.request.GET.get('size')
        if size and size != 'all':
            queryset = queryset.filter(item_size=size)
            
        location = self.request.GET.get('location')
        if location and location != 'all':
            queryset = queryset.filter(location=location)
            
        price_min = self.request.GET.get('price_min')
        if price_min:
            queryset = queryset.filter(sale_price__gte=price_min)
            
        price_max = self.request.GET.get('price_max')
        if price_max:
            queryset = queryset.filter(sale_price__lte=price_max)

        # If no filters applied, return none unless filter param present
        has_filters = any([query, category and category != 'all', metal and metal != 'all', 
                          size and size != 'all', location and location != 'all', price_min, price_max])
        
        if has_filters or self.request.GET.get('filter'):
            return queryset.order_by('style_code', 'location')
        else:
            return StockSnapshot.objects.none()

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        if company:
            base_qs = StockSnapshot.objects.filter(company=company)
            # Get latest snapshot
            latest_date = base_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
            if latest_date:
                base_qs = base_qs.filter(snapshot_date=latest_date)
            
            # Populate Filter Options (Distinct Values)
            context['categories'] = base_qs.exclude(category='').values_list('category', flat=True).distinct().order_by('category')
            context['metals'] = base_qs.exclude(base_metal='').values_list('base_metal', flat=True).distinct().order_by('base_metal')
            context['sizes'] = base_qs.exclude(item_size='').values_list('item_size', flat=True).distinct().order_by('item_size')
            context['locations'] = base_qs.exclude(location='').values_list('location', flat=True).distinct().order_by('location')
            context['snapshot_date'] = latest_date
        else:
            context['categories'] = []
            context['metals'] = []
            context['sizes'] = []
            context['locations'] = []
        
        # Pass current filters to context
        context['current_filters'] = self.request.GET
        
        # Group items by style_code
        stock_items = context.get('stock_items', [])
        grouped = {}
        for item in stock_items:
            style = item.style_code
            if style not in grouped:
                grouped[style] = {
                    'style_code': style,
                    'category': item.category,
                    'sub_category': item.sub_category,
                    'base_metal': item.base_metal,
                    'total_qty': 0,
                    'min_price': item.sale_price,
                    'max_price': item.sale_price,
                    'locations': [],
                    'first_item': item,
                }
            grouped[style]['total_qty'] += item.quantity
            grouped[style]['min_price'] = min(grouped[style]['min_price'], item.sale_price)
            grouped[style]['max_price'] = max(grouped[style]['max_price'], item.sale_price)
            grouped[style]['locations'].append({
                'location': item.location,
                'quantity': item.quantity,
                'sale_price': item.sale_price,
                'jewel_code': item.jewel_code,
                'certificate_no': item.certificate_no,
                'gross_weight': item.gross_weight,
                'diamond_pieces': item.diamond_pieces,
            })
        
        context['grouped_items'] = list(grouped.values())
        return context


class EMICalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/emi_calculator.html'


class SchemeCalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/scheme_calculator.html'


class CertificateGeneratorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/certificate_generator.html'

