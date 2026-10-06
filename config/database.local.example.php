<?php
/**
 * Copy to config/database.local.php and fill in the credentials for this machine.
 *
 * Recommended: a dedicated account limited to this schema, created as root once:
 *
 *   CREATE USER 'disinfentry'@'localhost'  IDENTIFIED BY '<password>';
 *   CREATE USER 'disinfentry'@'127.0.0.1'  IDENTIFIED BY '<password>';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON `disinfentry`.* TO 'disinfentry'@'localhost';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON `disinfentry`.* TO 'disinfentry'@'127.0.0.1';
 *   FLUSH PRIVILEGES;
 *
 * For a throwaway local XAMPP only, you may instead set 'user' => 'root', 'pass' => ''.
 * That is an explicit choice made here, never a silent fallback.
 */
return [
    'user' => 'disinfentry',
    'pass' => 'change-me',
    // 'host' => '127.0.0.1',
    // 'port' => 3306,
    // 'name' => 'disinfentry',
];
