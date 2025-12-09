"""
Utility functions for Learning Module.
"""

from io.BytesIO import BytesIO
from reportlab.lib.pagesizes import letter, A4
from reportlab.pdfgen import canvas
from reportlab.lib.units import inch
from reportlab.lib import colors
from datetime import datetime


def generate_certificate_pdf(certificate):
    """
    Generate a PDF certificate for a completed course.
    
    Args:
        certificate: CourseCertificate instance
    
    Returns:
        BytesIO buffer containing the PDF
    """
    buffer = BytesIO()
    c = canvas.Canvas(buffer, pagesize=A4)
    width, height = A4
    
    # Certificate border
    c.setLineWidth(3)
    c.setStrokeColor(colors.HexColor('#1e40af'))  # Blue
    c.rect(40, 40, width - 80, height - 80)
    
    # Title
    c.setFont("Helvetica-Bold", 36)
    c.setFillColor(colors.HexColor('#1e40af'))
    c.drawCentredString(width / 2, height - 120, "Certificate of Completion")
    
    # Subtitle
    c.setFont("Helvetica", 16)
    c.setFillColor(colors.black)
    c.drawCentredString(width / 2, height - 160, "This certifies that")
    
    # User name
    c.setFont("Helvetica-Bold", 28)
    c.setFillColor(colors.HexColor ('#059669'))  # Green
    c.drawCentredString(width / 2, height - 220, certificate.user.full_name)
    
    # Course completion text
    c.setFont("Helvetica", 16)
    c.setFillColor(colors.black)
    c.drawCentredString(width / 2, height - 260, "has successfully completed the course")
    
    # Course title
    c.setFont("Helvetica-Bold", 22)
    c.setFillColor(colors.HexColor('#1e40af'))
    c.drawCentredString(width / 2, height - 310, certificate.course.title)
    
    # Date
    c.setFont("Helvetica", 14)
    c.setFillColor(colors.black)
    date_str = certificate.issued_at.strftime("%B %d, %Y")
    c.drawCentredString(width / 2, height - 360, f"Issued on {date_str}")
    
    # Certificate number
    c.setFont("Helvetica", 10)
    c.setFillColor(colors.gray)
    c.drawCentredString(width / 2, 80, f"Certificate Number: {certificate.certificate_number}")
    
    # Company name (if available)
    if certificate.course.company:
        c.setFont("Helvetica-Bold", 12)
        c.setFillColor(colors.black)
        c.drawCentredString(width / 2, height - 410, certificate.course.company.name)
    
    c.showPage()
    c.save()
    
    buffer.seek(0)
    return buffer
