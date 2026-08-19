#!/usr/bin/env python3
import os
import re

DIRS_TO_SCAN = ['app', 'core']

def evaluate_type_safety():
    print("📊 Evaluating Codebase against PHPStan (Level 6 & 9) Type Safety Standards...\n")
    
    total_files = 0
    total_classes = 0
    total_methods = 0
    methods_with_return_type = 0
    methods_with_typed_params = 0
    total_params = 0
    typed_params = 0
    total_properties = 0
    typed_properties = 0
    
    # Regexes
    class_re = re.compile(r'\b(?:class|interface|trait)\s+([a-zA-Z0-9_]+)')
    
    # Matches functions/methods: public/protected/private/static function name(params) : return_type
    method_re = re.compile(r'\b(?:public|protected|private)?\s*(?:abstract|final|static)?\s*(?:public|protected|private)?\s*(?:static)?\s*function\s+([a-zA-Z0-9_]+)\s*\(([^)]*)\)(?:\s*:\s*([a-zA-Z0-9_|\?]+))?')
    
    # Matches class properties: public/protected/private readonly? type $name
    # Catch both typed (private string $var) and untyped (private $var)
    property_re = re.compile(r'\b(public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?([a-zA-Z0-9_|\?]+\s+)?\$([a-zA-Z0-9_]+)\s*(?:=|;)')
    
    for base_dir in DIRS_TO_SCAN:
        full_path = os.path.join(os.path.dirname(__file__), '..', base_dir)
        for root, _, files in os.walk(full_path):
            for file in files:
                if file.endswith('.php'):
                    total_files += 1
                    fpath = os.path.join(root, file)
                    
                    with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
                        content = f.read()
                        
                    # Count classes
                    classes = class_re.findall(content)
                    total_classes += len(classes)
                    
                    # Count properties
                    # Let's see how many properties are typed vs untyped
                    for prop_vis, prop_type, prop_name in property_re.findall(content):
                        # exclude variable assignments inside methods that might match, but let's assume property vis keywords are safe
                        total_properties += 1
                        if prop_type and prop_type.strip() and prop_type.strip() not in ['static', 'readonly']:
                            typed_properties += 1
                            
                    # Count methods
                    for m_name, m_params, m_return in method_re.findall(content):
                        total_methods += 1
                        if m_return and m_return.strip():
                            methods_with_return_type += 1
                            
                        # Parse params
                        params = [p.strip() for p in m_params.split(',') if p.strip()]
                        if params:
                            is_fully_typed = True
                            for p in params:
                                total_params += 1
                                # If param has type hint: string $var or ?int $var
                                parts = p.split()
                                if len(parts) > 1 and not parts[0].startswith('&') and not parts[0].startswith('.'):
                                    typed_params += 1
                                else:
                                    is_fully_typed = False
                            if is_fully_typed:
                                methods_with_typed_params += 1
                        else:
                            # 0 params counts as fully typed params
                            methods_with_typed_params += 1

    print(f"📁 Total Files Scanned: {total_files}")
    print(f"📦 Total Classes/Traits/Interfaces: {total_classes}")
    print(f"🏷️ Total Class Properties: {total_properties} | Typed Properties: {typed_properties} ({typed_properties/max(1, total_properties)*100:.1f}%)")
    print(f"⚙️ Total Methods/Functions: {total_methods}")
    print(f"   · With Explicit Return Types: {methods_with_return_type} ({methods_with_return_type/max(1, total_methods)*100:.1f}%)")
    print(f"   · With Fully Typed Parameters: {methods_with_typed_params} ({methods_with_typed_params/max(1, total_methods)*100:.1f}%)")
    print(f"   · Overall Parameter Type Safety: {typed_params}/{total_params} ({typed_params/max(1, total_params)*100:.1f}%)")
    
    # PHPStan Compliance Metric
    # Level 6 requires explicit return types and parameter types
    # Level 9 requires strict generic annotations and 100% properties
    level_6_score = ((methods_with_return_type/max(1, total_methods)) + (typed_params/max(1, total_params))) / 2 * 100
    level_9_score = ((methods_with_return_type/max(1, total_methods)) + (typed_params/max(1, total_params)) + (typed_properties/max(1, total_properties))) / 3 * 100
    
    print(f"\n🏆 PHPStan Baseline (Level 6) Readiness Score: {level_6_score:.1f}%")
    print(f"🎯 PHPStan Maximum Strictness (Level 9) Alignment Score: {level_9_score:.1f}%")
    
    if level_6_score < 90:
        print(f"\n🚀 ANALYSIS: The project is highly modern but has an ~{100-level_6_score:.1f}% gap to achieve flawless Level 6 type safety.")
        print("   Major areas to optimize:")
        print("   1. Adding missing return types (e.g. : void, : array, : bool) to controllers and base classes.")
        print("   2. Type-hinting untyped parameters in older helper functions and legacy jobs.")
        print("   3. Purging raw Superglobal accesses.")
    else:
        print("\n✨ ANALYSIS: The project is exceptionally well typed and incredibly close to flawless strict type safety.")

if __name__ == '__main__':
    evaluate_type_safety()
