import re
content = open('src/frontend/components/inventory_management/replenishment_requests.php', 'r').read()
new_content = re.sub(r'<\?php if \(\!empty\(\\\)\): \?>.*?<\?php endif; \?>', '', content, flags=re.DOTALL)
open('src/frontend/components/inventory_management/replenishment_requests.php', 'w').write(new_content)
