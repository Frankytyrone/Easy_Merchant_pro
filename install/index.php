<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Easy Builders Merchant Pro — Installer</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 0 20px; }
        label { display: block; margin-top: 14px; font-weight: bold; }
        input[type=text] { width: 100%; padding: 8px; box-sizing: border-box; }
        button { margin-top: 24px; padding: 10px 24px; background: #1a3a5c; color: #fff; border: none; cursor: pointer; border-radius: 4px; }
        button:hover { background: #0f2540; }
        .section { margin-top: 28px; border-top: 1px solid #ccc; padding-top: 16px; }
    </style>
</head>
<body>
    <h1>Easy Builders Merchant Pro — Installer</h1>
    <form method="post" action="install.php">

        <div class="section">
            <h2>Site Settings</h2>

            <label for="site_url">Site URL</label>
            <input type="text" id="site_url" name="site_url" value="https://shanemcgee.biz">

            <label for="app_path">App Path</label>
            <input type="text" id="app_path" name="app_path" value="/ebmpro/">

            <label for="api_path">API Path</label>
            <input type="text" id="api_path" name="api_path" value="/ebmpro_api/">
        </div>

        <div class="section">
            <h2>Shop Names</h2>

            <label for="shop1_name">Shop 1 Name</label>
            <input type="text" id="shop1_name" name="shop1_name" value="Easy Builders Merchant — Falcarragh">

            <label for="shop2_name">Shop 2 Name</label>
            <input type="text" id="shop2_name" name="shop2_name" value="Easy Builders Merchant — Gweedore">
        </div>

        <div class="section">
            <h2>Invoice Prefixes</h2>

            <label for="prefix_falcarragh">Invoice Prefix — Falcarragh</label>
            <input type="text" id="prefix_falcarragh" name="prefix_falcarragh" value="FAL">

            <label for="prefix_gweedore">Invoice Prefix — Gweedore</label>
            <input type="text" id="prefix_gweedore" name="prefix_gweedore" value="GWE">
        </div>

        <button type="submit">Run Installer</button>
    </form>
</body>
</html>
