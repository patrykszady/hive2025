#!/bin/bash

echo "🚀 Starting Laravel dev environment..."

# Launch Redis
sudo service redis-server start

# Start Meilisearch
nohup meilisearch > /dev/null 2>&1 &

# Start Horizon (Laravel queue worker)
php artisan horizon &

# Optional: Start Laravel dev server (if needed)
php artisan serve &

echo "✅ All services launched!"
