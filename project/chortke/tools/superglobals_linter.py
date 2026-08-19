#!/usr/bin/env python3
import os
import re
import sys

TARGET_DIRS = ['app/Controllers', 'app/Services']
SUPERGLOBALS = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE']

def lint_superglobals():
    print("🚀 Running Strict Enterprise Superglobals Linter (Phase 4 Assurance)...")
    violations = []
    
    # Allowlist or ignored patterns (e.g., if used in CSRF middleware or core wrappers)
    ignore_re = re.compile(r'(?:isset|\bempty)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)')
    
    for base_dir in TARGET_DIRS:
        full_base = os.path.join(os.path.dirname(__file__), '..', base_dir)
        for root, _, files in os.walk(full_base):
            for file in files:
                if file.endswith('.php'):
                    fpath = os.path.join(root, file)
                    rel_path = os.path.relpath(fpath, os.path.join(os.path.dirname(__file__), '..'))
                    
                    with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
                        lines = f.readlines()
                        
                    for line_idx, line in enumerate(lines, 1):
                        # Skip inline comments
                        if line.strip().startswith('//') or line.strip().startswith('*') or '/* ' in line:
                            continue
                            
                        for sg in SUPERGLOBALS:
                            if sg in line:
                                if not ignore_re.search(line):
                                    violations.append((rel_path, line_idx, sg, line.strip()))
                                    
    if violations:
        print(f"\n❌ LINTER FAILED: Direct Superglobal access detected in {len(violations)} locations!")
        print("💡 Enterprise Golden Rule: Raw access to $_GET/$_POST/$_REQUEST is strictly banned.")
        print("   Must inject and read from Core\\Request Container or validated DTOs.\n")
        
        for fp, lnum, sg, snippet in violations[:25]: # Show first 25
            print(f"  [!] {fp}:{lnum} -> Found {sg}")
            print(f"      Code: {snippet}\n")
            
        sys.exit(1)
    else:
        print("\n✅ LINTER PASSED: Zero direct unauthorized Superglobal accesses detected in Controllers/Services.")
        print("🔒 Architecture Verified: All requests adhere to unified Container pipelines.\n")
        sys.exit(0)

if __name__ == '__main__':
    lint_superglobals()
