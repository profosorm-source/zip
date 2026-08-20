<?php
$db = new PDO('sqlite:/workspace/runtime/storage/chortke.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS system_settings (`key` TEXT PRIMARY KEY, value TEXT, type TEXT DEFAULT 'string', created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS risk_policies (id INTEGER PRIMARY KEY AUTOINCREMENT, domain TEXT NOT NULL, key_name TEXT NOT NULL, value TEXT, value_type TEXT NOT NULL DEFAULT 'string', description TEXT, updated_by INTEGER, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(domain, key_name))");
$db->exec("CREATE TABLE IF NOT EXISTS banner_placements (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, name TEXT, slug TEXT UNIQUE NOT NULL, page TEXT DEFAULT 'all', position TEXT, description TEXT, is_active INTEGER DEFAULT 1, show_on_mobile INTEGER DEFAULT 1, show_on_desktop INTEGER DEFAULT 1, max_banners INTEGER DEFAULT 5, rotation_speed INTEGER DEFAULT 5, display_style TEXT DEFAULT 'slider', auto_rotate INTEGER DEFAULT 1, max_width INTEGER, max_height INTEGER)");
$db->exec("INSERT OR REPLACE INTO system_settings (`key`,value,type) VALUES ('site_timezone','Asia/Tehran','string'),('site_name','چرتکه','string'),('site_description','پلتفرم کسب درآمد آنلاین','string'),('maintenance_mode','0','boolean')");
echo "sqlite-ready\n";
