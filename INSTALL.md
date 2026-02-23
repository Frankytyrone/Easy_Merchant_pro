# Easy Builders Merchant Pro — Installation Guide

## Option A: Local Testing on XAMPP (your PC)

1. Download XAMPP from [apachefriends.org](https://www.apachefriends.org)
2. Copy `ebmpro/` folder to `C:\xampp\htdocs\ebmpro\`
3. Copy `ebmpro_api/` folder to `C:\xampp\htdocs\ebmpro_api\`
4. Open phpMyAdmin at `http://localhost/phpmyadmin`
5. Create database called `ebmpro_local`
6. Import `install/schema.sql`
7. Import `database/seed_dummy_data.sql` for test data
8. Open `http://localhost/ebmpro/` in browser
9. Login: username `admin` password `admin123`

---

## Option B: Live Deployment on FastComet (app.shanemcgee.biz)

> **Important:** The existing website at `https://shanemcgee.biz/` must NOT be touched.
> The app lives on the subdomain `app.shanemcgee.biz` only.

### Step 1 — Create the subdomain

- Log into FastComet cPanel at your hosting URL
- Click **Subdomains** (under Domains section)
- Subdomain field: type `app`
- Domain: select `shanemcgee.biz`
- Document Root: will auto-fill as `public_html/app` — leave it as is
- Click **Create**
- Wait 5 minutes for DNS to propagate

### Step 2 — Enable free SSL

- In cPanel click **Let's Encrypt SSL** (or AutoSSL)
- Find `app.shanemcgee.biz` in the list
- Click **Issue** or **Run AutoSSL**
- Done — your app will be on HTTPS ✅

### Step 3 — Upload the files

- In cPanel click **File Manager**
- Navigate to `public_html/app/`
- Click **Upload** button
- Upload the entire `ebmpro/` folder contents INTO `public_html/app/`
  (so `public_html/app/index.html` exists)
- Go back to `public_html/`
- Create a new folder called `ebmpro_api`
- Upload the entire `ebmpro_api/` folder contents INTO `public_html/ebmpro_api/`

The folder structure on the server should be:

```
public_html/
├── app/              ← ebmpro frontend files go here
│   ├── index.html
│   ├── css/
│   ├── js/
│   └── ...
├── ebmpro_api/       ← API files go here
│   ├── common.php
│   ├── auth.php
│   └── ...
└── (your existing website files — untouched!)
```

### Step 4 — Create the database

- In cPanel click **MySQL Databases**
- Under "Create New Database" type: `ebmpro` → click **Create Database**
- Under "Create New User" type a username (e.g. `ebmpro_user`) and a strong password → click **Create User**
- Under "Add User to Database" select your new user and database → click **Add** → tick **All Privileges** → click **Make Changes**
- Write down: database name, username, password — you need them next!

> **Note:** FastComet prefixes database names and usernames with your cPanel username,
> e.g. `youraccount_ebmpro` and `youraccount_ebmpro_user`.
> Use the FULL name including prefix.

### Step 5 — Import the database

- In cPanel click **phpMyAdmin**
- Click your new database in the left panel
- Click **Import** tab at the top
- Click **Choose File** → select `install/schema.sql` from your PC
- Click **Go**
- You should see "Import has been successfully finished"
- Optional: repeat with `database/seed_dummy_data.sql` to load test data

### Step 6 — Update the config file

- In File Manager go to `public_html/ebmpro_api/`
- Find `config.php` and click **Edit**
- Change these lines to match YOUR database details from Step 4:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'youraccount_ebmpro');      // ← your full database name
define('DB_USER', 'youraccount_ebmpro_user'); // ← your full username
define('DB_PASS', 'yourpassword');            // ← your password
define('DB_CHARSET', 'utf8mb4');
```

- Also update the app URL:

```php
define('APP_ENV', 'production');
define('APP_URL', 'https://app.shanemcgee.biz');
define('API_URL', 'https://app.shanemcgee.biz/ebmpro_api');
```

- Click **Save**

### Step 7 — Update the frontend API path

- In File Manager go to `public_html/app/js/`
- Find `app.js` and click **Edit**
- The API calls already use the relative path `/ebmpro_api/` — no changes needed ✅
- This relative path works on both localhost AND the live server automatically

### Step 8 — Set file permissions

- In File Manager, right-click the `ebmpro_api/` folder → **Change Permissions**
- Set to `755`
- Right-click `ebmpro_api/config.php` → **Change Permissions** → set to `644`

### Step 9 — Test it!

- Open `https://app.shanemcgee.biz` in your browser
- You should see the EBM Pro login screen
- Login with: username `admin` password `admin123`
- **Change the admin password immediately after first login!**

### Step 10 — Set up automatic backups (important!)

- In cPanel click **Cron Jobs**
- Add a new cron job:
  - Minute: `0`
  - Hour: `2`
  - Day/Month/Weekday: `*`
  - Command: `php /home/youraccount/public_html/ebmpro_api/backup.php`
- This runs a backup every night at 2am automatically ✅

---

## PHP Settings for Large Imports (13,000+ products)

The `.htaccess` files already handle this automatically on FastComet.

If you still get timeout errors during import, do this in cPanel:

- Click **Select PHP Version** (or MultiPHP Manager)
- Click **PHP Options** tab
- Set these values:
  - `max_execution_time` = 300
  - `max_input_time` = 300
  - `memory_limit` = 512M
  - `upload_max_filesize` = 128M
  - `post_max_size` = 128M
- Click **Save**

For XAMPP on your PC, open `C:\xampp\php\php.ini` in Notepad and change the same values, then restart Apache.

---

## Updating the System

When new features or fixes are added to GitHub:

**On XAMPP:**
1. Download the latest ZIP from GitHub
2. Extract and copy files to `C:\xampp\htdocs\`
3. Refresh browser with Ctrl+Shift+R

**On FastComet:**
1. Download the latest ZIP from GitHub
2. In cPanel File Manager, upload and overwrite the changed files
3. Never overwrite `config.php` — that has your database details!

---

## Troubleshooting common problems

| Problem | Solution |
|---|---|
| White screen / blank page | Check `ebmpro_api/config.php` database details |
| "Service unavailable" error | Database connection failed — check DB name/user/pass |
| Can't log in | Run the password reset SQL in phpMyAdmin |
| Products not saving | Check browser F12 console for red errors |
| Import times out | Increase PHP limits in cPanel PHP Options |
| SSL not working | Wait 24 hours or click Run AutoSSL again in cPanel |
| Existing website broken | Check you only uploaded to `public_html/app/` and `public_html/ebmpro_api/` |

### Password Reset (if locked out)

Run this in phpMyAdmin:

```sql
UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE username = 'admin';
```

This resets admin password to `password` — change it immediately after!
