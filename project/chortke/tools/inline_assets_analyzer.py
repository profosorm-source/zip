#!/usr/bin/env python3
import os
import re

VIEWS_DIR = 'views'

def analyze_inline_assets():
    print("🔬 Running Universal Inline Assets (JS/CSS) Analyzer & Bundling Classifier...\n")
    
    view_files = []
    for root, _, files in os.walk(os.path.join(os.path.dirname(__file__), '..', VIEWS_DIR)):
        for f in files:
            if f.endswith('.php'):
                view_files.append(os.path.join(root, f))
                
    # Regexes
    style_re = re.compile(r'<style[^>]*>(.*?)</style>', re.DOTALL | re.IGNORECASE)
    
    # Matches script tags but let's exclude src= or type="application/json"
    script_re = re.compile(r'<script([^>]*)>(.*?)</script>', re.DOTALL | re.IGNORECASE)
    
    inline_styles = []
    inline_scripts = []
    
    for fpath in sorted(view_files):
        rel_path = os.path.relpath(fpath, os.path.join(os.path.dirname(__file__), '..'))
        
        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            
        # Check styles
        for s_body in style_re.findall(content):
            if s_body.strip():
                inline_styles.append((rel_path, s_body.strip()))
                
        # Check scripts
        for s_attrs, s_body in script_re.findall(content):
            # Exclude script tags that have src= or are JSON data
            if 'src=' not in s_attrs.lower() and 'application/json' not in s_attrs.lower() and s_body.strip():
                # Ignore very simple window.vars assignments or CSRF setup if we want, or let's count them
                inline_scripts.append((rel_path, s_attrs.strip(), s_body.strip()))

    print(f"📁 Total View files verified: {len(view_files)}")
    print(f"🎨 View files with Inline CSS (<style>): {len(set([x[0] for x in inline_styles]))}")
    print(f"⚡ View files with Inline JS (<script>): {len(set([x[0] for x in inline_scripts]))}")
    
    print("\n--- 🎨 Inline CSS Classification ---")
    shared_css_candidates = []
    specific_css_candidates = []
    for fp, body in inline_styles:
        lines_count = len(body.splitlines())
        if lines_count < 15 and ('badge' in body.lower() or 'card' in body.lower() or 'btn' in body.lower() or 'table' in body.lower()):
            shared_css_candidates.append((fp, lines_count))
        else:
            specific_css_candidates.append((fp, lines_count))
            
    print(f"  · Candidates for `chortke-common.css` (General component tweaks, utility classes, shared layout styles): {len(shared_css_candidates)} blocks")
    print(f"  · Candidates for Dedicated specific CSS files (Highly custom charts, specialized dashboards, animations): {len(specific_css_candidates)} blocks")
    
    print("\n--- ⚡ Inline JS Classification ---")
    shared_js_candidates = []
    specific_js_candidates = []
    for fp, attrs, body in inline_scripts:
        lines_count = len(body.splitlines())
        # If it's general UI init like Notyf, SweetAlert, tooltip, modal, generic callbacks
        if 'notyf' in body.lower() or 'swal' in body.lower() or 'bootstrap' in body.lower() or lines_count < 10:
            shared_js_candidates.append((fp, lines_count))
        else:
            specific_js_candidates.append((fp, lines_count))

    print(f"  · Candidates for `chortke-common.js` (Shared Notyf initializers, inline DOM interactive listeners, global modal triggers): {len(shared_js_candidates)} blocks")
    print(f"  · Candidates for Dedicated specific JS files (Complex AJAX multi-step submission, specialized map/chart renders, countdown timers): {len(specific_js_candidates)} blocks")
    
    print("\n[!] Top 10 Files with most complex Inline Scripts to migrate to dedicated files:")
    # sort by length
    sorted_specific_js = sorted(specific_js_candidates, key=lambda x: x[1], reverse=True)
    for fp, lc in sorted_specific_js[:10]:
        print(f"    - {fp}: (~{lc} lines of inline JavaScript)")

if __name__ == '__main__':
    analyze_inline_assets()
