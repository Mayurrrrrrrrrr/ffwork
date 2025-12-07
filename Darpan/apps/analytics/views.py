import csv
import io
import json
from datetime import datetime
from django.views.generic import TemplateView, ListView, CreateView, FormView, UpdateView
from django.contrib.auth.mixins import LoginRequiredMixin, UserPassesTestMixin
from django.urls import reverse_lazy
from django.contrib import messages
from django.shortcuts import redirect
from django.db import transaction
from django.db.models import Sum, Max, Count, Avg, F, Q
from django.db.models.functions import TruncMonth, TruncDate

from .models import SalesRecord, GoldRate, CollectionMaster, ImportLog, StockSnapshot
from .forms import SalesRecordForm, CSVImportForm, GoldRateForm


class DataAdminRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin'])


class DashboardAccessMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])


class AnalyticsDashboardView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    """Comprehensive MIS Dashboard combining Sales, Stock, and CRM data"""
    template_name = 'analytics/dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        if not company:
            # Fallback for platform admins - find a company with data
            from apps.core.models import Company
            # Try to get a company that has stock or sales data
            for c in Company.objects.all():
                if StockSnapshot.objects.filter(company=c).exists() or SalesRecord.objects.filter(company=c).exists():
                    company = c
                    break
            if not company:
                company = Company.objects.first()
        
        # ============== SALES KPIs ==============
        sales_qs = SalesRecord.objects.filter(company=company) if company else SalesRecord.objects.none()
        sales_only = sales_qs.filter(transaction_type='sale')
        returns_only = sales_qs.filter(transaction_type='return')
        
        total_revenue = sales_only.aggregate(total=Sum('final_amount'))['total'] or 0
        total_returns = abs(returns_only.aggregate(total=Sum('final_amount'))['total'] or 0)
        net_sales = total_revenue - total_returns
        
        sales_count = sales_only.count()
        returns_count = returns_only.count()
        avg_order_value = net_sales / sales_count if sales_count > 0 else 0
        
        total_margin = sales_only.aggregate(total=Sum('gross_margin'))['total'] or 0
        margin_percentage = (total_margin / total_revenue * 100) if total_revenue > 0 else 0
        
        context.update({
            'total_revenue': total_revenue,
            'total_returns': total_returns,
            'net_sales': net_sales,
            'sales_count': sales_count,
            'returns_count': returns_count,
            'avg_order_value': avg_order_value,
            'total_margin': total_margin,
            'margin_percentage': margin_percentage,
        })
        
        # ============== STOCK KPIs ==============
        stock_qs = StockSnapshot.objects.filter(company=company) if company else StockSnapshot.objects.none()
        
        # Get latest snapshot
        latest_date = stock_qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        if latest_date:
            stock_qs = stock_qs.filter(snapshot_date=latest_date)
        
        total_skus = stock_qs.values('style_code').distinct().count()
        stock_qty = stock_qs.aggregate(total=Sum('quantity'))['total'] or 0
        stock_value = stock_qs.aggregate(total=Sum(F('quantity') * F('sale_price')))['total'] or 0
        total_weight = stock_qs.aggregate(total=Sum('gross_weight'))['total'] or 0
        low_stock_count = stock_qs.filter(quantity__lt=2, quantity__gt=0).count()
        
        context.update({
            'total_skus': total_skus,
            'stock_qty': stock_qty,
            'stock_value': stock_value,
            'total_weight': total_weight,
            'low_stock_count': low_stock_count,
        })
        
        # ============== SALES TREND (Last 30 days) ==============
        from django.utils import timezone
        from datetime import timedelta
        from collections import defaultdict
        import json
        
        thirty_days_ago = timezone.now().date() - timedelta(days=30)
        recent_sales = sales_only.filter(transaction_date__gte=thirty_days_ago).values('transaction_date', 'final_amount')
        
        daily_totals = defaultdict(float)
        for sale in recent_sales:
            if sale['transaction_date'] and sale['final_amount']:
                daily_totals[sale['transaction_date']] += float(sale['final_amount'])
        
        sorted_days = sorted(daily_totals.keys())
        context['trend_labels'] = json.dumps([day.strftime('%d %b') for day in sorted_days])
        context['trend_values'] = json.dumps([daily_totals[day] for day in sorted_days])
        
        # ============== SALES BY CATEGORY ==============
        category_data = sales_only.values('product_category').annotate(
            total=Sum('final_amount')
        ).order_by('-total')[:8]
        
        context['category_labels'] = json.dumps([item['product_category'] or 'Other' for item in category_data])
        context['category_values'] = json.dumps([float(item['total'] or 0) for item in category_data])
        
        # ============== SALES BY LOCATION ==============
        location_data = sales_only.values('region').annotate(
            total=Sum('final_amount')
        ).order_by('-total')[:8]
        
        context['location_labels'] = json.dumps([item['region'] or 'Unknown' for item in location_data])
        context['location_values'] = json.dumps([float(item['total'] or 0) for item in location_data])
        
        # ============== STOCK BY LOCATION ==============
        stock_location_data = stock_qs.values('location').annotate(
            value=Sum(F('quantity') * F('sale_price'))
        ).order_by('-value')[:8]
        
        context['stock_location_labels'] = json.dumps([item['location'] or 'Unknown' for item in stock_location_data])
        context['stock_location_values'] = json.dumps([float(item['value'] or 0) for item in stock_location_data])
        
        # ============== TOP PRODUCTS ==============
        top_products = sales_only.values('style_code', 'product_category').annotate(
            total=Sum('final_amount'),
            count=Count('id')
        ).order_by('-total')[:10]
        context['top_products'] = list(top_products)
        
        # ============== TOP SALES PEOPLE ==============
        top_sales_people = sales_only.exclude(sales_person='').values('sales_person').annotate(
            total=Sum('final_amount'),
            count=Count('id')
        ).order_by('-total')[:10]
        context['top_sales_people'] = list(top_sales_people)
        
        # ============== RECENT IMPORTS ==============
        if company:
            context['recent_imports'] = ImportLog.objects.filter(company=company).order_by('-imported_at')[:5]
        else:
            context['recent_imports'] = []
        
        return context


class AdvancedAnalyticsView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    template_name = 'analytics/advanced_dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        qs = SalesRecord.objects.filter(company=company)

        kpi_data = qs.aggregate(
            total_revenue=Sum('revenue'),
            gross_weight=Sum('gross_weight'),
            net_weight=Sum('net_weight'),
            total_transactions=Count('id')
        )
        context.update(kpi_data)

        category_data = qs.values('product_category').annotate(total=Sum('revenue')).order_by('-total')
        context['category_labels'] = [item['product_category'] for item in category_data]
        context['category_values'] = [float(item['total']) for item in category_data]

        metal_data = qs.values('base_metal').annotate(total=Sum('revenue')).order_by('-total')
        context['metal_labels'] = [item['base_metal'] for item in metal_data]
        context['metal_values'] = [float(item['total']) for item in metal_data]

        top_products = qs.values('product_name').annotate(total=Sum('revenue')).order_by('-total')[:10]
        context['product_labels'] = [item['product_name'] for item in top_products]
        context['product_values'] = [float(item['total']) for item in top_products]

        monthly_data = qs.annotate(month=TruncMonth('transaction_date')).values('month').annotate(total=Sum('revenue')).order_by('month')
        context['trend_labels'] = [item['month'].strftime('%b %Y') for item in monthly_data if item['month']]
        context['trend_values'] = [float(item['total']) for item in monthly_data if item['month']]

        context['recent_transactions'] = qs.order_by('-revenue')[:10]

        return context


class SalesKPIView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    """Sales KPI Dashboard with transaction type handling"""
    template_name = 'analytics/sales_kpis.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        qs = SalesRecord.objects.filter(company=company)
        
        # Filter by transaction type
        sales_qs = qs.filter(transaction_type='sale')
        returns_qs = qs.filter(transaction_type='return')
        
        # KPI Calculations
        total_revenue = sales_qs.aggregate(total=Sum('final_amount'))['total'] or 0
        total_returns = abs(returns_qs.aggregate(total=Sum('final_amount'))['total'] or 0)
        net_sales = total_revenue - total_returns
        
        sales_count = sales_qs.count()
        returns_count = returns_qs.count()
        avg_order_value = net_sales / sales_count if sales_count > 0 else 0
        
        total_margin = sales_qs.aggregate(total=Sum('gross_margin'))['total'] or 0
        margin_percentage = (total_margin / total_revenue * 100) if total_revenue > 0 else 0
        
        context.update({
            'total_revenue': total_revenue,
            'total_returns': total_returns,
            'net_sales': net_sales,
            'sales_count': sales_count,
            'returns_count': returns_count,
            'avg_order_value': avg_order_value,
            'total_margin': total_margin,
            'margin_percentage': margin_percentage,
        })
        
        # Sales by Location
        location_data = sales_qs.values('region').annotate(
            total=Sum('final_amount'),
            count=Count('id')
        ).order_by('-total')[:10]
        context['location_data'] = list(location_data)
        context['location_labels'] = [item['region'] or 'Unknown' for item in location_data]
        context['location_values'] = [float(item['total'] or 0) for item in location_data]
        
        # Sales by Category
        category_data = sales_qs.values('product_category').annotate(
            total=Sum('final_amount'),
            count=Count('id')
        ).order_by('-total')[:10]
        context['category_data'] = list(category_data)
        context['category_labels'] = [item['product_category'] or 'Unknown' for item in category_data]
        context['category_values'] = [float(item['total'] or 0) for item in category_data]
        
        # Top Selling Products (by style code)
        top_products = sales_qs.values('style_code', 'product_category').annotate(
            total=Sum('final_amount'),
            count=Count('id'),
            margin=Sum('gross_margin')
        ).order_by('-total')[:15]
        context['top_products'] = list(top_products)
        
        # Top Sales People
        top_sales_people = sales_qs.exclude(sales_person='').values('sales_person').annotate(
            total=Sum('final_amount'),
            count=Count('id')
        ).order_by('-total')[:10]
        context['top_sales_people'] = list(top_sales_people)
        
        # Daily Trend (last 30 days) - Oracle compatible version
        from django.utils import timezone
        from datetime import timedelta
        from collections import defaultdict
        
        thirty_days_ago = timezone.now().date() - timedelta(days=30)
        recent_sales = sales_qs.filter(transaction_date__gte=thirty_days_ago).values('transaction_date', 'final_amount')
        
        # Group by date in Python (Oracle TruncDate has compatibility issues)
        daily_totals = defaultdict(float)
        for sale in recent_sales:
            if sale['transaction_date'] and sale['final_amount']:
                daily_totals[sale['transaction_date']] += float(sale['final_amount'])
        
        sorted_days = sorted(daily_totals.keys())
        context['trend_labels'] = [day.strftime('%d %b') for day in sorted_days]
        context['trend_values'] = [daily_totals[day] for day in sorted_days]
        
        return context


class StockKPIView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    """Stock KPI Dashboard"""
    template_name = 'analytics/stock_kpis.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        qs = StockSnapshot.objects.filter(company=company)
        
        # Get latest snapshot date
        latest_date = qs.aggregate(Max('snapshot_date'))['snapshot_date__max']
        if latest_date:
            qs = qs.filter(snapshot_date=latest_date)
        
        # KPI Calculations
        total_skus = qs.values('style_code').distinct().count()
        total_quantity = qs.aggregate(total=Sum('quantity'))['total'] or 0
        total_value = qs.aggregate(total=Sum(F('quantity') * F('sale_price')))['total'] or 0
        total_weight = qs.aggregate(total=Sum('gross_weight'))['total'] or 0
        
        context.update({
            'total_skus': total_skus,
            'total_quantity': total_quantity,
            'total_value': total_value,
            'total_weight': total_weight,
            'snapshot_date': latest_date,
        })
        
        # Stock by Location
        location_data = qs.values('location').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price'))
        ).order_by('-value')[:10]
        context['location_data'] = list(location_data)
        context['location_labels'] = [item['location'] or 'Unknown' for item in location_data]
        context['location_values'] = [float(item['value'] or 0) for item in location_data]
        
        # Stock by Category
        category_data = qs.values('category').annotate(
            qty=Sum('quantity'),
            value=Sum(F('quantity') * F('sale_price'))
        ).order_by('-value')[:10]
        context['category_data'] = list(category_data)
        context['category_labels'] = [item['category'] or 'Unknown' for item in category_data]
        context['category_values'] = [float(item['value'] or 0) for item in category_data]
        
        # Low Stock Items (qty < 2)
        low_stock = qs.filter(quantity__lt=2, quantity__gt=0).order_by('quantity')[:20]
        context['low_stock_items'] = low_stock
        
        # Top Valued Items
        top_items = qs.order_by('-sale_price')[:15]
        context['top_items'] = top_items
        
        return context


class SalesRecordListView(LoginRequiredMixin, ListView):
    model = SalesRecord
    template_name = 'analytics/list.html'
    context_object_name = 'records'
    paginate_by = 50

    def get_queryset(self):
        return SalesRecord.objects.filter(company=self.request.user.company)


class SalesImportView(LoginRequiredMixin, DataAdminRequiredMixin, FormView):
    template_name = 'analytics/import.html'
    form_class = CSVImportForm
    success_url = reverse_lazy('analytics:list')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        # Recent imports - handle users without company
        company = self.request.user.company
        if company:
            context['recent_imports'] = ImportLog.objects.filter(
                company=company
            ).order_by('-imported_at')[:10]
        else:
            context['recent_imports'] = []
        return context

    def form_valid(self, form):
        file_type = form.cleaned_data['file_type']
        uploaded_file = form.cleaned_data['data_file']
        
        if file_type == 'sales':
            return self.process_flexible_import(uploaded_file, 'sales')
        elif file_type == 'stock':
            return self.process_flexible_import(uploaded_file, 'stock')
        elif file_type == 'crm':
            messages.info(self.request, "CRM import is not yet implemented. Coming soon!")
            return redirect('analytics:import')
        elif file_type == 'collection':
            return self.process_collection_csv(uploaded_file)
        
        messages.warning(self.request, "This file type is not yet implemented.")
        return redirect('analytics:import')

    def process_flexible_import(self, uploaded_file, import_type):
        """Use the new FlexibleImporter"""
        from .flexible_importer import FlexibleImporter
        
        # FlexibleImporter handles company fallback internally
        importer = FlexibleImporter(self.request.user.company, self.request.user)
        
        if import_type == 'sales':
            result = importer.import_sales(uploaded_file)
        else:
            result = importer.import_stock(uploaded_file)
        
        if result['success']:
            msg = f"Import successful: {result['rows_imported']} rows imported"
            if result.get('rows_skipped'):
                msg += f", {result['rows_skipped']} skipped"
            if result.get('rows_ignored'):
                msg += f", {result['rows_ignored']} ignored (RI/RR)"
            
            if result.get('columns_unmapped'):
                msg += f". Unmapped columns: {', '.join(result['columns_unmapped'][:5])}"
                if len(result['columns_unmapped']) > 5:
                    msg += f" and {len(result['columns_unmapped']) - 5} more"
            
            messages.success(self.request, msg)
        else:
            messages.error(self.request, f"Import failed: {result.get('error', 'Unknown error')}")
        
        return redirect('analytics:import')

    def process_collection_csv(self, csv_file):
        messages.info(self.request, "Collection Master import logic placeholder.")
        return redirect('analytics:list')


class ImportLogListView(LoginRequiredMixin, DataAdminRequiredMixin, ListView):
    model = ImportLog
    template_name = 'analytics/import_history.html'
    context_object_name = 'imports'
    paginate_by = 20

    def get_queryset(self):
        return ImportLog.objects.filter(company=self.request.user.company)


class GoldRateUpdateView(LoginRequiredMixin, DataAdminRequiredMixin, CreateView):
    model = GoldRate
    form_class = GoldRateForm
    template_name = 'analytics/gold_rate_form.html'
    success_url = reverse_lazy('analytics:dashboard')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        form.instance.updated_by = self.request.user
        messages.success(self.request, "Gold Rate updated successfully!")
        return super().form_valid(form)
