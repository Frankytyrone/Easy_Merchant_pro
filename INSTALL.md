# Easy Builders Merchant Pro — VPS Installation Guide

## Requirements
- VPS running Ubuntu 22.04 LTS (recommended: Hostinger VPS — KVM 2 plan or above)
- PHP 8.1+ with extensions: pdo_mysql, mbstring, zip, json, curl, xml
- MySQL 8.0+ (or MariaDB 10.6+)
- Apache 2.4+ or Nginx
- At least 2 GB RAM, 20 GB SSD

---

## Step 1 — Sign Up to Hostinger VPS

1. Go to [hostinger.com/vps-hosting](https://www.hostinger.com/vps-hosting)
2. Choose the **KVM 2** plan (4 vCPU, 8 GB RAM, 100 GB NVMe SSD) — handles 130,000+ products comfortably
3. Select **Ubuntu 22.04** as the operating system
4. Choose a datacenter in **Europe** (Frankfurt or Amsterdam for lowest latency to Ireland)
5. Complete checkout and note your VPS IP address and root password from hPanel

---

## Step 2 — Log Into Your VPS

Open hPanel → **VPS** → your server → **Terminal** (browser-based SSH), or use:

```bash
ssh root@YOUR_VPS_IP
```

---

## Step 3 — Create a Subdomain

In hPanel → **Domains** → your domain → **DNS Zone**:

1. Add an **A record**:
   - Name: `ebmpro`
   - Points to: `YOUR_VPS_IP`
   - TTL: 3600

This creates `ebmpro.easybuildersdonegal.ie` (replace with your actual domain).

---

## Step 4 — Run the Setup Script

Upload `setup.sh` to your VPS and run it:

```bash
# Upload via SFTP or paste the contents directly
chmod +x setup.sh
sudo bash setup.sh
```

The script will:
- Install Apache, PHP 8.1, and MySQL
- Install required PHP extensions
- Create the web directory
- Set file permissions
- Create the backups directory
- Output a health check

---

## Step 5 — Upload the Application Files

**Option A: File Manager (easiest)**
1. Log into hPanel → File Manager
2. Navigate to `/var/www/ebmpro/`
3. Upload the project ZIP file
4. Right-click → Extract

**Option B: SFTP**
Use FileZilla or Cyberduck:
- Host: `YOUR_VPS_IP`
- Username: `root`
- Password: your VPS password
- Upload all files to `/var/www/ebmpro/`

**Option C: Git (recommended)**
```bash
cd /var/www
git clone https://github.com/YOUR_REPO/Easy_Merchant_pro.git ebmpro
```

---

## Step 6 — Create the MySQL Database

```bash
mysql -u root -p
```

Run these SQL commands:

```sql
CREATE DATABASE ebmpro_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ebmpro_user'@'localhost' IDENTIFIED BY 'CHOOSE_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON ebmpro_db.* TO 'ebmpro_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## Step 7 — Import the Database Schema

```bash
mysql -u ebmpro_user -p ebmpro_db < /var/www/ebmpro/install/schema.sql
mysql -u ebmpro_user -p ebmpro_db < /var/www/ebmpro/install/schema_additions.sql
```

**Optional: Load test/dummy data**
```bash
mysql -u ebmpro_user -p ebmpro_db < /var/www/ebmpro/database/seed_dummy_data.sql
```

---

## Step 8 — Edit config.php

```bash
nano /var/www/ebmpro/ebmpro_api/config.php
```

Update these lines:
```php
define('SITE_URL',  'https://ebmpro.easybuildersdonegal.ie');
define('DB_NAME',   'ebmpro_db');
define('DB_USER',   'ebmpro_user');
define('DB_PASS',   'YOUR_STRONG_PASSWORD');
```

Save with `Ctrl+O`, exit with `Ctrl+X`.

---

## Step 9 — Configure Apache Virtual Host

```bash
nano /etc/apache2/sites-available/ebmpro.conf
```

Paste this configuration:

```apache
<VirtualHost *:80>
    ServerName ebmpro.easybuildersdonegal.ie
    DocumentRoot /var/www/ebmpro
    DirectoryIndex ebmpro/index.html

    <Directory /var/www/ebmpro>
        AllowOverride All
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>

    ErrorLog  /var/log/apache2/ebmpro_error.log
    CustomLog /var/log/apache2/ebmpro_access.log combined
</VirtualHost>
```

Enable the site:

```bash
a2ensite ebmpro.conf
a2enmod rewrite
systemctl reload apache2
```

---

## Step 10 — Set Up HTTPS (Free SSL via Let's Encrypt)

```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d ebmpro.easybuildersdonegal.ie
```

Follow the prompts. Certbot auto-renews the certificate every 90 days.

---

## Step 11 — Set Up Cron Jobs

```bash
crontab -e
```

Add these lines:

```cron
# Nightly backup at midnight — uses curl with admin token
# Replace TOKEN with a long-lived admin token generated from the auth API
# 0 0 * * * curl -s -H "Authorization: Bearer TOKEN" https://ebmpro.easybuildersdonegal.ie/ebmpro_api/admin.php?action=run_backup > /dev/null 2>&1

# Payment reminders — every hour
0 * * * * php /var/www/ebmpro/cron/send_reminders.php >> /var/log/ebmpro_reminders.log 2>&1
```

> **Note on backup cron:** The backup endpoint requires a valid Bearer token. Generate a long-lived admin token by logging in via the API, then use it in the curl command above. Alternatively, copy the backup script logic into a standalone CLI PHP file that reads the config directly without HTTP auth.

---

## Step 12 — Test the Installation

1. Open `https://ebmpro.easybuildersdonegal.ie/install/`
2. Follow the 5-step installer
3. Log in at `https://ebmpro.easybuildersdonegal.ie/ebmpro/` with:
   - Username: `admin`
   - Password: `Easy2026!`
4. **Change your password immediately** (Settings → Change Password)

---

## Step 13 — Set Up Both Stores

1. Log in as admin
2. Go to **Settings** → Stores
3. Edit **Falcarragh (FAL)** — enter address, phone, VAT number
4. Edit **Gweedore (GWE)** — enter address, phone, VAT number
5. Set up separate operator accounts for each store if needed

---

## Step 14 — Import Your Product Data

1. Export your Access database tables as XML or CSV
2. Log in as admin → **Admin Dashboard** (`/ebmpro/admin.html`)
3. Click the **Import** tab
4. Import in order:
   1. Products (largest file — takes several minutes for 130,000+ records)
   2. Customers
   3. Invoices
   4. Payments
5. Each import shows a progress bar — do not close the tab while running

---

## Stripe Setup (Optional)

1. Create a Stripe account at [stripe.com](https://stripe.com)
2. Go to Developers → API Keys
3. Copy your **Publishable key** and **Secret key**
4. In EBM Pro: Settings → Payment Settings → enter both keys
5. In Stripe Dashboard → Developers → Webhooks → Add endpoint:
   - URL: `https://ebmpro.easybuildersdonegal.ie/ebmpro_api/stripe_webhook.php`
   - Events: `checkout.session.completed`, `payment_link.completed`
6. Copy the webhook signing secret into Settings → Stripe Webhook Secret

---

## File Permissions Reference

```bash
# Web files (readable by Apache)
find /var/www/ebmpro -type f -exec chmod 644 {} \;
find /var/www/ebmpro -type d -exec chmod 755 {} \;

# Writable by PHP
chmod 750 /var/www/ebmpro/backups
chown www-data:www-data /var/www/ebmpro/backups

# Protect config
chmod 640 /var/www/ebmpro/ebmpro_api/config.php
chown www-data:www-data /var/www/ebmpro/ebmpro_api/config.php
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank page / 500 error | Check `tail -50 /var/log/apache2/ebmpro_error.log` |
| Database connection fails | Verify DB_HOST, DB_NAME, DB_USER, DB_PASS in config.php |
| Import times out | Increase `max_execution_time = 300` in `/etc/php/8.1/apache2/php.ini` |
| File upload fails | Increase `upload_max_filesize = 64M` and `post_max_size = 64M` in php.ini |
| CORS errors in browser | Ensure SITE_URL in config.php matches exactly (with https://) |
| SSL certificate fails | Make sure DNS has propagated first — wait 24h after adding A record |

---

## Security Checklist

- [ ] Changed admin password from `Easy2026!`
- [ ] Set strong MySQL password
- [ ] Enabled HTTPS
- [ ] `config.php` has permissions 640 (not world-readable)
- [ ] `/install/` directory returns "Already installed" (not the setup wizard)
- [ ] Backups are running (check `/var/www/ebmpro/backups/`)
- [ ] Keep PHP and MySQL updated: `apt update && apt upgrade`
