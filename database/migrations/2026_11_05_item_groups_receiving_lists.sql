-- =====================================================================
--  G-SMaRT Accounting - migration: item group pricing and sales, receiving lists
--
--  What it changes
--    1. Item groups (Inventory > Item List, type Group) get a Group price, the Price
--       variance GL account (a new column group_variance_account_id on items), and the
--       Consolidated price (the sum of the prices of the items in the group).
--    2. Lines of estimates, sales orders, invoices, sales receipts, credit memos and POS
--       sales keep the item group they were sold in: five new empty columns on
--       transaction_lines (group_item_id, group_ref, group_qty, group_rate, group_role).
--       Nothing already saved changes.
--    3. A new table item_group_sales: the item groups sold, one row per group on a
--       document, read by the two new reports.
--    4. A new account "Item Group Price Variance" (Income, code 4070 or the next free
--       one; an account of that name is used when there is one) and the module default
--       account "Item Group Price Variance" pointing to it.
--    5. A new empty column received_via on transactions: which page items were received
--       on (receipt = Receive Items, bill = Receive Items and Enter Bills), for the
--       Receive Items List and the Receive Items and Enter Bills List. It is filled in for
--       the item receipts already saved (and the bills they became), and for the bills
--       entered against a purchase order with Receive Items and Enter Bills.
--    6. Two new reports: Sales by Item Group (Sales Reports) for the roles that may open
--       Sales by Item Summary, and POS Sales by Item Group (Point of Sale) for the roles
--       that may open POS Sales by Item, each with its print right.
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_11_05_item_groups_receiving_lists.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Columns
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE items ADD COLUMN group_variance_account_id INT UNSIGNED NULL AFTER tax_code_id',
  'SELECT ''items: group_variance_account_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'items' AND column_name = 'group_variance_account_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines ADD COLUMN group_item_id INT UNSIGNED NULL AFTER other_name_id, ADD COLUMN group_ref INT UNSIGNED NULL AFTER group_item_id, ADD COLUMN group_qty DECIMAL(15,4) NULL AFTER group_ref, ADD COLUMN group_rate DECIMAL(15,4) NULL AFTER group_qty, ADD COLUMN group_role VARCHAR(10) NULL AFTER group_rate, ADD KEY ix_tl_group (group_item_id)',
  'SELECT ''transaction_lines: group columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'group_item_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions ADD COLUMN received_via VARCHAR(12) NULL AFTER other_name_id',
  'SELECT ''transactions: received_via already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'received_via');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Item group sales
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS item_group_sales (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id         BIGINT UNSIGNED NOT NULL,
  group_ref      INT UNSIGNED NOT NULL DEFAULT 0,
  group_item_id  INT UNSIGNED NOT NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  txn_date       DATE         NOT NULL,
  customer_id    INT UNSIGNED NULL,
  site_id        INT UNSIGNED NULL,
  terminal_id    INT UNSIGNED NULL,
  class_id       INT UNSIGNED NULL,
  sales_rep_id   INT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. The price variance account and its module default
-- ---------------------------------------------------------------------

SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
SET @c = (SELECT x.c FROM (SELECT '4070' AS c, 1 AS o UNION ALL SELECT '4071', 2 UNION ALL SELECT '4075', 3 UNION ALL SELECT '4080', 4 UNION ALL SELECT '4090', 5) x
           WHERE x.c NOT IN (SELECT code FROM accounts) ORDER BY x.o LIMIT 1);
INSERT INTO accounts (code, name, account_type, category, normal_balance, description)
SELECT @c, 'Item Group Price Variance', 'Income', 'Income', 'Credit', 'Item groups sold: the group price less the prices of the items in the group'
  FROM DUAL WHERE @a IS NULL AND @c IS NOT NULL;
SET @a = (SELECT id FROM accounts WHERE name = 'Item Group Price Variance' ORDER BY id LIMIT 1);
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES ('group_price_variance', 'Item Group Price Variance', 'inv', @a);
UPDATE default_accounts SET account_id = @a WHERE setting_key = 'group_price_variance' AND account_id IS NULL;

-- ---------------------------------------------------------------------
--  4. Which page items were received on
-- ---------------------------------------------------------------------

-- item receipts (bill to follow), and the bills they were turned into
UPDATE transactions SET received_via = 'receipt' WHERE txn_type = 'item_receipt' AND received_via IS NULL;
UPDATE transactions t
  JOIN audit_trail a ON a.record_type = 'transaction' AND a.record_id = t.id AND a.action = 'convert'
   SET t.received_via = 'receipt'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL;
-- bills entered against a purchase order with Receive Items and Enter Bills
UPDATE transactions t
   SET t.received_via = 'bill'
 WHERE t.txn_type = 'bill' AND t.received_via IS NULL AND t.memo LIKE 'Received against %';

-- ---------------------------------------------------------------------
--  5. The two reports
-- ---------------------------------------------------------------------

SET @igs_cust = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @igs_pos  = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @igs_csort = (SELECT COALESCE(MAX(sort_order), 200) FROM pages WHERE page_key = 'rpt.sales_item_detail');
SET @igs_psort = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE page_key = 'rpt.pos_sales_item');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.sales_item_group',     @igs_cust, 'Sales by Item Group',     'report', 'Sales Reports', @igs_csort + 1, 1),
  ('rpt.pos_sales_item_group', @igs_pos,  'POS Sales by Item Group', 'report', 'Reports',       @igs_psort + 1, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_item_summary'
  JOIN pages dst ON dst.page_key = 'rpt.sales_item_group'
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.pos_sales_item'
  JOIN pages dst ON dst.page_key = 'rpt.pos_sales_item_group'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  6. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Item groups; receiving lists',
        'Migration 2026_11_05: item group price, consolidated price and price variance account; item group sales; Sales by Item Group and POS Sales by Item Group reports; Receive Items lists');

-- Done. Next steps in the application:
--   System Administration > Company, Security & Closing Date > Inventory and POS: "Track Item Group Sales transactions"
--     and "Adopt the Group Price in the Item Group" (both on as delivered).
--   Inventory > Item List: open each item group and enter its Group price and Price variance GL account.
