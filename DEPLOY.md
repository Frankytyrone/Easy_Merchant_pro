# Deployment Guide

## Prerequisites
- FastComet cPanel hosting account
- PHP 8.0+
- MySQL 5.7+

## Steps

### 1. Upload Files
Upload the repository contents to `public_html/` on your FastComet server.

### 2. Run the Installer
Visit the installer URL to configure the database and write `ebmpro_api/config.php`:

```
https://shanemcgee.biz/install/
```

Fill in your database credentials and click **Run Installer**.

### 3. Access the App
Once installation is complete, open the app at:

```
https://shanemcgee.biz/ebmpro/
```

Default login: `admin` / `Easy2026!`

### 4. Set Up Cron Job
In cPanel Cron Jobs, add the following command (replace `USERNAME` with your cPanel username):

```
php /home/USERNAME/public_html/cron/send_reminders.php
```

Recommended schedule: daily at 08:00.

## URLs Summary

| Resource         | URL                                      |
|------------------|------------------------------------------|
| App              | https://shanemcgee.biz/ebmpro/           |
| API              | https://shanemcgee.biz/ebmpro_api/       |
| Installer        | https://shanemcgee.biz/install/          |
| Tracking pixel   | https://shanemcgee.biz/track/open.php    |
