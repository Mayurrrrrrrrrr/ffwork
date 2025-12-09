"""
Views for Learning Management System module.
"""

from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth.decorators import login_required
from django.contrib.auth.mixins import LoginRequiredMixin
from django.views.generic import ListView, DetailView, View
from django.contrib import messages
from django.utils import timezone
from django.db.models import Prefetch

from .models import (Course, Module, Lesson, Quiz, Question, UserCourseProgress, 
                      UserLessonProgress, UserQuizAttempt, CourseCertificate)
from apps.core.utils import log_audit_action

class CourseListView(LoginRequiredMixin, ListView):
    """
    Catalog of available courses for the user's company.
    """
    model = Course
    template_name = 'learning/catalog.html'
    context_object_name = 'courses'

    def get_queryset(self):
        user = self.request.user
        # Show active courses for user's company
        return Course.objects.filter(
            company=user.company, 
            is_active=True
        ).prefetch_related(
            Prefetch('student_progress', queryset=UserCourseProgress.objects.filter(user=user), to_attr='user_progress')
        )

class CourseDetailView(LoginRequiredMixin, DetailView):
    """
    Course dashboard showing modules, lessons, and progress.
    """
    model = Course
    template_name = 'learning/course_detail.html'
    context_object_name = 'course'

    def get_queryset(self):
        return Course.objects.filter(company=self.request.user.company, is_active=True)

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        course = self.object
        
        # Get or create progress record
        progress, created = UserCourseProgress.objects.get_or_create(user=user, course=course)
        context['progress'] = progress
        
        # Get modules with lessons and user completion status
        modules = Module.objects.filter(course=course).prefetch_related(
            'lessons', 
            'quizzes'
        ).order_by('order')
        
        # Map completed lessons
        completed_lesson_ids = UserLessonProgress.objects.filter(
            user=user, 
            lesson__module__course=course, 
            completed=True
        ).values_list('lesson_id', flat=True)
        
        context['modules'] = modules
        context['completed_lesson_ids'] = set(completed_lesson_ids)
        
        return context

class LessonView(LoginRequiredMixin, DetailView):
    """
    View for learning content (Video/Text).
    """
    model = Lesson
    template_name = 'learning/lesson.html'
    context_object_name = 'lesson'

    def get_queryset(self):
        return Lesson.objects.filter(module__course__company=self.request.user.company)

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        user = self.request.user
        lesson = self.object
        course = lesson.module.course
        
        # Mark as started/in-progress if not already
        course_progress, _ = UserCourseProgress.objects.get_or_create(user=user, course=course)
        if course_progress.status == 'not_started':
            course_progress.status = 'in_progress'
            course_progress.save()
            
        # Check if completed
        is_completed = UserLessonProgress.objects.filter(user=user, lesson=lesson, completed=True).exists()
        context['is_completed'] = is_completed
        
        # Navigation: Next/Prev lesson
        # Simplified logic: just get next by order
        # In real app, need to handle module transitions
        
        return context
    
    def post(self, request, *args, **kwargs):
        """Mark lesson as complete."""
        lesson = self.get_object()
        user = request.user
        
        progress, created = UserLessonProgress.objects.get_or_create(user=user, lesson=lesson)
        if not progress.completed:
            progress.completed = True
            progress.completed_at = timezone.now()
            progress.save()
            
            # Update course progress
            course_progress = UserCourseProgress.objects.get(user=user, course=lesson.module.course)
            course_progress.update_progress()
            
            messages.success(request, "Lesson completed!")
            
        return redirect('learning:course_detail', pk=lesson.module.course.pk)

class QuizView(LoginRequiredMixin, DetailView):
    """
    Take a quiz.
    """
    model = Quiz
    template_name = 'learning/quiz.html'
    context_object_name = 'quiz'

    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        # Check previous attempts
        context['attempts'] = UserQuizAttempt.objects.filter(
            user=self.request.user, quiz=self.object
        )
        return context

    def post(self, request, *args, **kwargs):
        quiz = self.get_object()
        user = request.user
        questions = quiz.questions.all()
        
        score = 0
        total_questions = questions.count()
        
        if total_questions == 0:
            messages.error(request, "Quiz has no questions.")
            return redirect('learning:course_detail', pk=quiz.module.course.pk)

        for question in questions:
            selected_option = request.POST.get(f'question_{question.id}')
            if selected_option and int(selected_option) == question.correct_option_index:
                score += 1
        
        percentage = int((score / total_questions) * 100)
        passed = percentage >= quiz.passing_score
        
        # Record attempt
        UserQuizAttempt.objects.create(
            user=user,
            quiz=quiz,
            score=percentage,
            passed=passed
        )
        
        if passed:
            msg = f"Congratulations! You passed with {percentage}%."
            tag = messages.SUCCESS
        else:
            msg = f"You scored {percentage}%. You need {quiz.passing_score}% to pass. Try again."
            tag = messages.WARNING
            
        messages.add_message(request, tag, msg)
        return redirect('learning:course_detail', pk=quiz.module.course.pk)


class GenerateCertificateView(LoginRequiredMixin, View):
    """
    Generate and download PDF certificate for completed course.
    """
    def get(self, request, course_id):
        from django.http import HttpResponse
        from .models import CourseCertificate
        from .utils import generate_certificate_pdf
        
        course = get_object_or_404(Course, id=course_id, company=request.user.company)
        
        # Check if user completed the course
        try:
            progress = UserCourseProgress.objects.get(user=request.user, course=course)
        except UserCourseProgress.DoesNotExist:
            messages.error(request, "You haven't started this course yet.")
            return redirect('learning:catalog')
        
        if progress.status != 'completed' or progress.progress_percent < 100:
            messages.error(request, "You must complete 100% of the course to receive a certificate.")
            return redirect('learning:course_detail', pk=course_id)
        
        # Check if user passed all quizzes
        failed_quizzes = Quiz.objects.filter(
            module__course=course,
            is_active=True
        ).exclude(
            id__in=UserQuizAttempt.objects.filter(
                user=request.user,
                quiz__module__course=course,
                passed=True
            ).values('quiz_id')
        )
        
        if failed_quizzes.exists():
            messages.error(request, "You must pass all quizzes to receive a certificate.")
            return redirect('learning:course_detail', pk=course_id)
        
        # Get or create certificate
        certificate, created = CourseCertificate.objects.get_or_create(
            user=request.user,
            course=course
        )
        
        # Generate PDF
        pdf_buffer = generate_certificate_pdf(certificate)
        
        response = HttpResponse(pdf_buffer, content_type='application/pdf')
        response['Content-Disposition'] = f'attachment; filename="certificate_{certificate.certificate_number}.pdf"'
        
        if created:
            messages.success(request, "Congratulations! Your certificate has been generated.")
        
        return response


class LeaderboardView(LoginRequiredMixin, ListView):
    """
    Display top learners by course completions and quiz scores.
    """
    template_name = 'learning/leaderboard.html'
    context_object_name = 'leaderboard'
    
    def get_queryset(self):
        from django.db.models import Count, Avg, Q
        from apps.core.models import User
        
        # Get all users in company with learning stats
        return User.objects.filter(
            company=self.request.user.company,
            is_active=True
        ).annotate(
            completed_courses=Count(
                'course_progress',
                filter=Q(course_progress__status='completed')
            ),
            avg_quiz_score=Avg('quiz_attempts__score')
        ).filter(
            completed_courses__gt=0  # Only show users who completed at least 1 course
        ).order_by('-completed_courses', '-avg_quiz_score')[:20]
    
    def get_context_data(self, **kwargs):
        context = super().get_context_data(**kwargs)
        
        # Add current user's rank
        leaderboard = list(self.get_queryset())
        for idx, user in enumerate(leaderboard, start=1):
            user.rank = idx
            if user.id == self.request.user.id:
                context['user_rank'] = idx
                break
        
        return context
