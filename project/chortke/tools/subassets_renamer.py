#!/usr/bin/env python3
import os
import re

def rearchitect_subassets():
    print("🚀 Running Sub-Assets Universal One-Word Token Normalizer (Purging all Hyphens and Underscores)...\n")
    
    base_dir = '/home/user/zip/chortke/public/assets'
    renames = []
    
    # Locate all files in public/assets with hyphens or underscores
    for r, _, fs in os.walk(base_dir):
        for f in fs:
            if ('-' in f or '_' in f) and f.endswith(('.js', '.css')):
                fpath = os.path.join(r, f)
                # Remove all hyphens and underscores
                new_f = f.replace('-', '').replace('_', '')
                new_fpath = os.path.join(r, new_f)
                
                # Make sure no collision or overwrite
                if not os.path.exists(new_fpath) or fpath == new_fpath:
                    os.rename(fpath, new_fpath)
                    
                    rel_old = os.path.relpath(fpath, base_dir)
                    rel_new = os.path.relpath(new_fpath, base_dir)
                    renames.append((f, new_f))
                    print(f"  [Normalized Filename] {f} -> {new_f}")

    # Update all references across the entire codebase (views, controllers, services)
    code_dirs = ['/home/user/zip/chortke/views', '/home/user/zip/chortke/app']
    updated_files = 0
    
    # Let's sort renames by longest first to avoid partial replacements
    sorted_renames = sorted(renames, key=lambda x: len(x[0]), reverse=True)
    
    for c_dir in code_dirs:
        for r, _, fs in os.walk(c_dir):
            for f in fs:
                if f.endswith('.php'):
                    fp = os.path.join(r, f)
                    with open(fp, 'r', encoding='utf-8', errors='ignore') as file:
                        content = file.read()
                        
                    new_content = content
                    for old_n, new_n in sorted_renames:
                        new_content = new_content.replace(old_n, new_n)
                        
                    if new_content != content:
                        updated_files += 1
                        with open(fp, 'w', encoding='utf-8') as file:
                            file.write(new_content)
                            
    print(f"\n✅ Successfully updated filename references across {updated_files} code and view files.")

if __name__ == '__main__':
    rearchitect_subassets()
