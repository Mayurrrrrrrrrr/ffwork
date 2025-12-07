"""
Flexible Data Import System for Darpan ERP.
Handles sales.csv, stock.csv, and CRM data with graceful column mapping.
"""

import logging
import pandas as pd
from datetime import datetime
from decimal import Decimal, InvalidOperation
from django.db import transaction
from django.utils import timezone

from apps.analytics.models import SalesRecord, StockSnapshot, ImportLog, CRMContact

logger = logging.getLogger(__name__)


class FlexibleImporter:
    """
    Import data with flexible column mapping.
    - Missing columns are ignored
    - Unmapped columns are reported
    - Transaction types handled according to rules
    """
    
    # Transaction type rules
    TRANSACTION_TYPES = {
        'FF': 'sale',      # Sales - positive
        '7DE': 'return',   # Return - negative
        '7DR': 'return',   # Return - negative
        'LB': 'return',    # Return - negative
        'LE': 'return',    # Return - negative
        'LU': 'return',    # Return - negative
        'RI': 'ignore',    # Ignore completely
        'RR': 'ignore',    # Ignore completely
    }
    
    # Sales CSV column mapping
    SALES_COLUMN_MAP = {
        'ClientName': 'client_name',
        'ClientMobile': 'client_mobile',
        'JewelCode': 'jewel_code',
        'StyleCode': 'style_code',
        'BaseMetal': 'base_metal',
        'GrossWt': 'gross_weight',
        'NetWt': 'net_weight',
        'Stocktype': 'entry_type',
        'Free Gold': 'free_gold_weight',
        'Solitaire pieces': 'solitaire_pieces',
        'Solitaire Weight': 'solitaire_weight',
        'TotDiaPc': 'total_diamond_pieces',
        'TotDiaWt': 'total_diamond_weight',
        'Colour stone pieces': 'color_stone_pieces',
        'Colour stone weight': 'color_stone_weight',
        'Product Category': 'product_category',
        'Product Subcategory': 'product_subcategory',
        'Collection': 'collection',
        'Quantity': 'quantity',
        'TransactionNo': 'transaction_no',
        'Transaction Date': 'transaction_date',
        'Location': 'region',
        'SALES EXU': 'sales_person',
        'Discount': 'discount_amount',
        'Original selling price': 'gross_amount',
        'Discount (Percentage)': 'discount_percentage',
        'Discount (Amount)': 'discount_amount_alt',
        'Gross Amount after discount': 'revenue',
        'GST': 'gst_amount',
        'Final Amount (with GST)': 'final_amount',
        'Gross Margin': 'gross_margin',
        'PANNO': 'pan_no',
        'GSTNO': 'gst_no',
        'Itemsize': 'item_size',
    }
    
    # Stock CSV column mapping
    STOCK_COLUMN_MAP = {
        'Jewel Code': 'jewel_code',
        'Style Code': 'style_code',
        'Location Name': 'location',
        'Category': 'category',
        'Sub Category': 'sub_category',
        'Base Metal': 'base_metal',
        'Item Size': 'item_size',
        'Qty': 'quantity',
        'Gross Wt': 'gross_weight',
        'Net Wt': 'net_weight',
        'Pure Wt': 'pure_weight',
        'Dia Pcs': 'diamond_pieces',
        'Dia Wt': 'diamond_weight',
        'CS Pcs': 'color_stone_pieces',
        'CS Wt': 'color_stone_weight',
        'Sale Price': 'sale_price',
        'Date': 'snapshot_date',
        'Month': 'stock_month',
        'Year': 'stock_year',
        'Jewelry CertificateNo': 'certificate_no',
    }
    
    def __init__(self, company, user):
        self.user = user
        self.errors = []
        self.warnings = []
        
        # Validate company - get from user or create default
        if company:
            self.company = company
        elif user and user.company:
            self.company = user.company
        else:
            # Fallback: get or create a default company
            from apps.core.models import Company
            self.company, _ = Company.objects.get_or_create(
                name='Default Company',
                defaults={'is_active': True}
            )
            logger.warning(f"User {user} has no company, using default")
    
    def _validate_company(self):
        """Ensure we have a valid company before import"""
        if not self.company or not self.company.id:
            raise ValueError("No valid company available for import. Please contact admin.")
    
    def _parse_decimal(self, value, default=0):
        """Safely parse decimal values"""
        if pd.isna(value) or value == '' or value is None:
            return Decimal(default)
        try:
            # Remove commas and currency symbols
            clean_value = str(value).replace(',', '').replace('₹', '').replace(' ', '').strip()
            if clean_value == '' or clean_value == '-':
                return Decimal(default)
            return Decimal(clean_value)
        except (InvalidOperation, ValueError):
            return Decimal(default)
    
    def _parse_int(self, value, default=0):
        """Safely parse integer values"""
        if pd.isna(value) or value == '' or value is None:
            return default
        try:
            clean_value = str(value).replace(',', '').strip()
            if clean_value == '' or clean_value == '-':
                return default
            return int(float(clean_value))
        except (ValueError, TypeError):
            return default
    
    def _parse_date(self, value, formats=['%d-%m-%Y', '%Y-%m-%d', '%d/%m/%Y']):
        """Safely parse date values"""
        if pd.isna(value) or value == '' or value is None:
            return None
        
        if isinstance(value, datetime):
            return value.date()
        
        for fmt in formats:
            try:
                return datetime.strptime(str(value).strip(), fmt).date()
            except ValueError:
                continue
        return None
    
    def _get_transaction_type(self, transaction_no):
        """Determine transaction type from transaction number prefix"""
        if not transaction_no:
            return 'sale'
        
        prefix = str(transaction_no).split('/')[0].upper()
        return self.TRANSACTION_TYPES.get(prefix, 'sale')
    
    def _map_columns(self, df, column_map):
        """Map DataFrame columns and return mapped/unmapped lists"""
        mapped = []
        unmapped = []
        rename_map = {}
        
        df_cols_lower = {col.lower().strip(): col for col in df.columns}
        
        for source_col, target_field in column_map.items():
            source_lower = source_col.lower().strip()
            if source_lower in df_cols_lower:
                original_col = df_cols_lower[source_lower]
                rename_map[original_col] = target_field
                mapped.append(source_col)
            else:
                # Try partial match
                for df_col_lower, df_col_orig in df_cols_lower.items():
                    if source_lower in df_col_lower or df_col_lower in source_lower:
                        rename_map[df_col_orig] = target_field
                        mapped.append(source_col)
                        break
        
        # Find unmapped columns
        mapped_originals = set(rename_map.keys())
        for col in df.columns:
            if col not in mapped_originals:
                unmapped.append(col)
        
        return rename_map, mapped, unmapped
    
    def import_sales(self, file):
        """Import sales data from CSV/Excel"""
        try:
            # Validate company first
            self._validate_company()
            
            # Read file
            if file.name.endswith('.csv'):
                df = pd.read_csv(file)
            else:
                df = pd.read_excel(file)
            
            if df.empty:
                return {'success': False, 'error': 'File is empty'}
            
            # Map columns
            rename_map, mapped, unmapped = self._map_columns(df, self.SALES_COLUMN_MAP)
            df = df.rename(columns=rename_map)
            
            rows_imported = 0
            rows_skipped = 0
            rows_ignored = 0
            
            records_to_create = []
            
            for idx, row in df.iterrows():
                try:
                    # Get transaction type
                    tx_no = row.get('transaction_no', '')
                    tx_type = self._get_transaction_type(tx_no)
                    
                    # Skip ignored transactions (RI, RR)
                    if tx_type == 'ignore':
                        rows_ignored += 1
                        continue
                    
                    # Parse transaction date
                    tx_date = self._parse_date(row.get('transaction_date'))
                    if not tx_date:
                        self.warnings.append(f"Row {idx + 2}: Invalid date, skipping")
                        rows_skipped += 1
                        continue
                    
                    # Parse financial values
                    final_amount = self._parse_decimal(row.get('final_amount', 0))
                    gross_margin = self._parse_decimal(row.get('gross_margin', 0))
                    
                    # Apply negative sign for returns
                    if tx_type == 'return':
                        final_amount = -abs(final_amount)
                        gross_margin = -abs(gross_margin)
                    
                    record = SalesRecord(
                        company=self.company,
                        transaction_no=str(tx_no) if tx_no else '',
                        transaction_date=tx_date,
                        transaction_type=tx_type,
                        client_name=str(row.get('client_name', ''))[:255],
                        client_mobile=str(row.get('client_mobile', ''))[:20],
                        jewel_code=str(row.get('jewel_code', ''))[:100],
                        style_code=str(row.get('style_code', ''))[:100],
                        product_category=str(row.get('product_category', ''))[:100],
                        product_subcategory=str(row.get('product_subcategory', ''))[:100],
                        collection=str(row.get('collection', ''))[:100],
                        base_metal=str(row.get('base_metal', ''))[:50],
                        gross_weight=self._parse_decimal(row.get('gross_weight')),
                        net_weight=self._parse_decimal(row.get('net_weight')),
                        free_gold_weight=self._parse_decimal(row.get('free_gold_weight')),
                        solitaire_pieces=self._parse_int(row.get('solitaire_pieces')),
                        solitaire_weight=self._parse_decimal(row.get('solitaire_weight')),
                        total_diamond_pieces=self._parse_int(row.get('total_diamond_pieces')),
                        total_diamond_weight=self._parse_decimal(row.get('total_diamond_weight')),
                        color_stone_pieces=self._parse_int(row.get('color_stone_pieces')),
                        color_stone_weight=self._parse_decimal(row.get('color_stone_weight')),
                        quantity=self._parse_int(row.get('quantity', 1)) or 1,
                        gross_amount=self._parse_decimal(row.get('gross_amount')),
                        discount_amount=self._parse_decimal(row.get('discount_amount')),
                        discount_percentage=self._parse_decimal(row.get('discount_percentage')),
                        gst_amount=self._parse_decimal(row.get('gst_amount')),
                        final_amount=final_amount,
                        gross_margin=gross_margin,
                        region=str(row.get('region', ''))[:100],
                        sales_person=str(row.get('sales_person', ''))[:100],
                        entry_type=str(row.get('entry_type', ''))[:20],
                        pan_no=str(row.get('pan_no', ''))[:20] if row.get('pan_no') else None,
                        gst_no=str(row.get('gst_no', ''))[:50] if row.get('gst_no') else None,
                        created_by=self.user,
                    )
                    records_to_create.append(record)
                    rows_imported += 1
                    
                except Exception as e:
                    self.warnings.append(f"Row {idx + 2}: {str(e)}")
                    rows_skipped += 1
            
            # Bulk create
            if records_to_create:
                try:
                    with transaction.atomic():
                        SalesRecord.objects.bulk_create(records_to_create, batch_size=500)
                except Exception as e:
                    logger.error(f"Bulk create failed: {e}")
                    return {'success': False, 'error': f'Database error: {str(e)}'}
            
            # Create import log
            ImportLog.objects.create(
                company=self.company,
                file_type='sales',
                file_name=file.name,
                rows_imported=rows_imported,
                rows_skipped=rows_skipped,
                rows_ignored=rows_ignored,
                columns_mapped=mapped,
                columns_unmapped=unmapped,
                errors=self.warnings[:50],  # Limit stored errors
                imported_by=self.user,
            )
            
            return {
                'success': True,
                'rows_imported': rows_imported,
                'rows_skipped': rows_skipped,
                'rows_ignored': rows_ignored,
                'columns_mapped': mapped,
                'columns_unmapped': unmapped,
                'warnings': self.warnings[:20],
            }
            
        except Exception as e:
            logger.error(f"Sales import failed: {e}")
            return {'success': False, 'error': str(e)}
    
    def import_stock(self, file):
        """Import stock/inventory data from CSV/Excel"""
        try:
            # Validate company first
            self._validate_company()
            
            if file.name.endswith('.csv'):
                df = pd.read_csv(file)
            else:
                df = pd.read_excel(file)
            
            if df.empty:
                return {'success': False, 'error': 'File is empty'}
            
            rename_map, mapped, unmapped = self._map_columns(df, self.STOCK_COLUMN_MAP)
            df = df.rename(columns=rename_map)
            
            rows_imported = 0
            rows_skipped = 0
            
            records_to_create = []
            snapshot_date = timezone.now().date()
            
            for idx, row in df.iterrows():
                try:
                    # Parse snapshot date if available
                    parsed_date = self._parse_date(row.get('snapshot_date'))
                    if parsed_date:
                        snapshot_date = parsed_date
                    
                    style_code = str(row.get('style_code', ''))
                    if not style_code or style_code == 'nan':
                        rows_skipped += 1
                        continue
                    
                    # Parse sale price (remove commas)
                    sale_price = self._parse_decimal(row.get('sale_price', 0))
                    
                    record = StockSnapshot(
                        company=self.company,
                        jewel_code=str(row.get('jewel_code', ''))[:100],
                        style_code=style_code[:100],
                        location=str(row.get('location', ''))[:100],
                        category=str(row.get('category', ''))[:100],
                        sub_category=str(row.get('sub_category', ''))[:100],
                        base_metal=str(row.get('base_metal', ''))[:50],
                        item_size=str(row.get('item_size', ''))[:20],
                        certificate_no=str(row.get('certificate_no', ''))[:100],
                        stock_month=str(row.get('stock_month', ''))[:20],
                        stock_year=self._parse_int(row.get('stock_year')) or None,
                        quantity=self._parse_int(row.get('quantity', 0)),
                        gross_weight=self._parse_decimal(row.get('gross_weight')),
                        net_weight=self._parse_decimal(row.get('net_weight')),
                        pure_weight=self._parse_decimal(row.get('pure_weight')),
                        diamond_pieces=self._parse_int(row.get('diamond_pieces')),
                        diamond_weight=self._parse_decimal(row.get('diamond_weight')),
                        color_stone_pieces=self._parse_int(row.get('color_stone_pieces')),
                        color_stone_weight=self._parse_decimal(row.get('color_stone_weight')),
                        sale_price=sale_price,
                        snapshot_date=snapshot_date,
                    )
                    records_to_create.append(record)
                    rows_imported += 1
                    
                except Exception as e:
                    self.warnings.append(f"Row {idx + 2}: {str(e)}")
                    rows_skipped += 1
            
            if records_to_create:
                try:
                    with transaction.atomic():
                        StockSnapshot.objects.bulk_create(records_to_create, batch_size=500)
                except Exception as e:
                    logger.error(f"Stock bulk create failed: {e}")
                    return {'success': False, 'error': f'Database error: {str(e)}'}
            
            ImportLog.objects.create(
                company=self.company,
                file_type='stock',
                file_name=file.name,
                rows_imported=rows_imported,
                rows_skipped=rows_skipped,
                columns_mapped=mapped,
                columns_unmapped=unmapped,
                errors=self.warnings[:50],
                imported_by=self.user,
            )
            
            return {
                'success': True,
                'rows_imported': rows_imported,
                'rows_skipped': rows_skipped,
                'columns_mapped': mapped,
                'columns_unmapped': unmapped,
                'warnings': self.warnings[:20],
            }
            
        except Exception as e:
            logger.error(f"Stock import failed: {e}")
            return {'success': False, 'error': str(e)}
    
    # CRM CSV column mapping
    CRM_COLUMN_MAP = {
        'Record Id': 'record_id',
        'Contact Owner': 'store_name',
        'First Name': 'first_name',
        'Last Name': 'last_name',
        'Contact Name': 'full_name',
        'Mobile': 'mobile',
        'Phone': 'phone',
        'Email': 'email',
        'Date of Birth': 'dob',
        'Anniversary Date': 'anniversary',
        'Lead Source': 'lead_source',
        'Lead Status': 'lead_status',
        'Original Lead Source': 'original_lead_source',
        'Gender': 'gender',
        'Marital Status': 'marital_status',
        'Budget Range': 'budget_range',
        'Product Category of Interest': 'interest_category',
        'Loyalty Points Available': 'loyalty_points',
        'Loyalty Points Redeemed': 'loyalty_redeemed',
        'Loyalty Points Earned': 'loyalty_earned',
        'Last Engagement Date_overall': 'last_engagement_date',
        'Total Signal Scores': 'total_signal_score',
        'Sales Person': 'sales_person',
        'Original Sales Person': 'original_sales_person',
        'Location': 'location',
        'Mailing City': 'city',
        'Mailing State': 'state',
        'Created Time': 'created_time',
        'Modified Time': 'modified_time',
    }
    
    def import_crm(self, file):
        """Import CRM contact data from CSV/Excel"""
        try:
            self._validate_company()
            
            if file.name.endswith('.csv'):
                df = pd.read_csv(file)
            else:
                df = pd.read_excel(file)
            
            if df.empty:
                return {'success': False, 'error': 'File is empty'}
            
            rename_map, mapped, unmapped = self._map_columns(df, self.CRM_COLUMN_MAP)
            df = df.rename(columns=rename_map)
            
            rows_imported = 0
            rows_skipped = 0
            
            records_to_create = []
            
            for idx, row in df.iterrows():
                try:
                    # Build full name if not present
                    full_name = str(row.get('full_name', ''))
                    if not full_name or full_name == 'nan':
                        first = str(row.get('first_name', '')).strip()
                        last = str(row.get('last_name', '')).strip()
                        full_name = f"{first} {last}".strip()
                    
                    mobile = str(row.get('mobile', ''))[:20]
                    if not mobile or mobile == 'nan':
                        mobile = ''
                    
                    record = CRMContact(
                        company=self.company,
                        record_id=str(row.get('record_id', ''))[:100],
                        full_name=full_name[:255],
                        first_name=str(row.get('first_name', ''))[:100],
                        last_name=str(row.get('last_name', ''))[:100],
                        mobile=mobile,
                        phone=str(row.get('phone', ''))[:20] if row.get('phone') else '',
                        email=str(row.get('email', ''))[:254] if row.get('email') else '',
                        dob=self._parse_date(row.get('dob')),
                        anniversary=self._parse_date(row.get('anniversary')),
                        store_name=str(row.get('store_name', ''))[:255],
                        location=str(row.get('location', ''))[:255],
                        city=str(row.get('city', ''))[:100],
                        state=str(row.get('state', ''))[:100],
                        lead_source=str(row.get('lead_source', ''))[:100],
                        lead_status=str(row.get('lead_status', ''))[:50],
                        original_lead_source=str(row.get('original_lead_source', ''))[:100],
                        gender=str(row.get('gender', ''))[:20],
                        marital_status=str(row.get('marital_status', ''))[:50],
                        budget_range=str(row.get('budget_range', ''))[:100],
                        interest_category=str(row.get('interest_category', ''))[:255],
                        loyalty_points=self._parse_int(row.get('loyalty_points', 0)),
                        loyalty_redeemed=self._parse_int(row.get('loyalty_redeemed', 0)),
                        loyalty_earned=self._parse_int(row.get('loyalty_earned', 0)),
                        last_engagement_date=self._parse_date(row.get('last_engagement_date')),
                        total_signal_score=self._parse_decimal(row.get('total_signal_score', 0)),
                        sales_person=str(row.get('sales_person', ''))[:100],
                        original_sales_person=str(row.get('original_sales_person', ''))[:100],
                    )
                    records_to_create.append(record)
                    rows_imported += 1
                    
                except Exception as e:
                    self.warnings.append(f"Row {idx + 2}: {str(e)}")
                    rows_skipped += 1
            
            if records_to_create:
                try:
                    with transaction.atomic():
                        CRMContact.objects.bulk_create(records_to_create, batch_size=500)
                except Exception as e:
                    logger.error(f"CRM bulk create failed: {e}")
                    return {'success': False, 'error': f'Database error: {str(e)}'}
            
            ImportLog.objects.create(
                company=self.company,
                file_type='crm',
                file_name=file.name,
                rows_imported=rows_imported,
                rows_skipped=rows_skipped,
                columns_mapped=mapped,
                columns_unmapped=unmapped,
                errors=self.warnings[:50],
                imported_by=self.user,
            )
            
            return {
                'success': True,
                'rows_imported': rows_imported,
                'rows_skipped': rows_skipped,
                'columns_mapped': mapped,
                'columns_unmapped': unmapped,
                'warnings': self.warnings[:20],
            }
            
        except Exception as e:
            logger.error(f"CRM import failed: {e}")
            return {'success': False, 'error': str(e)}

