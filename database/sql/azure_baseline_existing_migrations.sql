/*
One-time Azure SQL migration baseline repair.

Run this only after taking an Azure SQL backup. It records migrations whose
tables/columns already exist, without creating or dropping any application data.
It intentionally does not record the report migrations; those should still run
through `php artisan migrate --force` after this script succeeds.
*/

SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.migrations', N'U') IS NULL
BEGIN
    CREATE TABLE [migrations] (
        [id] int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [migration] nvarchar(255) NOT NULL,
        [batch] int NOT NULL
    );
END;

DECLARE @batch int = COALESCE((SELECT MAX([batch]) FROM [migrations]), 0) + 1;

IF OBJECT_ID(N'dbo.users', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.password_reset_tokens', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.sessions', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'0001_01_01_000000_create_users_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'0001_01_01_000000_create_users_table', @batch);
END;

IF OBJECT_ID(N'dbo.cache', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.cache_locks', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'0001_01_01_000001_create_cache_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'0001_01_01_000001_create_cache_table', @batch);
END;

IF OBJECT_ID(N'dbo.jobs', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.job_batches', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.failed_jobs', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'0001_01_01_000002_create_jobs_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'0001_01_01_000002_create_jobs_table', @batch);
END;

IF COL_LENGTH(N'dbo.users', N'name') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2025_11_13_055025_add_name_column_to_users_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2025_11_13_055025_add_name_column_to_users_table', @batch);
END;

IF OBJECT_ID(N'dbo.permissions', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.roles', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.model_has_permissions', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.model_has_roles', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.role_has_permissions', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2025_12_23_035623_create_permission_tables')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2025_12_23_035623_create_permission_tables', @batch);
END;

IF OBJECT_ID(N'dbo.roles', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [roles] WHERE [name] IN (N'Admins', N'Users', N'Tech Supports'))
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2025_12_23_072334_fix_role_names_to_singular')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2025_12_23_072334_fix_role_names_to_singular', @batch);
END;

IF OBJECT_ID(N'dbo.companies', N'U') IS NOT NULL
    AND OBJECT_ID(N'dbo.company_role', N'U') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2026_01_13_203405_create_companies_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2026_01_13_203405_create_companies_table', @batch);
END;

IF COL_LENGTH(N'dbo.users', N'company_id') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2026_01_13_203738_add_company_id_to_users_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2026_01_13_203738_add_company_id_to_users_table', @batch);
END;

IF COL_LENGTH(N'dbo.companies', N'tin') IS NOT NULL
    AND COL_LENGTH(N'dbo.companies', N'default_tax_rate') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2026_05_10_000001_extend_companies_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2026_05_10_000001_extend_companies_table', @batch);
END;

IF COL_LENGTH(N'dbo.roles', N'landing_page') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2026_05_10_223538_add_landing_page_to_roles_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2026_05_10_223538_add_landing_page_to_roles_table', @batch);
END;

IF COL_LENGTH(N'dbo.users', N'department') IS NOT NULL
    AND COL_LENGTH(N'dbo.users', N'position') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM [migrations] WHERE [migration] = N'2026_05_26_090000_add_department_and_position_to_users_table')
BEGIN
    INSERT INTO [migrations] ([migration], [batch])
    VALUES (N'2026_05_26_090000_add_department_and_position_to_users_table', @batch);
END;

COMMIT TRANSACTION;

SELECT [migration], [batch]
FROM [migrations]
ORDER BY [batch], [migration];
