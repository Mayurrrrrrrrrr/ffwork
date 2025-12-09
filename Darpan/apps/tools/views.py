"""
Views for Tools module.
"""

import logging
from django.views.generic import TemplateView, ListView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.db.models import Q, Max
from apps.analytics.models import StockSnapshot

logger = logging.getLogger(__name__)


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
        try:
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
            
            # Search Query - strip and check for actual value
            query = (self.request.GET.get('q') or '').strip()
            if query:
                queryset = queryset.filter(
                    Q(style_code__icontains=query) | 
                    Q(jewel_code__icontains=query) |
                    Q(category__icontains=query) |
                    Q(location__icontains=query) |
                    Q(certificate_no__icontains=query)
                )
                
            # Advanced Filters - strip and check for actual value
            category = (self.request.GET.get('category') or '').strip()
            if category and category != 'all':
                queryset = queryset.filter(category=category)
                
            metal = (self.request.GET.get('metal') or '').strip()
            if metal and metal != 'all':
                queryset = queryset.filter(base_metal=metal)
                
            size = (self.request.GET.get('size') or '').strip()
            if size and size != 'all':
                queryset = queryset.filter(item_size=size)
                
            location = (self.request.GET.get('location') or '').strip()
            if location and location != 'all':
                queryset = queryset.filter(location=location)
            
            # Price filters with safe parsing
            price_min = (self.request.GET.get('price_min') or '').strip()
            if price_min:
                try:
                    queryset = queryset.filter(sale_price__gte=float(price_min))
                except (ValueError, TypeError):
                    pass
                
            price_max = (self.request.GET.get('price_max') or '').strip()
            if price_max:
                try:
                    queryset = queryset.filter(sale_price__lte=float(price_max))
                except (ValueError, TypeError):
                    pass

            # Check if ANY filter is actually applied
            has_filters = any([
                query,
                category and category != 'all',
                metal and metal != 'all',
                size and size != 'all',
                location and location != 'all',
                price_min,
                price_max
            ])
            
            # If filter button clicked, show results even if no filters
            if self.request.GET.get('filter') or has_filters:
                return queryset.order_by('style_code', 'location')
            else:
                return StockSnapshot.objects.none()
                
        except Exception as e:
            logger.exception("StockLookupView.get_queryset failed")
            return StockSnapshot.objects.none()

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        
        # Initialize defaults to prevent template errors
        context.update({
            'categories': [],
            'metals': [],
            'sizes': [],
            'locations': [],
            'snapshot_date': None,
            'grouped_items': [],
            'current_filters': self.request.GET,
            'query_string': '',
        })
        try:
            # Get user's company or fallback to any available company
            company = self.request.user.company
            if not company:
                from apps.core.models import Company  
                company = Company.objects.first()
            
            # Query stock for user's company, or all stock if no company match
            if company:
                base_qs = StockSnapshot.objects.filter(company=company)
                if not base_qs.exists():
                    # Fallback: show all stock data if user's company has no data
                    base_qs = StockSnapshot.objects.all()
            else:
                base_qs = StockSnapshot.objects.all()
                
            if base_qs.exists():
                # Get latest snapshot
                latest_date = base_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
                
                if latest_date:
                    base_qs = base_qs.filter(snapshot_date=latest_date)
                    context['snapshot_date'] = latest_date
                    
                    # Debug logging
                    logger.info(f"StockLookup: Found {base_qs.count()} records for latest_date {latest_date}")
                    
                    # Safely populate filter options with limits
                    try:
                        context['categories'] = list(base_qs.exclude(category='').values_list('category', flat=True).distinct().order_by('category')[:100])
                        context['metals'] = list(base_qs.exclude(base_metal='').values_list('base_metal', flat=True).distinct().order_by('base_metal')[:50])
                        context['sizes'] = list(base_qs.exclude(item_size='').values_list('item_size', flat=True).distinct().order_by('item_size')[:50])
                        context['locations'] = list(base_qs.exclude(location='').values_list('location', flat=True).distinct().order_by('location')[:100])
                        
                        # Debug log filter counts
                        logger.info(f"StockLookup Filters - Categories: {len(context['categories'])}, Metals: {len(context['metals'])}, Sizes: {len(context['sizes'])}, Locations: {len(context['locations'])}")
                    except Exception as e:
                        logger.error(f"Error loading filter options: {e}")
            
            # Build pagination query string (without page param to prevent duplication)
            query_params = self.request.GET.copy()
            if 'page' in query_params:
                query_params.pop('page')
            context['query_string'] = query_params.urlencode()
            
            # Group items by style_code with defensive null handling
            stock_items = context.get('stock_items') or []
            grouped = {}
            
            for item in stock_items:
                if not item:
                    continue
                    
                style = item.style_code or 'Unknown'
                
                if style not in grouped:
                    grouped[style] = {
                        'style_code': style,
                        'category': item.category or 'Unknown',
                        'sub_category': item.sub_category or '',
                        'base_metal': item.base_metal or '',
                        'total_qty': 0,
                        'min_price': item.sale_price or 0,
                        'max_price': item.sale_price or 0,
                        'locations': [],
                        'first_item': item,
                    }
                
                grouped[style]['total_qty'] += (item.quantity or 0)
                
                # Update price range safely
                item_price = item.sale_price or 0
                if item_price > 0:
                    current_min = grouped[style]['min_price']
                    current_max = grouped[style]['max_price']
                    grouped[style]['min_price'] = min(current_min, item_price) if current_min > 0 else item_price
                    grouped[style]['max_price'] = max(current_max, item_price)
                
                grouped[style]['locations'].append({
                    'location': item.location or 'Unknown',
                    'quantity': item.quantity or 0,
                    'sale_price': item.sale_price or 0,
                    'jewel_code': item.jewel_code or '',
                    'certificate_no': item.certificate_no or '',
                    'gross_weight': item.gross_weight or 0,
                    'diamond_pieces': item.diamond_pieces or 0,
                })
            
            context['grouped_items'] = list(grouped.values())
            
        except Exception as e:
            logger.exception("StockLookupView.get_context_data failed")
            context['error'] = str(e)
        
        return context


class EMICalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/emi_calculator.html'


class SchemeCalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/scheme_calculator.html'


class CertificateGeneratorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/certificate_generator.html'

