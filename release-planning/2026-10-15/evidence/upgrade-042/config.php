<?php
Setting('DB_DRIVER', 'pgsql');
Setting('DB_HOST', getenv('PGHOST'));
Setting('DB_PORT', intval(getenv('PGPORT')));
Setting('DB_NAME', 'victual_upgrade042_audit');
Setting('DB_USER', getenv('PGUSER'));
Setting('DB_PASSWORD', getenv('PGPASSWORD'));
