"""
AI Service using Groq for analytics insights.
Provides AI-powered analysis of sales, stock, and business data.
"""

import logging
import markdown
from django.conf import settings

logger = logging.getLogger(__name__)


class GroqAIService:
    """
    AI service for generating business insights using Groq's LLM.
    """
    
    def __init__(self):
        self.api_key = getattr(settings, 'GROQ_API_KEY', None)
        self.model = getattr(settings, 'GROQ_MODEL', 'llama-3.1-70b-versatile')
        self.max_tokens = getattr(settings, 'GROQ_MAX_TOKENS', 2048)
        self._client = None
    
    @property
    def client(self):
        """Lazy initialization of Groq client."""
        if self._client is None and self.api_key:
            try:
                from groq import Groq
                self._client = Groq(api_key=self.api_key)
            except ImportError:
                logger.warning("Groq library not installed")
            except Exception as e:
                logger.error(f"Failed to initialize Groq client: {e}")
        return self._client
    
    @property
    def is_available(self):
        """Check if AI service is properly configured."""
        return bool(self.api_key and self.client)
    
    def _call_groq(self, system_prompt: str, user_prompt: str) -> str:
        """
        Make a call to Groq API.
        
        Args:
            system_prompt: The system role message
            user_prompt: The user message/query
            
        Returns:
            The AI response text or error message
        """
        if not self.is_available:
            return "AI service is not configured. Please set GROQ_API_KEY in your environment."
        
        try:
            response = self.client.chat.completions.create(
                model=self.model,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_prompt}
                ],
                max_tokens=self.max_tokens,
                temperature=0.7,
            )
            return response.choices[0].message.content
        except Exception as e:
            logger.error(f"Groq API error: {e}")
            return f"Error generating AI response: {str(e)}"
    
    def analyze_sales_data(self, sales_summary: dict) -> str:
        """
        Analyze sales data and provide insights.
        
        Args:
            sales_summary: Dict containing sales metrics
            
        Returns:
            Markdown formatted analysis
        """
        system_prompt = """You are an expert business analyst for a jewelry retail company.
Analyze the provided sales data and give actionable insights.
Format your response in clear markdown with headers and bullet points.
Be concise but insightful. Focus on:
- Key performance highlights
- Areas of concern
- Actionable recommendations
- Trend observations"""

        user_prompt = f"""Analyze this sales data and provide business insights:

**Sales Summary:**
- Total Revenue: ₹{sales_summary.get('total_revenue', 0):,.0f}
- Net Sales: ₹{sales_summary.get('net_sales', 0):,.0f}
- Total Returns: ₹{sales_summary.get('total_returns', 0):,.0f}
- Number of Sales: {sales_summary.get('sales_count', 0)}
- Number of Returns: {sales_summary.get('returns_count', 0)}
- Average Order Value: ₹{sales_summary.get('avg_order_value', 0):,.0f}
- Gross Margin: ₹{sales_summary.get('total_margin', 0):,.0f}
- Margin Percentage: {sales_summary.get('margin_percentage', 0):.1f}%

**Top Categories:** {', '.join(sales_summary.get('top_categories', ['N/A']))}
**Top Locations:** {', '.join(sales_summary.get('top_locations', ['N/A']))}

Provide a brief analysis with key insights and recommendations."""

        response = self._call_groq(system_prompt, user_prompt)
        return response
    
    def analyze_stock_data(self, stock_summary: dict) -> str:
        """
        Analyze stock/inventory data and provide insights.
        
        Args:
            stock_summary: Dict containing stock metrics
            
        Returns:
            Markdown formatted analysis
        """
        system_prompt = """You are an inventory management expert for a jewelry retail company.
Analyze the provided stock data and give actionable insights.
Format your response in clear markdown.
Focus on:
- Stock health assessment
- Reorder recommendations
- Slow-moving inventory concerns
- Optimization suggestions"""

        user_prompt = f"""Analyze this inventory data and provide insights:

**Stock Summary:**
- Total SKUs: {stock_summary.get('total_skus', 0)}
- Total Quantity: {stock_summary.get('stock_qty', 0):,}
- Stock Value: ₹{stock_summary.get('stock_value', 0):,.0f}
- Total Weight: {stock_summary.get('total_weight', 0):,.2f} grams
- Low Stock Items: {stock_summary.get('low_stock_count', 0)}

**Stock by Category:** {stock_summary.get('category_breakdown', 'N/A')}
**Stock by Location:** {stock_summary.get('location_breakdown', 'N/A')}

Provide inventory management insights and recommendations."""

        response = self._call_groq(system_prompt, user_prompt)
        return response
    
    def generate_dashboard_insights(self, dashboard_data: dict) -> str:
        """
        Generate overall business insights for the dashboard.
        
        Args:
            dashboard_data: Combined sales and stock data
            
        Returns:
            Markdown formatted insights
        """
        system_prompt = """You are a business intelligence expert for a jewelry retail company.
Provide a brief executive summary of the business performance.
Be concise - maximum 3-4 bullet points.
Focus on the most important insights only."""

        user_prompt = f"""Provide a quick executive summary:

**This Month Performance:**
- Revenue: ₹{dashboard_data.get('revenue', 0):,.0f}
- Sales Count: {dashboard_data.get('sales_count', 0)}
- Stock Value: ₹{dashboard_data.get('stock_value', 0):,.0f}
- Pending Tasks: {dashboard_data.get('tasks_pending', 0)}

Give 3-4 key bullet points for the executive summary."""

        response = self._call_groq(system_prompt, user_prompt)
        return response
    
    def answer_business_query(self, query: str, context: dict) -> str:
        """
        Answer a free-form business question using available data.
        
        Args:
            query: User's question
            context: Available business data for context
            
        Returns:
            AI-generated answer
        """
        system_prompt = """You are a helpful business analyst assistant for a jewelry retail company called Darpan.
Answer the user's question based on the provided business data context.
Be helpful, accurate, and concise.
If you don't have enough data to answer, say so clearly."""

        user_prompt = f"""Business Context:
{context}

User Question: {query}

Please provide a helpful answer based on the available data."""

        response = self._call_groq(system_prompt, user_prompt)
        return response
    
    def to_html(self, markdown_text: str) -> str:
        """Convert markdown response to HTML."""
        try:
            return markdown.markdown(markdown_text, extensions=['tables', 'fenced_code'])
        except:
            return markdown_text


# Singleton instance
ai_service = GroqAIService()
