"""
Test cases for Darpan ERP models.
"""
from django.test import TestCase
from apps.core.models import User, Company


class UserModelTest(TestCase):
    """Test cases for User model."""
    
    def setUp(self):
        """Set up test data."""
        self.company = Company.objects.create(
            name='Test Company',
            code='TEST'
        )
    
    def test_user_creation(self):
        """Test basic user creation."""
        user = User.objects.create_user(
            email='test@example.com',
            password='testpass123',
            company=self.company
        )
        self.assertEqual(user.email, 'test@example.com')
        self.assertTrue(user.check_password('testpass123'))
    
    def test_user_str(self):
        """Test user string representation."""
        user = User.objects.create_user(
            email='test@example.com',
            password='testpass123',
            company=self.company
        )
        self.assertIn('test@example.com', str(user))


class CompanyModelTest(TestCase):
    """Test cases for Company model."""
    
    def test_company_creation(self):
        """Test company creation."""
        company = Company.objects.create(
            name='Test Company',
            code='TEST'
        )
        self.assertEqual(company.name, 'Test Company')
        self.assertEqual(company.code, 'TEST')
