# Vendor Directory

The jsPDF and jsPDF-AutoTable libraries are now loaded from CDN in `ebmpro/index.html`:

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" crossorigin="anonymous" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" crossorigin="anonymous" defer></script>
```

The stub `.js` files in this directory are no longer loaded by the application and can be removed.
