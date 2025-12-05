#!/bin/bash

# Exit on error
set -e

DOMAIN="darpan.yuktaa.com"
EMAIL="platform@admin.com" # Change if needed

echo "Updating .env ALLOWED_HOSTS..."
# Add domain to ALLOWED_HOSTS if not present
sed -i "s/ALLOWED_HOSTS=.*/ALLOWED_HOSTS=${DOMAIN},152.67.2.136,localhost/" .env

echo "Updating Nginx configuration..."
# Update server_name in Nginx config
sudo sed -i "s/server_name .*/server_name ${DOMAIN} 152.67.2.136;/" /etc/nginx/sites-available/darpan

# Reload Nginx to apply changes
sudo systemctl reload nginx

echo "Installing Certbot..."
sudo apt-get update
sudo apt-get install -y certbot python3-certbot-nginx

echo "Obtaining SSL Certificate..."
# Run certbot non-interactively
sudo certbot --nginx \
    --non-interactive \
    --agree-tos \
    --email ${EMAIL} \
    --domains ${DOMAIN} \
    --redirect

echo "SSL Setup Complete!"
echo "Visit https://${DOMAIN}"
