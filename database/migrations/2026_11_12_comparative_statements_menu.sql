-- =====================================================================
--  G-SMaRT Accounting - migration: comparative statements, the Financial Set-up & Reports menu
--
--  What it changes
--    1. The menu "Chart of Accounts" is renamed "Financial Set-up & Reports" (the modules
--       table, used by Page Access Configuration and the audit trail filter). Its pages
--       keep their names; the page "Chart of Accounts" is still there under Lists.
--    2. Eight new reports under Financial Set-up & Reports > Reports:
--         Comparative Income Statement by Month, by Quarter, by Year, by Custom Range
--         Comparative Balance Sheet by Month, by Quarter, by Year, by Custom Date
--       The income statement ones are given to the roles that may open the Income
--       Statement, the balance sheet ones to the roles that may open the Balance Sheet,
--       each with its print right.
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
--    or:         mysql -u root -p accounting_system < 2026_11_12_comparative_statements_menu.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The menu name
-- ---------------------------------------------------------------------

-- only while it still has its old name: since v32 (2026_12_03) the menu is called Financial
UPDATE modules SET name = 'Financial Set-up & Reports' WHERE code = 'coa' AND name = 'Chart of Accounts';

-- ---------------------------------------------------------------------
--  2. The eight reports
-- ---------------------------------------------------------------------

SET @cmp_coa  = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @cmp_sort = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'rpt.income_statement_class');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.cmp_is_month',   @cmp_coa, 'Comparative Income Statement by Month',        'report', 'Reports', @cmp_sort + 1, 1),
  ('rpt.cmp_is_quarter', @cmp_coa, 'Comparative Income Statement by Quarter',      'report', 'Reports', @cmp_sort + 2, 1),
  ('rpt.cmp_is_year',    @cmp_coa, 'Comparative Income Statement by Year',         'report', 'Reports', @cmp_sort + 3, 1),
  ('rpt.cmp_is_custom',  @cmp_coa, 'Comparative Income Statement by Custom Range', 'report', 'Reports', @cmp_sort + 4, 1),
  ('rpt.cmp_bs_month',   @cmp_coa, 'Comparative Balance Sheet by Month',           'report', 'Reports', @cmp_sort + 5, 1),
  ('rpt.cmp_bs_quarter', @cmp_coa, 'Comparative Balance Sheet by Quarter',         'report', 'Reports', @cmp_sort + 6, 1),
  ('rpt.cmp_bs_year',    @cmp_coa, 'Comparative Balance Sheet by Year',            'report', 'Reports', @cmp_sort + 7, 1),
  ('rpt.cmp_bs_custom',  @cmp_coa, 'Comparative Balance Sheet by Custom Date',     'report', 'Reports', @cmp_sort + 8, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.income_statement'
  JOIN pages dst ON dst.page_key IN ('rpt.cmp_is_month', 'rpt.cmp_is_quarter', 'rpt.cmp_is_year', 'rpt.cmp_is_custom')
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.balance_sheet'
  JOIN pages dst ON dst.page_key IN ('rpt.cmp_bs_month', 'rpt.cmp_bs_quarter', 'rpt.cmp_bs_year', 'rpt.cmp_bs_custom')
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Comparative statements; Financial Set-up & Reports',
        'Migration 2026_11_12: menu Chart of Accounts renamed Financial Set-up & Reports; Comparative Income Statement by Month, Quarter, Year and Custom Range; Comparative Balance Sheet by Month, Quarter, Year and Custom Date');

-- Done. Next steps in the application:
--   System Administration > Company, Security & Closing Date > Inventory and POS:
--     "Block sales of items with no unit cost" and "Block posting processing orders with no unit cost" (both on as delivered).
