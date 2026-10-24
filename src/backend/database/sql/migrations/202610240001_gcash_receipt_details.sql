-- Preserve existing exchanges; only new Completed exchanges receive saved details.
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gcash_transactions' AND column_name='receipt_details_json'), 'ALTER TABLE gcash_transactions ADD COLUMN receipt_details_json LONGTEXT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
