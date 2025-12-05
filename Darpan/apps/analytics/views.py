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
from django.db.models import Sum, Max

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
            return self.process_sales_csv(uploaded_file)
        elif file_type == 'collection':
            return self.process_collection_csv(uploaded_file)
        # Add other handlers later
        
        messages.warning(self.request, "This file type is not yet implemented.")
        return redirect('analytics:import')

    def process_sales_csv(self, csv_file):
        decoded_file = csv_file.read().decode('utf-8')
        io_string = io.StringIO(decoded_file)
        reader = csv.reader(io_string)
        
        # Skip header
        next(reader, None)
        
        new_rows = 0
        updated_rows = 0
        skipped_rows = 0
        
        try:
            with transaction.atomic():
                for row in reader:
                    if len(row) < 42:
                        skipped_rows += 1
                        continue
                        
                    # Map CSV columns to Model (0-based index from legacy logic)
                    # 26: TransactionNo, 27: Date, 36: GrossAmount, 25: Quantity
                    
                    trans_no = row[26]
                    if not trans_no:
                        skipped_rows += 1
                        continue
                        
                    # Parse Date
                    try:
                        trans_date = datetime.strptime(row[27], '%d-%m-%Y').date() if row[27] else None
                    except ValueError:
                         # Try fallback format if needed, or skip
                        trans_date = None
                        
                    if not trans_date:
                        skipped_rows += 1
                        continue

                    # Calculate Net Sales (Logic from legacy getTransactionCalculations)
                    entry_type = trans_no.split('/')[0] if '/' in trans_no else ''
                    gross_amt = float(row[36].replace(',', '')) if row[36] else 0.0
                    qty = int(row[25]) if row[25] else 0
                    
                    net_sales = gross_amt
                    if entry_type in ['7DE', '7DR', 'LB', 'LE', 'LU']:
                        net_sales = -gross_amt
                        
                    # Update or Create
                    obj, created = SalesRecord.objects.update_or_create(
                        company=self.request.user.company,
                        transaction_no=trans_no,
                        defaults={
                            'transaction_date': trans_date,
                            'client_name': row[1],
                            'client_mobile': row[2],
                            'jewel_code': row[3],
                            'style_code': row[4],
                            'base_metal': row[5],
                            'gross_weight': float(row[6]) if row[6] else 0,
                            'net_weight': float(row[7]) if row[7] else 0,
                            'product_category': row[21],
                            'product_subcategory': row[22],
                            'collection': row[23],
                            'quantity': qty,
                            'revenue': net_sales, # Net Sales
                            'gross_amount': gross_amt,
                            'region': row[28], # Location
                            'entry_type': entry_type,
                            'created_by': self.request.user
                        }
                    )
                    
                    if created:
                        new_rows += 1
                    else:
                        updated_rows += 1
                        
            messages.success(self.request, f"Sales Import: {new_rows} new, {updated_rows} updated, {skipped_rows} skipped.")
        except Exception as e:
            messages.error(self.request, f"Error processing CSV: {str(e)}")
            
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
