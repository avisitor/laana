#!/bin/bash
# Deploy Read-Only Reverse Proxy for Elasticsearch and Embeddings

set -e

echo "========================================"
echo "Proxy Deployment: Elasticsearch & Embeddings"
echo "========================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run with sudo"
    exit 1
fi

# Elasticsearch configuration
ES_CONFIG_FILE="nginx-elasticsearch-readonly.conf"
ES_NGINX_AVAILABLE="/etc/nginx/sites-available/elasticsearch"
ES_NGINX_ENABLED="/etc/nginx/sites-enabled/elasticsearch"

# Embeddings configuration
EMB_CONFIG_FILE="nginx-embeddings.conf"
EMB_NGINX_AVAILABLE="/etc/nginx/sites-available/embeddings"
EMB_NGINX_ENABLED="/etc/nginx/sites-enabled/embeddings"

# Deploy Elasticsearch
echo "📋 Deploying Elasticsearch configuration..."
cp "$ES_CONFIG_FILE" "$ES_NGINX_AVAILABLE"
echo "✅ Config copied to $ES_NGINX_AVAILABLE"

if [ ! -L "$ES_NGINX_ENABLED" ]; then
    echo "🔗 Creating symlink..."
    ln -s "$ES_NGINX_AVAILABLE" "$ES_NGINX_ENABLED"
    echo "✅ Symlink created"
else
    echo "✅ Symlink already exists"
fi

# Deploy Embeddings
echo ""
echo "📋 Deploying Embeddings configuration..."
cp "$EMB_CONFIG_FILE" "$EMB_NGINX_AVAILABLE"
echo "✅ Config copied to $EMB_NGINX_AVAILABLE"

if [ ! -L "$EMB_NGINX_ENABLED" ]; then
    echo "🔗 Creating symlink..."
    ln -s "$EMB_NGINX_AVAILABLE" "$EMB_NGINX_ENABLED"
    echo "✅ Symlink created"
else
    echo "✅ Symlink already exists"
fi

# Test nginx config
echo ""
echo "🧪 Testing nginx configuration..."
nginx -t

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Configuration is valid"
    echo ""
    
    # Note: Port 443 should already be open for HTTPS
    
    # Reload nginx
    echo ""
    echo "🔄 Reloading nginx..."
    systemctl reload nginx
    echo "✅ Nginx reloaded"
    
    echo ""
    echo "========================================"
    echo "✅ Deployment Complete!"
    echo "========================================"
    echo ""
    echo "Services now accessible at:"
    echo "  🔍 Elasticsearch: https://elasticsearch.worldspot.org"
    echo "  🧠 Embeddings:    https://embeddings.worldspot.org"
    echo ""
    echo "Elasticsearch restrictions:"
    echo "  ✅ GET/HEAD/POST allowed for _search and _count"
    echo "  ❌ Admin endpoints blocked"
    echo ""
    echo "Embeddings restrictions:"
    echo "  ✅ GET/POST allowed"
    echo "  ⚠️  No authentication (add later)"
    echo ""
else
    echo ""
    echo "❌ Configuration test failed"
    echo "Please review the errors above"
    exit 1
fi
