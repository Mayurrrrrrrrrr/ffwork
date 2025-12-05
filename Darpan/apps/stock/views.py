"""
Views for Stock Transfer module.
"""

from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from django.contrib.auth.mixins import LoginRequiredMixin
from django.views.generic import ListView, DetailView, CreateView, View
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils import timezone
from django.db.models import Q, Count, Prefetch
from django.db import transaction

from .models import Product, StockTransfer, TransferItem, Inventory, ProductImage, ProductAttribute
from .forms import ProductForm, StockTransferForm, TransferItemFormSet, ReceiveFormSet
from apps.core.utils import log_audit_action

# --- Product Views ---

class ProductListView(LoginRequiredMixin, ListView):
    model = Product
    template_name = 'stock/product_list.html'
    context_object_name = 'products'

    def get_queryset(self):
        return Product.objects.filter(company=self.request.user.company)

class ProductCreateView(LoginRequiredMixin, CreateView):
    model = Product
    form_class = ProductForm
    template_name = 'stock/product_form.html'
    success_url = reverse_lazy('stock:product_list')

    def form_valid(self, form):
        form.instance.company = self.request.user.company
        messages.success(self.request, "Product created successfully.")
        return super().form_valid(form)


class AdvancedProductSearchView(LoginRequiredMixin, ListView):
    """
    E-commerce grade product search with faceted filters and sorting.
    """
    model = Product
    template_name = 'stock/product_grid.html'
    context_object_name = 'products'
    paginate_by = 24

    def get_queryset(self):
        qs = Product.objects.filter(
            company=self.request.user.company,
            is_active=True
        ).select_related('company').prefetch_related(
            Prefetch('images', queryset=ProductImage.objects.filter(is_primary=True).order_by('display_order')),
            'attributes',
            'inventory_entries__store'
        )

        # Search query
        query = self.request.GET.get('q', '').strip()
        if query:
            qs = qs.filter(
                Q(name__icontains=query) |
                Q(description__icontains=query) |
                Q(style_code__icontains=query) |
                Q(sku__icontains=query) |
                Q(tags__icontains=query)
            )

        # Faceted filters
        category = self.request.GET.get('category')
        if category:
            qs = qs.filter(category=category)

        metal = self.request.GET.get('metal')
        if metal:
            qs = qs.filter(base_metal=metal)

        # Price range filters
        min_price = self.request.GET.get('price_min')
        if min_price:
            try:
                qs = qs.filter(sale_price__gte=float(min_price))
            except ValueError:
                pass

        max_price = self.request.GET.get('price_max')
        if max_price:
            try:
                qs = qs.filter(sale_price__lte=float(max_price))
            except ValueError:
                pass

        # Sorting
        sort = self.request.GET.get('sort', '-trending_score')
        if sort in ['-trending_score', 'name', '-sale_price', 'sale_price', '-created_at']:
            qs = qs.order_by(sort)

        return qs

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)

        # Facets for filters
        all_products = Product.objects.filter(
            company=self.request.user.company,
            is_active=True
        )

        context['facets'] = {
            'categories': all_products.values('category').annotate(count=Count('id')).order_by('category'),
            'metals': all_products.values('base_metal').annotate(count=Count('id')).order_by('base_metal'),
            'price_ranges': [
                {'label': 'Under ₹10k', 'min': 0, 'max': 10000},
                {'label': '₹10k - ₹50k', 'min': 10000, 'max': 50000},
                {'label': '₹50k - ₹1L', 'min': 50000, 'max': 100000},
                {'label': 'Above ₹1L', 'min': 100000, 'max': None},
            ]
        }

        context['current_filters'] = {
            'q': self.request.GET.get('q', ''),
            'category': self.request.GET.get('category', ''),
            'metal': self.request.GET.get('metal', ''),
            'sort': self.request.GET.get('sort', '-trending_score'),
        }

        return context


# --- Transfer Views ---

class TransferListView(LoginRequiredMixin, ListView):
    model = StockTransfer
    template_name = 'stock/transfer_list.html'
    context_object_name = 'transfers'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        view_type = self.request.GET.get('view', 'my')
        
        if view_type == 'approve':
            # Simplified: Admins/Managers approve all requests
            return StockTransfer.objects.filter(
                company=user.company,
                status='requested'
            ).order_by('-created_at')
        elif view_type == 'receive':
            # Transfers shipped to user's store (if assigned) or any store in company
            if user.store:
                return StockTransfer.objects.filter(
                    destination_store=user.store,
                    status='shipped'
                )
            else:
                return StockTransfer.objects.filter(
                    company=user.company,
                    status='shipped'
                )
        else:
            return StockTransfer.objects.filter(requested_by=user).order_by('-created_at')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        
        context['approve_count'] = StockTransfer.objects.filter(
            company=user.company, status='requested'
        ).count()
        
        if user.store:
            context['receive_count'] = StockTransfer.objects.filter(
                destination_store=user.store, status='shipped'
            ).count()
        else:
             context['receive_count'] = StockTransfer.objects.filter(
                company=user.company, status='shipped'
            ).count()
            
        context['current_view'] = self.request.GET.get('view', 'my')
        return context

class TransferCreateView(LoginRequiredMixin, CreateView):
    model = StockTransfer
    form_class = StockTransferForm
    template_name = 'stock/transfer_form.html'
    success_url = reverse_lazy('stock:transfer_list')

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['user'] = self.request.user
        return kwargs

    def get_context_data(self, **kwargs):
        data = super().get_context_data(**kwargs)
        if self.request.POST:
            data['items'] = TransferItemFormSet(self.request.POST, form_kwargs={'user': self.request.user})
        else:
            data['items'] = TransferItemFormSet(form_kwargs={'user': self.request.user})
        return data

    def form_valid(self, form):
        context = self.get_context_data()
        items = context['items']
        with transaction.atomic():
            self.object = form.save(commit=False)
            self.object.requested_by = self.request.user
            self.object.company = self.request.user.company
            self.object.status = 'draft'
            self.object.save()
            
            if items.is_valid():
                items.instance = self.object
                items.save()
            else:
                return self.form_invalid(form)
                
        log_audit_action(self.request, 'create', f"Created ISO {self.object.iso_number}", 'stock_transfer', self.object.id)
        messages.success(self.request, "Stock Transfer Request created successfully.")
        return redirect(self.success_url)

class TransferDetailView(LoginRequiredMixin, DetailView):
    model = StockTransfer
    template_name = 'stock/transfer_detail.html'
    context_object_name = 'transfer'

    def get_queryset(self):
        return StockTransfer.objects.filter(company=self.request.user.company)

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        if self.object.status == 'shipped':
            context['receive_formset'] = ReceiveFormSet(instance=self.object)
        return context

class TransferActionView(LoginRequiredMixin, View):
    def post(self, request, pk):
        transfer = get_object_or_404(StockTransfer, pk=pk, company=request.user.company)
        action = request.POST.get('action')
        
        if action == 'submit':
            if transfer.status == 'draft':
                transfer.status = 'requested'
                transfer.save()
                messages.success(request, "Transfer submitted for approval.")
                
        elif action == 'approve':
            if transfer.status == 'requested':
                transfer.status = 'approved'
                transfer.approved_by = request.user
                transfer.save()
                messages.success(request, "Transfer approved.")
                
        elif action == 'ship':
            if transfer.status == 'approved':
                transfer.status = 'shipped'
                transfer.ship_date = timezone.now().date()
                # Auto-fill shipped quantity = requested quantity for simplicity
                for item in transfer.items.all():
                    item.quantity_shipped = item.quantity_requested
                    item.save()
                transfer.save()
                messages.success(request, "Transfer marked as shipped.")
                
        elif action == 'receive':
            if transfer.status == 'shipped':
                formset = ReceiveFormSet(request.POST, instance=transfer)
                if formset.is_valid():
                    formset.save()
                    transfer.status = 'received'
                    transfer.receive_date = timezone.now().date()
                    transfer.save()
                    messages.success(request, "Transfer received.")
                else:
                    messages.error(request, "Error receiving items.")
                    return redirect('stock:transfer_detail', pk=pk)
                    
        log_audit_action(request, action, f"{action.title()} ISO {transfer.iso_number}", 'stock_transfer', transfer.id)
        return redirect('stock:transfer_detail', pk=pk)

class QuickTransferRequestView(LoginRequiredMixin, View):
    def post(self, request):
        inventory_id = request.POST.get('inventory_id')
        inventory = get_object_or_404(Inventory, pk=inventory_id, company=request.user.company)
        
        if not request.user.store:
            messages.error(request, "You must be assigned to a store to request transfers.")
            return redirect('tools:stock_lookup')
            
        if request.user.store == inventory.store:
            messages.warning(request, "Item is already in your store.")
            return redirect('tools:stock_lookup')

        # Create Transfer Request
        with transaction.atomic():
            transfer = StockTransfer.objects.create(
                company=request.user.company,
                source_store=inventory.store,
                destination_store=request.user.store,
                requested_by=request.user,
                status='requested'
            )
            
            TransferItem.objects.create(
                transfer=transfer,
                product=inventory.product,
                quantity_requested=1
            )
            
        log_audit_action(request, 'create', f"Requested Transfer {transfer.iso_number}", 'stock_transfer', transfer.id)
        messages.success(request, f"Transfer requested for {inventory.product.name} from {inventory.store.name}.")
        return redirect('stock:transfer_list')
