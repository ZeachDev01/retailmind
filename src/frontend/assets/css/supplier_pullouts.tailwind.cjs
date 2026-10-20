// Rebuild from the repository root (PowerShell):
// '@tailwind utilities;' | npx --yes tailwindcss@3.4.19 -c src/frontend/assets/css/supplier_pullouts.tailwind.cjs -i - -o src/frontend/assets/css/supplier_pullouts.css --minify
module.exports = {
    content: [require('node:path').join(__dirname, '../../components/inventory_management/supplier_pullouts.php')],
    important: '#supplier-pullouts',
    corePlugins: { preflight: false },
};
