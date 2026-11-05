-- =====================================================================
--  G-SMaRT Accounting - migration: Trial Balance by Class, Statement of Cash Flows by Class
--
--  What it changes
--    Two new reports under Financial > Reports:
--      Trial Balance by Class            for the roles that may open the Trial Balance
--      Statement of Cash Flows by Class  for the roles that may open the Statement of Cash Flows
--    each with its print right.
--
--  No table or column is added, nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (tools/install.php and the bundled dumps include it).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_12_10_class_reports.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @cr_coa   = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @cr_tb    = (SELECT COALESCE(MAX(sort_order), 160) FROM pages WHERE page_key = 'rpt.trial_balance');
SET @cr_cf    = (SELECT COALESCE(MAX(sort_order), 260) FROM pages WHERE page_key = 'rpt.cash_flow');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.trial_balance_class', @cr_coa, 'Trial Balance by Class',          'report', 'Reports', @cr_tb + 5, 1),
  ('rpt.cash_flow_class',     @cr_coa, 'Statement of Cash Flows by Class', 'report', 'Reports', @cr_cf + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.trial_balance'
  JOIN pages dst ON dst.page_key = 'rpt.trial_balance_class'
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.cash_flow'
  JOIN pages dst ON dst.page_key = 'rpt.cash_flow_class'
 WHERE src.allowed = 1;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Trial Balance by Class; Statement of Cash Flows by Class',
        'Migration 2026_12_10: reports Trial Balance by Class and Statement of Cash Flows by Class');
