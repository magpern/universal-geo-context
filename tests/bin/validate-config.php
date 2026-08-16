<?php

/**
 * Validate that the generated wp-tests-config.php contains required $table_prefix variable.
 * Usage: php tests/bin/validate-config.php /path/to/wp-tests-config.php
 *
 * Exit code 0 if valid, 1 if invalid.
 */

$config_file = $argv[1] ?? '';

if (! file_exists($config_file)) {
    fprintf(STDERR, "Error: Config file not found: %s\n", $config_file);
    exit(1);
}

// Check for $table_prefix in the config file source without executing it.
$config_content = file_get_contents($config_file);

if (! preg_match('/\$table_prefix\s*=/', $config_content)) {
    fprintf(STDERR, "Error: \$table_prefix not defined in config file\n");
    exit(1);
}

printf("OK: \$table_prefix is defined in wp-tests-config.php\n");
exit(0);
