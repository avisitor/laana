#!/bin/bash
# OpenSearch Installation Script for AlmaLinux 9

set -e

echo "Adding OpenSearch repository..."
sudo curl -SL https://artifacts.opensearch.org/releases/bundle/opensearch/2.x/opensearch-2.x.repo -o /etc/yum.repos.d/opensearch-2.x.repo

echo "Installing OpenSearch..."
# For OpenSearch 2.12+, an initial admin password is required
sudo env OPENSEARCH_INITIAL_ADMIN_PASSWORD=Admin123! dnf install opensearch -y

echo "Configuring OpenSearch..."
# Basic configuration to allow it to start on a single node if needed
# You might want to adjust /etc/opensearch/opensearch.yml later
if [ ! -f /etc/opensearch/opensearch.yml.bak ]; then
    sudo cp /etc/opensearch/opensearch.yml /etc/opensearch/opensearch.yml.bak
fi

# Ensure it can start as a single node for jumpstart
sudo sed -i 's/#discovery.type: zen/discovery.type: single-node/' /etc/opensearch/opensearch.yml

echo "Enabling and starting OpenSearch service..."
sudo systemctl daemon-reload
sudo systemctl enable opensearch
sudo systemctl start opensearch

echo "Checking OpenSearch status..."
sudo systemctl status opensearch --no-pager

echo "OpenSearch installation and startup complete!"
