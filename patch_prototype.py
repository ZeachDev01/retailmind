import re

with open('src/frontend/components/report/forecast_dashboard_prototype.php', 'r') as f:
    content = f.read()

# Remove the switcher nav block
content = re.sub(r'<nav class="fp-switcher.*?<\/nav>', '', content, flags=re.DOTALL)
# Remove the JS block that manages variants
content = re.sub(r'<script>.*?\(\);.*?<\/script>', '', content, flags=re.DOTALL)

with open('src/frontend/components/report/forecast_dashboard_prototype.php', 'w') as f:
    f.write(content)
