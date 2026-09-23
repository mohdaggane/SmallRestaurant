<?php
require_once __DIR__ . '/config.php';

$conn->query("CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$defaults = [
    'company_name'  => 'SAHAN ICT',
    'system_name'   => 'Restaurant POS',
    'contact_phone' => '+252 61 5000000',
    'contact_email' => 'info@sahanict.org',
    'web_name'      => 'sahanict.org',
    'web_url'       => 'https://sahanict.org',
    'logo_url'      => ''
];

foreach ($defaults as $k => $v) {
    db_run("INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES (?, ?)", [$k, $v]);
}

if (!is_dir(__DIR__ . '/../uploads')) {
    mkdir(__DIR__ . '/../uploads', 0755, true);
}

echo "MIGRATION_SUCCESS\n";
print_r(db_all("SELECT * FROM platform_settings"));
