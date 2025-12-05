#!/bin/bash

# Darpan Deployment Script for Ubuntu (Oracle Cloud)

# Exit on error
set -e

echo "Starting deployment setup..."

# 1. Update System
echo "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# 2. Install Dependencies
echo "Installing system dependencies..."
sudo apt install -y python3-pip python3-dev libmysqlclient-dev mysql-server nginx curl git

# 3. Secure MySQL (Automated)
# NOTE: In a real scenario, you might want to do this manually or use a more secure method.
# For this script, we'll assume the user will set up the DB user/pass manually or we prompt for it.

echo "----------------------------------------------------------------"
echo "Database Setup"
echo "----------------------------------------------------------------"
read -p "Enter Database Name (default: darpan_db): " DB_NAME
DB_NAME=${DB_NAME:-darpan_db}
read -p "Enter Database User (default: darpan_user): " DB_USER
DB_USER=${DB_USER:-darpan_user}
read -s -p "Enter Database Password: " DB_PASS
echo ""

echo "Creating database and user..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# 4. Project Setup
# Assuming this script is run from inside the cloned repo
PROJECT_DIR=$(pwd)
echo "Setting up project in $PROJECT_DIR..."

# Create Virtual Env
if [ ! -d "venv" ]; then
    python3 -m venv venv
fi

source venv/bin/activate
pip install -r requirements.txt
pip install gunicorn

# 5. Environment Variables
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cp .env.example .env
    # Simple sed replacement - might need to be more robust
    sed -i "s/DB_NAME=.*/DB_NAME=${DB_NAME}/" .env
    sed -i "s/DB_USER=.*/DB_USER=${DB_USER}/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
    sed -i "s/DEBUG=True/DEBUG=False/" .env
    
    # Generate a random secret key
    SECRET=$(python3 -c 'from django.core.management.utils import get_random_secret_key; print(get_random_secret_key())')
    sed -i "s/SECRET_KEY=.*/SECRET_KEY='${SECRET}'/" .env
    
    # Get Public IP
    PUBLIC_IP=$(curl -s ifconfig.me)
    sed -i "s/ALLOWED_HOSTS=.*/ALLOWED_HOSTS=localhost,127.0.0.1,${PUBLIC_IP}/" .env
fi

# 6. Migrations & Static Files
echo "Running migrations..."
python manage.py migrate
echo "Collecting static files..."
python manage.py collectstatic --noinput

# 7. Gunicorn Setup
echo "Configuring Gunicorn..."
sudo cp setup/gunicorn.service /etc/systemd/system/
sudo systemctl start gunicorn
sudo systemctl enable gunicorn

# 8. Nginx Setup
echo "Configuring Nginx..."
sudo cp setup/nginx.conf /etc/nginx/sites-available/darpan
# Update server_name in nginx conf
sudo sed -i "s/YOUR_DOMAIN_OR_IP/${PUBLIC_IP}/" /etc/nginx/sites-available/darpan

sudo ln -sf /etc/nginx/sites-available/darpan /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx

# 9. Firewall (Oracle Cloud specific - open ports in iptables if needed, but usually handled by Oracle Security Lists)
# However, Ubuntu on Oracle often has iptables rules.
echo "Adjusting firewall rules..."
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save

echo "----------------------------------------------------------------"
echo "Deployment Complete!"
echo "Visit http://${PUBLIC_IP} to see your application."
echo "----------------------------------------------------------------"
