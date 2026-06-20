# Hostinger VPS Deployment Runbook

This repo is ready for Git-based deployment, but the API is a Laravel overlay. Do not run `composer install` directly inside `insightistic-api/` until it has been merged into a real Laravel application.

## 1. Server Packages

Ubuntu example:

```bash
sudo apt update
sudo apt install -y nginx git unzip curl postgresql postgresql-contrib supervisor

# PHP 8.3+ with Laravel extensions
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 LTS and PM2
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
sudo npm install -g pm2
```

## 2. Clone Repo

```bash
sudo mkdir -p /var/www/insightistic
sudo chown -R $USER:www-data /var/www/insightistic
cd /var/www/insightistic
git clone https://github.com/Shubochandrosarker/insightistic-app.git repo
```

For a private repo, authenticate with a GitHub deploy key or a fine-scoped personal access token.

## 3. Deploy API

```bash
cd /var/www/insightistic
composer create-project laravel/laravel api
cd api
composer require laravel/sanctum barryvdh/laravel-dompdf stripe/stripe-php

# Merge the API overlay from this repo into the Laravel app.
rsync -a --delete ../repo/insightistic-api/app/ app/
rsync -a --delete ../repo/insightistic-api/bootstrap/ bootstrap/
rsync -a --delete ../repo/insightistic-api/config/ config/
rsync -a --delete ../repo/insightistic-api/database/ database/
rsync -a --delete ../repo/insightistic-api/resources/ resources/
rsync -a --delete ../repo/insightistic-api/routes/ routes/
```

Create `/var/www/insightistic/api/.env` from Laravel's `.env.example` and set:

```env
APP_NAME=Insightistic
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.insightistic.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=insightistic
DB_USERNAME=insightistic
DB_PASSWORD=CHANGE_ME

INSIGHTISTIC_APP_URL=https://app.insightistic.com
INSIGHTISTIC_AI_PROVIDER=openai
INSIGHTISTIC_AI_MODEL=gpt-4o-mini
OPENAI_API_KEY=

MAIL_MAILER=smtp
MAIL_FROM_ADDRESS=reports@insightistic.com
MAIL_FROM_NAME=Insightistic

STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

Then finish Laravel setup:

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\PlanSeeder --force
php artisan storage:link
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

Add cron:

```bash
* * * * * cd /var/www/insightistic/api && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor queue worker:

```ini
[program:insightistic-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/insightistic/api/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/insightistic/api/storage/logs/worker.log
stopwaitsecs=3600
```

Save it as `/etc/supervisor/conf.d/insightistic-worker.conf`, then run:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start insightistic-worker:*
```

## 4. Deploy Next.js App

```bash
cd /var/www/insightistic/repo/insightistic-app
cp .env.local.example .env.local
sed -i 's#NEXT_PUBLIC_API_URL=.*#NEXT_PUBLIC_API_URL=https://api.insightistic.com#' .env.local
npm ci
npm run build
pm2 start npm --name insightistic-app -- start
pm2 save
pm2 startup
```

## 5. Nginx

API vhost:

```nginx
server {
    listen 80;
    server_name api.insightistic.com;
    root /var/www/insightistic/api/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

App vhost:

```nginx
server {
    listen 80;
    server_name app.insightistic.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }
}
```

Enable and test:

```bash
sudo ln -s /etc/nginx/sites-available/insightistic-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/insightistic-app /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

Use Hostinger SSL or Certbot after DNS points to the VPS.

## 6. Update Deployment

```bash
cd /var/www/insightistic/repo
git pull

rsync -a --delete insightistic-api/app/ ../api/app/
rsync -a --delete insightistic-api/bootstrap/ ../api/bootstrap/
rsync -a --delete insightistic-api/config/ ../api/config/
rsync -a --delete insightistic-api/database/ ../api/database/
rsync -a --delete insightistic-api/resources/ ../api/resources/
rsync -a --delete insightistic-api/routes/ ../api/routes/

cd ../api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
sudo supervisorctl restart insightistic-worker:*

cd ../repo/insightistic-app
npm ci
npm run build
pm2 restart insightistic-app
```

## 7. WordPress Connector

Zip `insightistic-connector/` and upload it from WordPress admin. In the Insightistic dashboard, create a site, copy the one-time connector token, then paste the API URL and token into the plugin.
