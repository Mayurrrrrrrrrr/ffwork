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
from django.db.models import Sum, Max, Count

from .models import SalesRecord, GoldRate, CollectionMaster
from .forms import SalesRecordForm, CSVImportForm, GoldRateForm

class DataAdminRequiredMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin'])

class DashboardAccessMixin(UserPassesTestMixin):
    def test_func(self):
        return self.request.user.is_superuser or self.request.user.has_any_role(['admin', 'platform_admin', 'store_manager'])

class AnalyticsDashboardView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    template_name = 'analytics/dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        
        # Sales Data
        records = SalesRecord.objects.filter(company=company).order_by('transaction_date')
        
        total_revenue = records.aggregate(Sum('revenue'))['revenue__sum'] or 0
        context['total_revenue'] = total_revenue
        context['sales_count'] = records.count()
        
        # Chart Data (Group by Date)
        sales_by_date = {}
        for record in records:
            d_str = record.transaction_date.strftime('%Y-%m-%d')
            sales_by_date[d_str] = sales_by_date.get(d_str, 0) + float(record.revenue)
            
        context['chart_dates'] = list(sales_by_date.keys())
        context['chart_revenues'] = list(sales_by_date.values())
        
        # Data Status
        context['last_upload'] = SalesRecord.objects.filter(company=company).order_by('-created_at').first()
        context['max_transaction_date'] = records.aggregate(Max('transaction_date'))['transaction_date__max']
        
        # Gold Rate
        context['current_gold_rate'] = GoldRate.objects.filter(company=company).order_by('-updated_at').first()
        
        return context

class AdvancedAnalyticsView(LoginRequiredMixin, DashboardAccessMixin, TemplateView):
    template_name = 'analytics/advanced_dashboard.html'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        company = self.request.user.company
        qs = SalesRecord.objects.filter(company=company)

        # KPI Cards
        kpi_data = qs.aggregate(
            total_revenue=Sum('revenue'),
            gross_weight=Sum('gross_weight'),
            net_weight=Sum('net_weight'),
            total_transactions=Count('id')
        )
        context.update(kpi_data)

        # 1. Sales by Category (Pie Chart)
        category_data = qs.values('product_category').annotate(
            total=Sum('revenue')
        ).order_by('-total')
        context['category_labels'] = [item['product_category'] for item in category_data]
        context['category_values'] = [float(item['total']) for item in category_data]

        # 2. Sales by Metal (Doughnut Chart)
        metal_data = qs.values('base_metal').annotate(
            total=Sum('revenue')
        ).order_by('-total')
        context['metal_labels'] = [item['base_metal'] for item in metal_data]
        context['metal_values'] = [float(item['total']) for item in metal_data]

        # 3. Top Selling Products (Bar Chart) - Top 10
        top_products = qs.values('product_name').annotate(
            total=Sum('revenue')
        ).order_by('-total')[:10]
        context['product_labels'] = [item['product_name'] for item in top_products]
        context['product_values'] = [float(item['total']) for item in top_products]

        # 4. Monthly Trend (Line Chart)
        # Oracle doesn't support TruncMonth directly in all Django versions seamlessly without some setup,
        # but we'll try standard Django aggregation first.
        from django.db.models.functions import TruncMonth
        monthly_data = qs.annotate(
            month=TruncMonth('transaction_date')
        ).values('month').annotate(
            total=Sum('revenue')
        ).order_by('month')
        
        context['trend_labels'] = [item['month'].strftime('%b %Y') for item in monthly_data if item['month']]
        context['trend_values'] = [float(item['total']) for item in monthly_data if item['month']]

        # 5. Recent High Value Transactions
        context['recent_transactions'] = qs.order_by('-revenue')[:10]

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

    def form_valid(self, form):
        file_type = form.cleaned_data['file_type']
        uploaded_file = form.cleaned_data['data_file']
        
        if file_type == 'sales':
            return self.process_sales_import(uploaded_file)
        elif file_type == 'collection':
            return self.process_collection_csv(uploaded_file)
        
        messages.warning(self.request, "This file type is not yet implemented.")
        return redirect('analytics:import')

    def process_sales_import(self, uploaded_file):
        """Use the new SalesImporter for intelligent import"""
        from .importers import SalesImporter
        
        importer = SalesImporter(uploaded_file, self.request.user.company, self.request.user)
        result = importer.process()
        
        if result['success']:
            msg = f"Import successful: {result['created']} created, {result['updated']} updated, {result['skipped']} skipped"
            if result.get('warnings'):
                msg += f". {len(result['warnings'])} warnings."
            messages.success(self.request, msg)
        else:
            messages.error(self.request, f"Import failed: {', '.join(result['errors'])}")
        
        return redirect('analytics:list')

    def process_collection_csv(self, csv_file):
        # Implementation for Collection Master
        messages.info(self.request, "Collection Master import logic placeholder.")
        return redirect('analytics:list')

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
