import os
import django

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
django.setup()

from apps.core.models import Company
from apps.learning.models import Course, Module, Lesson, Quiz, Question

try:
    company = Company.objects.first()
    if not company:
        print("No company found. Please run create_company.py first.")
        exit()

    # Create a sample course
    course, created = Course.objects.get_or_create(
        company=company,
        title="Sales Mastery 101",
        defaults={
            'description': "Comprehensive training for new sales representatives. Learn the art of persuasion, closing deals, and managing client relationships."
        }
    )
    print(f"Course: {course.title}")

    # Module 1: Introduction
    mod1, _ = Module.objects.get_or_create(
        course=course,
        title="Introduction to Sales",
        defaults={'order': 1, 'description': "Basics of sales process"}
    )
    
    # Lessons for Module 1
    Lesson.objects.get_or_create(
        module=mod1,
        title="Welcome & Overview",
        defaults={
            'content_type': 'text',
            'text_content': "Welcome to the Sales Mastery course! In this journey, you will learn...",
            'duration_minutes': 5,
            'order': 1
        }
    )
    
    Lesson.objects.get_or_create(
        module=mod1,
        title="The Sales Mindset",
        defaults={
            'content_type': 'video',
            'video_url': 'https://www.youtube.com/embed/dQw4w9WgXcQ', # Placeholder
            'duration_minutes': 15,
            'order': 2
        }
    )

    # Module 2: Closing Techniques
    mod2, _ = Module.objects.get_or_create(
        course=course,
        title="Closing the Deal",
        defaults={'order': 2, 'description': "Advanced closing strategies"}
    )
    
    Lesson.objects.get_or_create(
        module=mod2,
        title="Handling Objections",
        defaults={
            'content_type': 'text',
            'text_content': "Objections are opportunities in disguise. Here is how to handle them...",
            'duration_minutes': 20,
            'order': 1
        }
    )

    # Quiz for Module 2
    quiz, _ = Quiz.objects.get_or_create(
        module=mod2,
        title="Closing Techniques Quiz",
        defaults={'passing_score': 70}
    )
    
    # Questions
    if not quiz.questions.exists():
        Question.objects.create(
            quiz=quiz,
            text="What is the best way to handle a price objection?",
            options=["Ignore it", "Lower the price immediately", "Emphasize value over cost", "Argue with the client"],
            correct_option_index=2,
            order=1
        )
        Question.objects.create(
            quiz=quiz,
            text="When should you attempt to close?",
            options=["Immediately", "After addressing needs", "Never", "When the client leaves"],
            correct_option_index=1,
            order=2
        )
        print(f"Created quiz with questions for {mod2.title}")

    print("Sample course data created successfully!")

except Exception as e:
    print(f"Error: {str(e)}")
