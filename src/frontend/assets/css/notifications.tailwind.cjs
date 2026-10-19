// Rebuild from the repository root (PowerShell):
// '@tailwind utilities;' | npx --yes tailwindcss@3.4.19 -c src/frontend/assets/css/notifications.tailwind.cjs -i - -o src/frontend/assets/css/notifications-utilities.css --minify
module.exports = {
    content: [require('node:path').join(__dirname, '../../components/notification/notifications.php')],
    important: '.notifications-page',
    corePlugins: { preflight: false },
};
