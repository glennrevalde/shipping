-- =====================================================================
--  G-SMaRT Accounting - migration: average cost on the books, Average Cost Changes report
--
--  What it changes
--    1. The quantities on hand by site (table item_site_stock) are set to the total of
--       each item's movements at each site. Before v31 a site row that Rebuild Data had
--       removed at zero was not taken down again when an edited processing order, bill
--       or sale reversed a movement there, so the table could hold less than the
--       movements (the Inventory Center showed "The quantity on hand differs from the
--       total of its movements") and the average cost of the next receipts was divided
--       by too small a quantity, which overstated it.
--    2. The average cost of every item with stock and value on hand is set to its
--       remaining value divided by its remaining quantity (both from its movements).
--    3. A new report under Inventory > Inventory Reports: Average Cost Changes, given
--       to the roles that may open Inventory Valuation Detail, with its print right.
--
--  No table or column is added and no posted amount changes. The costs that earlier
--  transactions already took at the overstated average are put right by
--  System Administration > Utilities: Verify & Rebuild Data > Rebuild data, run once after this script.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (tools/install.php and the bundled dumps include it).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_11_26_average_cost_books.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Quantities on hand by site = the total of the movements there
-- ---------------------------------------------------------------------

INSERT INTO item_site_stock (item_id, site_id, qty_on_hand)
SELECT m.item_id, m.site_id, SUM(m.qty)
  FROM inventory_movements m
  JOIN items i ON i.id = m.item_id
  JOIN sites s ON s.id = m.site_id
 GROUP BY m.item_id, m.site_id
ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand);

UPDATE item_site_stock x
  LEFT JOIN (SELECT DISTINCT item_id, site_id FROM inventory_movements) m
         ON m.item_id = x.item_id AND m.site_id = x.site_id
   SET x.qty_on_hand = 0
 WHERE m.item_id IS NULL AND x.qty_on_hand <> 0;

-- ---------------------------------------------------------------------
--  2. Average cost = remaining value / remaining quantity
-- ---------------------------------------------------------------------

UPDATE items i
  JOIN (SELECT item_id, SUM(qty) AS q, SUM(total_cost) AS v FROM inventory_movements GROUP BY item_id) b
    ON b.item_id = i.id
   SET i.avg_cost = ROUND(b.v / b.q, 4)
 WHERE i.item_type IN ('inventory', 'assembly') AND b.q > 0.000001 AND b.v > 0.004;

-- ---------------------------------------------------------------------
--  3. The Average Cost Changes report
-- ---------------------------------------------------------------------

SET @acc_mod  = (SELECT module_id FROM pages WHERE page_key = 'rpt.inv_valuation_detail' LIMIT 1);
SET @acc_mod  = COALESCE(@acc_mod, (SELECT id FROM modules WHERE code = 'inv' LIMIT 1));
SET @acc_sort = (SELECT COALESCE(MAX(sort_order), 1240) FROM pages WHERE page_key = 'rpt.inv_valuation_detail');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.avg_cost_changes', @acc_mod, 'Average Cost Changes', 'report', 'Inventory Reports', @acc_sort + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.inv_valuation_detail'
  JOIN pages dst ON dst.page_key = 'rpt.avg_cost_changes'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  4. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Average cost on the books; Average Cost Changes',
        'Migration 2026_11_26: quantities on hand by site set to the totals of the movements, average costs set to remaining value / remaining quantity; report Average Cost Changes');

-- Done. Next step in the application (once, as an administrator):
--   System Administration > Utilities: Verify & Rebuild Data > Rebuild data, so the transactions that took the
--   overstated average (processing orders, sales) are costed again in date order.
