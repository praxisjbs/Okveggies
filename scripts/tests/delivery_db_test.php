<?php
// Run after migrations on a scratch MariaDB: php scripts/tests/delivery_db_test.php
$root=dirname(__DIR__,2); require_once $root.'/includes/bootstrap.php';
$zones=Delivery::zonesActive(); if(!$zones){fwrite(STDERR,"No active seeded zones.\n");exit(1);}
$household=Delivery::allowedDaysFor('household'); $business=Delivery::allowedDaysFor('business');
if(count($household)!==4||count($business)!==2){fwrite(STDERR,"Seeded delivery days are wrong.\n");exit(1);}
echo "Seeded zones and delivery days verified.\n";
