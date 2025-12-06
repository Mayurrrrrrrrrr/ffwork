#!/bin/bash

# Setup Gunicorn Service
echo "🔧 Configuring Gunicorn Service..."

SERVICE_FILE="/etc/systemd/system/gunicorn.service"

# Create service file content
sudo bash -c "cat > $SERVICE_FILE" <<EOF
[Unit]
Description=gunicorn daemon for Darpan
After=network.target

[Service]
User=ubuntu
Group=www-data
WorkingDirectory=/home/ubuntu/darpan/Darpan
ExecStart=/home/ubuntu/darpan/Darpan/venv/bin/gunicorn --access-logfile - --workers 3 --bind unix:/home/ubuntu/darpan/Darpan/darpan.sock config.wsgi:application

[Install]
WantedBy=multi-user.target
EOF

# Reload systemd and restart gunicorn
echo "🔄 Reloading systemd and restarting Gunicorn..."
sudo systemctl daemon-reload
sudo systemctl restart gunicorn
sudo systemctl enable gunicorn
sudo systemctl status gunicorn --no-pager

echo "✅ Gunicorn service configured!"
