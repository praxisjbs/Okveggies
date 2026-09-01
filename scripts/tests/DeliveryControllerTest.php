<?php
$deliveryController=file_get_contents(dirname(__DIR__,2).'/api/v1/delivery.php');
okv_test_ok(str_contains($deliveryController,'delivery_success('),'delivery admin writes share the progressive response helper');
okv_test_ok(str_contains($deliveryController,"okv_redirect('/admin/delivery.php?delivery=updated',303)"),'plain delivery forms return to the admin screen');
