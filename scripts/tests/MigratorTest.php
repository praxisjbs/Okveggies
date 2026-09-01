<?php
okv_test_ok(Migrator::isResultStatement('SHOW INDEX FROM orders'), 'migration SHOW statements are treated as result sets');
okv_test_ok(Migrator::isResultStatement("\n -- verify\n SELECT COUNT(*) FROM orders"), 'migration SELECT statements are treated as result sets');
okv_test_ok(!Migrator::isResultStatement('ALTER TABLE orders ADD INDEX idx_test (id)'), 'migration DDL stays on the write path');
