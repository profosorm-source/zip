#!/usr/bin/env python3
import os
import re

ASSET_MAP = {
    # JS
    'admin-panel.js': 'admin.js',
    'user-panel.js': 'user.js',
    'user-sidebar.js': 'sidebar.js',
    'swal-init.js': 'swal.js',
    'chortke-loader.js': 'loader.js',
    'security-utils.js': 'security.js',
    'advanced-fingerprint.js': 'fingerprint.js',
    'ads-wizard.js': 'wizard.js',
    'banner-carousel.js': 'carousel.js',
    'behavioral-captcha.js': 'captcha.js',
    'youtube-task.js': 'youtube.js',
    'seo-scroll.js': 'scroll.js',
    'seo-tracker.js': 'tracker.js',
    
    # CSS
    'ads-wizard.css': 'wizard.css',
    'user-social-tasks.css': 'tasks.css'
}

def rearchitect_asset_names():
    print("🚀 Re-architecting Asset Filenames to Single-Word Token Standard and Updating Views...\n")
    
    # 1. Rename actual files
    js_dir = '/home/user/zip/chortke/public/assets/js'
    css_dir = '/home/user/zip/chortke/public/assets/css'
    
    for old_name, new_name in ASSET_MAP.items():
        base = js_dir if old_name.endswith('.js') else css_dir
        old_path = os.path.join(base, old_name)
        new_path = os.path.join(base, new_name)
        
        if os.path.exists(old_path):
            os.rename(old_path, new_path)
            print(f"  [Renamed Asset] {old_name} -> {new_name}")
            
    # 2. Update all references in views
    views_dir = '/home/user/zip/chortke/views'
    updated_files = 0
    
    for r, _, fs in os.walk(views_dir):
        for f in fs:
            if f.endswith('.php'):
                fpath = os.path.join(r, f)
                with open(fpath, 'r', encoding='utf-8', errors='ignore') as file:
                    content = file.read()
                    
                new_content = content
                for old_name, new_name in ASSET_MAP.items():
                    new_content = new_content.replace(old_name, new_name)
                    
                if new_content != content:
                    updated_files += 1
                    with open(fpath, 'w', encoding='utf-8') as file:
                        file.write(new_content)
                        
    print(f"\n✅ Fully updated asset references across {updated_files} view files.")

if __name__ == '__main__':
    rearchitect_asset_names()
