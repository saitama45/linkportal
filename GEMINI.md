# Project Instructions - Accounting

## Database & Testing Safety
- **Database Integrity:** NEVER run commands that perform `migrate:fresh` or use the `RefreshDatabase` trait (e.g., `php artisan test`) without first confirming that the target database is isolated (e.g., SQLite in-memory).
- **Environment Verification:** Before running any test command, check that `APP_ENV` is set to `testing` and that the `DB_CONNECTION` is NOT pointing to a production or development database that contains persistent data.
