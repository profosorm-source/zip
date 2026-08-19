#!/usr/bin/env python3
import os
import re

VIEWS_DIR = 'views'

def scan_all_views():
    print("🔍 Running Universal Semantic & Security Scanner across ALL View files...\n")
    
    view_files = []
    for root, _, files in os.walk(os.path.join(os.path.dirname(__file__), '..', VIEWS_DIR)):
        for f in files:
            if f.endswith('.php'):
                view_files.append(os.path.join(root, f))
                
    print(f"📁 Total View files located: {len(view_files)}")
    
    # Categories of Vulnerabilities / Bugs
    direct_db = []
    missing_csrf = []
    unescaped_vars = []
    direct_superglobals = []
    
    form_re = re.compile(r'<form\s+([^>]*?)>(.*?)</form>', re.DOTALL | re.IGNORECASE)
    
    # Catch <?= $var ?> or <?php echo $var; ?> where $var is not wrapped in safe helpers
    # Allowlist safe helpers: e, number_format, intval, json_encode, url, asset, csrf_token, method_field, setting, time, date, number, count, mb_, strtotime, nl2br, to_jalali
    raw_echo_re = re.compile(r'<\?=\s*(\$[a-zA-Z0-9_>\[\]\'"-]+)\s*\?>')
    safe_vars = ['$styles', '$scripts', '$content', '$i', '$page', '$currentPage', '$totalPages', '$sidebarBadges']
    
    sg_re = re.compile(r'\$_(?:GET|POST|REQUEST)\[')
    
    for fpath in sorted(view_files):
        rel_path = os.path.relpath(fpath, os.path.join(os.path.dirname(__file__), '..'))
        
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            
        # Check DB
        if 'db()->' in content or 'Database::getInstance()' in content:
            direct_db.append(rel_path)
            
        # Check Forms for CSRF
        for form_attrs, form_body in form_re.findall(content):
            if 'method="get"' not in form_attrs.lower() and "method='get'" not in form_attrs.lower():
                if '_csrf_token' not in form_body and '_csrf_token' not in form_attrs and 'csrf_field()' not in form_body and 'csrf_token()' not in form_body:
                    missing_csrf.append(rel_path)
                    
        # Check Superglobals
        if sg_re.search(content):
            direct_superglobals.append(rel_path)
            
        # Check Unescaped Variables
        for var in raw_echo_re.findall(content):
            if var.strip() not in safe_vars and not var.strip().startswith('$nc['):
                unescaped_vars.append((rel_path, var.strip()))

    print("\n--- Summary of Universal View Audit ---")
    print(f"🔴 Direct Database queries in views: {len(set(direct_db))} files")
    for fp in sorted(set(direct_db)):
        print(f"  - {fp}")
        
    print(f"\n🟠 Forms potentially missing CSRF Token inside UI: {len(set(missing_csrf))} files")
    for fp in sorted(set(missing_csrf))[:20]: # show first 20
        print(f"  - {fp}")
        
    print(f"\n🟡 Direct Superglobal access in views ($_GET/$_POST): {len(set(direct_superglobals))} files")
    for fp in sorted(set(direct_superglobals))[:20]:
        print(f"  - {fp}")
        
    print(f"\n🔵 Raw / Unescaped dynamic variables printed in views (XSS vulnerability): {len(set([x[0] for x in unescaped_vars]))} files")
    for fp, var in unescaped_vars[:30]:
        print(f"  - {fp}: {var}")

if __name__ == '__main__':
    scan_all_views()
