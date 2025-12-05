"""
Views for Tasks module.
"""

from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from django.contrib.auth.mixins import LoginRequiredMixin
from django.views.generic import ListView, DetailView, CreateView, UpdateView, View
from django.urls import reverse_lazy
from django.contrib import messages
from django.utils import timezone
from django.db.models import Q

from .models import Task, TaskComment
from .forms import TaskForm, TaskCommentForm
from apps.core.utils import log_audit_action

class TaskListView(LoginRequiredMixin, ListView):
    model = Task
    template_name = 'tasks/list.html'
    context_object_name = 'tasks'
    paginate_by = 10

    def get_queryset(self):
        user = self.request.user
        view_type = self.request.GET.get('view', 'my')
        
        if view_type == 'assigned':
            return Task.objects.filter(assigned_by=user).select_related('assigned_to')
        else:
            return Task.objects.filter(assigned_to=user).select_related('assigned_by').order_by('status', 'due_date')

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        
        context['my_pending_count'] = Task.objects.filter(
            assigned_to=user
        ).exclude(status='done').count()
        
        context['current_view'] = self.request.GET.get('view', 'my')
        return context

class TaskCreateView(LoginRequiredMixin, CreateView):
    model = Task
    form_class = TaskForm
    template_name = 'tasks/form.html'
    success_url = reverse_lazy('tasks:list')

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['user'] = self.request.user
        return kwargs

    def form_valid(self, form):
        self.object = form.save(commit=False)
        self.object.company = self.request.user.company
        self.object.assigned_by = self.request.user
        self.object.save()
        
        log_audit_action(
            self.request, 'create', 
            f"Created task: {self.object.title} for {self.object.assigned_to.full_name}", 
            'task', self.object.id
        )
        
        messages.success(self.request, "Task created successfully.")
        return redirect(self.success_url)

class TaskDetailView(LoginRequiredMixin, DetailView):
    model = Task
    template_name = 'tasks/detail.html'
    context_object_name = 'task'

    def get_queryset(self):
        user = self.request.user
        # Users can view tasks they are assigned to OR assigned by them OR if they are admins/managers (simplified for now)
        return Task.objects.filter(
            Q(assigned_to=user) | Q(assigned_by=user) | Q(company=user.company)
        )

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        context['comment_form'] = TaskCommentForm()
        context['comments'] = self.object.comments.select_related('user').all()
        return context

class TaskUpdateView(LoginRequiredMixin, UpdateView):
    model = Task
    form_class = TaskForm
    template_name = 'tasks/form.html'
    success_url = reverse_lazy('tasks:list')

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs['user'] = self.request.user
        return kwargs
        
    def get_queryset(self):
        user = self.request.user
        return Task.objects.filter(Q(assigned_to=user) | Q(assigned_by=user))

    def form_valid(self, form):
        response = super().form_valid(form)
        if self.object.status == 'done' and not self.object.completed_at:
            self.object.completed_at = timezone.now()
            self.object.save()
            
        log_audit_action(
            self.request, 'update', 
            f"Updated task: {self.object.title}", 
            'task', self.object.id
        )
        messages.success(self.request, "Task updated successfully.")
        return response

class TaskCommentView(LoginRequiredMixin, View):
    def post(self, request, pk):
        task = get_object_or_404(Task, pk=pk)
        
        # Permission check
        if task.assigned_to != request.user and task.assigned_by != request.user and task.company != request.user.company:
             messages.error(request, "Permission denied.")
             return redirect('tasks:list')

        form = TaskCommentForm(request.POST)
        if form.is_valid():
            comment = form.save(commit=False)
            comment.task = task
            comment.user = request.user
            comment.save()
            messages.success(request, "Comment added.")
        else:
            messages.error(request, "Error adding comment.")
            
        return redirect('tasks:detail', pk=pk)

class TaskStatusUpdateView(LoginRequiredMixin, View):
    def post(self, request, pk):
        task = get_object_or_404(Task, pk=pk)
        
        # Only assignee or creator can update status
        if task.assigned_to != request.user and task.assigned_by != request.user:
             messages.error(request, "Permission denied.")
             return redirect('tasks:list')
             
        new_status = request.POST.get('status')
        if new_status in dict(Task.STATUS_CHOICES):
            task.status = new_status
            if new_status == 'done':
                task.completed_at = timezone.now()
            task.save()
            
            log_audit_action(
                request, 'update_status', 
                f"Updated task status to {new_status}: {task.title}", 
                'task', task.id
            )
            messages.success(request, f"Task marked as {task.get_status_display()}.")
        
        return redirect('tasks:detail', pk=pk)
