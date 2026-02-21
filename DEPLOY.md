# Deploying Easy Builders Merchant Pro

## What you need
- Your FastComet cPanel login
- Your domain name (e.g. shanemcgee.biz)  
- 20 minutes

## Step 1: Upload Files
1. Log into cPanel at https://yourhostname/cpanel
2. Click **File Manager**
3. Navigate to **public_html**
4. Click **Upload** (top toolbar)
5. Upload the ZIP file containing the Easy Builders Merchant Pro files
6. Once uploaded, right-click the ZIP file → **Extract**
7. All files should now be inside public_html/

## Step 2: Create the Database
1. In cPanel, find and click **MySQL Databases**
2. Under "Create New Database", type: `ebmpro_db` → click **Create Database**
3. Under "MySQL Users", create a new user:
   - Username: `ebmpro_user`
   - Password: Use a strong password (write it down — you'll need it in Step 3)
   - Click **Create User**
4. Under "Add User To Database":
   - Select `ebmpro_user` and `ebmpro_db`
   - Click **Add** → Select **ALL PRIVILEGES** → Click **Make Changes**

## Step 3: Run the Installer
1. Open your web browser
2. Go to: `https://shanemcgee.biz/install/`
3. Follow the 5 steps on screen:
   - **Step 1**: Server checks (should all show green ✓)
   - **Step 2**: Enter database details from Step 2 above
   - **Step 3**: Enter your shop name, address, VAT number
   - **Step 4**: Enter your email/SMTP details (ask your email provider if unsure)
   - **Step 5**: Click "Run Installer" — wait for success message
4. **Write down your login details shown on screen**

## Step 4: Access the App
1. Go to: `https://shanemcgee.biz/ebmpro/`
2. Log in with:
   - Username: **admin**
   - Password: **Easy2026!**
3. ⚠️ **Change your password immediately** in Settings → Profile

## Step 5: Install as Desktop App (Windows)
1. Open Chrome or Microsoft Edge
2. Go to `https://shanemcgee.biz/ebmpro/`
3. Look for the install icon (⊕) in the browser address bar (far right)
4. Click it → Click **Install**
5. The app opens as its own window — like a desktop program!
6. It works even when your internet is off — changes sync automatically when you reconnect

## Step 6: Set Up Automatic Payment Reminders (Optional)
1. In cPanel, find and click **Cron Jobs**
2. Set the schedule to: **Once Per Hour** (Every Hour)
3. In the **Command** box, enter:
   ```
   php /home/YOURUSERNAME/public_html/cron/send_reminders.php
   ```
   *(Replace YOURUSERNAME with your actual cPanel username — visible in File Manager path)*
4. Click **Add New Cron Job**
5. Done! Overdue invoices will automatically get reminder emails

## Troubleshooting

**"Already installed" message when going to /install/**
- The installer ran successfully already. Go to `/ebmpro/` to use the app.
- If you need to reinstall, delete the file `install/install.lock` via File Manager.

**Can't connect to database (Step 2 of installer)**
- Double-check the database name and username — they must include your cPanel prefix (e.g. `cpaneluser_ebmpro_db`)
- Make sure the user was added to the database with ALL PRIVILEGES

**Emails not sending**
- Check SMTP settings in Settings → Email
- Most email providers use port 587 with TLS
- Gmail users: enable "App Passwords" in Google Account → Security

**App is slow loading products**
- This is normal on first load with large product lists
- After first load, products are cached locally

## Security Checklist
- [ ] Changed admin password from Easy2026!
- [ ] Saved a copy of the backup ZIP (Settings → Backup)
- [ ] Set up cron for automatic reminders
- [ ] Checked that /install/ shows "Already installed" (not the wizard)