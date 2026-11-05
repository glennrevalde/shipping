-- =====================================================================
--  G-SMaRT Accounting - migration: Ending Inventory - POS Cashier
--
--  What it changes
--    1. a new report page under Point of Sale > Reports:
--         rpt.pos_ending_inventory  Ending Inventory - POS Cashier: the quantity on hand
--                                   of every item at a cashier's outlet at the end of a
--                                   shift (or any date and time), by item code or by item
--                                   description, printable on the POS roll printer
--       It is given to the roles that may open the X- and Z-Reading Summary (POS
--       supervisors, accountants, auditors) with their print right, and to the roles
--       that may open Shifts, Cash Drops & Readings (cashiers), who print their own.
--
--  The date-and-time filters, the Cashier and Shift filters of the inventory movement
--  reports and "Exclude zero sales" need no change to the database.
--
--  No table or column is added, changed or dropped, and nothing is deleted.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_10_29_pos_ending_inventory_shift_times.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @pei_pos  = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @pei_sort = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key IN ('rpt.pos_void_log', 'rpt.pos_discount_log', 'rpt.pos_readings'));

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.pos_ending_inventory', @pei_pos, 'Ending Inventory - POS Cashier', 'report', 'Reports', @pei_sort + 1, 1);

-- the roles of the X- and Z-Reading Summary, with their print right
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.pos_readings'
  JOIN pages dst ON dst.page_key = 'rpt.pos_ending_inventory'
 WHERE src.allowed = 1;

-- the cashiers (the roles that may open Shifts, Cash Drops & Readings): open and print their own
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, 1, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.shifts'
  JOIN pages dst ON dst.page_key = 'rpt.pos_ending_inventory'
 WHERE src.allowed = 1;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Ending Inventory - POS Cashier',
        'Migration 2026_10_29: Ending Inventory - POS Cashier report added for the POS report roles and the cashiers');

-- Done. Next steps in the application:
--   Point of Sale > Reports > Ending Inventory - POS Cashier; Shifts, Cash Drops & Readings has an Ending inventory button per shift.
