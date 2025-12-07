"""
Report views for Analytics module.
Provides intelligent reports with filters: Sales, Products, Customers, Stock, Sell-Through, Combined Insights.
"""

from django.views.generic import TemplateView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.db.models import Sum, Count, Avg, Max, Min, F, Q
from django.db.models.functions import TruncMonth, TruncDate, ExtractMonth, ExtractDay
from django.utils import timezone
from datetime import timedelta, datetime
from collections import defaultdict
import json

from .models import SalesRecord, StockSnapshot, CRMContact, ImportLog


class ReportAccessMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])


def get_company(user):
    """Get company for user or fallback to first available"""
    if user.company:
        return user.company
    from apps.core.models import Company
    return Company.objects.first()


def get_filter_options(company):
    """Get filter options for dropdowns"""
    sales_qs = SalesRecord.objects.filter(company=company) if company else SalesRecord.objects.none()
    stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
    
    return {
        'categories': list(sales_qs.exclude(product_category='').values_list('product_category', flat=True).distinct().order_by('product_category')[:50]),
        'subcategories': list(sales_qs.exclude(product_subcategory='').values_list('product_subcategory', flat=True).distinct().order_by('product_subcategory')[:50]),
        'collections': list(sales_qs.exclude(collection='').values_list('collection', flat=True).distinct().order_by('collection')[:50]),
        'locations': list(sales_qs.exclude(region='').values_list('region', flat=True).distinct().order_by('region')[:30]),
        'metals': list(sales_qs.exclude(base_metal='').values_list('base_metal', flat=True).distinct().order_by('base_metal')[:20]),
        'salespersons': list(sales_qs.exclude(sales_person='').values_list('sales_person', flat=True).distinct().order_by('sales_person')[:50]),
    }


def apply_filters(queryset, request, is_stock=False):
    """Apply common filters to queryset"""
    # Date filters
    date_from = request.GET.get('date_from')
    date_to = request.GET.get('date_to')
    
    if date_from:
        try:
            d = datetime.strptime(date_from, '%Y-%m-%d').date()
            if is_stock:
                queryset = queryset.filter(snapshot_date__gte=d)
            else:
                queryset = queryset.filter(transaction_date__gte=d)
        except: pass
    
    if date_to:
        try:
            d = datetime.strptime(date_to, '%Y-%m-%d').date()
            if is_stock:
                queryset = queryset.filter(snapshot_date__lte=d)
            else:
                queryset = queryset.filter(transaction_date__lte=d)
        except: pass
    
    # Other filters
    category = request.GET.get('category')
    if category:
        if is_stock:
            queryset = queryset.filter(category=category)
        else:
            queryset = queryset.filter(product_category=category)
    
    subcategory = request.GET.get('subcategory')
    if subcategory:
        if is_stock:
            queryset = queryset.filter(sub_category=subcategory)
        else:
            queryset = queryset.filter(product_subcategory=subcategory)
    
    collection = request.GET.get('collection')
    if collection and not is_stock:
        queryset = queryset.filter(collection=collection)
    
    location = request.GET.get('location')
    if location:
        if is_stock:
            queryset = queryset.filter(location=location)
        else:
            queryset = queryset.filter(region=location)
    
    metal = request.GET.get('metal')
    if metal:
        queryset = queryset.filter(base_metal=metal)
    
    salesperson = request.GET.get('salesperson')
    if salesperson and not is_stock:
        queryset = queryset.filter(sales_person=salesperson)
    
    return queryset


class ReportsMenuView(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Reports menu with links to all reports"""
    template_name = 'analytics/reports/menu.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['reports'] = [
            {'name': 'Sales Performance', 'url': 'analytics:report_sales', 'icon': 'trending-up', 'desc': 'Revenue by store, salesperson, and time'},
            {'name': 'Product Analysis', 'url': 'analytics:report_products', 'icon': 'package', 'desc': 'Top sellers, slow movers, category performance'},
            {'name': 'Sell-Through Analysis', 'url': 'analytics:report_sellthrough', 'icon': 'bar-chart-2', 'desc': 'Sales vs stock by style, category'},
            {'name': 'Customer Insights', 'url': 'analytics:report_customers', 'icon': 'users', 'desc': 'CRM data, birthdays, lead status'},
            {'name': 'Stock Summary', 'url': 'analytics:report_stock', 'icon': 'box', 'desc': 'Value by location, low stock alerts'},
            {'name': 'Combined Insights', 'url': 'analytics:report_combined', 'icon': 'layers', 'desc': 'CRM + Sales combined analysis'},
            {'name': 'Exhibition Report', 'url': 'analytics:report_exhibition', 'icon': 'calendar', 'desc': 'Exhibition sales analysis'},
            {'name': 'Salesperson Scorecard', 'url': 'analytics:report_salesperson', 'icon': 'user-check', 'desc': 'Individual performance metrics'},
        ]
        return context


class SalesPerformanceReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Sales Performance Report - by store, salesperson, time period"""
    template_name = 'analytics/reports/sales_performance.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        sales_qs = apply_filters(sales_qs, self.request)
        
        # Overall KPIs
        context['total_revenue'] = sales_qs.aggregate(total=Sum('revenue'))['total'] or 0
        context['total_transactions'] = sales_qs.count()
        context['avg_order_value'] = context['total_revenue'] / max(context['total_transactions'], 1)
        context['total_margin'] = sales_qs.aggregate(total=Sum('gross_margin'))['total'] or 0
        
        # By Store/Location
        store_data = sales_qs.values('region').annotate(
            revenue=Sum('revenue'),
            count=Count('id'),
            margin=Sum('gross_margin')
        ).order_by('-revenue')[:15]
        context['store_data'] = list(store_data)
        context['store_labels'] = json.dumps([s['region'] or 'Unknown' for s in store_data])
        context['store_values'] = json.dumps([float(s['revenue'] or 0) for s in store_data])
        
        # By Salesperson
        sales_person_data = sales_qs.exclude(sales_person='').values('sales_person').annotate(
            revenue=Sum('revenue'),
            count=Count('id'),
            avg_value=Avg('revenue')
        ).order_by('-revenue')[:15]
        context['salesperson_data'] = list(sales_person_data)
        
        # Daily Trend
        daily_data = sales_qs.values('transaction_date').annotate(
            total=Sum('revenue')
        ).order_by('transaction_date')
        
        daily_totals = defaultdict(float)
        for item in daily_data:
            if item['transaction_date']:
                daily_totals[item['transaction_date']] = float(item['total'] or 0)
        
        sorted_days = sorted(daily_totals.keys())[-60:]  # Last 60 days max
        context['trend_labels'] = json.dumps([d.strftime('%d %b') for d in sorted_days])
        context['trend_values'] = json.dumps([daily_totals[d] for d in sorted_days])
        
        return context


class ProductAnalysisReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Product Analysis Report - top sellers, slow movers, category performance"""
    template_name = 'analytics/reports/product_analysis.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        sales_qs = apply_filters(sales_qs, self.request)
        
        # Top 20 products by revenue
        top_products = sales_qs.values('style_code', 'product_category', 'collection').annotate(
            revenue=Sum('revenue'),
            qty=Sum('quantity'),
            margin=Sum('gross_margin'),
            avg_discount=Avg('discount_percentage')
        ).order_by('-revenue')[:20]
        context['top_products'] = list(top_products)
        
        # Category performance
        category_data = sales_qs.values('product_category').annotate(
            revenue=Sum('revenue'),
            count=Count('id'),
            margin=Sum('gross_margin')
        ).order_by('-revenue')[:10]
        context['category_data'] = list(category_data)
        context['category_labels'] = json.dumps([c['product_category'] or 'Unknown' for c in category_data])
        context['category_values'] = json.dumps([float(c['revenue'] or 0) for c in category_data])
        
        # Collection performance
        collection_data = sales_qs.exclude(collection='').values('collection').annotate(
            revenue=Sum('revenue'),
            count=Count('id')
        ).order_by('-revenue')[:10]
        context['collection_data'] = list(collection_data)
        
        # Discount impact
        context['avg_discount'] = sales_qs.aggregate(avg=Avg('discount_percentage'))['avg'] or 0
        context['total_discount'] = sales_qs.aggregate(total=Sum('discount_amount'))['total'] or 0
        
        return context


class SellThroughReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Sell-Through Report - Sales vs Stock analysis by style, category with date filters"""
    template_name = 'analytics/reports/sellthrough.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        # Get stock data (with optional date filter)
        stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
        
        # Allow selecting stock snapshot date
        stock_date = self.request.GET.get('stock_date')
        if stock_date:
            try:
                d = datetime.strptime(stock_date, '%Y-%m-%d').date()
                stock_qs = stock_qs.filter(snapshot_date=d)
            except:
                pass
        
        if not stock_qs.exists():
            latest_date = StockSnapshot.objects.filter(company=company).aggregate(Max('snapshot_date'))['snapshot_date__max'] if company else None
            if latest_date:
                stock_qs = StockSnapshot.objects.filter(company=company, snapshot_date=latest_date)
        
        stock_qs = apply_filters(stock_qs, self.request, is_stock=True)
        
        # Get available stock dates for dropdown
        available_dates = StockSnapshot.objects.filter(company=company).values_list('snapshot_date', flat=True).distinct().order_by('-snapshot_date')[:30] if company else []
        context['available_stock_dates'] = list(available_dates)
        
        # Get sales data
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        sales_qs = apply_filters(sales_qs, self.request)
        
        # Sell-through by Style Code
        style_stock = stock_qs.values('style_code').annotate(
            stock_qty=Sum('quantity'),
            stock_value=Sum(F('quantity') * F('sale_price'))
        )
        style_stock_dict = {s['style_code']: s for s in style_stock}
        
        style_sales = sales_qs.values('style_code').annotate(
            sold_qty=Sum('quantity'),
            sold_value=Sum('revenue')
        )
        
        sellthrough_data = []
        for s in style_sales:
            style_code = s['style_code']
            stock_info = style_stock_dict.get(style_code, {'stock_qty': 0, 'stock_value': 0})
            total_qty = (stock_info.get('stock_qty') or 0) + (s['sold_qty'] or 0)
            sellthrough_pct = (s['sold_qty'] or 0) / max(total_qty, 1) * 100
            sellthrough_data.append({
                'style_code': style_code,
                'sold_qty': s['sold_qty'],
                'sold_value': s['sold_value'],
                'stock_qty': stock_info.get('stock_qty', 0),
                'stock_value': stock_info.get('stock_value', 0),
                'sellthrough_pct': round(sellthrough_pct, 1)
            })
        
        sellthrough_data.sort(key=lambda x: x['sellthrough_pct'], reverse=True)
        context['sellthrough_by_style'] = sellthrough_data[:50]
        
        # Sell-through by Category
        cat_stock = stock_qs.values('category').annotate(
            stock_qty=Sum('quantity'),
            stock_value=Sum(F('quantity') * F('sale_price'))
        )
        cat_stock_dict = {c['category']: c for c in cat_stock}
        
        cat_sales = sales_qs.values('product_category').annotate(
            sold_qty=Sum('quantity'),
            sold_value=Sum('revenue')
        )
        
        sellthrough_by_cat = []
        for c in cat_sales:
            cat = c['product_category']
            stock_info = cat_stock_dict.get(cat, {'stock_qty': 0, 'stock_value': 0})
            total_qty = (stock_info.get('stock_qty') or 0) + (c['sold_qty'] or 0)
            sellthrough_pct = (c['sold_qty'] or 0) / max(total_qty, 1) * 100
            sellthrough_by_cat.append({
                'category': cat or 'Unknown',
                'sold_qty': c['sold_qty'],
                'sold_value': c['sold_value'],
                'stock_qty': stock_info.get('stock_qty', 0),
                'stock_value': stock_info.get('stock_value', 0),
                'sellthrough_pct': round(sellthrough_pct, 1)
            })
        
        sellthrough_by_cat.sort(key=lambda x: x['sellthrough_pct'], reverse=True)
        context['sellthrough_by_category'] = sellthrough_by_cat
        context['snapshot_date'] = stock_qs.first().snapshot_date if stock_qs.exists() else None
        
        return context


class CustomerInsightsReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Customer Insights Report - CRM data, birthdays, lead status"""
    template_name = 'analytics/reports/customer_insights.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        crm_qs = CRMContact.objects.filter(company=company) if company else CRMContact.objects.none()
        
        # Total contacts
        context['total_contacts'] = crm_qs.count()
        
        if context['total_contacts'] == 0:
            context['lead_status_data'] = []
            context['lead_labels'] = json.dumps([])
            context['lead_values'] = json.dumps([])
            context['lead_source_data'] = []
            context['upcoming_birthdays'] = []
            context['upcoming_anniversaries'] = []
            context['store_data'] = []
            context['total_loyalty_points'] = 0
            context['total_redeemed'] = 0
            return context
        
        # Lead status distribution
        lead_status_data = list(crm_qs.exclude(lead_status='').values('lead_status').annotate(
            count=Count('id')
        ).order_by('-count'))
        context['lead_status_data'] = lead_status_data
        context['lead_labels'] = json.dumps([l['lead_status'] for l in lead_status_data])
        context['lead_values'] = json.dumps([l['count'] for l in lead_status_data])
        
        # Lead source distribution
        context['lead_source_data'] = list(crm_qs.exclude(lead_source='').values('lead_source').annotate(
            count=Count('id')
        ).order_by('-count')[:10])
        
        # Upcoming birthdays (next 30 days) - safer approach
        today = timezone.now().date()
        upcoming_bdays = []
        
        for contact in crm_qs.exclude(dob__isnull=True).only('full_name', 'mobile', 'dob')[:500]:
            try:
                if contact.dob:
                    # Handle Feb 29 safely
                    try:
                        this_year_bday = contact.dob.replace(year=today.year)
                    except ValueError:
                        this_year_bday = contact.dob.replace(year=today.year, day=28)
                    
                    if this_year_bday < today:
                        try:
                            this_year_bday = contact.dob.replace(year=today.year + 1)
                        except ValueError:
                            this_year_bday = contact.dob.replace(year=today.year + 1, day=28)
                    
                    days_until = (this_year_bday - today).days
                    if 0 <= days_until <= 30:
                        upcoming_bdays.append({
                            'name': contact.full_name,
                            'mobile': contact.mobile,
                            'dob': contact.dob,
                            'days_until': days_until
                        })
            except Exception:
                continue
        
        upcoming_bdays.sort(key=lambda x: x['days_until'])
        context['upcoming_birthdays'] = upcoming_bdays[:20]
        
        # Upcoming anniversaries (same safe approach)
        upcoming_anniv = []
        for contact in crm_qs.exclude(anniversary__isnull=True).only('full_name', 'mobile', 'anniversary')[:500]:
            try:
                if contact.anniversary:
                    try:
                        this_year = contact.anniversary.replace(year=today.year)
                    except ValueError:
                        this_year = contact.anniversary.replace(year=today.year, day=28)
                    
                    if this_year < today:
                        try:
                            this_year = contact.anniversary.replace(year=today.year + 1)
                        except ValueError:
                            this_year = contact.anniversary.replace(year=today.year + 1, day=28)
                    
                    days_until = (this_year - today).days
                    if 0 <= days_until <= 30:
                        upcoming_anniv.append({
                            'name': contact.full_name,
                            'mobile': contact.mobile,
                            'anniversary': contact.anniversary,
                            'days_until': days_until
                        })
            except Exception:
                continue
        
        upcoming_anniv.sort(key=lambda x: x['days_until'])
        context['upcoming_anniversaries'] = upcoming_anniv[:20]
        
        # By store
        context['store_data'] = list(crm_qs.exclude(store_name='').values('store_name').annotate(
            count=Count('id')
        ).order_by('-count')[:10])
        
        # Loyalty stats
        context['total_loyalty_points'] = crm_qs.aggregate(total=Sum('loyalty_points'))['total'] or 0
        context['total_redeemed'] = crm_qs.aggregate(total=Sum('loyalty_redeemed'))['total'] or 0
        
        return context


class StockSummaryReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Stock Summary Report - value by location, category, low stock alerts"""
    template_name = 'analytics/reports/stock_summary.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
        latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        
        if latest_date:
            stock_qs = stock_qs.filter(snapshot_date=latest_date)
        
        stock_qs = apply_filters(stock_qs, self.request, is_stock=True)
        
        context['snapshot_date'] = latest_date
        
        if not stock_qs.exists():
            context['total_skus'] = 0
            context['total_qty'] = 0
            context['total_value'] = 0
            context['total_weight'] = 0
            context['location_data'] = []
            context['location_labels'] = json.dumps([])
            context['location_values'] = json.dumps([])
            context['category_data'] = []
            context['low_stock_items'] = []
            return context
        
        # Overall KPIs
        context['total_skus'] = stock_qs.values('style_code').distinct().count()
        context['total_qty'] = stock_qs.aggregate(total=Sum('quantity'))['total'] or 0
        context['total_value'] = stock_qs.aggregate(total=Sum(F('quantity') * F('sale_price')))['total'] or 0
        context['total_weight'] = stock_qs.aggregate(total=Sum('gross_weight'))['total'] or 0
        
        # By Location
        location_data = list(stock_qs.values('location').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price')),
            sku_count=Count('style_code', distinct=True)
        ).order_by('-value')[:10])
        context['location_data'] = location_data
        context['location_labels'] = json.dumps([l['location'] or 'Unknown' for l in location_data])
        context['location_values'] = json.dumps([float(l['value'] or 0) for l in location_data])
        
        # By Category
        context['category_data'] = list(stock_qs.values('category').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price'))
        ).order_by('-value')[:10])
        
        # Low stock items (qty = 1)
        context['low_stock_items'] = stock_qs.filter(quantity__lte=1, quantity__gt=0).order_by('quantity', '-sale_price')[:30]
        
        return context


class CombinedInsightsReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Combined Insights Report - CRM + Sales data analysis"""
    template_name = 'analytics/reports/combined_insights.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        crm_qs = CRMContact.objects.filter(company=company) if company else CRMContact.objects.none()
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        
        # Match customers between CRM and Sales by mobile
        crm_mobiles = set(crm_qs.values_list('mobile', flat=True))
        sales_mobiles = set(sales_qs.exclude(client_mobile='').values_list('client_mobile', flat=True))
        
        matched_mobiles = crm_mobiles.intersection(sales_mobiles)
        context['crm_total'] = len(crm_mobiles)
        context['sales_customers'] = len(sales_mobiles)
        context['matched_customers'] = len(matched_mobiles)
        context['crm_not_purchased'] = len(crm_mobiles - sales_mobiles)
        context['sales_not_in_crm'] = len(sales_mobiles - crm_mobiles)
        
        # Top customers from CRM who purchased
        if matched_mobiles:
            top_buyers = list(sales_qs.filter(client_mobile__in=list(matched_mobiles)[:100]).values(
                'client_name', 'client_mobile'
            ).annotate(
                total_spent=Sum('revenue'),
                total_qty=Sum('quantity'),
                order_count=Count('id')
            ).order_by('-total_spent')[:20])
            context['top_buyers'] = top_buyers
        else:
            context['top_buyers'] = []
        
        # Lead source conversion
        lead_conversion = []
        lead_sources = list(crm_qs.exclude(lead_source='').values_list('lead_source', flat=True).distinct()[:10])
        for source in lead_sources:
            source_contacts = crm_qs.filter(lead_source=source)
            source_mobiles = set(source_contacts.values_list('mobile', flat=True))
            purchased = len(source_mobiles.intersection(sales_mobiles))
            count = source_contacts.count()
            lead_conversion.append({
                'source': source,
                'total': count,
                'purchased': purchased,
                'conversion_rate': round(purchased / max(count, 1) * 100, 1)
            })
        lead_conversion.sort(key=lambda x: x['conversion_rate'], reverse=True)
        context['lead_conversion'] = lead_conversion
        
        # Store performance
        context['store_crm'] = list(crm_qs.exclude(store_name='').values('store_name').annotate(contacts=Count('id'))[:10])
        context['store_sales'] = list(sales_qs.exclude(region='').values('region').annotate(revenue=Sum('revenue'))[:10])
        
        return context


class ExhibitionReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Exhibition Sales Report - Analysis of exhibition-specific sales"""
    template_name = 'analytics/reports/exhibition.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        # Get exhibition sales (salesperson contains 'EXHIBITION' or location contains exhibition-like terms)
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        sales_qs = apply_filters(sales_qs, self.request)
        
        exhibition_qs = sales_qs.filter(
            Q(sales_person__icontains='exhibition') | 
            Q(region__icontains='exhibition')
        )
        
        context['exhibition_revenue'] = exhibition_qs.aggregate(total=Sum('revenue'))['total'] or 0
        context['exhibition_transactions'] = exhibition_qs.count()
        context['exhibition_margin'] = exhibition_qs.aggregate(total=Sum('gross_margin'))['total'] or 0
        context['exhibition_items'] = exhibition_qs.aggregate(total=Sum('quantity'))['total'] or 0
        
        # Regular sales for comparison
        regular_qs = sales_qs.exclude(
            Q(sales_person__icontains='exhibition') | 
            Q(region__icontains='exhibition')
        )
        context['regular_revenue'] = regular_qs.aggregate(total=Sum('revenue'))['total'] or 0
        context['regular_transactions'] = regular_qs.count()
        
        # Top products in exhibitions
        context['top_exhibition_products'] = list(exhibition_qs.values('style_code', 'product_category').annotate(
            revenue=Sum('revenue'),
            qty=Sum('quantity')
        ).order_by('-revenue')[:15])
        
        # Exhibition by category
        context['exhibition_by_category'] = list(exhibition_qs.values('product_category').annotate(
            revenue=Sum('revenue'),
            items=Sum('quantity')
        ).order_by('-revenue')[:10])
        
        # Exhibition daily trend
        daily = exhibition_qs.values('transaction_date').annotate(total=Sum('revenue')).order_by('transaction_date')
        daily_dict = {d['transaction_date']: float(d['total'] or 0) for d in daily if d['transaction_date']}
        sorted_days = sorted(daily_dict.keys())[-30:]
        context['trend_labels'] = json.dumps([d.strftime('%d %b') for d in sorted_days])
        context['trend_values'] = json.dumps([daily_dict[d] for d in sorted_days])
        
        return context


class SalespersonScorecardReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Salesperson Scorecard - Individual performance metrics"""
    template_name = 'analytics/reports/salesperson_scorecard.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = get_company(self.request.user)
        
        context['filters'] = get_filter_options(company)
        context['current_filters'] = self.request.GET
        
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        sales_qs = apply_filters(sales_qs, self.request)
        
        # All salespersons ranked
        salesperson_stats = list(sales_qs.exclude(sales_person='').values('sales_person').annotate(
            revenue=Sum('revenue'),
            transactions=Count('id'),
            items=Sum('quantity'),
            margin=Sum('gross_margin'),
            avg_value=Avg('revenue'),
            avg_discount=Avg('discount_percentage')
        ).order_by('-revenue'))
        
        # Calculate ranks and contribution %
        total_revenue = sum(s['revenue'] or 0 for s in salesperson_stats)
        for i, s in enumerate(salesperson_stats):
            s['rank'] = i + 1
            s['contribution'] = round((s['revenue'] or 0) / max(total_revenue, 1) * 100, 1)
        
        context['salesperson_stats'] = salesperson_stats
        context['total_salespersons'] = len(salesperson_stats)
        context['total_revenue'] = total_revenue
        
        # Top performer details
        if salesperson_stats:
            top = salesperson_stats[0]['sales_person']
            top_qs = sales_qs.filter(sales_person=top)
            context['top_performer'] = {
                'name': top,
                'revenue': salesperson_stats[0]['revenue'],
                'top_categories': list(top_qs.values('product_category').annotate(rev=Sum('revenue')).order_by('-rev')[:5]),
                'top_products': list(top_qs.values('style_code').annotate(rev=Sum('revenue')).order_by('-rev')[:5])
            }
        
        return context
