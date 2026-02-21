# Test Checklist — Easy Builders Merchant Pro

Run these tests after deploying. Tick each one off as you complete it.

---

[ ] **1. BASIC LOGIN**
   - Go to the app URL: `https://shanemcgee.biz/ebmpro/`
   - Login with: username `admin`, password `Easy2026!`
   - ✓ You should see the New Invoice screen

[ ] **2. STORE SWITCH**
   - Click the store selector at the top (shows "Falcarragh")
   - Switch to "Gweedore"
   - ✓ The store name in the header changes to Gweedore
   - Switch back to Falcarragh
   - ✓ Returns to Falcarragh

[ ] **3. NEW INVOICE OFFLINE**
   - Turn off WiFi or unplug the network cable
   - ✓ The indicator at top should change from 🟢 to 🔴
   - Create a new invoice: search for a customer, add some products, click Save
   - Close the browser window completely
   - Reopen the browser and go to the app URL
   - ✓ The invoice is still there (stored locally)

[ ] **4. SYNC ON RECONNECT**
   - With the invoice saved offline (from Test 3), turn the internet back on
   - ✓ The indicator changes from 🔴 to 🟢
   - ✓ A "Syncing..." message briefly appears
   - Log into the app on a different device/browser
   - ✓ The invoice created offline now appears on the other device

[ ] **5. PRODUCT SEARCH**
   - On the New Invoice screen, click the Product Search box
   - Type the first 3 letters of any product code (e.g. "TIM" for Timber)
   - ✓ Results appear instantly (within 1 second) showing code, description, price, VAT%
   - Click a result to add it to the invoice
   - ✓ Item appears in the invoice table

[ ] **6. CROSS-STORE INVOICE**
   - Set store to **Gweedore** (top selector)
   - Create and save an invoice
   - ✓ Invoice number starts with GWE- (e.g. GWE-1001)
   - Set store back to **Falcarragh**
   - Go to Invoice List
   - ✓ You can see invoices from both stores (or filter by store)

[ ] **7. EMAIL INVOICE**
   - Open a saved invoice
   - Click the **Email** button
   - Enter your own email address as a test
   - Click Send
   - ✓ You receive the email within a few minutes
   - ✓ Email contains the invoice as formatted HTML with company details

[ ] **8. EMAIL OPEN TRACKING**
   - Open the test email from Test 7
   - Wait 30 seconds
   - Go back to the app → Invoice List → open that invoice
   - ✓ Status shows "Opened" with a timestamp

[ ] **9. PART PAYMENT**
   - Open any invoice with a balance
   - Click **Add Payment**
   - Enter an amount that is LESS than the full balance
   - Click Save Payment
   - ✓ The invoice status changes to "Part Paid"
   - ✓ The balance shown updates correctly (original total minus payment)
   - Add another payment for the remaining balance
   - ✓ Status changes to "Paid", balance shows €0.00

[ ] **10. EDIT + AUDIT TRAIL**
   - Open a saved invoice
   - Change the price of one of the items
   - Save the invoice
   - Go to Settings → Audit Log (admin only)
   - ✓ You can see an entry showing: who made the change, what was changed, when, which store

[ ] **11. BACKUP**
   - Go to Settings → Backup
   - Click **Download Backup**
   - ✓ A ZIP file downloads to your computer
   - Open the ZIP file
   - ✓ It contains a .sql file and a .json file with your data

[ ] **12. INSTALL AS DESKTOP APP (Windows)**
   - Open Google Chrome or Microsoft Edge
   - Go to `https://shanemcgee.biz/ebmpro/`
   - Look for the ⊕ icon in the address bar (right side)
   - Click it → Click **Install**
   - ✓ The app opens in its own window (no browser tabs/address bar)
   - ✓ A shortcut appears on your Desktop and/or Start Menu
   - Close and reopen the desktop shortcut
   - ✓ App loads and works normally

---

## If any test fails:
1. Check the browser console for errors (press F12 → Console tab)
2. Check `/ebmpro_api/` is accessible
3. Verify database connection in Settings
4. See DEPLOY.md Troubleshooting section