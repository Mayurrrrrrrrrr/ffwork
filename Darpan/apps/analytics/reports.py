"""
Report views for Analytics module.
Provides intelligent reports: Sales, Products, Customers, Stock, Sell-Through, Combined Insights.
"""

from django.views.generic import TemplateView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.db.models import Sum, Count, Avg, Max, F, Q
from django.db.models.functions import TruncMonth, TruncDate
from django.utils import timezone
from datetime import timedelta
from collections import defaultdict
import json

from .models import SalesRecord, StockSnapshot, CRMContact, ImportLog


class ReportAccessMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])


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
        ]
        return context


class SalesPerformanceReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Sales Performance Report - by store, salesperson, time period"""
    template_name = 'analytics/reports/sales_performance.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        
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
            avg_value=Avg('final_amount')
        ).order_by('-revenue')[:15]
        context['salesperson_data'] = list(sales_person_data)
        
        # Monthly Trend
        thirty_days_ago = timezone.now().date() - timedelta(days=90)
        monthly_data = sales_qs.filter(transaction_date__gte=thirty_days_ago).values('transaction_date').annotate(
            total=Sum('revenue')
        ).order_by('transaction_date')
        
        daily_totals = defaultdict(float)
        for item in monthly_data:
            if item['transaction_date']:
                daily_totals[item['transaction_date']] += float(item['total'] or 0)
        
        sorted_days = sorted(daily_totals.keys())
        context['trend_labels'] = json.dumps([d.strftime('%d %b') for d in sorted_days])
        context['trend_values'] = json.dumps([daily_totals[d] for d in sorted_days])
        
        return context


class ProductAnalysisReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Product Analysis Report - top sellers, slow movers, category performance"""
    template_name = 'analytics/reports/product_analysis.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        
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
    """Sell-Through Report - Sales vs Stock analysis by style, category"""
    template_name = 'analytics/reports/sellthrough.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        # Get latest stock snapshot
        stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
        latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        
        if latest_date:
            stock_qs = stock_qs.filter(snapshot_date=latest_date)
        
        # Get sales data
        sales_qs = SalesRecord.objects.filter(company=company, transaction_type='sale') if company else SalesRecord.objects.none()
        
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
        context['snapshot_date'] = latest_date
        
        return context


class CustomerInsightsReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Customer Insights Report - CRM data, birthdays, lead status"""
    template_name = 'analytics/reports/customer_insights.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        crm_qs = CRMContact.objects.filter(company=company) if company else CRMContact.objects.none()
        
        # Total contacts
        context['total_contacts'] = crm_qs.count()
        
        # Lead status distribution
        lead_status_data = crm_qs.exclude(lead_status='').values('lead_status').annotate(
            count=Count('id')
        ).order_by('-count')
        context['lead_status_data'] = list(lead_status_data)
        context['lead_labels'] = json.dumps([l['lead_status'] for l in lead_status_data])
        context['lead_values'] = json.dumps([l['count'] for l in lead_status_data])
        
        # Lead source distribution
        lead_source_data = crm_qs.exclude(lead_source='').values('lead_source').annotate(
            count=Count('id')
        ).order_by('-count')[:10]
        context['lead_source_data'] = list(lead_source_data)
        
        # Upcoming birthdays (next 30 days)
        today = timezone.now().date()
        upcoming_bdays = []
        for contact in crm_qs.exclude(dob__isnull=True)[:500]:  # Limit for performance
            if contact.dob:
                this_year_bday = contact.dob.replace(year=today.year)
                if this_year_bday < today:
                    this_year_bday = contact.dob.replace(year=today.year + 1)
                days_until = (this_year_bday - today).days
                if 0 <= days_until <= 30:
                    upcoming_bdays.append({
                        'name': contact.full_name,
                        'mobile': contact.mobile,
                        'dob': contact.dob,
                        'days_until': days_until
                    })
        upcoming_bdays.sort(key=lambda x: x['days_until'])
        context['upcoming_birthdays'] = upcoming_bdays[:20]
        
        # Upcoming anniversaries
        upcoming_anniv = []
        for contact in crm_qs.exclude(anniversary__isnull=True)[:500]:
            if contact.anniversary:
                this_year = contact.anniversary.replace(year=today.year)
                if this_year < today:
                    this_year = contact.anniversary.replace(year=today.year + 1)
                days_until = (this_year - today).days
                if 0 <= days_until <= 30:
                    upcoming_anniv.append({
                        'name': contact.full_name,
                        'mobile': contact.mobile,
                        'anniversary': contact.anniversary,
                        'days_until': days_until
                    })
        upcoming_anniv.sort(key=lambda x: x['days_until'])
        context['upcoming_anniversaries'] = upcoming_anniv[:20]
        
        # By store
        store_data = crm_qs.exclude(store_name='').values('store_name').annotate(
            count=Count('id')
        ).order_by('-count')[:10]
        context['store_data'] = list(store_data)
        
        # Loyalty stats
        context['total_loyalty_points'] = crm_qs.aggregate(total=Sum('loyalty_points'))['total'] or 0
        context['total_redeemed'] = crm_qs.aggregate(total=Sum('loyalty_redeemed'))['total'] or 0
        
        return context


class StockSummaryReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Stock Summary Report - value by location, category, low stock alerts"""
    template_name = 'analytics/reports/stock_summary.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
        stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
        latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        
        if latest_date:
            stock_qs = stock_qs.filter(snapshot_date=latest_date)
        
        context['snapshot_date'] = latest_date
        
        # Overall KPIs
        context['total_skus'] = stock_qs.values('style_code').distinct().count()
        context['total_qty'] = stock_qs.aggregate(total=Sum('quantity'))['total'] or 0
        context['total_value'] = stock_qs.aggregate(total=Sum(F('quantity') * F('sale_price')))['total'] or 0
        context['total_weight'] = stock_qs.aggregate(total=Sum('gross_weight'))['total'] or 0
        
        # By Location
        location_data = stock_qs.values('location').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price')),
            sku_count=Count('style_code', distinct=True)
        ).order_by('-value')[:10]
        context['location_data'] = list(location_data)
        context['location_labels'] = json.dumps([l['location'] or 'Unknown' for l in location_data])
        context['location_values'] = json.dumps([float(l['value'] or 0) for l in location_data])
        
        # By Category
        category_data = stock_qs.values('category').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price'))
        ).order_by('-value')[:10]
        context['category_data'] = list(category_data)
        
        # Low stock items (qty = 1)
        low_stock = stock_qs.filter(quantity__lte=1, quantity__gt=0).order_by('quantity', '-sale_price')[:30]
        context['low_stock_items'] = low_stock
        
        return context


class CombinedInsightsReport(LoginRequiredMixin, ReportAccessMixin, TemplateView):
    """Combined Insights Report - CRM + Sales data analysis"""
    template_name = 'analytics/reports/combined_insights.html'
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            from apps.core.models import Company
            company = Company.objects.first()
        
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
            top_buyers = sales_qs.filter(client_mobile__in=list(matched_mobiles)[:100]).values(
                'client_name', 'client_mobile'
            ).annotate(
                total_spent=Sum('revenue'),
                total_qty=Sum('quantity'),
                order_count=Count('id')
            ).order_by('-total_spent')[:20]
            context['top_buyers'] = list(top_buyers)
        else:
            context['top_buyers'] = []
        
        # Lead source conversion (CRM leads who purchased)
        lead_conversion = []
        lead_sources = crm_qs.exclude(lead_source='').values_list('lead_source', flat=True).distinct()
        for source in list(lead_sources)[:10]:
            source_contacts = crm_qs.filter(lead_source=source)
            source_mobiles = set(source_contacts.values_list('mobile', flat=True))
            purchased = len(source_mobiles.intersection(sales_mobiles))
            lead_conversion.append({
                'source': source,
                'total': source_contacts.count(),
                'purchased': purchased,
                'conversion_rate': round(purchased / max(source_contacts.count(), 1) * 100, 1)
            })
        lead_conversion.sort(key=lambda x: x['conversion_rate'], reverse=True)
        context['lead_conversion'] = lead_conversion
        
        # Store performance comparison
        store_crm = crm_qs.exclude(store_name='').values('store_name').annotate(contacts=Count('id'))
        store_sales = sales_qs.exclude(region='').values('region').annotate(revenue=Sum('revenue'))
        
        context['store_crm'] = list(store_crm)[:10]
        context['store_sales'] = list(store_sales)[:10]
        
        return context
