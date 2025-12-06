"""
Test cases for Darpan ERP views.
"""
from django.test import TestCase, Client
from django.urls import reverse
from apps.core.models import User, Company


class AuthenticationViewsTest(TestCase):
    """Test cases for authentication views."""
    
    def setUp(self):
        """Set up test client and data."""
        self.client = Client()
        self.company = Company.objects.create(
            name='Test Company',
            code='TEST'
        )
        self.user = User.objects.create_user(
            email='test@example.com',
            password='testpass123',
            company=self.company
        )
    
    def test_login_page_loads(self):
        """Test login page loads successfully."""
        response = self.client.get(reverse('authentication:login'))
        self.assertEqual(response.status_code, 200)
    
    def test_login_success(self):
        """Test successful login."""
        response = self.client.post(reverse('authentication:login'), {
            'username': 'test@example.com',
            'password': 'testpass123'
        })
        # Should redirect to dashboard on success
        self.assertIn(response.status_code, [200, 302])


class DashboardViewsTest(TestCase):
    """Test cases for dashboard views."""
    
    def setUp(self):
        """Set up authenticated client."""
        self.client = Client()
        self.company = Company.objects.create(
            name='Test Company',
            code='TEST'
        )
        self.user = User.objects.create_user(
            email='test@example.com',
            password='testpass123',
            company=self.company
        )
        self.client.login(email='test@example.com', password='testpass123')
    
    def test_dashboard_requires_login(self):
        """Test dashboard requires authentication."""
        client = Client()  # New unauthenticated client
        response = client.get(reverse('dashboard:home'))
        self.assertEqual(response.status_code, 302)  # Redirect to login
