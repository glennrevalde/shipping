-- =====================================================================
--  G-SMaRT Accounting - migration: receipts ticked to settle, the Financial menu
--
--  What it changes
--    1. A new table clearing_settlements: the card and e-wallet receipts (POS sales,
--       customer payments, sales receipts) that a settlement on Make Deposits was
--       ticked for (Details > Settle). Ticked receipts are no longer listed as
--       unsettled. It starts empty: the settlements already made stay as they are,
--       taken against the oldest receipts first.
--    2. The menu "Financial Set-up & Reports" is renamed "Financial" (the modules
--       table, used by Page Access Configuration and the audit trail filter). Its
--       pages, and the "Financial Reports" card of the Report Center, keep their names.
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (tools/install.php and the bundled dumps include it).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_12_03_settle_receipts_financial_menu.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The receipts ticked to settle
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS clearing_settlements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deposit_txn_id  BIGINT UNSIGNED NOT NULL,                -- the deposit that holds the settlement
  account_id      INT UNSIGNED    NOT NULL,                -- the card / e-wallet clearing account
  receipt_txn_id  BIGINT UNSIGNED NOT NULL,                -- the POS sale, payment or sales receipt settled
  amount          DECIMAL(15,2)   NOT NULL DEFAULT 0,      -- what of it was settled
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cs_deposit (deposit_txn_id),
  KEY ix_cs_receipt (account_id, receipt_txn_id),
  KEY ix_cs_receipt_txn (receipt_txn_id),
  CONSTRAINT fk_cs_deposit FOREIGN KEY (deposit_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_receipt FOREIGN KEY (receipt_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. The menu name
-- ---------------------------------------------------------------------

UPDATE modules SET name = 'Financial' WHERE code = 'coa';

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Settle ticks on Make Deposits; Financial menu',
        'Migration 2026_12_03: table clearing_settlements for the card and e-wallet receipts ticked to settle; menu Financial Set-up & Reports renamed Financial');
