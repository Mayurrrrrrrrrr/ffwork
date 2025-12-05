#!/bin/bash

# Exit on error
set -e

# Configuration
DB_NAME="darpan_db"
DB_USER="darpan_user"
DB_PASS="StrongPassword123!" # CHANGE THIS!
PROJECT_DIR="/home/ubuntu/darpan"

echo "Updating system..."
sudo apt-get update
sudo apt-get install -y python3-pip python3-venv python3-dev libmysqlclient-dev nginx git ufw pkg-config build-essential

echo "Installing MySQL Server..."
sudo apt-get install -y mysql-server

echo "Configuring MySQL..."
# Create DB and User if they don't exist
sudo mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "Setting up Virtual Environment..."
cd ${PROJECT_DIR}

# Ensure python3-venv is installed
sudo apt-get install -y python3-venv

# Force recreate venv to ensure it's clean
rm -rf venv
python3 -m venv venv

# Use the virtual environment's pip explicitly
./venv/bin/pip install --upgrade pip
./venv/bin/pip install -r requirements.txt
./venv/bin/pip install gunicorn

echo "Configuring .env..."
if [ ! -f ".env" ]; then
    cp .env.example .env
fi

# Always update .env with DB credentials (even if file exists)
sed -i "s/DB_NAME=.*/DB_NAME=${DB_NAME}/" .env
sed -i "s/DB_USER=.*/DB_USER=${DB_USER}/" .env
sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
sed -i "s/DEBUG=True/DEBUG=False/" .env
sed -i "s/ALLOWED_HOSTS=.*/ALLOWED_HOSTS=152.67.2.136,localhost/" .env

echo "Running Migrations..."
./venv/bin/python manage.py migrate
./venv/bin/python manage.py collectstatic --noinput

echo "Configuring Gunicorn..."
sudo cp deploy/gunicorn.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl restart gunicorn
sudo systemctl enable gunicorn

echo "Configuring Nginx..."
sudo cp deploy/nginx.conf /etc/nginx/sites-available/darpan
sudo ln -sf /etc/nginx/sites-available/darpan /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx

echo "Configuring Firewall (UFW)..."
sudo ufw allow 'Nginx Full'
sudo ufw allow OpenSSH
# Enable UFW if not enabled (be careful not to lock yourself out, usually Oracle has its own firewall too)
# sudo ufw --force enable 

echo "Deployment Complete!"
echo "Visit http://152.67.2.136"
