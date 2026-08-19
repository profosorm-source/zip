#!/usr/bin/env python3
import re
from pathlib import Path

path = Path('views/user/dashboard.php')
text = path.read_text(encoding='utf-8')

# Find the script block starting with the chart.js script tag and ending with </script>
old = re.search(
    r'<script<\?=\s*\$cspNonceAttr\s*\?>\s*src="<\?=\s*asset\(\'assets/vendor/chartjs/chart\.umd\.min\.js\'\)\s*\?>">.*?</script>\s*\n\s*<\?php\s*\$content\s*=\s*ob_get_clean\(\);',
    text,
    re.DOTALL | re.IGNORECASE,
)
if not old:
    print('Script block not found')
    exit(1)

new = '''<script type="application/json" id="user-dashboard-data"><?= json_encode($viewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= asset('assets/js/views/user/dashboard.js') ?>"></script>

<?php $content = ob_get_clean();'''

text = text[:old.start()] + new + text[old.end():]
path.write_text(text, encoding='utf-8')
print('Replaced script block')
