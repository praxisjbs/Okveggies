<?php
$basketJs=file_get_contents(dirname(__DIR__,2).'/assets/js/basket.js');
okv_test_ok(str_contains($basketJs,"form.getAttribute('action')"),'basket JavaScript reads the form URL without action-field shadowing');
okv_test_ok(!str_contains($basketJs,'fetch(form.action'),'basket JavaScript never posts to a shadowed form action property');
