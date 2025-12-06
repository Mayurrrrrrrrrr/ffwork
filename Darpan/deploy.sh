#!/bin/bash

# Deployment script for Darpan

echo "🚀 Starting deployment..."

# 1. Pull latest changes
echo "📦 Pulling latest changes from git..."
git pull origin main

# 2. Activate virtual environment
echo "🔌 Activating virtual environment..."
source venv/bin/activate

# 3. Install dependencies
echo "📥 Installing dependencies..."
pip install -r requirements.txt

# 4. Run migrations
echo "🗄️ Running database migrations..."
python manage.py migrate

# 5. Collect static files
echo "🎨 Collecting static files..."
python manage.py collectstatic --noinput

# 6. Restart application server
echo "🔄 Restarting Gunicorn..."
# Adjust the service name if different (e.g., darpan.service)
sudo systemctl restart gunicorn

echo "✅ Deployment complete!"
