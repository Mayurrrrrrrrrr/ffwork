"""
Views for Tools module.
"""

from django.views.generic import TemplateView, ListView
from django.contrib.auth.mixins import LoginRequiredMixin
from django.db.models import Q
from apps.stock.models import Product, Inventory

class ToolsIndexView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/index.html'

from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin

class StockAccessRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        # Allow admin, platform_admin, and store_manager
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])

class StockLookupView(LoginRequiredMixin, StockAccessRequiredMixin, ListView):
    model = Inventory
    template_name = 'tools/stock_lookup.html'
    context_object_name = 'inventory_items'
    paginate_by = 20

    def get_queryset(self):
        queryset = Inventory.objects.filter(company=self.request.user.company).select_related('product', 'store')
        
        # Search Query (Name, SKU, Store)
        query = self.request.GET.get('q')
        if query:
            queryset = queryset.filter(
                Q(product__name__icontains=query) | 
                Q(product__sku__icontains=query) |
                Q(store__name__icontains=query)
            )
            
        # Advanced Filters
        category = self.request.GET.get('category')
        if category and category != 'all':
            queryset = queryset.filter(product__category=category)
            
        metal = self.request.GET.get('metal')
        if metal and metal != 'all':
            queryset = queryset.filter(product__base_metal=metal)
            
        size = self.request.GET.get('size')
        if size and size != 'all':
            queryset = queryset.filter(product__size=size)
            
        location = self.request.GET.get('location')
        if location and location != 'all':
            queryset = queryset.filter(store__name=location)
            
        price_min = self.request.GET.get('price_min')
        if price_min:
            queryset = queryset.filter(product__sale_price__gte=price_min)
            
        price_max = self.request.GET.get('price_max')
        if price_max:
            queryset = queryset.filter(product__sale_price__lte=price_max)

        # If no filters applied, return none (or all? Legacy returned none)
        # Let's return all if "filter" param is present (like legacy) or if any filter is set
        has_filters = any([query, category, metal, size, location, price_min, price_max])
        
        if has_filters or self.request.GET.get('filter'):
            return queryset.order_by('product__name', 'store__name')
        else:
            return Inventory.objects.none()

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        # Populate Filter Options (Distinct Values)
        context['categories'] = Product.objects.filter(company=company).values_list('category', flat=True).distinct().order_by('category')
        context['metals'] = Product.objects.filter(company=company).values_list('base_metal', flat=True).distinct().order_by('base_metal')
        context['sizes'] = Product.objects.filter(company=company).values_list('size', flat=True).distinct().order_by('size')
        context['locations'] = Inventory.objects.filter(company=company).values_list('store__name', flat=True).distinct().order_by('store__name')
        
        # Pass current filters to context
        context['current_filters'] = self.request.GET
        return context

class EMICalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/emi_calculator.html'

class SchemeCalculatorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/scheme_calculator.html'

class CertificateGeneratorView(LoginRequiredMixin, TemplateView):
    template_name = 'tools/certificate_generator.html'
