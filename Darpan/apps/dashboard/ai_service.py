# Darpan/apps/dashboard/ai_service.py
import os
from django.conf import settings
from django.db.models import Sum
from apps.tasks.models import Task
from apps.analytics.models import SalesRecord


class AIAssistant:
    def __init__(self):
        self.client = None
        self.model = "llama-3.3-70b-versatile"  # Updated from decommissioned 3.1
        self._init_client()
    
    def _init_client(self):
        """Initialize Groq client if API key is available."""
        api_key = getattr(settings, 'GROQ_API_KEY', None)
        if api_key:
            try:
                from groq import Groq
                self.client = Groq(api_key=api_key)
            except ImportError:
                pass
            except Exception:
                pass

    def get_erp_context(self, user):
        """
        Gathers a summary of data relevant to the specific user/company
        to feed into the AI as context.
        """
        context = []
        company = user.company
        
        # 1. User Context
        try:
            roles = ', '.join([r.name for r in user.roles.all()])
        except:
            roles = 'User'
        context.append(f"User: {user.full_name}, Role: {roles}")
        
        # 2. Task Context
        try:
            pending_tasks = Task.objects.filter(assigned_to=user, status__in=['todo', 'in_progress']).count()
            context.append(f"My Pending Tasks: {pending_tasks}")
        except:
            context.append("My Pending Tasks: Unable to fetch")

        # 3. Sales/KPI Context (If admin/manager)
        if company and user.has_any_role(['admin', 'store_manager', 'platform_admin']):
            try:
                sales = SalesRecord.objects.filter(company=company).aggregate(total=Sum('final_amount'))
                revenue = sales.get('total', 0) or 0
                context.append(f"Total Company Revenue: ₹{revenue:,.2f}")
                
                # Add more context
                sales_count = SalesRecord.objects.filter(company=company, transaction_type='sale').count()
                context.append(f"Total Sales Transactions: {sales_count}")
            except:
                context.append("Revenue Data: Unable to fetch")

        return "\n".join(context)

    def generate_response(self, user_question, user):
        """Generate AI response based on user question and ERP context."""
        if not self.client:
            return "AI service is not configured. Please set GROQ_API_KEY in your environment."
        
        try:
            # 1. Get System Context
            data_context = self.get_erp_context(user)
            
            company_name = user.company.name if user.company else "Darpan"
            
            # 2. Construct System Prompt
            system_prompt = f"""
            You are 'Darpan AI', a helpful enterprise assistant for the company {company_name}.
            
            Current Operational Context:
            {data_context}
            
            Guidelines:
            - Answer questions based on the context provided above.
            - If asked about data you don't have, say you don't have access to that specific record yet.
            - Keep answers concise and professional.
            - Format key numbers in bold using **number** syntax.
            - Use bullet points for lists.
            - Be helpful and friendly.
            """

            # 3. Call Groq
            chat_completion = self.client.chat.completions.create(
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_question}
                ],
                model=self.model,
                temperature=0.5,  # Lower temperature for more factual answers
                max_tokens=1024,
            )

            return chat_completion.choices[0].message.content

        except Exception as e:
            return f"I encountered an error connecting to the AI brain: {str(e)}"
