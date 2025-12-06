"""
Universal Data Import Framework for Darpan ERP.
Supports CSV, Excel (.xlsx), and JSON formats with intelligent field mapping.
"""

from abc import ABC, abstractmethod
import logging
import pandas as pd
import io
from django.core.exceptions import ValidationError
from django.db import transaction

logger = logging.getLogger(__name__)


class BaseImporter(ABC):
    """Abstract base class for all importers"""
    
    def __init__(self, file, company, user):
        self.file = file
        self.company = company
        self.user = user
        self.errors = []
        self.warnings = []
        
    @abstractmethod
    def validate_schema(self, df: pd.DataFrame) -> bool:
        """Validate column structure"""
        pass
    
    @abstractmethod
    def transform_data(self, df: pd.DataFrame) -> pd.DataFrame:
        """Clean and transform data"""
        pass
    
    @abstractmethod
    def import_records(self, df: pd.DataFrame) -> dict:
        """Save to database"""
        pass
    
    def process(self) -> dict:
        """Main import pipeline"""
        try:
            df = self.read_file()
            
            if not self.validate_schema(df):
                return {'success': False, 'errors': self.errors}
            
            df = self.transform_data(df)
            result = self.import_records(df)
            
            return {
                'success': True,
                'created': result['created'],
                'updated': result['updated'],
                'skipped': result['skipped'],
                'warnings': self.warnings
            }
        except Exception as e:
            return {'success': False, 'errors': [str(e)]}
    
    def read_file(self) -> pd.DataFrame:
        """Intelligently read various file formats"""
        ext = self.file.name.split('.')[-1].lower()
        
        if ext == 'csv':
            return pd.read_csv(self.file)
        elif ext in ['xlsx', 'xls']:
            return pd.read_excel(self.file)
        elif ext == 'json':
            return pd.read_json(self.file)
        else:
            raise ValidationError(f"Unsupported file type: {ext}")


class SalesImporter(BaseImporter):
    """Intelligent sales data importer"""
    
    REQUIRED_FIELDS = ['date', 'amount']
    OPTIONAL_FIELDS = ['product', 'customer', 'store', 'quantity']
    
    # Field mapping aliases - support various column naming conventions
    FIELD_ALIASES = {
        'date': ['transaction_date', 'sale_date', 'bill_date', 'Date', 'DATE', 'trans_date'],
        'amount': ['revenue', 'total', 'net_sales', 'Amount', 'AMOUNT', 'gross_amount'],
        'product': ['item', 'product_name', 'sku', 'style_code', 'Product'],
        'customer': ['client', 'customer_name', 'buyer', 'client_name'],
    }
    
    def validate_schema(self, df: pd.DataFrame) -> bool:
        """Smart column detection"""
        df_columns_lower = [c.lower().strip() for c in df.columns]
        
        for required in self.REQUIRED_FIELDS:
            found = False
            for alias in self.FIELD_ALIASES.get(required, [required]):
                if alias.lower() in df_columns_lower:
                    found = True
                    break
            
            if not found:
                self.errors.append(f"Missing required field: {required}")
        
        return len(self.errors) == 0
    
    def transform_data(self, df: pd.DataFrame) -> pd.DataFrame:
        """Clean and standardize data"""
        # Normalize column names
        df = self._normalize_columns(df)
        
        # Data cleaning
        df['date'] = pd.to_datetime(df['date'], errors='coerce')
        df['amount'] = pd.to_numeric(df['amount'], errors='coerce')
        
        # Remove invalid rows
        invalid_count = df[df['date'].isna() | df['amount'].isna()].shape[0]
        if invalid_count > 0:
            self.warnings.append(f"{invalid_count} rows with invalid date/amount will be skipped")
        
        df = df.dropna(subset=['date', 'amount'])
        
        return df
    
    def _normalize_columns(self, df: pd.DataFrame) -> pd.DataFrame:
        """Map various column names to standard fields"""
        rename_map = {}
        
        for col in df.columns:
            col_lower = col.lower().strip()
            for standard_field, aliases in self.FIELD_ALIASES.items():
                if col_lower in [a.lower() for a in aliases]:
                    rename_map[col] = standard_field
                    break
        
        return df.rename(columns=rename_map)
    
    def import_records(self, df: pd.DataFrame) -> dict:
        """Bulk upsert with progress tracking"""
        from apps.analytics.models import SalesRecord
        
        records_to_create = []
        created, updated, skipped = 0, 0, 0
        
        for idx, row in df.iterrows():
            try:
                # Create new record
                records_to_create.append(SalesRecord(
                    company=self.company,
                    transaction_date=row['date'],
                    revenue=row['amount'],
                    product_name=row.get('product', ''),
                    client_name=row.get('customer', ''),
                    created_by=self.user
                ))
                created += 1
                    
            except Exception as e:
                self.warnings.append(f"Row {idx + 2}: {str(e)}")
                skipped += 1
        
        # Bulk create for performance
        if records_to_create:
            try:
                SalesRecord.objects.bulk_create(
                    records_to_create, 
                    batch_size=1000, 
                    ignore_conflicts=True
                )
            except Exception as e:
                logger.error(f"Bulk create failed: {e}")
                self.warnings.append(f"Bulk import error: {str(e)}")
        
        return {'created': created, 'updated': updated, 'skipped': skipped}


class StockImporter(BaseImporter):
    """Product/Stock data importer"""
    
    REQUIRED_FIELDS = ['name', 'sku']
    
    FIELD_ALIASES = {
        'name': ['product_name', 'item_name', 'Name'],
        'sku': ['SKU', 'code', 'item_code'],
        'category': ['Category', 'type'],
        'price': ['sale_price', 'Price', 'mrp'],
    }
    
    def validate_schema(self, df: pd.DataFrame) -> bool:
        """Validate required fields for products"""
        df_columns_lower = [c.lower().strip() for c in df.columns]
        
        for required in self.REQUIRED_FIELDS:
            found = False
            for alias in self.FIELD_ALIASES.get(required, [required]):
                if alias.lower() in df_columns_lower:
                    found = True
                    break
            
            if not found:
                self.errors.append(f"Missing required field: {required}")
        
        return len(self.errors) == 0
    
    def transform_data(self, df: pd.DataFrame) -> pd.DataFrame:
        """Clean product data"""
        df = self._normalize_columns(df)
        
        # Clean numeric fields
        if 'price' in df.columns:
            df['price'] = pd.to_numeric(df['price'], errors='coerce').fillna(0)
        
        return df
    
    def _normalize_columns(self, df: pd.DataFrame) -> pd.DataFrame:
        """Map column names"""
        rename_map = {}
        
        for col in df.columns:
            col_lower = col.lower().strip()
            for standard_field, aliases in self.FIELD_ALIASES.items():
                if col_lower in [a.lower() for a in aliases]:
                    rename_map[col] = standard_field
                    break
        
        return df.rename(columns=rename_map)
    
    def import_records(self, df: pd.DataFrame) -> dict:
        """Import product records"""
        from apps.stock.models import Product
        
        created, updated, skipped = 0, 0, 0
        
        for idx, row in df.iterrows():
            try:
                obj, is_created = Product.objects.update_or_create(
                    company=self.company,
                    sku=row['sku'],
                    defaults={
                        'name': row['name'],
                        'category': row.get('category', ''),
                        'sale_price': row.get('price', 0),
                    }
                )
                
                if is_created:
                    created += 1
                else:
                    updated += 1
                    
            except Exception as e:
                self.warnings.append(f"Row {idx + 2}: {str(e)}")
                skipped += 1
        
        return {'created': created, 'updated': updated, 'skipped': skipped}
