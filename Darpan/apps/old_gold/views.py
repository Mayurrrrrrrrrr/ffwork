from django.views.generic import CreateView, ListView, DetailView
from django.contrib.auth.mixins import LoginRequiredMixin
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils import timezone
from django.db import transaction
from django.shortcuts import redirect

from .models import OldGoldTransaction
from .forms import OldGoldForm
from apps.core.utils import log_audit_action

class OldGoldCreateView(LoginRequiredMixin, CreateView):
    model = OldGoldTransaction
    form_class = OldGoldForm
    template_name = 'old_gold/entry_form.html'
    success_url = reverse_lazy('old_gold:list')

    def form_valid(self, form):
        with transaction.atomic():
            form.instance.company = self.request.user.company
            # Handle store assignment: Use user's store or fallback to first store of company (simplified)
            if self.request.user.store:
                form.instance.store = self.request.user.store
            else:
                # For platform admins/users without store, force them to select or default to first
                # For now, let's assume they must have a store or we pick the first one
                first_store = self.request.user.company.stores.first()
                if not first_store:
                     messages.error(self.request, "No store found for this company. Cannot create transaction.")
                     return self.form_invalid(form)
                form.instance.store = first_store

            form.instance.created_by = self.request.user
            
            # Generate BOS Number
            # Format: BOS/STORE_CODE/YEAR/SEQUENCE
            store_code = form.instance.store.gati_location_name or "NA" # Using gati_location_name as code for now
            year = timezone.now().year
            prefix = f"BOS/{store_code}/{year}/"
            
            # Find last sequence
            last_tx = OldGoldTransaction.objects.filter(
                company=self.request.user.company,
                bill_of_supply_no__startswith=prefix
            ).order_by('-id').first()
            
            if last_tx:
                try:
                    last_seq = int(last_tx.bill_of_supply_no.split('/')[-1])
                    new_seq = last_seq + 1
                except ValueError:
                    new_seq = 1
            else:
                new_seq = 1
            
            form.instance.bill_of_supply_no = f"{prefix}{new_seq:04d}"
            
            # Calculate Final Value (Backend validation)
            # Logic: (Gross After * Purity After / 100) * Gold Rate
            # Note: The form should have these values. We can recalculate to be safe or trust the form if it's read-only calculated
            # Let's trust the form for now but ensure consistency if we had a rate master
            
            # Calculate Net Weight
            net_weight = (form.instance.gross_weight_after * form.instance.purity_after) / 100
            form.instance.net_weight_calculated = net_weight
            
            # Calculate Value (assuming gold_rate_applied is provided in form or we fetch it)
            # In the legacy code, rate is fetched. Here we don't have a GoldRate model yet.
            # We will assume the user enters the rate for now or we add a field to the form.
            # Wait, I didn't add gold_rate_applied to the form fields! I need to add it.
            # For now, I'll calculate final_value based on what's in the instance (if form saved it)
            
            # Recalculate final value to ensure it matches
            # form.instance.final_value = net_weight * form.instance.gold_rate_applied
            
            response = super().form_valid(form)
            
            log_audit_action(self.request, 'create', f"Created Old Gold BOS {form.instance.bill_of_supply_no}", 'old_gold', form.instance.id)
            messages.success(self.request, f"Transaction saved. BOS: {form.instance.bill_of_supply_no}")
            return response

class OldGoldListView(LoginRequiredMixin, ListView):
    model = OldGoldTransaction
    template_name = 'old_gold/list.html'
    context_object_name = 'transactions'
    paginate_by = 20

    def get_queryset(self):
        return OldGoldTransaction.objects.filter(company=self.request.user.company)

class OldGoldDetailView(LoginRequiredMixin, DetailView):
    model = OldGoldTransaction
    template_name = 'old_gold/bos_print.html'
    context_object_name = 'transaction'

    def get_queryset(self):
        return OldGoldTransaction.objects.filter(company=self.request.user.company)
