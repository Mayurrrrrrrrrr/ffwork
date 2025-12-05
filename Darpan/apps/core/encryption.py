import base64
from cryptography.fernet import Fernet
from django.conf import settings
from .models import Company

class EncryptionManager:
    """
    Handles data encryption/decryption using per-company keys.
    """
    
    @staticmethod
    def generate_key():
        """Generate a new Fernet key."""
        return Fernet.generate_key()

    @staticmethod
    def get_cipher(company):
        """Get Fernet cipher for a company."""
        if not company.encryption_key:
            # Generate key if missing (should be done on creation, but for safety)
            key = EncryptionManager.generate_key()
            company.encryption_key = key
            company.save()
        
        return Fernet(company.encryption_key)

    @staticmethod
    def encrypt(data, company):
        """Encrypt string data."""
        if not data:
            return None
        cipher = EncryptionManager.get_cipher(company)
        return cipher.encrypt(data.encode('utf-8')).decode('utf-8')

    @staticmethod
    def decrypt(encrypted_data, company):
        """Decrypt string data."""
        if not encrypted_data:
            return None
        cipher = EncryptionManager.get_cipher(company)
        try:
            return cipher.decrypt(encrypted_data.encode('utf-8')).decode('utf-8')
        except Exception:
            return "[Decryption Failed]"
