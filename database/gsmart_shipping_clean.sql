-- =====================================================================
--  G-SMaRT Shipping System - clean database (set-up only, no transactions)
--  Generated 2026-10-31 22:42:54 +08:00
--  2 databases, 2,063 rows:
--    gsmart_shipping: Visayas Star Shipping Lines, Inc. (2,029 rows, 0 transactions)
--    gsmart_shipping_master: the platform (companies, Company Codes, usernames, Super Admins; 34 rows)
--
--  Import:  phpMyAdmin > Import (no database selected) > choose this file > Go
--      or:  mysql -u root -p < gsmart_shipping_clean.sql
--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.
--  Import as root (or a user with CREATE, INSERT, REFERENCES and TRIGGER privileges).
--  WARNING: importing drops and recreates every table in the databases below.
--
--  Log-ins: see USER_ACCOUNTS.md in the program folder. Company administrator admin / Admin@12345,
--  Super Admin superadmin / Super@12345 (a new password is asked at first log-in).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';

-- =====================================================================
--  COMPANY DATABASE 1: gsmart_shipping  (Visayas Star Shipping Lines, Inc.)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `gsmart_shipping` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_shipping`;

-- tables
-- =====================================================================
--  G-SMaRT Shipping System - database schema (accounting core + shipping operations)
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================


-- ---------------------------------------------------------------------
--  SYSTEM ADMINISTRATION AND SECURITY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  employee_no          VARCHAR(30)  NULL,
  pos_default_site_id  INT UNSIGNED NULL,                 -- the site this user's POS Register opens on
  pos_default_class_id INT UNSIGNED NULL,                 -- the class every new sale of theirs starts with
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  approval_status      VARCHAR(10)  NOT NULL DEFAULT 'approved',  -- approved / pending / rejected (joined with the Company Code)
  created_via          VARCHAR(12)  NOT NULL DEFAULT 'admin',     -- admin / registration / provisioning / setup / platform
  approved_by          INT UNSIGNED NULL,                         -- who approved or rejected the registration
  approved_at          DATETIME     NULL,
  rejected_reason      VARCHAR(255) NULL,
  is_platform          TINYINT(1)   NOT NULL DEFAULT 0,           -- the account a Super Admin works through inside the company
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_approval (approval_status),
  KEY ix_users_pos_site (pos_default_site_id),
  KEY ix_users_pos_class (pos_default_class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  description VARCHAR(255) NULL,
  is_admin    TINYINT(1)   NOT NULL DEFAULT 0,   -- full access to everything
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- standard role shipped with the system
  all_sites   TINYINT(1)   NOT NULL DEFAULT 1,   -- 0 = limited to role_sites
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_roles;
CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS modules;
CREATE TABLE modules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  icon       VARCHAR(40)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_modules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(60)  NOT NULL,
  module_id    INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  page_type    ENUM('center','transaction','list','report','setup','admin') NOT NULL DEFAULT 'transaction',
  menu_group   VARCHAR(40)  NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  show_in_menu TINYINT(1)   NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  UNIQUE KEY uq_pages_key (page_key),
  CONSTRAINT fk_pages_module FOREIGN KEY (module_id) REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page access + action permissions per role (View = allowed)
DROP TABLE IF EXISTS role_page_access;
CREATE TABLE role_page_access (
  role_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id, page_id),
  CONSTRAINT fk_rpa_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpa_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-level overrides always take precedence over roles
DROP TABLE IF EXISTS user_page_overrides;
CREATE TABLE user_page_overrides (
  user_id     INT UNSIGNED NOT NULL,
  page_id     INT UNSIGNED NOT NULL,
  allowed     TINYINT(1)   NOT NULL DEFAULT 0,
  can_create  TINYINT(1)   NOT NULL DEFAULT 0,
  can_edit    TINYINT(1)   NOT NULL DEFAULT 0,
  can_delete  TINYINT(1)   NOT NULL DEFAULT 0,
  can_print   TINYINT(1)   NOT NULL DEFAULT 0,
  can_approve TINYINT(1)   NOT NULL DEFAULT 0,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, page_id),
  CONSTRAINT fk_upo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sensitive permissions set separately from the grid
--  view_costs, view_bank_balances, credit_limit_override, coa_changes,
--  closed_period_changes (0/1); max_discount_pct, max_price_override_pct,
--  max_amount (number, blank = unlimited)
DROP TABLE IF EXISTS role_special_permissions;
CREATE TABLE role_special_permissions (
  role_id    INT UNSIGNED NOT NULL,
  perm_key   VARCHAR(40)  NOT NULL,
  perm_value VARCHAR(40)  NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rsp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permanent, read-only audit trail (see triggers at the end of the file)
DROP TABLE IF EXISTS audit_trail;
CREATE TABLE audit_trail (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(50)  NULL,
  user_roles  VARCHAR(255) NULL,
  ip_address  VARCHAR(45)  NULL,
  workstation VARCHAR(255) NULL,
  site_id     INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  module      VARCHAR(20)  NOT NULL,
  page_key    VARCHAR(60)  NULL,
  action      VARCHAR(30)  NOT NULL,
  record_type VARCHAR(40)  NULL,
  record_id   BIGINT       NULL,
  record_ref  VARCHAR(160) NULL,
  amount      DECIMAL(15,2) NULL,
  changes     LONGTEXT     NULL,   -- JSON [{field, old, new}]
  snapshot    LONGTEXT     NULL,   -- JSON copy of the record
  reason      VARCHAR(255) NULL,
  approved_by VARCHAR(50)  NULL,
  KEY ix_audit_date (created_at),
  KEY ix_audit_user (user_id, created_at),
  KEY ix_audit_record (record_type, record_id),
  KEY ix_audit_action (action),
  KEY ix_audit_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS memorized_reports;
CREATE TABLE memorized_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  group_name  VARCHAR(60)  NULL,
  report_key  VARCHAR(60)  NOT NULL,
  query_string TEXT        NULL,
  is_shared   TINYINT(1)   NOT NULL DEFAULT 0,
  schedule    VARCHAR(10)  NULL,          -- daily, weekly, monthly (tools/send_scheduled_reports.php)
  email_to    VARCHAR(255) NULL,
  last_sent_at DATETIME    NULL,
  created_by  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sequences;
CREATE TABLE sequences (
  seq_key VARCHAR(30) NOT NULL PRIMARY KEY,
  prefix  VARCHAR(10) NOT NULL DEFAULT '',
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad     TINYINT      NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CHART OF ACCOUNTS AND LISTS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS accounts;
CREATE TABLE accounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  account_type    ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                       'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                       'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  category        ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  normal_balance  ENUM('Debit','Credit') NOT NULL,
  parent_id       INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  bank_account_no VARCHAR(40)  NULL,
  with_sl         TINYINT(1)   NOT NULL DEFAULT 0,    -- keeps a subsidiary ledger: every entry needs a name
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  is_system       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_accounts_code (code),
  KEY ix_acc_with_sl (with_sl),
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module default accounts (ar, ap, undeposited_funds, output_tax, ...)
DROP TABLE IF EXISTS default_accounts;
CREATE TABLE default_accounts (
  setting_key VARCHAR(40)  NOT NULL PRIMARY KEY,
  label       VARCHAR(120) NOT NULL,
  module      VARCHAR(20)  NOT NULL,
  account_id  INT UNSIGNED NULL,
  CONSTRAINT fk_da_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS terms;
CREATE TABLE terms (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  net_days      INT          NOT NULL DEFAULT 0,
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_days INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tax_codes;
CREATE TABLE tax_codes (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  rate                DECIMAL(6,3) NOT NULL DEFAULT 0,
  sales_account_id    INT UNSIGNED NULL,   -- Output Tax
  purchase_account_id INT UNSIGNED NULL,   -- Input Tax
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tax_code (code),
  CONSTRAINT fk_tax_sales FOREIGN KEY (sales_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tax_purch FOREIGN KEY (purchase_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(40)  NOT NULL,
  method_type         ENUM('cash','check','card','ewallet','bank_transfer','voucher','other') NOT NULL DEFAULT 'other',
  clearing_account_id INT UNSIGNED NULL,   -- POS clearing account for card / e-wallet / voucher / bank
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  show_in_pos         TINYINT(1)   NOT NULL DEFAULT 1,   -- offered as a payment option at the POS register
  CONSTRAINT fk_pm_account FOREIGN KEY (clearing_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_levels;
CREATE TABLE price_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(40)  NOT NULL,
  adjust_pct DECIMAL(6,2) NOT NULL DEFAULT 0,  -- e.g. -10 = 10% below standard price
  is_active  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS sales_reps;
CREATE TABLE sales_reps (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  initials  VARCHAR(10)  NULL,
  user_id   INT UNSIGNED NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_rep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS customer_types;
CREATE TABLE customer_types (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS billing_groups;
CREATE TABLE billing_groups (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Class List: your own way of splitting the books (branches, departments, projects ...).
-- Every transaction can carry a class; the ledger keeps it, so financial reports can filter by it.
DROP TABLE IF EXISTS classes;
CREATE TABLE classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employees and other names (payees and subsidiary ledgers that are neither customers nor vendors)
DROP TABLE IF EXISTS other_names;
CREATE TABLE other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CUSTOMERS AND VENDORS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id           INT UNSIGNED NULL,          -- job / project under a customer
  name                VARCHAR(120) NOT NULL,
  company_name        VARCHAR(120) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(40)  NULL,
  mobile              VARCHAR(40)  NULL,
  email               VARCHAR(120) NULL,
  billing_address     VARCHAR(255) NULL,
  shipping_address    VARCHAR(255) NULL,
  tin                 VARCHAR(30)  NULL,
  wht_ids             VARCHAR(100) NULL,          -- withholding taxes it usually withholds from its payments
  terms_id            INT UNSIGNED NULL,
  credit_limit        DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = no limit
  price_level_id      INT UNSIGNED NULL,
  tax_code_id         INT UNSIGNED NULL,
  payment_method_id   INT UNSIGNED NULL,
  customer_type_id    INT UNSIGNED NULL,
  sales_rep_id        INT UNSIGNED NULL,
  billing_group_id    INT UNSIGNED NULL,
  allow_pos_charge    TINYINT(1)   NOT NULL DEFAULT 0,  -- approved account customer at the POS
  notes               TEXT         NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_customers_name (name),
  CONSTRAINT fk_cust_parent FOREIGN KEY (parent_id) REFERENCES customers(id),
  CONSTRAINT fk_cust_terms  FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_cust_pl     FOREIGN KEY (price_level_id) REFERENCES price_levels(id),
  CONSTRAINT fk_cust_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_cust_pm     FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_cust_type   FOREIGN KEY (customer_type_id) REFERENCES customer_types(id),
  CONSTRAINT fk_cust_rep    FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_cust_bg     FOREIGN KEY (billing_group_id) REFERENCES billing_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS vendors;
CREATE TABLE vendors (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                       VARCHAR(120) NOT NULL,
  company_name               VARCHAR(120) NULL,
  contact_person             VARCHAR(120) NULL,
  phone                      VARCHAR(40)  NULL,
  fax                        VARCHAR(40)  NULL,
  email                      VARCHAR(120) NULL,
  address                    VARCHAR(255) NULL,
  tin                        VARCHAR(30)  NULL,
  terms_id                   INT UNSIGNED NULL,
  credit_limit               DECIMAL(15,2) NOT NULL DEFAULT 0,
  default_expense_account_id INT UNSIGNED NULL,
  tax_code_id                INT UNSIGNED NULL,
  account_no                 VARCHAR(40)  NULL,   -- our account number with the vendor
  wht_ids                    VARCHAR(100) NULL,   -- withholding taxes usually withheld from it (withholding_taxes ids, "1,3")
  notes                      TEXT         NULL,
  is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_vendors_name (name),
  CONSTRAINT fk_vend_terms FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_vend_exp   FOREIGN KEY (default_expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_vend_tax   FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entity_notes;
CREATE TABLE entity_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('customer','vendor','item') NOT NULL,
  entity_id   INT UNSIGNED NOT NULL,
  note        TEXT         NOT NULL,
  is_todo     TINYINT(1)   NOT NULL DEFAULT 0,
  due_date    DATE         NULL,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notes_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  INVENTORY
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS sites;
CREATE TABLE sites (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  site_type ENUM('warehouse','store','stockroom','van','damaged','other','vessel') NOT NULL DEFAULT 'warehouse',
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_categories;
CREATE TABLE item_categories (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units of measure: a set holds the base unit (stock and costs are kept in it)
-- plus the larger or smaller units a document may be entered in
DROP TABLE IF EXISTS uom_sets;
CREATE TABLE uom_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60)  NOT NULL,
  uom_kind   VARCHAR(10)  NOT NULL DEFAULT 'count',  -- count, weight, volume, length, time, other
  base_unit  VARCHAR(20)  NOT NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_uom_sets_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS uom_units;
CREATE TABLE uom_units (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  set_id              INT UNSIGNED NOT NULL,
  code                VARCHAR(20)  NOT NULL,
  name                VARCHAR(60)  NOT NULL,
  factor              DECIMAL(15,6) NOT NULL DEFAULT 1,  -- base units in one of this unit
  is_base             TINYINT(1)   NOT NULL DEFAULT 0,
  is_sales_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_purchase_default TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_uom_units (set_id, code),
  CONSTRAINT fk_uom_units_set FOREIGN KEY (set_id) REFERENCES uom_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS items;
CREATE TABLE items (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_type            ENUM('service','inventory','non_inventory','assembly','other_charge','subtotal','group','discount','payment') NOT NULL,
  code                 VARCHAR(40)  NOT NULL,
  barcode              VARCHAR(60)  NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  purchase_description VARCHAR(255) NULL,
  category_id          INT UNSIGNED NULL,
  unit                 VARCHAR(20)  NULL,
  uom_set_id           INT UNSIGNED NULL,                  -- U/M set (blank = single unit)
  sales_uom_id         INT UNSIGNED NULL,                  -- unit offered first on sales forms
  purchase_uom_id      INT UNSIGNED NULL,                  -- unit offered first on purchase forms
  cost                 DECIMAL(15,4) NOT NULL DEFAULT 0,  -- standard purchase cost
  avg_cost             DECIMAL(15,4) NOT NULL DEFAULT 0,  -- weighted average cost
  sales_price          DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_pct         DECIMAL(6,2)  NOT NULL DEFAULT 0,  -- discount items: percent (0 = use sales_price as amount)
  income_account_id    INT UNSIGNED NULL,
  cogs_account_id      INT UNSIGNED NULL,
  asset_account_id     INT UNSIGNED NULL,
  expense_account_id   INT UNSIGNED NULL,
  tax_code_id          INT UNSIGNED NULL,
  group_variance_account_id INT UNSIGNED NULL,             -- group items: account of the group price less the consolidated price
  preferred_vendor_id  INT UNSIGNED NULL,
  reorder_point        DECIMAL(15,4) NOT NULL DEFAULT 0,
  max_stock            DECIMAL(15,4) NOT NULL DEFAULT 0,
  build_point          DECIMAL(15,4) NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_barcode (barcode),
  CONSTRAINT fk_items_cat    FOREIGN KEY (category_id) REFERENCES item_categories(id),
  CONSTRAINT fk_items_inc    FOREIGN KEY (income_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_cogs   FOREIGN KEY (cogs_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_asset  FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_exp    FOREIGN KEY (expense_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_tax    FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_items_gvar   FOREIGN KEY (group_variance_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_items_vendor FOREIGN KEY (preferred_vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_items_uom    FOREIGN KEY (uom_set_id) REFERENCES uom_sets(id),
  CONSTRAINT fk_items_suom   FOREIGN KEY (sales_uom_id) REFERENCES uom_units(id),
  CONSTRAINT fk_items_puom   FOREIGN KEY (purchase_uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bill of materials for assemblies, and members of group items
DROP TABLE IF EXISTS item_components;
CREATE TABLE item_components (
  parent_item_id    INT UNSIGNED NOT NULL,
  component_item_id INT UNSIGNED NOT NULL,
  qty               DECIMAL(15,4) NOT NULL DEFAULT 1,
  PRIMARY KEY (parent_item_id, component_item_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_comp   FOREIGN KEY (component_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing formulas (multi-output bills of materials) for disassembly,
-- de-kitting / break-bulk and co-product / by-product processing
DROP TABLE IF EXISTS process_formulas;
CREATE TABLE process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formula lines, quantities per batch. input = consumed, output = produced, cost = labor / overhead absorbed
DROP TABLE IF EXISTS process_formula_lines;
CREATE TABLE process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS price_level_items;
CREATE TABLE price_level_items (
  price_level_id INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  custom_price   DECIMAL(15,4) NOT NULL,
  PRIMARY KEY (price_level_id, item_id),
  CONSTRAINT fk_pli_level FOREIGN KEY (price_level_id) REFERENCES price_levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_item  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS item_site_stock;
CREATE TABLE item_site_stock (
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, site_id),
  CONSTRAINT fk_iss_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iss_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TRANSACTIONS (all modules)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS transactions;
CREATE TABLE transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_type           VARCHAR(30)  NOT NULL,
  -- estimate, sales_order, invoice, sales_receipt, statement_charge, finance_charge,
  -- payment, credit_memo, refund, purchase_order, bill, vendor_credit, item_receipt,
  -- bill_payment, check, deposit, transfer, journal, inventory_adjustment,
  -- inventory_transfer, build_assembly, pos_sale, pos_return, depreciation,
  -- asset_disposal, bank_charge, bank_interest, item_processing, cash_voucher, vendor_advance
  txn_no             VARCHAR(30)  NULL,
  txn_date           DATE         NOT NULL,
  due_date           DATE         NULL,
  ship_date          DATE         NULL,
  ref_no             VARCHAR(60)  NULL,
  customer_id        INT UNSIGNED NULL,
  vendor_id          INT UNSIGNED NULL,
  payee_name         VARCHAR(120) NULL,
  account_id         INT UNSIGNED NULL,     -- AR / AP / bank / deposit-to / from account
  account2_id        INT UNSIGNED NULL,     -- transfer-to / cash-back / adjustment account
  site_id            INT UNSIGNED NULL,
  site2_id           INT UNSIGNED NULL,     -- transfer-to site
  terms_id           INT UNSIGNED NULL,
  sales_rep_id       INT UNSIGNED NULL,
  payment_method_id  INT UNSIGNED NULL,
  billing_address    VARCHAR(255) NULL,
  shipping_address   VARCHAR(255) NULL,
  memo               VARCHAR(255) NULL,
  customer_message   VARCHAR(255) NULL,
  subtotal           DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_back_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  total              DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20)  NOT NULL DEFAULT 'open',
  -- open, closed, paid, partial, pending, accepted, suspended, completed, void
  is_pending         TINYINT(1)   NOT NULL DEFAULT 0,
  is_voided          TINYINT(1)   NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME     NULL,
  to_be_printed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_printed         TINYINT(1)   NOT NULL DEFAULT 0,
  restock            TINYINT(1)   NOT NULL DEFAULT 1,     -- credit memos / POS returns
  credit_disposition VARCHAR(10)  NULL,                   -- keep, apply, refund
  progress_pct       DECIMAL(6,2) NULL,                   -- progress invoicing
  source_txn_id      BIGINT UNSIGNED NULL,                -- converted from / refund of
  terminal_id        INT UNSIGNED NULL,
  pos_shift_id       INT UNSIGNED NULL,
  receipt_no         VARCHAR(30)  NULL,
  amount_tendered    DECIMAL(15,2) NOT NULL DEFAULT 0,
  change_given       DECIMAL(15,2) NOT NULL DEFAULT 0,
  approved_by        INT UNSIGNED NULL,
  batch_ref          VARCHAR(40)  NULL,
  process_type       VARCHAR(20)  NULL,                   -- item processing: disassembly, dekit, co_product
  alloc_method       VARCHAR(20)  NULL,                   -- item processing: cost allocation method
  process_formula_id INT UNSIGNED NULL,                   -- item processing: formula used
  process_batches    DECIMAL(15,4) NULL,                  -- item processing: formula batches run
  class_id           INT UNSIGNED NULL,                   -- class of the whole transaction (lines may override it)
  other_name_id      INT UNSIGNED NULL,                   -- payee from Employees & Other Names
  received_via       VARCHAR(12)  NULL,                   -- receipt = Receive Items, bill = Receive Items and Enter Bills
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by         INT UNSIGNED NULL,
  updated_at         DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_txn_type_date (txn_type, txn_date),
  KEY ix_txn_customer (customer_id, txn_date),
  KEY ix_txn_vendor (vendor_id, txn_date),
  KEY ix_txn_status (status),
  KEY ix_txn_source (source_txn_id),
  KEY ix_txn_shift (pos_shift_id),
  KEY ix_txn_formula (process_formula_id),
  KEY ix_txn_class (class_id),
  KEY ix_txn_other_name (other_name_id),
  CONSTRAINT fk_txn_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_txn_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_txn_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_formula  FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_account2 FOREIGN KEY (account2_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_site     FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_site2    FOREIGN KEY (site2_id) REFERENCES sites(id),
  CONSTRAINT fk_txn_terms    FOREIGN KEY (terms_id) REFERENCES terms(id),
  CONSTRAINT fk_txn_rep      FOREIGN KEY (sales_rep_id) REFERENCES sales_reps(id),
  CONSTRAINT fk_txn_pm       FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS transaction_lines;
CREATE TABLE transaction_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id          BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  line_kind       VARCHAR(10)  NOT NULL DEFAULT 'item',   -- item, expense, journal, deposit, component, input, output, cost
  item_id         INT UNSIGNED NULL,
  account_id      INT UNSIGNED NULL,
  description     VARCHAR(255) NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit            VARCHAR(20)  NULL,
  uom_id          INT UNSIGNED NULL,                      -- unit the line was entered in
  uom_factor      DECIMAL(15,6) NOT NULL DEFAULT 1,       -- base units in one entered unit
  uom_qty         DECIMAL(15,4) NOT NULL DEFAULT 0,       -- quantity as entered
  uom_rate        DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price as entered
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount_kind   VARCHAR(3)    NOT NULL DEFAULT 'pct',   -- pct = percent of the line, amt = amount off
  discount_pct    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,       -- journal: + debit / - credit
  tax_code_id     INT UNSIGNED NULL,
  tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  site_id         INT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,                      -- customer:job for billable costs / journal
  vendor_id       INT UNSIGNED NULL,
  is_billable     TINYINT(1)   NOT NULL DEFAULT 0,
  billed_txn_id   BIGINT UNSIGNED NULL,                   -- invoice that billed this cost
  ref_txn_id      BIGINT UNSIGNED NULL,                   -- deposit: payment / receipt being deposited
  pos_shift_id    INT UNSIGNED NULL,                      -- deposit: the POS shift whose cash the line deposits
  source_line_id  BIGINT UNSIGNED NULL,                   -- estimate / SO / PO line
  qty_fulfilled   DECIMAL(15,4) NOT NULL DEFAULT 0,       -- SO invoiced / PO received
  amount_fulfilled DECIMAL(15,2) NOT NULL DEFAULT 0,      -- estimate progress-invoiced
  is_closed       TINYINT(1)   NOT NULL DEFAULT 0,
  cost_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,       -- COGS captured at posting
  value_change    DECIMAL(15,2) NOT NULL DEFAULT 0,       -- inventory value adjustment
  output_role     VARCHAR(12)  NULL,                      -- item processing output: co_product, by_product, scrap
  alloc_basis     DECIMAL(15,4) NOT NULL DEFAULT 0,       -- item processing: price / weight / percent / value entered
  alloc_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,       -- item processing: share of the total cost
  planned_qty     DECIMAL(15,4) NULL,                     -- item processing: quantity expected by the formula
  class_id        INT UNSIGNED NULL,                      -- class of this line (blank = the transaction's class)
  other_name_id   INT UNSIGNED NULL,                      -- subsidiary ledger: employee / other name
  group_item_id   INT UNSIGNED NULL,                      -- item group sold: the group this line belongs to
  group_ref       INT UNSIGNED NULL,                      -- which group of the document (several of the same group can be on it)
  group_qty       DECIMAL(15,4) NULL,                     -- groups sold on the line's group
  group_rate      DECIMAL(15,4) NULL,                     -- price of one group
  group_role      VARCHAR(10)  NULL,                      -- member = an item of the group, variance = group price less consolidated price
  KEY ix_tl_txn (txn_id),
  KEY ix_tl_group (group_item_id),
  KEY ix_tl_item (item_id),
  KEY ix_tl_ref (ref_txn_id),
  KEY ix_tl_shift (pos_shift_id),
  KEY ix_tl_source (source_line_id),
  KEY ix_tl_class (class_id),
  KEY ix_tl_other_name (other_name_id),
  CONSTRAINT fk_tl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_tl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_tl_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_tl_tax     FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id),
  CONSTRAINT fk_tl_site    FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_tl_cust    FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_tl_uom     FOREIGN KEY (uom_id) REFERENCES uom_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments / credits applied to invoices and bills
DROP TABLE IF EXISTS payment_applications;
CREATE TABLE payment_applications (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,   -- payment, credit memo, bill payment, vendor credit
  target_txn_id  BIGINT UNSIGNED NOT NULL,   -- invoice, statement/finance charge, bill
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  withholding    DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pa_payment (payment_txn_id),
  KEY ix_pa_target (target_txn_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withholding Tax Table (BIR Reports): the taxes a payment can have withheld
DROP TABLE IF EXISTS withholding_taxes;
CREATE TABLE withholding_taxes (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20)  NOT NULL,
  atc                   VARCHAR(10)  NULL,                   -- BIR alphanumeric tax code (WC158, WC160, WV010 ...)
  name                  VARCHAR(100) NOT NULL,
  tax_kind              ENUM('income','vat') NOT NULL DEFAULT 'income',   -- expanded withholding of income tax, or VAT / business tax withheld
  rate                  DECIMAL(9,4) NOT NULL DEFAULT 0,     -- percent
  base_type             ENUM('net_of_vat','gross') NOT NULL DEFAULT 'net_of_vat',
  applies_to            ENUM('purchases','sales','both') NOT NULL DEFAULT 'both',
  payable_account_id    INT UNSIGNED NULL,                   -- withheld from suppliers (blank = module default)
  receivable_account_id INT UNSIGNED NULL,                   -- withheld by customers (blank = module default)
  description           VARCHAR(255) NULL,
  sort_order            INT          NOT NULL DEFAULT 0,
  is_active             TINYINT(1)   NOT NULL DEFAULT 1,
  created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_wht_code (code),
  CONSTRAINT fk_wht_payable FOREIGN KEY (payable_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_wht_receivable FOREIGN KEY (receivable_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes withheld by a payment (bill payment or customer payment), by document paid and type: BIR Form 2307 and the BIR Reports
DROP TABLE IF EXISTS payment_withholdings;
CREATE TABLE payment_withholdings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_txn_id BIGINT UNSIGNED NOT NULL,
  target_txn_id  BIGINT UNSIGNED NULL,        -- the bill / invoice it was withheld on
  wht_id         INT UNSIGNED NOT NULL,
  tax_kind       ENUM('income','vat') NOT NULL DEFAULT 'income',
  atc            VARCHAR(10)  NULL,
  rate           DECIMAL(9,4) NOT NULL DEFAULT 0,
  base           DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  account_id     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pw_payment (payment_txn_id),
  KEY ix_pw_target (target_txn_id),
  KEY ix_pw_wht (wht_id),
  CONSTRAINT fk_pw_payment FOREIGN KEY (payment_txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pw_target  FOREIGN KEY (target_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pw_wht     FOREIGN KEY (wht_id) REFERENCES withholding_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item group sales (System Administration > Track Item Group Sales transactions): one row per group sold on a document
DROP TABLE IF EXISTS item_group_sales;
CREATE TABLE item_group_sales (
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
  qty            DECIMAL(15,4) NOT NULL DEFAULT 0,   -- groups sold
  group_price    DECIMAL(15,4) NOT NULL DEFAULT 0,   -- price of one group
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,   -- what the groups sold for (before tax)
  consolidated   DECIMAL(15,2) NOT NULL DEFAULT 0,   -- the items of the groups at their own prices
  variance       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- amount less consolidated (to the price variance account)
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_igs_txn (txn_id),
  KEY ix_igs_group (group_item_id, txn_date),
  KEY ix_igs_date (txn_type, txn_date),
  CONSTRAINT fk_igs_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_igs_group FOREIGN KEY (group_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Card and e-wallet receipts ticked to settle on Make Deposits (v32, Details > Settle)
DROP TABLE IF EXISTS clearing_settlements;
CREATE TABLE clearing_settlements (
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

-- General ledger (generated automatically by the posting engine)
DROP TABLE IF EXISTS journal_lines;
CREATE TABLE journal_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_date          DATE         NOT NULL,
  account_id        INT UNSIGNED NOT NULL,
  debit             DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(15,2) NOT NULL DEFAULT 0,
  customer_id       INT UNSIGNED NULL,
  vendor_id         INT UNSIGNED NULL,
  item_id           INT UNSIGNED NULL,
  site_id           INT UNSIGNED NULL,
  class_id          INT UNSIGNED NULL,
  other_name_id     INT UNSIGNED NULL,
  memo              VARCHAR(255) NULL,
  cleared           TINYINT(1)   NOT NULL DEFAULT 0,
  reconciliation_id INT UNSIGNED NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jl_account_date (account_id, txn_date),
  KEY ix_jl_txn (txn_id),
  KEY ix_jl_customer (customer_id),
  KEY ix_jl_vendor (vendor_id),
  KEY ix_jl_class (class_id, txn_date),
  KEY ix_jl_other_name (other_name_id),
  CONSTRAINT fk_jl_class   FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id),
  CONSTRAINT fk_jl_txn     FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perpetual inventory movements (generated automatically)
DROP TABLE IF EXISTS inventory_movements;
CREATE TABLE inventory_movements (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id      BIGINT UNSIGNED NOT NULL,
  txn_line_id BIGINT UNSIGNED NULL,
  txn_date    DATE         NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cost  DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- total on hand (all sites) after the movement
  avg_after   DECIMAL(15,4) NOT NULL DEFAULT 0,    -- average cost after the movement
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_im_item_date (item_id, txn_date),
  KEY ix_im_txn (txn_id),
  CONSTRAINT fk_im_txn  FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_im_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VENDORS - RECEIPT MANAGEMENT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS receipt_documents;
CREATE TABLE receipt_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_path     VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type     VARCHAR(80)  NULL,
  source        ENUM('upload','email','mobile') NOT NULL DEFAULT 'upload',
  vendor_id     INT UNSIGNED NULL,
  receipt_date  DATE         NULL,
  amount        DECIMAL(15,2) NULL,
  account_id    INT UNSIGNED NULL,
  memo          VARCHAR(255) NULL,
  status        ENUM('for_review','matched','created','rejected') NOT NULL DEFAULT 'for_review',
  linked_txn_id BIGINT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT UNSIGNED NULL,
  reviewed_at   DATETIME     NULL,
  CONSTRAINT fk_rd_vendor  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_rd_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_rd_txn     FOREIGN KEY (linked_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FIXED ASSETS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS asset_categories;
CREATE TABLE asset_categories (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)  NOT NULL,
  asset_account_id       INT UNSIGNED NOT NULL,
  accum_dep_account_id   INT UNSIGNED NOT NULL,
  dep_expense_account_id INT UNSIGNED NOT NULL,
  default_method         ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL DEFAULT 'straight_line',
  default_life_years     DECIMAL(5,2) NOT NULL DEFAULT 5,
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_ac_asset FOREIGN KEY (asset_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_accum FOREIGN KEY (accum_dep_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_ac_exp   FOREIGN KEY (dep_expense_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_locations;
CREATE TABLE asset_locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_asset_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fixed_assets;
CREATE TABLE fixed_assets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_no            VARCHAR(30)  NOT NULL,
  description         VARCHAR(160) NOT NULL,
  category_id         INT UNSIGNED NOT NULL,
  location            VARCHAR(120) NULL,
  location_id         INT UNSIGNED NULL,
  site_id             INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,      -- default class (depreciation entry when no class is chosen)
  serial_no           VARCHAR(60)  NULL,
  vendor_id           INT UNSIGNED NULL,
  acquisition_date    DATE         NOT NULL,
  cost                DECIMAL(15,2) NOT NULL,
  salvage_value       DECIMAL(15,2) NOT NULL DEFAULT 0,
  useful_life_years   DECIMAL(5,2) NOT NULL,
  method              ENUM('straight_line','declining_balance','units_of_production','syd') NOT NULL,
  db_multiplier       DECIMAL(4,2) NOT NULL DEFAULT 2.00,
  total_units         DECIMAL(15,2) NULL,      -- units of production
  unit_label          VARCHAR(30)  NULL,
  -- change in estimate: new basis applied from basis_date forward
  basis_date          DATE         NULL,
  basis_nbv           DECIMAL(15,2) NULL,
  basis_life_months   INT          NULL,
  -- existing assets: depreciation recorded before the system was used
  opening_accum_dep   DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_through     DATE         NULL,
  acquisition_txn_id  BIGINT UNSIGNED NULL,
  status              ENUM('active','fully_depreciated','disposed') NOT NULL DEFAULT 'active',
  disposal_date       DATE         NULL,
  disposal_proceeds   DECIMAL(15,2) NULL,
  disposal_txn_id     BIGINT UNSIGNED NULL,
  notes               TEXT         NULL,
  current_meter       DECIMAL(15,2) NULL,     -- odometer / running hours
  meter_unit          VARCHAR(20)  NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_fa_no (asset_no),
  KEY ix_fa_location (location_id),
  KEY ix_fa_class (class_id),
  CONSTRAINT fk_fa_location FOREIGN KEY (location_id) REFERENCES asset_locations(id),
  CONSTRAINT fk_fa_class  FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_fa_cat    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
  CONSTRAINT fk_fa_site   FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_fa_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_fa_acq    FOREIGN KEY (acquisition_txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fa_disp   FOREIGN KEY (disposal_txn_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_depreciation;
CREATE TABLE asset_depreciation (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units_used   DECIMAL(15,2) NULL,
  amount       DECIMAL(15,2) NOT NULL,
  txn_id       BIGINT UNSIGNED NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ad_period (asset_id, period_year, period_month),
  CONSTRAINT fk_ad_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_txn   FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_meter_readings;
CREATE TABLE asset_meter_readings (
  asset_id     INT UNSIGNED NOT NULL,
  period_year  SMALLINT     NOT NULL,
  period_month TINYINT      NOT NULL,
  units        DECIMAL(15,2) NOT NULL,
  entered_by   INT UNSIGNED NULL,
  entered_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (asset_id, period_year, period_month),
  CONSTRAINT fk_amr_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixed asset maintenance and preventive maintenance
DROP TABLE IF EXISTS asset_pm_schedules;
CREATE TABLE asset_pm_schedules (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id         INT UNSIGNED NOT NULL,
  task_name        VARCHAR(160) NOT NULL,
  task_type        VARCHAR(30)  NOT NULL DEFAULT 'other',  -- registration, change_oil, cleaning, inspection, insurance, other
  interval_value   INT          NULL,                      -- repeat every N ... (NULL = one time only)
  interval_unit    VARCHAR(10)  NULL,                      -- days, weeks, months, years
  meter_interval   DECIMAL(15,2) NULL,                     -- or every N km / hours
  next_due_date    DATE         NULL,
  next_due_meter   DECIMAL(15,2) NULL,
  remind_days      INT          NOT NULL DEFAULT 14,       -- start reminding this many days before the due date
  last_done_date   DATE         NULL,
  last_done_meter  DECIMAL(15,2) NULL,
  assigned_to      VARCHAR(120) NULL,
  notes            VARCHAR(255) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  KEY ix_pm_asset (asset_id),
  KEY ix_pm_due (is_active, next_due_date),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance;
CREATE TABLE asset_maintenance (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maint_no            VARCHAR(30)  NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  maint_date          DATE         NOT NULL,
  maint_type          VARCHAR(20)  NOT NULL DEFAULT 'repair',     -- repair, preventive, inspection, upgrade
  status              VARCHAR(20)  NOT NULL DEFAULT 'completed',  -- in_progress, completed, void
  problem             VARCHAR(255) NULL,                          -- what was reported / why
  description         TEXT         NULL,                          -- maintenance description: the work done
  vendor_id           INT UNSIGNED NULL,                          -- supplier of the parts / service
  performed_by        VARCHAR(120) NULL,                          -- mechanic, technician or shop
  ref_no              VARCHAR(60)  NULL,                          -- supplier invoice / job order no.
  meter_reading       DECIMAL(15,2) NULL,
  downtime_days       DECIMAL(7,2) NULL,
  parts_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  labor_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total           DECIMAL(15,2) NOT NULL DEFAULT 0,
  total               DECIMAL(15,2) NOT NULL DEFAULT 0,
  with_vat            TINYINT(1)   NOT NULL DEFAULT 0,
  payment_mode        VARCHAR(20)  NOT NULL DEFAULT 'none',       -- none, bill, check, cash_voucher
  pay_account_id      INT UNSIGNED NULL,                          -- bank (check) or cash account (cash voucher)
  parts_account_id    INT UNSIGNED NULL,
  labor_account_id    INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,
  txn_id              BIGINT UNSIGNED NULL,                       -- the bill, check or cash voucher created for it
  schedule_id         INT UNSIGNED NULL,                          -- preventive maintenance task completed by this record
  next_service_date   DATE         NULL,
  notes               TEXT         NULL,
  void_reason         VARCHAR(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_maint_no (maint_no),
  KEY ix_maint_asset (asset_id, maint_date),
  KEY ix_maint_vendor (vendor_id),
  KEY ix_maint_txn (txn_id),
  KEY ix_maint_class (class_id),
  KEY ix_maint_schedule (schedule_id),
  CONSTRAINT fk_maint_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id),
  CONSTRAINT fk_maint_vendor   FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_maint_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_maint_txn      FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_maint_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_parts;
CREATE TABLE asset_maintenance_parts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  part_no         VARCHAR(60)  NULL,
  description     VARCHAR(200) NOT NULL,
  serial_no       VARCHAR(60)  NULL,
  model_no        VARCHAR(60)  NULL,
  size_spec       VARCHAR(60)  NULL,
  brand           VARCHAR(60)  NULL,
  qty             DECIMAL(15,4) NOT NULL DEFAULT 1,
  unit            VARCHAR(20)  NULL,
  unit_cost       DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  warranty_until  DATE         NULL,
  KEY ix_mparts_maint (maintenance_id),
  KEY ix_mparts_partno (part_no),
  CONSTRAINT fk_mparts_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_labor;
CREATE TABLE asset_maintenance_labor (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  description     VARCHAR(200) NOT NULL,
  worker          VARCHAR(120) NULL,
  charge_type     VARCHAR(10)  NOT NULL DEFAULT 'hourly',   -- hourly (man-hours x rate) or bulk (one charge)
  hours           DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate            DECIMAL(15,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  KEY ix_mlabor_maint (maintenance_id),
  CONSTRAINT fk_mlabor_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_maintenance_photos;
CREATE TABLE asset_maintenance_photos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  maintenance_id  INT UNSIGNED NOT NULL,
  file_name       VARCHAR(120) NOT NULL,      -- name inside storage/maintenance/
  original_name   VARCHAR(190) NULL,
  mime_type       VARCHAR(60)  NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  caption         VARCHAR(190) NULL,
  uploaded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mphotos_maint (maintenance_id),
  CONSTRAINT fk_mphotos_maint FOREIGN KEY (maintenance_id) REFERENCES asset_maintenance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS asset_pm_log;
CREATE TABLE asset_pm_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  schedule_id     INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,
  task_name       VARCHAR(160) NOT NULL,
  due_date        DATE         NULL,
  done_date       DATE         NOT NULL,
  done_meter      DECIMAL(15,2) NULL,
  maintenance_id  INT UNSIGNED NULL,
  remarks         VARCHAR(255) NULL,
  done_by         INT UNSIGNED NULL,          -- the user who acknowledged that the task is complete
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pmlog_schedule (schedule_id),
  KEY ix_pmlog_asset (asset_id, done_date),
  CONSTRAINT fk_pmlog_schedule FOREIGN KEY (schedule_id) REFERENCES asset_pm_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_pmlog_asset    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  POINT OF SALE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS pos_terminals;
CREATE TABLE pos_terminals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  site_id         INT UNSIGNED NOT NULL,
  cash_account_id INT UNSIGNED NOT NULL,
  receipt_prefix  VARCHAR(10)  NOT NULL DEFAULT 'OR',
  next_receipt_no INT UNSIGNED NOT NULL DEFAULT 1,
  next_z_no       INT UNSIGNED NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  class_id        INT UNSIGNED NULL,                      -- default class of sales made at this terminal
  UNIQUE KEY uq_term_code (code),
  CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_term_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_term_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_shifts;
CREATE TABLE pos_shifts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  terminal_id    INT UNSIGNED NOT NULL,
  cashier_id     INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  opened_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(15,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(15,2) NULL,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  deposit_txn_id BIGINT UNSIGNED NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  corrected_by   INT UNSIGNED NULL,          -- who last corrected the closing entries (float, drops, count)
  corrected_at   DATETIME     NULL,
  KEY ix_shift_term (terminal_id, status),
  CONSTRAINT fk_shift_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_tenders;
CREATE TABLE pos_tenders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id            BIGINT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,     -- net amount applied to the sale (after change)
  reference         VARCHAR(60)  NULL,
  KEY ix_pt_txn (txn_id),
  CONSTRAINT fk_pt_txn FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_pm  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_cash_drops;
CREATE TABLE pos_cash_drops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id    INT UNSIGNED NOT NULL,
  amount      DECIMAL(15,2) NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS pos_readings;
CREATE TABLE pos_readings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reading_type   ENUM('X','Z') NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  shift_id       INT UNSIGNED NOT NULL,
  z_no           INT UNSIGNED NULL,
  business_date  DATE         NOT NULL,
  first_receipt  VARCHAR(30)  NULL,
  last_receipt   VARCHAR(30)  NULL,
  sales_count    INT          NOT NULL DEFAULT 0,
  gross_sales    DECIMAL(15,2) NOT NULL DEFAULT 0,
  discounts      DECIMAL(15,2) NOT NULL DEFAULT 0,
  returns        DECIMAL(15,2) NOT NULL DEFAULT 0,
  voids          DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(15,2) NOT NULL DEFAULT 0,
  tenders_json   TEXT         NULL,
  cash_drops     DECIMAL(15,2) NOT NULL DEFAULT 0,
  expected_cash  DECIMAL(15,2) NOT NULL DEFAULT 0,
  counted_cash   DECIMAL(15,2) NULL,
  over_short     DECIMAL(15,2) NULL,
  created_by     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_term  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id),
  CONSTRAINT fk_pr_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time approval codes a supervisor generates for a cashier or clerk (used once, then expired)
DROP TABLE IF EXISTS approval_codes;
CREATE TABLE approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,          -- the user it is meant for (NULL: any user)
  page_key      VARCHAR(60)  NULL,          -- the page it may approve on (NULL: any page the issuer may approve on)
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',   -- open / used / expired / cancelled
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at),
  CONSTRAINT fk_apc_issuer FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BANKING
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS bank_reconciliations;
CREATE TABLE bank_reconciliations (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  statement_date     DATE         NOT NULL,
  beginning_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
  ending_balance     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cleared_balance    DECIMAL(15,2) NOT NULL DEFAULT 0,
  difference         DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_charge_txn_id BIGINT UNSIGNED NULL,
  interest_txn_id    BIGINT UNSIGNED NULL,
  service_charge     DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_date       DATE         NULL,
  service_account_id INT UNSIGNED NULL,
  interest_earned    DECIMAL(15,2) NOT NULL DEFAULT 0,
  interest_date      DATE         NULL,
  interest_account_id INT UNSIGNED NULL,
  class_id           INT UNSIGNED NULL,
  status             ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by       INT UNSIGNED NULL,
  completed_at       DATETIME     NULL,
  KEY ix_br_account (account_id, statement_date),
  CONSTRAINT fk_br_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the items cleared in each reconciliation (used by the reports)
DROP TABLE IF EXISTS reconciliation_items;
CREATE TABLE reconciliation_items (
  reconciliation_id INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,   -- + deposit / - payment
  cleared           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (reconciliation_id, txn_id),
  CONSTRAINT fk_ri_rec FOREIGN KEY (reconciliation_id) REFERENCES bank_reconciliations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleared transactions changed after reconciliation
DROP TABLE IF EXISTS reconciliation_discrepancies;
CREATE TABLE reconciliation_discrepancies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reconciliation_id INT UNSIGNED NULL,
  account_id        INT UNSIGNED NOT NULL,
  txn_id            BIGINT UNSIGNED NOT NULL,
  txn_ref           VARCHAR(80)  NULL,
  change_type       VARCHAR(20)  NOT NULL,   -- amount changed, voided, deleted, uncleared
  cleared_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  new_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
  changed_by        INT UNSIGNED NULL,
  changed_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rd_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SECURITY - site and terminal assignments
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS role_sites;
CREATE TABLE role_sites (
  role_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, site_id),
  CONSTRAINT fk_rs_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_sites;
CREATE TABLE user_sites (
  user_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, site_id),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_terminals;
CREATE TABLE user_terminals (
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, terminal_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_term FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Memorized transactions, budgets and forecasts, report layouts,
--  CSV imports and database backups
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS memorized_txns;
CREATE TABLE memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budgets;
CREATE TABLE budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS budget_lines;
CREATE TABLE budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS report_layouts;
CREATE TABLE report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS import_batches;
CREATE TABLE import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS backup_log;
CREATE TABLE backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  SHIPPING OPERATIONS (G-SMaRT Shipping System)
-- =====================================================================

-- ---------------------------------------------------------------------
--  MARITIME MASTERS: ports, routes, vessels, accommodation, certificates
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_ports;
CREATE TABLE shp_ports (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  city           VARCHAR(80)  NULL,
  province       VARCHAR(80)  NULL,
  region         VARCHAR(40)  NULL,
  authority      VARCHAR(80)  NULL,                    -- PPA, CPA, private port operator
  berths         VARCHAR(255) NULL,                    -- berth / jetty names, comma separated
  fee_vendor_id  INT UNSIGNED NULL,                    -- the authority paid when terminal fees are remitted
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_ports_code (code),
  CONSTRAINT fk_shp_ports_vendor FOREIGN KEY (fee_vendor_id) REFERENCES vendors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_offices;
CREATE TABLE shp_offices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(12)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  port_id         INT UNSIGNED NULL,
  office_type     VARCHAR(20)  NOT NULL DEFAULT 'ticket_office',   -- ticket_office, terminal_counter, agency, online
  address         VARCHAR(255) NULL,
  cash_account_id INT UNSIGNED NULL,                    -- cash on hand of the counter (blank = Undeposited Funds)
  class_id        INT UNSIGNED NULL,                    -- accounting class for sales made here (blank = the vessel's)
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_offices_code (code),
  CONSTRAINT fk_shp_offices_port FOREIGN KEY (port_id) REFERENCES shp_ports(id),
  CONSTRAINT fk_shp_offices_cash FOREIGN KEY (cash_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_shp_offices_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_user_offices;
CREATE TABLE shp_user_offices (
  user_id   INT UNSIGNED NOT NULL,
  office_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, office_id),
  CONSTRAINT fk_shp_uo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_uo_office FOREIGN KEY (office_id) REFERENCES shp_offices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_routes;
CREATE TABLE shp_routes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  origin_port_id  INT UNSIGNED NOT NULL,
  dest_port_id    INT UNSIGNED NOT NULL,
  distance_nm     DECIMAL(8,2) NOT NULL DEFAULT 0,
  transit_minutes INT          NOT NULL DEFAULT 0,
  service_type    VARCHAR(20)  NOT NULL DEFAULT 'ropax',       -- ropax, passenger, fastcraft, roro, cargo
  sailing_note    VARCHAR(255) NULL,                           -- frequency, restrictions, planned closures
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_routes_code (code),
  KEY ix_shp_routes_pair (origin_port_id, dest_port_id),
  CONSTRAINT fk_shp_routes_origin FOREIGN KEY (origin_port_id) REFERENCES shp_ports(id),
  CONSTRAINT fk_shp_routes_dest FOREIGN KEY (dest_port_id) REFERENCES shp_ports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_route_stops;
CREATE TABLE shp_route_stops (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id       INT UNSIGNED NOT NULL,
  seq            INT          NOT NULL DEFAULT 1,
  port_id        INT UNSIGNED NOT NULL,
  arrive_offset  INT          NOT NULL DEFAULT 0,     -- minutes after departure from the origin
  depart_offset  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_shp_rs (route_id, seq),
  CONSTRAINT fk_shp_rs_route FOREIGN KEY (route_id) REFERENCES shp_routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_rs_port FOREIGN KEY (port_id) REFERENCES shp_ports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_vessels;
CREATE TABLE shp_vessels (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(12)  NOT NULL,
  name                VARCHAR(120) NOT NULL,
  vessel_type         VARCHAR(20)  NOT NULL DEFAULT 'ropax',  -- ropax, passenger, fastcraft, roro, cargo
  official_no         VARCHAR(40)  NULL,
  imo_no              VARCHAR(20)  NULL,
  call_sign           VARCHAR(20)  NULL,
  classification      VARCHAR(80)  NULL,                      -- class society / MARINA classification
  owner_operator      VARCHAR(120) NULL,
  year_built          SMALLINT     NULL,
  gross_tonnage       DECIMAL(10,2) NOT NULL DEFAULT 0,
  net_tonnage         DECIMAL(10,2) NOT NULL DEFAULT 0,
  deadweight_t        DECIMAL(10,2) NOT NULL DEFAULT 0,
  length_m            DECIMAL(8,2) NOT NULL DEFAULT 0,
  breadth_m           DECIMAL(8,2) NOT NULL DEFAULT 0,
  pax_capacity        INT          NOT NULL DEFAULT 0,        -- approved passenger limit (all classes)
  crew_complement     INT          NOT NULL DEFAULT 0,
  lane_meters         DECIMAL(8,2) NOT NULL DEFAULT 0,        -- vehicle deck lane meters
  vehicle_lanes       INT          NOT NULL DEFAULT 0,
  max_vehicle_weight_t DECIMAL(8,2) NOT NULL DEFAULT 0,
  max_vehicle_length_m DECIMAL(8,2) NOT NULL DEFAULT 0,
  cargo_capacity_t    DECIMAL(10,2) NOT NULL DEFAULT 0,
  cargo_capacity_cbm  DECIMAL(10,2) NOT NULL DEFAULT 0,
  service_speed_kn    DECIMAL(6,2) NOT NULL DEFAULT 0,
  fuel_type           VARCHAR(40)  NULL,
  fuel_item_id        INT UNSIGNED NULL,                      -- the inventory item burnt (liters)
  fuel_tank_l         DECIMAL(12,2) NOT NULL DEFAULT 0,
  fuel_rate_lph       DECIMAL(10,2) NOT NULL DEFAULT 0,       -- planned consumption, liters per sailing hour
  home_port_id        INT UNSIGNED NULL,
  class_id            INT UNSIGNED NULL,                      -- accounting class: every posting for the vessel carries it
  site_id             INT UNSIGNED NULL,                      -- inventory site: the vessel's tanks and stores
  fixed_asset_id      INT UNSIGNED NULL,
  status              VARCHAR(20)  NOT NULL DEFAULT 'active', -- active, drydock, laid_up, retired
  notes               VARCHAR(255) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_vessels_code (code),
  CONSTRAINT fk_shp_vessels_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
  CONSTRAINT fk_shp_vessels_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_shp_vessels_fuel FOREIGN KEY (fuel_item_id) REFERENCES items(id),
  CONSTRAINT fk_shp_vessels_home FOREIGN KEY (home_port_id) REFERENCES shp_ports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_accom_classes;
CREATE TABLE shp_accom_classes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id     INT UNSIGNED NOT NULL,
  code          VARCHAR(10)  NOT NULL,                 -- ECO, TOU, BUS, CAB, OPEN: matched to the fare matrix
  name          VARCHAR(60)  NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'seat',  -- seat, bunk, cabin, open
  deck          VARCHAR(30)  NULL,
  capacity      INT          NOT NULL DEFAULT 0,
  seat_rows     INT          NOT NULL DEFAULT 0,
  seat_cols     INT          NOT NULL DEFAULT 0,
  priority_seats INT         NOT NULL DEFAULT 0,       -- accessible / priority seats held for seniors, PWD and pregnant women
  reserved_seating TINYINT(1) NOT NULL DEFAULT 1,      -- 0 = open seating (capacity only)
  sort_order    INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_shp_ac (vessel_id, code),
  CONSTRAINT fk_shp_ac_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_seats;
CREATE TABLE shp_seats (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id      INT UNSIGNED NOT NULL,
  accom_class_id INT UNSIGNED NOT NULL,
  seat_no        VARCHAR(10)  NOT NULL,
  seat_row       INT          NOT NULL DEFAULT 0,
  seat_col       INT          NOT NULL DEFAULT 0,
  is_priority    TINYINT(1)   NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_shp_seats (vessel_id, seat_no),
  KEY ix_shp_seats_class (accom_class_id),
  CONSTRAINT fk_shp_seats_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_seats_class FOREIGN KEY (accom_class_id) REFERENCES shp_accom_classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_certificates;
CREATE TABLE shp_certificates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id   INT UNSIGNED NOT NULL,
  cert_type   VARCHAR(80)  NOT NULL,
  cert_no     VARCHAR(60)  NULL,
  issuer      VARCHAR(80)  NULL,
  issued_on   DATE         NULL,
  expires_on  DATE         NULL,
  is_critical TINYINT(1)   NOT NULL DEFAULT 0,        -- expired critical certificate = no-sail hold
  alert_days  INT          NOT NULL DEFAULT 30,
  file_path   VARCHAR(255) NULL,
  owner_user_id INT UNSIGNED NULL,
  notes       VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_cert_exp (expires_on),
  CONSTRAINT fk_shp_cert_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FARES, PASSENGER CATEGORIES, FEES, VEHICLE AND CARGO RATES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_fare_categories;
CREATE TABLE shp_fare_categories (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(10)  NOT NULL,
  name          VARCHAR(60)  NOT NULL,
  discount_pct  DECIMAL(6,2) NOT NULL DEFAULT 0,
  vat_exempt    TINYINT(1)   NOT NULL DEFAULT 0,      -- the fare is taken net of VAT before the discount
  is_statutory  TINYINT(1)   NOT NULL DEFAULT 0,      -- privilege given by law: never stacked with a promo
  requires_id   TINYINT(1)   NOT NULL DEFAULT 0,
  id_label      VARCHAR(60)  NULL,
  legal_basis   VARCHAR(120) NULL,
  min_age       INT          NULL,
  max_age       INT          NULL,
  fee_exempt    TINYINT(1)   NOT NULL DEFAULT 0,      -- terminal fee and insurance waived (infants)
  effective_from DATE        NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_shp_fc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_fares;
CREATE TABLE shp_fares (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id       INT UNSIGNED NOT NULL,
  accom_code     VARCHAR(10)  NOT NULL,
  base_fare      DECIMAL(12,2) NOT NULL DEFAULT 0,     -- regular adult fare, VAT included
  peak_fare      DECIMAL(12,2) NULL,                   -- used on the peak dates of shp_peak_dates
  fuel_surcharge DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_code_id    INT UNSIGNED NULL,
  effective_from DATE         NOT NULL,
  effective_to   DATE         NULL,
  approved_by    INT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_fares_lookup (route_id, accom_code, effective_from),
  CONSTRAINT fk_shp_fares_route FOREIGN KEY (route_id) REFERENCES shp_routes(id),
  CONSTRAINT fk_shp_fares_tax FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_peak_dates;
CREATE TABLE shp_peak_dates (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  date_from DATE         NOT NULL,
  date_to   DATE         NOT NULL,
  KEY ix_shp_peak (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_fees;
CREATE TABLE shp_fees (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(12)  NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  fee_type            VARCHAR(20)  NOT NULL DEFAULT 'terminal',   -- terminal, insurance, security, baggage, service, environmental
  applies_to          VARCHAR(12)  NOT NULL DEFAULT 'passenger',  -- passenger, vehicle, cargo
  calc                VARCHAR(12)  NOT NULL DEFAULT 'per_pax',    -- per_pax, per_ticket, per_vehicle, per_waybill
  amount              DECIMAL(12,2) NOT NULL DEFAULT 0,
  ownership           VARCHAR(10)  NOT NULL DEFAULT 'agent',      -- agent = collected for a third party (liability), principal = company revenue
  liability_account_id INT UNSIGNED NULL,
  revenue_account_id  INT UNSIGNED NULL,
  vendor_id           INT UNSIGNED NULL,                          -- the third party it is remitted to
  port_id             INT UNSIGNED NULL,                          -- charged only at this origin port (blank = all)
  exempt_categories   VARCHAR(120) NULL,                          -- fare category codes not charged (comma separated)
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_shp_fees_code (code),
  CONSTRAINT fk_shp_fees_liab FOREIGN KEY (liability_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_shp_fees_rev FOREIGN KEY (revenue_account_id) REFERENCES accounts(id),
  CONSTRAINT fk_shp_fees_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
  CONSTRAINT fk_shp_fees_port FOREIGN KEY (port_id) REFERENCES shp_ports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_promos;
CREATE TABLE shp_promos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(20)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  discount_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  route_id     INT UNSIGNED NULL,
  valid_from   DATE         NULL,
  valid_to     DATE         NULL,
  max_uses     INT          NULL,
  used_count   INT          NOT NULL DEFAULT 0,
  max_per_booking INT       NOT NULL DEFAULT 10,       -- anti-scalping limit
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_shp_promo_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_vehicle_classes;
CREATE TABLE shp_vehicle_classes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  max_length_m  DECIMAL(6,2) NOT NULL DEFAULT 0,
  max_weight_t  DECIMAL(8,2) NOT NULL DEFAULT 0,
  lane_m        DECIMAL(6,2) NOT NULL DEFAULT 0,     -- lane meters taken on the deck
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_shp_vc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_vehicle_rates;
CREATE TABLE shp_vehicle_rates (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id         INT UNSIGNED NOT NULL,
  vehicle_class_id INT UNSIGNED NOT NULL,
  rate             DECIMAL(12,2) NOT NULL DEFAULT 0,  -- VAT included
  includes_driver  TINYINT(1)   NOT NULL DEFAULT 0,
  effective_from   DATE         NOT NULL,
  effective_to     DATE         NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_vr (route_id, vehicle_class_id, effective_from),
  CONSTRAINT fk_shp_vr_route FOREIGN KEY (route_id) REFERENCES shp_routes(id),
  CONSTRAINT fk_shp_vr_class FOREIGN KEY (vehicle_class_id) REFERENCES shp_vehicle_classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_cargo_tariffs;
CREATE TABLE shp_cargo_tariffs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id        INT UNSIGNED NULL,                  -- blank = every route
  commodity_class VARCHAR(20)  NOT NULL DEFAULT 'general',   -- general, perishable, dangerous, valuable, livestock
  rate_per_kg     DECIMAL(12,4) NOT NULL DEFAULT 0,
  rate_per_cbm    DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_charge      DECIMAL(12,2) NOT NULL DEFAULT 0,
  handling_fee    DECIMAL(12,2) NOT NULL DEFAULT 0,
  insurance_pct   DECIMAL(6,3)  NOT NULL DEFAULT 0,   -- of the declared value
  effective_from  DATE         NOT NULL,
  effective_to    DATE         NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_ct (route_id, commodity_class, effective_from),
  CONSTRAINT fk_shp_ct_route FOREIGN KEY (route_id) REFERENCES shp_routes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CREW
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_crew;
CREATE TABLE shp_crew (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  crew_no         VARCHAR(20)  NOT NULL,
  last_name       VARCHAR(60)  NOT NULL,
  first_name      VARCHAR(60)  NOT NULL,
  rank_title      VARCHAR(60)  NOT NULL,              -- Master, Chief Mate, Chief Engineer, AB, Oiler, Purser ...
  department      VARCHAR(12)  NOT NULL DEFAULT 'deck',   -- deck, engine, hotel
  vessel_id       INT UNSIGNED NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active', -- active, on_leave, inactive
  mobile          VARCHAR(30)  NULL,
  seafarer_id     VARCHAR(40)  NULL,                  -- SIRB / seaman's book (restricted)
  license_no      VARCHAR(40)  NULL,
  daily_allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
  hire_date       DATE         NULL,
  user_id         INT UNSIGNED NULL,                  -- the crew member's own log-in, when they have one
  other_name_id   INT UNSIGNED NULL,                  -- Employees & Other Names (allowances, advances)
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_crew_no (crew_no),
  CONSTRAINT fk_shp_crew_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id) ON DELETE SET NULL,
  CONSTRAINT fk_shp_crew_other FOREIGN KEY (other_name_id) REFERENCES other_names(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_crew_certs;
CREATE TABLE shp_crew_certs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  crew_id    INT UNSIGNED NOT NULL,
  cert_type  VARCHAR(80)  NOT NULL,                   -- STCW BST, COC, medical fitness, SSAT ...
  cert_no    VARCHAR(60)  NULL,
  issued_on  DATE         NULL,
  expires_on DATE         NULL,
  is_required TINYINT(1)  NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_cc_exp (expires_on),
  CONSTRAINT fk_shp_cc_crew FOREIGN KEY (crew_id) REFERENCES shp_crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  VOYAGES
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_voyages;
CREATE TABLE shp_voyages (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_no          VARCHAR(30)  NOT NULL,           -- voyage number (VNS)
  route_id           INT UNSIGNED NOT NULL,
  vessel_id          INT UNSIGNED NOT NULL,
  voyage_date        DATE         NOT NULL,
  etd                DATETIME     NOT NULL,
  eta                DATETIME     NOT NULL,
  atd                DATETIME     NULL,
  ata                DATETIME     NULL,
  status             VARCHAR(12)  NOT NULL DEFAULT 'draft',
  -- draft, published, on_sale, boarding, ready, departed, arrived, completed, cancelled
  booking_open       DATETIME     NULL,
  booking_close      DATETIME     NULL,
  boarding_open_min  INT          NOT NULL DEFAULT 60,   -- boarding opens this many minutes before ETD
  pax_quota          INT          NULL,                  -- blank = the vessel's passenger capacity
  vehicle_quota_lm   DECIMAL(8,2) NULL,
  cargo_quota_t      DECIMAL(10,2) NULL,
  berth              VARCHAR(40)  NULL,
  delay_minutes      INT          NOT NULL DEFAULT 0,
  delay_cause        VARCHAR(160) NULL,
  cancel_reason      VARCHAR(255) NULL,
  cancel_authority   VARCHAR(120) NULL,
  weather_ack        TINYINT(1)   NOT NULL DEFAULT 0,
  weather_note       VARCHAR(160) NULL,
  clearance_ref      VARCHAR(60)  NULL,                  -- PCG / port clearance reference typed by the officer
  distance_nm        DECIMAL(8,2) NULL,
  sailing_hours      DECIMAL(8,2) NULL,
  fuel_rob_departure DECIMAL(12,2) NULL,
  fuel_rob_arrival   DECIMAL(12,2) NULL,
  fuel_planned_l     DECIMAL(12,2) NULL,
  report_text        TEXT         NULL,
  report_signed_by   INT UNSIGNED NULL,
  report_signed_at   DATETIME     NULL,
  released_by        INT UNSIGNED NULL,
  released_at        DATETIME     NULL,
  override_by        INT UNSIGNED NULL,
  override_reason    VARCHAR(255) NULL,
  revenue_txn_id     BIGINT UNSIGNED NULL,               -- revenue recognition journal
  version            INT          NOT NULL DEFAULT 1,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_shp_voy_no (voyage_no),
  KEY ix_shp_voy_date (voyage_date, route_id),
  KEY ix_shp_voy_vessel (vessel_id, etd),
  KEY ix_shp_voy_status (status),
  CONSTRAINT fk_shp_voy_route FOREIGN KEY (route_id) REFERENCES shp_routes(id),
  CONSTRAINT fk_shp_voy_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_voyage_events;
CREATE TABLE shp_voyage_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id   INT UNSIGNED NOT NULL,
  event_type  VARCHAR(20)  NOT NULL,                  -- status, delay, port_call, incident, note, hold, override, notice
  from_status VARCHAR(12)  NULL,
  to_status   VARCHAR(12)  NULL,
  details     VARCHAR(500) NULL,
  user_id     INT UNSIGNED NULL,
  event_at    DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_ve_voy (voyage_id, id),
  CONSTRAINT fk_shp_ve_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_voyage_checklist;
CREATE TABLE shp_voyage_checklist (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id  INT UNSIGNED NOT NULL,
  item_key   VARCHAR(30)  NOT NULL,
  label      VARCHAR(160) NOT NULL,
  is_done    TINYINT(1)   NOT NULL DEFAULT 0,
  done_by    INT UNSIGNED NULL,
  done_at    DATETIME     NULL,
  notes      VARCHAR(255) NULL,
  UNIQUE KEY uq_shp_vc (voyage_id, item_key),
  CONSTRAINT fk_shp_vc_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_voyage_crew;
CREATE TABLE shp_voyage_crew (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id  INT UNSIGNED NOT NULL,
  crew_id    INT UNSIGNED NOT NULL,
  position   VARCHAR(60)  NULL,
  signed_on  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_vcrew (voyage_id, crew_id),
  CONSTRAINT fk_shp_vcrew_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_vcrew_crew FOREIGN KEY (crew_id) REFERENCES shp_crew(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_holds;
CREATE TABLE shp_holds (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id        INT UNSIGNED NOT NULL,
  voyage_id        INT UNSIGNED NULL,
  hold_type        VARCHAR(12)  NOT NULL DEFAULT 'no_sail',    -- no_sail, restricted
  source_type      VARCHAR(20)  NOT NULL DEFAULT 'manual',     -- defect, certificate, manual
  source_id        INT UNSIGNED NULL,
  reason           VARCHAR(255) NOT NULL,
  status           VARCHAR(10)  NOT NULL DEFAULT 'active',     -- active, released
  placed_by        INT UNSIGNED NULL,
  placed_at        DATETIME     NULL,
  technical_release_by INT UNSIGNED NULL,                      -- engineer who signed the inspection
  technical_release_at DATETIME NULL,
  released_by      INT UNSIGNED NULL,                          -- management release (another person)
  released_at      DATETIME     NULL,
  release_evidence VARCHAR(500) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_holds (vessel_id, status),
  CONSTRAINT fk_shp_holds_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CASHIER SHIFTS, BOOKINGS, TICKETS, PAYMENTS AND REFUNDS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_cashier_shifts;
CREATE TABLE shp_cashier_shifts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_no        VARCHAR(20)  NOT NULL,
  office_id       INT UNSIGNED NOT NULL,
  cashier_id      INT UNSIGNED NOT NULL,
  opened_at       DATETIME     NOT NULL,
  opening_float   DECIMAL(12,2) NOT NULL DEFAULT 0,
  closed_at       DATETIME     NULL,
  expected_cash   DECIMAL(12,2) NOT NULL DEFAULT 0,
  counted_cash    DECIMAL(12,2) NULL,
  over_short      DECIMAL(12,2) NOT NULL DEFAULT 0,
  non_cash_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  refunds_cash    DECIMAL(12,2) NOT NULL DEFAULT 0,
  void_count      INT          NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'open',    -- open, closed, reviewed
  reviewed_by     INT UNSIGNED NULL,
  reviewed_at     DATETIME     NULL,
  over_short_txn_id BIGINT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_shift_no (shift_no),
  KEY ix_shp_shift_cashier (cashier_id, status),
  CONSTRAINT fk_shp_shift_office FOREIGN KEY (office_id) REFERENCES shp_offices(id),
  CONSTRAINT fk_shp_shift_user FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_portal_users;
CREATE TABLE shp_portal_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  mobile        VARCHAR(30)  NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'active',
  failed_attempts INT        NOT NULL DEFAULT 0,
  locked_until  DATETIME     NULL,
  last_login_at DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_pu_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_bookings;
CREATE TABLE shp_bookings (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_ref      VARCHAR(12)  NOT NULL,
  channel          VARCHAR(12)  NOT NULL DEFAULT 'counter',  -- counter, portal, agent, corporate, phone
  office_id        INT UNSIGNED NULL,
  customer_id      INT UNSIGNED NULL,                        -- accounting customer billed / receipted
  agent_customer_id INT UNSIGNED NULL,                       -- travel agent that sold it
  portal_user_id   INT UNSIGNED NULL,
  contact_name     VARCHAR(120) NOT NULL,
  contact_email    VARCHAR(160) NULL,
  contact_phone    VARCHAR(30)  NULL,
  status           VARCHAR(12)  NOT NULL DEFAULT 'held',     -- held, confirmed, cancelled, expired, completed
  hold_expires_at  DATETIME     NULL,
  promo_id         INT UNSIGNED NULL,
  total_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  refunded_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  confirmed_at     DATETIME     NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_shp_bk_ref (booking_ref),
  KEY ix_shp_bk_status (status, hold_expires_at),
  KEY ix_shp_bk_portal (portal_user_id),
  CONSTRAINT fk_shp_bk_office FOREIGN KEY (office_id) REFERENCES shp_offices(id),
  CONSTRAINT fk_shp_bk_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_shp_bk_agent FOREIGN KEY (agent_customer_id) REFERENCES customers(id),
  CONSTRAINT fk_shp_bk_portal FOREIGN KEY (portal_user_id) REFERENCES shp_portal_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_tickets;
CREATE TABLE shp_tickets (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_no        VARCHAR(30)  NULL,                       -- given when issued (paid)
  booking_id       INT UNSIGNED NOT NULL,
  pax_seq          INT          NOT NULL DEFAULT 1,
  voyage_id        INT UNSIGNED NOT NULL,
  last_name        VARCHAR(60)  NOT NULL,
  first_name       VARCHAR(60)  NOT NULL,
  middle_name      VARCHAR(60)  NULL,
  birth_date       DATE         NULL,
  gender           CHAR(1)      NULL,
  nationality      VARCHAR(40)  NULL DEFAULT 'Filipino',
  contact_no       VARCHAR(30)  NULL,
  id_type          VARCHAR(40)  NULL,
  id_last4         VARCHAR(8)   NULL,                       -- only the last characters of the ID are kept
  emergency_name   VARCHAR(120) NULL,
  emergency_phone  VARCHAR(30)  NULL,
  special_assist   VARCHAR(120) NULL,
  category_id      INT UNSIGNED NOT NULL,
  discount_verified_by INT UNSIGNED NULL,
  accom_code       VARCHAR(10)  NOT NULL,
  seat_id          INT UNSIGNED NULL,
  is_driver        TINYINT(1)   NOT NULL DEFAULT 0,
  fare_id          INT UNSIGNED NULL,
  fare_gross       DECIMAL(12,2) NOT NULL DEFAULT 0,       -- regular fare before the privilege (VAT included)
  discount_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,       -- statutory / promo discount
  promo_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  fare_net         DECIMAL(12,2) NOT NULL DEFAULT 0,       -- fare revenue (VAT excluded)
  vat_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  surcharge_net    DECIMAL(12,2) NOT NULL DEFAULT 0,
  fees_total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0,
  status           VARCHAR(12)  NOT NULL DEFAULT 'held',
  -- held, issued, checked_in, boarded, no_show, cancelled, refunded, transferred, expired
  qr_sig           VARCHAR(40)  NULL,
  issued_at        DATETIME     NULL,
  checked_in_at    DATETIME     NULL,
  boarded_at       DATETIME     NULL,
  boarded_by       INT UNSIGNED NULL,
  transferred_from_id INT UNSIGNED NULL,
  revenue_done     TINYINT(1)   NOT NULL DEFAULT 0,        -- deferred revenue recognized
  manual_ticket_no VARCHAR(30)  NULL,                       -- digitized from a manual (outage) ticket
  version          INT          NOT NULL DEFAULT 1,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_shp_tk_no (ticket_no),
  UNIQUE KEY uq_shp_tk_bpv (booking_id, pax_seq, voyage_id),
  KEY ix_shp_tk_voy (voyage_id, status),
  KEY ix_shp_tk_name (last_name, first_name),
  CONSTRAINT fk_shp_tk_booking FOREIGN KEY (booking_id) REFERENCES shp_bookings(id),
  CONSTRAINT fk_shp_tk_voyage FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_tk_cat FOREIGN KEY (category_id) REFERENCES shp_fare_categories(id),
  CONSTRAINT fk_shp_tk_seat FOREIGN KEY (seat_id) REFERENCES shp_seats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one seat on one voyage belongs to one live ticket: the primary key refuses a second
DROP TABLE IF EXISTS shp_seat_allocations;
CREATE TABLE shp_seat_allocations (
  voyage_id  INT UNSIGNED NOT NULL,
  seat_id    INT UNSIGNED NOT NULL,
  ticket_id  INT UNSIGNED NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (voyage_id, seat_id),
  UNIQUE KEY uq_shp_sa_ticket (ticket_id),
  CONSTRAINT fk_shp_sa_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_sa_seat FOREIGN KEY (seat_id) REFERENCES shp_seats(id),
  CONSTRAINT fk_shp_sa_ticket FOREIGN KEY (ticket_id) REFERENCES shp_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_ticket_fees;
CREATE TABLE shp_ticket_fees (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NULL,
  vehicle_id INT UNSIGNED NULL,
  cargo_id   INT UNSIGNED NULL,
  fee_id     INT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  ownership  VARCHAR(10)  NOT NULL DEFAULT 'agent',
  account_id INT UNSIGNED NOT NULL,
  status     VARCHAR(10)  NOT NULL DEFAULT 'held',     -- held, collected, refunded, remitted, waived
  remit_txn_id BIGINT UNSIGNED NULL,
  KEY ix_shp_tf_ticket (ticket_id),
  KEY ix_shp_tf_fee (fee_id, status),
  CONSTRAINT fk_shp_tf_fee FOREIGN KEY (fee_id) REFERENCES shp_fees(id),
  CONSTRAINT fk_shp_tf_acct FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_vehicles;
CREATE TABLE shp_vehicles (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_no       VARCHAR(30)  NULL,                       -- vehicle pass number, given when paid
  booking_id       INT UNSIGNED NOT NULL,
  voyage_id        INT UNSIGNED NOT NULL,
  vehicle_class_id INT UNSIGNED NOT NULL,
  plate_no         VARCHAR(20)  NOT NULL,
  make_model       VARCHAR(80)  NULL,
  length_m         DECIMAL(6,2) NOT NULL DEFAULT 0,
  weight_t         DECIMAL(8,2) NOT NULL DEFAULT 0,
  axles            INT          NOT NULL DEFAULT 2,
  operator_name    VARCHAR(120) NULL,
  driver_ticket_id INT UNSIGNED NULL,                      -- the driver travels on this passenger ticket (counted once)
  declared_cargo   VARCHAR(255) NULL,
  hazardous        TINYINT(1)   NOT NULL DEFAULT 0,
  hazardous_desc   VARCHAR(160) NULL,
  restrictions_ack TINYINT(1)   NOT NULL DEFAULT 0,
  rate_id          INT UNSIGNED NULL,
  fare_gross       DECIMAL(12,2) NOT NULL DEFAULT 0,
  fare_net         DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  fees_total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0,
  status           VARCHAR(12)  NOT NULL DEFAULT 'held',   -- held, issued, checked_in, loaded, unloaded, no_show, cancelled, refunded
  qr_sig           VARCHAR(40)  NULL,
  deck             VARCHAR(20)  NULL,
  lane_no          VARCHAR(10)  NULL,
  lane_position    INT          NULL,
  loaded_at        DATETIME     NULL,
  unloaded_at      DATETIME     NULL,
  revenue_done     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_veh_no (vehicle_no),
  KEY ix_shp_veh_voy (voyage_id, status),
  KEY ix_shp_veh_plate (plate_no),
  CONSTRAINT fk_shp_veh_booking FOREIGN KEY (booking_id) REFERENCES shp_bookings(id),
  CONSTRAINT fk_shp_veh_voyage FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_veh_class FOREIGN KEY (vehicle_class_id) REFERENCES shp_vehicle_classes(id),
  CONSTRAINT fk_shp_veh_driver FOREIGN KEY (driver_ticket_id) REFERENCES shp_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_payments;
CREATE TABLE shp_payments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_no        VARCHAR(20)  NOT NULL,
  booking_id        INT UNSIGNED NULL,
  cargo_id          INT UNSIGNED NULL,
  channel           VARCHAR(16)  NOT NULL DEFAULT 'cash',   -- cash, card, ewallet, bank_transfer, corporate, agent, gateway
  payment_method_id INT UNSIGNED NULL,
  provider_ref      VARCHAR(80)  NULL,                       -- card approval / e-wallet / gateway reference
  idempotency_key   VARCHAR(64)  NULL,                       -- one business event, one payment (webhook replays)
  amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
  status            VARCHAR(12)  NOT NULL DEFAULT 'captured', -- pending, authorized, captured, settled, failed, reversed
  shift_id          INT UNSIGNED NULL,
  office_id         INT UNSIGNED NULL,
  txn_id            BIGINT UNSIGNED NULL,                    -- the accounting sales receipt / invoice / payment
  received_by       INT UNSIGNED NULL,
  received_at       DATETIME     NULL,
  notes             VARCHAR(255) NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_pay_no (payment_no),
  UNIQUE KEY uq_shp_pay_idem (idempotency_key),
  UNIQUE KEY uq_shp_pay_provider (channel, provider_ref),
  KEY ix_shp_pay_booking (booking_id),
  KEY ix_shp_pay_shift (shift_id),
  CONSTRAINT fk_shp_pay_booking FOREIGN KEY (booking_id) REFERENCES shp_bookings(id),
  CONSTRAINT fk_shp_pay_pm FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_shp_pay_shift FOREIGN KEY (shift_id) REFERENCES shp_cashier_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_refunds;
CREATE TABLE shp_refunds (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  refund_no        VARCHAR(20)  NOT NULL,
  booking_id       INT UNSIGNED NOT NULL,
  ticket_id        INT UNSIGNED NULL,
  vehicle_id       INT UNSIGNED NULL,
  refund_type      VARCHAR(20)  NOT NULL DEFAULT 'passenger_cancel', -- passenger_cancel, voyage_cancelled, overcharge, compensation
  reason           VARCHAR(255) NOT NULL,
  original_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  fee_retained     DECIMAL(12,2) NOT NULL DEFAULT 0,
  refund_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  method           VARCHAR(16)  NOT NULL DEFAULT 'cash',
  status           VARCHAR(10)  NOT NULL DEFAULT 'requested',   -- requested, approved, rejected, paid
  requested_by     INT UNSIGNED NULL,
  requested_at     DATETIME     NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  paid_by          INT UNSIGNED NULL,
  paid_at          DATETIME     NULL,
  shift_id         INT UNSIGNED NULL,
  credit_txn_id    BIGINT UNSIGNED NULL,                        -- credit memo (refund payable to the customer)
  fee_txn_id       BIGINT UNSIGNED NULL,                        -- journal: cancellation fee earned
  paid_txn_id      BIGINT UNSIGNED NULL,                        -- customer refund (money out)
  case_id          INT UNSIGNED NULL,
  notes            VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_ref_no (refund_no),
  KEY ix_shp_ref_status (status),
  CONSTRAINT fk_shp_ref_booking FOREIGN KEY (booking_id) REFERENCES shp_bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_boarding_scans;
CREATE TABLE shp_boarding_scans (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id   INT UNSIGNED NOT NULL,
  ticket_id   INT UNSIGNED NULL,
  vehicle_id  INT UNSIGNED NULL,
  scan_code   VARCHAR(120) NOT NULL,
  scan_type   VARCHAR(12)  NOT NULL DEFAULT 'board',   -- checkin, board, vehicle, unload
  result      VARCHAR(16)  NOT NULL,                   -- accepted, duplicate, wrong_voyage, cancelled, unpaid, invalid, not_open, conflict
  message     VARCHAR(255) NULL,
  device_id   VARCHAR(40)  NULL,
  operator_id INT UNSIGNED NULL,
  scanned_at  DATETIME     NOT NULL,                   -- time on the device
  is_offline  TINYINT(1)   NOT NULL DEFAULT 0,         -- scanned with no connection, sent later
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_scan_voy (voyage_id, result),
  KEY ix_shp_scan_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_manifests;
CREATE TABLE shp_manifests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id     INT UNSIGNED NOT NULL,
  version_no    INT          NOT NULL DEFAULT 1,
  status        VARCHAR(12)  NOT NULL DEFAULT 'draft',    -- draft, final, superseded
  pax_booked    INT          NOT NULL DEFAULT 0,
  pax_checked_in INT         NOT NULL DEFAULT 0,
  pax_boarded   INT          NOT NULL DEFAULT 0,
  crew_count    INT          NOT NULL DEFAULT 0,
  vehicle_count INT          NOT NULL DEFAULT 0,
  cargo_count   INT          NOT NULL DEFAULT 0,
  remarks       VARCHAR(255) NULL,
  generated_by  INT UNSIGNED NULL,
  generated_at  DATETIME     NULL,
  signed_by     INT UNSIGNED NULL,
  signed_at     DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_man (voyage_id, version_no),
  CONSTRAINT fk_shp_man_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_manifest_lines;
CREATE TABLE shp_manifest_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  manifest_id INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL,
  line_kind   VARCHAR(10)  NOT NULL DEFAULT 'pax',    -- pax, crew, vehicle
  ref_id      INT UNSIGNED NULL,
  full_name   VARCHAR(180) NOT NULL,
  age         INT          NULL,
  gender      CHAR(1)      NULL,
  nationality VARCHAR(40)  NULL,
  category    VARCHAR(60)  NULL,
  accom       VARCHAR(60)  NULL,
  seat_no     VARCHAR(10)  NULL,
  ref_no      VARCHAR(30)  NULL,
  status      VARCHAR(12)  NULL,
  KEY ix_shp_ml (manifest_id, line_no),
  CONSTRAINT fk_shp_ml_man FOREIGN KEY (manifest_id) REFERENCES shp_manifests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_manual_tickets;
CREATE TABLE shp_manual_tickets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id     INT UNSIGNED NOT NULL,
  series_no     VARCHAR(30)  NOT NULL,                  -- the pre-numbered paper ticket
  passenger_name VARCHAR(160) NOT NULL,
  category_id   INT UNSIGNED NULL,
  accom_code    VARCHAR(10)  NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  issued_by     INT UNSIGNED NULL,
  issued_at     DATETIME     NULL,
  status        VARCHAR(12)  NOT NULL DEFAULT 'recorded',  -- recorded, digitized, duplicate, void
  ticket_id     INT UNSIGNED NULL,                          -- the ticket it was digitized into
  duplicate_of  INT UNSIGNED NULL,
  incident_note VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_mt (voyage_id, series_no),
  CONSTRAINT fk_shp_mt_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  CARGO AND FREIGHT
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_cargo;
CREATE TABLE shp_cargo (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  waybill_no         VARCHAR(20)  NOT NULL,
  voyage_id          INT UNSIGNED NULL,
  route_id           INT UNSIGNED NOT NULL,
  shipper_customer_id INT UNSIGNED NULL,
  shipper_name       VARCHAR(120) NOT NULL,
  shipper_phone      VARCHAR(30)  NULL,
  consignee_name     VARCHAR(120) NOT NULL,
  consignee_phone    VARCHAR(30)  NULL,
  consignee_address  VARCHAR(255) NULL,
  commodity_class    VARCHAR(20)  NOT NULL DEFAULT 'general',
  description        VARCHAR(255) NOT NULL,
  packages           INT          NOT NULL DEFAULT 1,
  weight_kg          DECIMAL(12,2) NOT NULL DEFAULT 0,
  volume_cbm         DECIMAL(10,3) NOT NULL DEFAULT 0,
  chargeable_basis   VARCHAR(8)   NULL,                  -- kg, cbm, min
  declared_value     DECIMAL(14,2) NOT NULL DEFAULT 0,
  special_handling   VARCHAR(160) NULL,
  tariff_id          INT UNSIGNED NULL,
  freight_gross      DECIMAL(12,2) NOT NULL DEFAULT 0,  -- VAT included
  handling_fee       DECIMAL(12,2) NOT NULL DEFAULT 0,
  insurance_fee      DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
  freight_net        DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_terms      VARCHAR(10)  NOT NULL DEFAULT 'prepaid',  -- prepaid, credit
  status             VARCHAR(16)  NOT NULL DEFAULT 'quotation',
  -- quotation, booked, accepted, loaded, in_transit, landed, delivered, short_landed, damaged, claimed, closed, cancelled, rolled_over
  paid_txn_id        BIGINT UNSIGNED NULL,               -- sales receipt (prepaid)
  invoice_txn_id     BIGINT UNSIGNED NULL,               -- invoice (credit, billed after delivery)
  revenue_done       TINYINT(1)   NOT NULL DEFAULT 0,
  received_qty       INT          NULL,                  -- packages counted at delivery
  delivered_to       VARCHAR(120) NULL,
  delivered_at       DATETIME     NULL,
  pod_notes          VARCHAR(255) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL,
  UNIQUE KEY uq_shp_cargo_wb (waybill_no),
  KEY ix_shp_cargo_voy (voyage_id, status),
  CONSTRAINT fk_shp_cargo_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_cargo_route FOREIGN KEY (route_id) REFERENCES shp_routes(id),
  CONSTRAINT fk_shp_cargo_cust FOREIGN KEY (shipper_customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_cargo_items;
CREATE TABLE shp_cargo_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cargo_id       INT UNSIGNED NOT NULL,
  line_no        INT          NOT NULL DEFAULT 1,
  description    VARCHAR(160) NOT NULL,
  packages       INT          NOT NULL DEFAULT 1,
  packing        VARCHAR(30)  NULL,                    -- box, sack, pallet, crate, drum
  weight_kg      DECIMAL(12,2) NOT NULL DEFAULT 0,
  length_cm      DECIMAL(8,2) NOT NULL DEFAULT 0,
  width_cm       DECIMAL(8,2) NOT NULL DEFAULT 0,
  height_cm      DECIMAL(8,2) NOT NULL DEFAULT 0,
  volume_cbm     DECIMAL(10,3) NOT NULL DEFAULT 0,
  declared_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  marks          VARCHAR(80)  NULL,
  CONSTRAINT fk_shp_ci_cargo FOREIGN KEY (cargo_id) REFERENCES shp_cargo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_cargo_events;
CREATE TABLE shp_cargo_events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cargo_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(16)  NULL,
  to_status   VARCHAR(16)  NULL,
  details     VARCHAR(500) NULL,
  photo_path  VARCHAR(255) NULL,
  user_id     INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_ce (cargo_id, id),
  CONSTRAINT fk_shp_ce_cargo FOREIGN KEY (cargo_id) REFERENCES shp_cargo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FUEL, VOYAGE COSTS AND THE LINKS TO THE ACCOUNTING BOOKS
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_fuel_logs;
CREATE TABLE shp_fuel_logs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id       INT UNSIGNED NOT NULL,
  voyage_id       INT UNSIGNED NULL,
  log_type        VARCHAR(12)  NOT NULL,                -- bunkering, sounding, issue, adjustment
  log_at          DATETIME     NOT NULL,
  item_id         INT UNSIGNED NOT NULL,
  liters          DECIMAL(12,2) NOT NULL DEFAULT 0,     -- received (+) or issued (-)
  rob_book        DECIMAL(12,2) NULL,                   -- remaining on board by the books
  rob_sounding    DECIMAL(12,2) NULL,                   -- remaining on board measured
  variance_l      DECIMAL(12,2) NULL,
  unit_cost       DECIMAL(12,4) NOT NULL DEFAULT 0,
  amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
  vendor_id       INT UNSIGNED NULL,
  ref_no          VARCHAR(40)  NULL,
  txn_id          BIGINT UNSIGNED NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'posted', -- posted, pending (an adjustment waiting for approval), rejected
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_fuel (vessel_id, log_at),
  KEY ix_shp_fuel_voy (voyage_id),
  CONSTRAINT fk_shp_fuel_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_fuel_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_voyage_costs;
CREATE TABLE shp_voyage_costs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id    INT UNSIGNED NOT NULL,
  cost_type    VARCHAR(20)  NOT NULL,                  -- port_charges, pilotage, towing, crew_allowance, stores, water, commission, other
  description  VARCHAR(160) NOT NULL,
  vendor_id    INT UNSIGNED NULL,
  account_id   INT UNSIGNED NOT NULL,
  amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_code_id  INT UNSIGNED NULL,
  tax_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  pay_mode     VARCHAR(8)   NOT NULL DEFAULT 'bill',  -- bill, check
  bank_account_id INT UNSIGNED NULL,
  ref_no       VARCHAR(40)  NULL,
  txn_id       BIGINT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_vcost (voyage_id),
  CONSTRAINT fk_shp_vcost_voy FOREIGN KEY (voyage_id) REFERENCES shp_voyages(id),
  CONSTRAINT fk_shp_vcost_acct FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every accounting document a shipping event created, once per event (event_key is the idempotency key)
DROP TABLE IF EXISTS shp_postings;
CREATE TABLE shp_postings (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key     VARCHAR(120) NOT NULL,
  source_type   VARCHAR(20)  NOT NULL,     -- booking, ticket, refund, voyage, cargo, fuel, wo, cost, shift, fee_remit, case, drydock
  source_id     INT UNSIGNED NOT NULL,
  voyage_id     INT UNSIGNED NULL,
  vessel_id     INT UNSIGNED NULL,
  work_order_id INT UNSIGNED NULL,
  txn_id        BIGINT UNSIGNED NULL,
  amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
  memo          VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_post_event (event_key),
  KEY ix_shp_post_src (source_type, source_id),
  KEY ix_shp_post_voy (voyage_id),
  KEY ix_shp_post_vessel (vessel_id),
  KEY ix_shp_post_wo (work_order_id),
  KEY ix_shp_post_txn (txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  MAINTENANCE (CMMS): equipment, planned maintenance, defects, work orders, dry-docking
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_equipment;
CREATE TABLE shp_equipment (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id      INT UNSIGNED NOT NULL,
  parent_id      INT UNSIGNED NULL,
  code           VARCHAR(30)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  level          VARCHAR(12)  NOT NULL DEFAULT 'equipment',  -- system, subsystem, equipment, part
  maker          VARCHAR(80)  NULL,
  model          VARCHAR(80)  NULL,
  serial_no      VARCHAR(60)  NULL,
  installed_on   DATE         NULL,
  criticality    VARCHAR(10)  NOT NULL DEFAULT 'medium',     -- low, medium, high, critical
  running_hours  DECIMAL(12,1) NOT NULL DEFAULT 0,
  hours_read_at  DATETIME     NULL,
  warranty_until DATE         NULL,
  vendor_id      INT UNSIGNED NULL,
  manual_ref     VARCHAR(120) NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_eq (vessel_id, code),
  KEY ix_shp_eq_parent (parent_id),
  CONSTRAINT fk_shp_eq_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_eq_parent FOREIGN KEY (parent_id) REFERENCES shp_equipment(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_meter_readings;
CREATE TABLE shp_meter_readings (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  reading      DECIMAL(12,1) NOT NULL,
  read_at      DATETIME     NOT NULL,
  voyage_id    INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_mr (equipment_id, read_at),
  CONSTRAINT fk_shp_mr_eq FOREIGN KEY (equipment_id) REFERENCES shp_equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_pm_plans;
CREATE TABLE shp_pm_plans (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id       INT UNSIGNED NOT NULL,
  equipment_id    INT UNSIGNED NULL,
  name            VARCHAR(120) NOT NULL,
  basis           VARCHAR(10)  NOT NULL DEFAULT 'calendar',  -- calendar, hours, both, survey
  interval_days   INT          NULL,
  interval_hours  DECIMAL(10,1) NULL,
  lead_days       INT          NOT NULL DEFAULT 7,
  last_done_on    DATE         NULL,
  last_done_hours DECIMAL(12,1) NULL,
  next_due_on     DATE         NULL,
  next_due_hours  DECIMAL(12,1) NULL,
  checklist       TEXT         NULL,                         -- one step per line
  est_hours       DECIMAL(8,2) NOT NULL DEFAULT 0,
  is_regulatory   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  open_wo_id      INT UNSIGNED NULL,                         -- the work order raised for the current due date
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_pm_due (next_due_on),
  CONSTRAINT fk_shp_pm_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_pm_eq FOREIGN KEY (equipment_id) REFERENCES shp_equipment(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_defects;
CREATE TABLE shp_defects (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  defect_no       VARCHAR(20)  NOT NULL,
  vessel_id       INT UNSIGNED NOT NULL,
  equipment_id    INT UNSIGNED NULL,
  title           VARCHAR(160) NOT NULL,
  description     TEXT         NULL,
  severity        VARCHAR(10)  NOT NULL DEFAULT 'minor',     -- minor, major, critical
  safety_critical TINYINT(1)   NOT NULL DEFAULT 0,           -- designated critical: puts a no-sail hold on the vessel
  operating_impact VARCHAR(160) NULL,
  photo_path      VARCHAR(255) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'reported',  -- reported, triaged, work_order, resolved, deferred, closed, rejected
  reported_by     INT UNSIGNED NULL,
  reported_at     DATETIME     NOT NULL,
  triaged_by      INT UNSIGNED NULL,
  triaged_at      DATETIME     NULL,
  hold_id         INT UNSIGNED NULL,
  work_order_id   INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_def_no (defect_no),
  KEY ix_shp_def (vessel_id, status),
  CONSTRAINT fk_shp_def_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_def_eq FOREIGN KEY (equipment_id) REFERENCES shp_equipment(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_drydock;
CREATE TABLE shp_drydock (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dd_no           VARCHAR(20)  NOT NULL,
  vessel_id       INT UNSIGNED NOT NULL,
  title           VARCHAR(160) NOT NULL,
  yard_vendor_id  INT UNSIGNED NULL,
  survey_type     VARCHAR(60)  NULL,
  planned_start   DATE         NULL,
  planned_end     DATE         NULL,
  actual_start    DATE         NULL,
  actual_end      DATE         NULL,
  budget          DECIMAL(14,2) NOT NULL DEFAULT 0,
  quoted_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  capex_assessment VARCHAR(10) NOT NULL DEFAULT 'capex',    -- capex, opex, mixed (approved accounting policy)
  status          VARCHAR(12)  NOT NULL DEFAULT 'planned',  -- planned, in_progress, completed, cancelled
  progress_pct    DECIMAL(5,1) NOT NULL DEFAULT 0,
  notes           VARCHAR(500) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_dd_no (dd_no),
  CONSTRAINT fk_shp_dd_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_dd_vendor FOREIGN KEY (yard_vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_drydock_milestones;
CREATE TABLE shp_drydock_milestones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  drydock_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  due_date    DATE         NULL,
  amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  status      VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending, done, billed
  bill_txn_id BIGINT UNSIGNED NULL,
  done_at     DATETIME     NULL,
  CONSTRAINT fk_shp_ddm_dd FOREIGN KEY (drydock_id) REFERENCES shp_drydock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_work_orders;
CREATE TABLE shp_work_orders (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_no            VARCHAR(20)  NOT NULL,
  vessel_id        INT UNSIGNED NOT NULL,
  equipment_id     INT UNSIGNED NULL,
  defect_id        INT UNSIGNED NULL,
  pm_plan_id       INT UNSIGNED NULL,
  drydock_id       INT UNSIGNED NULL,
  wo_type          VARCHAR(12)  NOT NULL DEFAULT 'corrective',   -- corrective, preventive, survey, drydock, inspection
  title            VARCHAR(160) NOT NULL,
  description      TEXT         NULL,
  priority         VARCHAR(8)   NOT NULL DEFAULT 'normal',       -- low, normal, high, urgent
  safety_critical  TINYINT(1)   NOT NULL DEFAULT 0,
  status           VARCHAR(14)  NOT NULL DEFAULT 'reported',
  -- reported, triaged, approved, planned, waiting_parts, in_progress, inspection, completed, closed, deferred, cancelled
  planned_start    DATE         NULL,
  planned_end      DATE         NULL,
  actual_start     DATETIME     NULL,
  actual_end       DATETIME     NULL,
  downtime_hours   DECIMAL(8,2) NOT NULL DEFAULT 0,
  assigned_to      INT UNSIGNED NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME     NULL,
  executed_by      INT UNSIGNED NULL,
  inspected_by     INT UNSIGNED NULL,
  inspected_at     DATETIME     NULL,
  inspection_result VARCHAR(6)  NULL,                            -- pass, fail
  inspection_notes VARCHAR(500) NULL,
  accepted_by      INT UNSIGNED NULL,                            -- independent acceptance / release
  accepted_at      DATETIME     NULL,
  root_cause       VARCHAR(255) NULL,
  findings         VARCHAR(500) NULL,
  repair_procedure VARCHAR(500) NULL,
  reading_before   VARCHAR(80)  NULL,
  reading_after    VARCHAR(80)  NULL,
  deferral_reason  VARCHAR(255) NULL,
  deferral_until   DATE         NULL,
  deferral_approved_by INT UNSIGNED NULL,
  expense_mode     VARCHAR(6)   NOT NULL DEFAULT 'opex',         -- opex = repairs expense, capex = capital work in progress
  cost_labor       DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_parts       DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_services    DECIMAL(14,2) NOT NULL DEFAULT 0,
  checklist        TEXT         NULL,
  checklist_done   TEXT         NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL,
  UNIQUE KEY uq_shp_wo_no (wo_no),
  KEY ix_shp_wo (vessel_id, status),
  CONSTRAINT fk_shp_wo_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_wo_eq FOREIGN KEY (equipment_id) REFERENCES shp_equipment(id),
  CONSTRAINT fk_shp_wo_defect FOREIGN KEY (defect_id) REFERENCES shp_defects(id),
  CONSTRAINT fk_shp_wo_dd FOREIGN KEY (drydock_id) REFERENCES shp_drydock(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_wo_log;
CREATE TABLE shp_wo_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_id       INT UNSIGNED NOT NULL,
  from_status VARCHAR(14)  NULL,
  to_status   VARCHAR(14)  NULL,
  details     VARCHAR(500) NULL,
  user_id     INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_wol (wo_id, id),
  CONSTRAINT fk_shp_wol_wo FOREIGN KEY (wo_id) REFERENCES shp_work_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_wo_labor;
CREATE TABLE shp_wo_labor (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_id       INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  worker_name VARCHAR(120) NOT NULL,
  work_date   DATE         NOT NULL,
  hours       DECIMAL(8,2) NOT NULL DEFAULT 0,
  rate        DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_overtime TINYINT(1)   NOT NULL DEFAULT 0,
  notes       VARCHAR(255) NULL,
  txn_id      BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shp_wl_wo FOREIGN KEY (wo_id) REFERENCES shp_work_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_wo_parts;
CREATE TABLE shp_wo_parts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_id      INT UNSIGNED NOT NULL,
  item_id    INT UNSIGNED NOT NULL,
  site_id    INT UNSIGNED NOT NULL,
  qty        DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost  DECIMAL(12,4) NOT NULL DEFAULT 0,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  issued_by  INT UNSIGNED NULL,
  issued_at  DATETIME     NULL,
  txn_id     BIGINT UNSIGNED NULL,                     -- the inventory adjustment that took the parts out of stock
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shp_wp_wo FOREIGN KEY (wo_id) REFERENCES shp_work_orders(id),
  CONSTRAINT fk_shp_wp_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_shp_wp_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_wo_services;
CREATE TABLE shp_wo_services (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wo_id       INT UNSIGNED NOT NULL,
  vendor_id   INT UNSIGNED NOT NULL,
  description VARCHAR(160) NOT NULL,
  ref_no      VARCHAR(40)  NULL,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_code_id INT UNSIGNED NULL,
  tax_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  txn_id      BIGINT UNSIGNED NULL,                    -- the bill entered for the contractor
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_shp_ws_wo FOREIGN KEY (wo_id) REFERENCES shp_work_orders(id),
  CONSTRAINT fk_shp_ws_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  PURCHASE REQUISITIONS AND CANVASS (the purchase order itself is the accounting's)
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_requisitions;
CREATE TABLE shp_requisitions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pr_no           VARCHAR(20)  NOT NULL,
  requested_by    INT UNSIGNED NOT NULL,
  department      VARCHAR(40)  NULL,
  vessel_id       INT UNSIGNED NULL,
  wo_id           INT UNSIGNED NULL,
  site_id         INT UNSIGNED NULL,                    -- where the goods are received
  needed_by       DATE         NULL,
  priority        VARCHAR(10)  NOT NULL DEFAULT 'normal',  -- normal, urgent, emergency
  justification   VARCHAR(500) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'draft',
  -- draft, submitted, approved, rejected, canvass, ordered, closed, cancelled
  est_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
  budget_note     VARCHAR(160) NULL,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  selected_quote_id INT UNSIGNED NULL,
  po_txn_id       BIGINT UNSIGNED NULL,
  emergency_review_by INT UNSIGNED NULL,                -- after-the-fact review of an emergency purchase
  emergency_review_at DATETIME  NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_shp_pr_no (pr_no),
  KEY ix_shp_pr (status),
  CONSTRAINT fk_shp_pr_vessel FOREIGN KEY (vessel_id) REFERENCES shp_vessels(id),
  CONSTRAINT fk_shp_pr_wo FOREIGN KEY (wo_id) REFERENCES shp_work_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_requisition_lines;
CREATE TABLE shp_requisition_lines (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pr_id         INT UNSIGNED NOT NULL,
  line_no       INT          NOT NULL DEFAULT 1,
  item_id       INT UNSIGNED NULL,
  description   VARCHAR(160) NOT NULL,
  part_no       VARCHAR(60)  NULL,
  qty           DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit          VARCHAR(12)  NULL,
  est_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_shp_prl_pr FOREIGN KEY (pr_id) REFERENCES shp_requisitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_prl_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_quotes;
CREATE TABLE shp_quotes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pr_id         INT UNSIGNED NOT NULL,
  vendor_id     INT UNSIGNED NOT NULL,
  quote_ref     VARCHAR(40)  NULL,
  quote_date    DATE         NULL,
  delivery_days INT          NULL,
  terms_note    VARCHAR(120) NULL,
  total         DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_selected   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_q (pr_id, vendor_id),
  CONSTRAINT fk_shp_q_pr FOREIGN KEY (pr_id) REFERENCES shp_requisitions(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_q_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_quote_lines;
CREATE TABLE shp_quote_lines (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  quote_id   INT UNSIGNED NOT NULL,
  pr_line_id INT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_shp_ql (quote_id, pr_line_id),
  CONSTRAINT fk_shp_ql_q FOREIGN KEY (quote_id) REFERENCES shp_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_shp_ql_line FOREIGN KEY (pr_line_id) REFERENCES shp_requisition_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  APPROVALS (maker-checker), DELEGATIONS, CUSTOMER SERVICE, NOTICES, COMPLIANCE
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS shp_approvals;
CREATE TABLE shp_approvals (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  approval_type  VARCHAR(20)  NOT NULL,
  -- refund, compensation, departure_override, hold_release, fuel_adjustment, wo_deferral, requisition, emergency_purchase, fare_change
  ref_table      VARCHAR(30)  NOT NULL,
  ref_id         INT UNSIGNED NOT NULL,
  ref_no         VARCHAR(40)  NULL,
  amount         DECIMAL(14,2) NULL,
  summary        VARCHAR(255) NOT NULL,
  requested_by   INT UNSIGNED NOT NULL,
  requested_at   DATETIME     NOT NULL,
  status         VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending, approved, rejected, cancelled
  decided_by     INT UNSIGNED NULL,
  decided_at     DATETIME     NULL,
  decided_for    INT UNSIGNED NULL,                         -- the approver the decider stood in for (delegation)
  decision_notes VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_appr (status, approval_type),
  KEY ix_shp_appr_ref (ref_table, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_delegations;
CREATE TABLE shp_delegations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  from_user_id   INT UNSIGNED NOT NULL,
  to_user_id     INT UNSIGNED NOT NULL,
  approval_types VARCHAR(255) NOT NULL,                     -- comma separated, or * for all
  valid_from     DATE         NOT NULL,
  valid_to       DATE         NOT NULL,
  reason         VARCHAR(255) NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_dlg (to_user_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_cases;
CREATE TABLE shp_cases (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_no             VARCHAR(20)  NOT NULL,
  category            VARCHAR(20)  NOT NULL,
  -- booking_help, rebooking, refund_followup, missed_departure, lost_baggage, cargo_claim, damaged_cargo, accessibility, delayed_voyage, complaint, other
  channel             VARCHAR(10)  NOT NULL DEFAULT 'desk',     -- portal, desk, phone, email, agent
  subject             VARCHAR(160) NOT NULL,
  description         TEXT         NULL,
  priority            VARCHAR(8)   NOT NULL DEFAULT 'normal',   -- low, normal, high, urgent
  status              VARCHAR(12)  NOT NULL DEFAULT 'open',     -- open, in_progress, waiting, resolved, closed
  customer_name       VARCHAR(120) NOT NULL,
  customer_email      VARCHAR(160) NULL,
  customer_phone      VARCHAR(30)  NULL,
  portal_user_id      INT UNSIGNED NULL,
  booking_id          INT UNSIGNED NULL,
  ticket_id           INT UNSIGNED NULL,
  voyage_id           INT UNSIGNED NULL,
  cargo_id            INT UNSIGNED NULL,
  assigned_to         INT UNSIGNED NULL,
  sla_due_at          DATETIME     NULL,
  escalated           TINYINT(1)   NOT NULL DEFAULT 0,
  first_response_at   DATETIME     NULL,
  resolved_at         DATETIME     NULL,
  resolution_code     VARCHAR(20)  NULL,
  resolution_notes    VARCHAR(500) NULL,
  comp_type           VARCHAR(12)  NOT NULL DEFAULT 'none',     -- none, refund, voucher, cash
  comp_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
  comp_status         VARCHAR(10)  NOT NULL DEFAULT 'none',     -- none, requested, approved, rejected, paid
  comp_txn_id         BIGINT UNSIGNED NULL,
  satisfaction        TINYINT      NULL,                        -- 1 to 5, from the customer
  feedback            VARCHAR(500) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_shp_case_no (case_no),
  KEY ix_shp_case (status, priority),
  KEY ix_shp_case_portal (portal_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_case_actions;
CREATE TABLE shp_case_actions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  case_id     INT UNSIGNED NOT NULL,
  action_type VARCHAR(12)  NOT NULL,     -- note, reply, status, assign, escalate, compensation, customer
  details     VARCHAR(1000) NOT NULL,
  is_internal TINYINT(1)   NOT NULL DEFAULT 1,
  user_id     INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_ca (case_id, id),
  CONSTRAINT fk_shp_ca_case FOREIGN KEY (case_id) REFERENCES shp_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_notifications;
CREATE TABLE shp_notifications (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key       VARCHAR(120) NOT NULL,
  event_type      VARCHAR(30)  NOT NULL,      -- booking_confirmed, ticket_issued, schedule_change, voyage_cancelled, boarding_open, refund, expiry, receivable, wo_due
  channel         VARCHAR(8)   NOT NULL DEFAULT 'in_app',   -- in_app, email, sms
  recipient_type  VARCHAR(16)  NOT NULL DEFAULT 'internal', -- passenger, agent, cargo_customer, internal
  recipient       VARCHAR(160) NOT NULL,
  user_id         INT UNSIGNED NULL,
  subject         VARCHAR(160) NOT NULL,
  body            VARCHAR(1000) NOT NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'queued',   -- queued, sent, failed, no_provider, read
  attempts        INT          NOT NULL DEFAULT 0,
  last_attempt_at DATETIME     NULL,
  provider_status VARCHAR(160) NULL,
  ref_table       VARCHAR(30)  NULL,
  ref_id          INT UNSIGNED NULL,
  read_at         DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shp_ntf (event_key, channel, recipient),
  KEY ix_shp_ntf_user (user_id, read_at),
  KEY ix_shp_ntf_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_compliance;
CREATE TABLE shp_compliance (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(160) NOT NULL,
  authority     VARCHAR(12)  NOT NULL,          -- BIR, MARINA, PCG, PPA, NPC, LGU
  reference     VARCHAR(120) NULL,              -- the issuance it follows (e.g. RR 7-2024, MC DS 2026-01)
  description   VARCHAR(500) NULL,
  frequency     VARCHAR(12)  NOT NULL DEFAULT 'once',   -- once, monthly, quarterly, annual
  due_date      DATE         NULL,
  owner_user_id INT UNSIGNED NULL,
  status        VARCHAR(14)  NOT NULL DEFAULT 'open',   -- open, done, not_applicable, needs_review
  evidence_note VARCHAR(500) NULL,
  completed_by  INT UNSIGNED NULL,
  completed_at  DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_comp (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS shp_reg_exports;
CREATE TABLE shp_reg_exports (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voyage_id    INT UNSIGNED NULL,
  export_type  VARCHAR(20)  NOT NULL,           -- manifest_csv, manifest_print, sales_schedule, port_fees
  manifest_id  INT UNSIGNED NULL,
  row_count    INT          NOT NULL DEFAULT 0,
  exported_by  INT UNSIGNED NULL,
  note         VARCHAR(255) NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_shp_rx (voyage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- data

-- settings (75 rows)
INSERT INTO `settings` (`setting_key`,`setting_value`,`updated_at`) VALUES
('adopt_group_price','1','2026-10-31 22:42:52'),
('allow_negative_stock','0','2026-10-31 22:42:52'),
('audit_retention_years','10','2026-10-31 22:42:52'),
('bir_signatory_name','','2026-10-31 22:42:52'),
('bir_signatory_tin','','2026-10-31 22:42:52'),
('bir_signatory_title','','2026-10-31 22:42:52'),
('bir_zip_code','','2026-10-31 22:42:52'),
('block_zero_cost_processing','1','2026-10-31 22:42:52'),
('block_zero_cost_sales','1','2026-10-31 22:42:52'),
('bt_printer_autoprint','0','2026-10-31 22:42:52'),
('bt_printer_cut','0','2026-10-31 22:42:52'),
('bt_printer_enabled','0','2026-10-31 22:42:52'),
('bt_printer_feed','4','2026-10-31 22:42:52'),
('bt_printer_name','','2026-10-31 22:42:52'),
('bt_printer_width','32','2026-10-31 22:42:52'),
('closing_date','','2026-10-31 22:42:52'),
('company_address','Pier 4, North Reclamation Area, Cebu City 6000','2026-10-31 22:42:52'),
('company_email','reservations@visayasstar.test','2026-10-31 22:42:52'),
('company_logo','','2026-10-31 22:42:52'),
('company_name','Visayas Star Shipping Lines, Inc.','2026-10-31 22:42:52'),
('company_phone','(032) 412-5500','2026-10-31 22:42:52'),
('company_tin','000-482-917-000','2026-10-31 22:42:52'),
('costing_method','weighted_average','2026-10-31 22:42:52'),
('finance_charge_grace_days','0','2026-10-31 22:42:52'),
('finance_charge_min','50','2026-10-31 22:42:52'),
('finance_charge_on_charges','0','2026-10-31 22:42:52'),
('finance_charge_rate','12','2026-10-31 22:42:52'),
('fiscal_year_start_month','1','2026-10-31 22:42:52'),
('item_search_by_page','','2026-10-31 22:42:52'),
('item_search_format','code_name','2026-10-31 22:42:52'),
('large_discount_pct','10','2026-10-31 22:42:52'),
('logo_targets','','2026-10-31 22:42:52'),
('max_failed_logins','5','2026-10-31 22:42:52'),
('pos_posting_mode','per_sale','2026-10-31 22:42:52'),
('pos_receipt_footer','Thank you for sailing with us!\nThis serves as your official receipt.','2026-10-31 22:42:52'),
('pos_receipt_show_customer','1','2026-10-31 22:42:52'),
('pos_receipt_title','OFFICIAL RECEIPT','2026-10-31 22:42:52'),
('pos_receipt_title_charge','CHARGE INVOICE','2026-10-31 22:42:52'),
('pos_receipt_title_payment','ACKNOWLEDGEMENT RECEIPT','2026-10-31 22:42:52'),
('pos_receipt_title_return','RETURN / REFUND','2026-10-31 22:42:52'),
('pw_expiry_days','90','2026-10-31 22:42:52'),
('pw_min_length','8','2026-10-31 22:42:52'),
('pw_require_number','1','2026-10-31 22:42:52'),
('pw_require_symbol','1','2026-10-31 22:42:52'),
('pw_require_upper','1','2026-10-31 22:42:52'),
('session_timeout_minutes','30','2026-10-31 22:42:52'),
('shp_booking_close_minutes','30','2026-10-31 22:42:52'),
('shp_cancel_fee_pct','20','2026-10-31 22:42:52'),
('shp_cancel_late_fee_pct','50','2026-10-31 22:42:52'),
('shp_cancel_late_hours','24','2026-10-31 22:42:52'),
('shp_change_fee','150','2026-10-31 22:42:52'),
('shp_counter_hold_minutes','20','2026-10-31 22:42:52'),
('shp_email_provider','','2026-10-31 22:42:52'),
('shp_fuel_variance_pct','2','2026-10-31 22:42:52'),
('shp_gateway_provider','','2026-10-31 22:42:52'),
('shp_labor_rate','250','2026-10-31 22:42:52'),
('shp_large_refund_amount','5000','2026-10-31 22:42:52'),
('shp_max_pax_per_booking','20','2026-10-31 22:42:52'),
('shp_noshow_policy','forfeit','2026-10-31 22:42:52'),
('shp_offline_scans','1','2026-10-31 22:42:52'),
('shp_portal_hold_minutes','120','2026-10-31 22:42:52'),
('shp_post_labor','1','2026-10-31 22:42:52'),
('shp_qr_secret','61a0a7775f29056649918b2e87c3867dd4d38465c2b7d2bd','2026-10-31 22:42:52'),
('shp_refund_fees_unused','1','2026-10-31 22:42:52'),
('shp_revenue_policy','deferred','2026-10-31 22:42:52'),
('shp_sla_high_hours','12','2026-10-31 22:42:52'),
('shp_sla_low_hours','96','2026-10-31 22:42:52'),
('shp_sla_normal_hours','48','2026-10-31 22:42:52'),
('shp_sla_urgent_hours','4','2026-10-31 22:42:52'),
('shp_sms_provider','','2026-10-31 22:42:52'),
('shp_ticket_is_invoice','0','2026-10-31 22:42:52'),
('shp_ticket_terms','Present this e-ticket (printed or on your phone) and a valid ID at the boarding gate at least 30 minutes before departure.\nPassengers availing of statutory discounts must present the required ID.\nTickets are valid only for the voyage shown. Changes and refunds follow the company\'s published policy.','2026-10-31 22:42:52'),
('shp_vat_inclusive','1','2026-10-31 22:42:52'),
('track_group_sales','1','2026-10-31 22:42:52'),
('withholding_agent','1','2026-10-31 22:42:52');

-- users (28 rows)
INSERT INTO `users` (`id`,`username`,`password_hash`,`full_name`,`email`,`employee_no`,`pos_default_site_id`,`pos_default_class_id`,`status`,`approval_status`,`created_via`,`approved_by`,`approved_at`,`rejected_reason`,`is_platform`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`,`updated_at`) VALUES
(1,'admin','$2y$10$UxMKrLLCZ72ONmAFLSlcaOnS2B83fogOgYcfGs94cSBKaiuLyMBWe','System Administrator','admin@visayasstar.test','VSS-001',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(2,'gm','$2y$10$aZh1HC8XN7xOEiERVzVrhOOEODbqX58VITw2yvKNogj4lb6c7wfm.','Ramon Villafuerte','gm@visayasstar.test','VSS-002',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(3,'opsmgr','$2y$10$.iJEiKsOokWxogVm5oEfUuj9qHpezUm8y8DhlXKNtGqcYVVLpBpHq','Teresa Abellana','opsmgr@visayasstar.test','VSS-003',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(4,'fleetmgr','$2y$10$.Xul8b9CoxKkYizfPK9DM.8ML5cN7YzhqYysp6aAyz8DybROAus3m','Engr. Arnel Pacquiao','fleetmgr@visayasstar.test','VSS-004',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(5,'captain','$2y$10$lbVgoMCTOqscpiPJPP1QZetibxTpRSlEmTf/QVdv2dFS4TrHPcWJ.','Capt. Rodrigo Saavedra','captain@visayasstar.test','VSS-005',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(6,'chiefeng','$2y$10$.aRCho2PTDxPrm6yUxvt4OJc8yp6pNDm0q/0vbpNtxBq55exThRXu','Engr. Victor Labra','chiefeng@visayasstar.test','VSS-006',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(7,'planner','$2y$10$7Imc.en8JrdBDTZ8PoQbIOzV4X7CL1YIKkaL02MctUHiYn3SHaxFi','Liza Montenegro','planner@visayasstar.test','VSS-007',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(8,'tech1','$2y$10$dhIXfJFLHsGOKIqpZd1mkuBXAUuWuYpKzOBPzgTv/VAHyWwXInroy','Joel Sumalinog','tech1@visayasstar.test','VSS-008',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(9,'tech2','$2y$10$tRoRn8SDM2mk2iTBcneHdOIwvElWMTfZDT7q6oXhwLQ4fbw9mkxGm','Ricky Bacalso','tech2@visayasstar.test','VSS-009',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(10,'procure','$2y$10$376n4mrbmjhNfo/NF2W4iulvLbAAAMPglfWNon18hPNdb6bzKAri.','Marivic Ouano','procure@visayasstar.test','VSS-010',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(11,'warehouse','$2y$10$OzBA8odwzYNJVhXY19LyF.gn6YIxT9vzBdYfEcZTIZmNV5Yvxl3Jq','Dennis Cabahug','warehouse@visayasstar.test','VSS-011',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(12,'tktmgr','$2y$10$2UrZ0gKyMVX43fRmCUglaewjFRjcA26mFzDb.4e6lpWcd566/vZwy','Grace Lim-Uy','tktmgr@visayasstar.test','VSS-012',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:52',0,NULL,NULL,NULL,'2026-10-31 22:42:52',NULL),
(13,'ticket1','$2y$10$xRpUimqqD5jJe/pF6VAKvOqIl0yAJY3Jq2xYx5JX6uGkiYldDoYUe','Analyn Tumulak','ticket1@visayasstar.test','VSS-013',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(14,'ticket2','$2y$10$ji775IrnpyYVT6qH78dcjO2/tk2IYnahQ2HIaSpHeh1ey1aei31M.','Paolo Ybanez','ticket2@visayasstar.test','VSS-014',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(15,'boarding1','$2y$10$9LrnaztC5udzVJcmTWlesOF/TLcd1jr/sBq7c59F0l.HBcz3xlYyG','Jessa Mae Rosales','boarding1@visayasstar.test','VSS-015',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(16,'cargoclerk','$2y$10$ntqUr2yiF/uttkfQDlcyEONWH7X/qA8g4qMQ68QHaZUaJaHulWbqq','Noel Alcuizar','cargoclerk@visayasstar.test','VSS-016',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(17,'portagent','$2y$10$sNTgTByF4IgjaET33HhP4uO9LnmizL3oTSWdmURbpkLea4ahqWKYu','Mylene Gacasan','portagent@visayasstar.test','VSS-017',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(18,'csagent','$2y$10$TB153Kg3nmd/yozYmmLAE.9f4E3OJz0sQWsePwKXWxSNTs9JE3ja.','Kristine Dumaguit','csagent@visayasstar.test','VSS-018',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(19,'accountant','$2y$10$lGfwlC9NhRIl0hXczR20JeS8k3XrdUxY8.0Z0RQss6Rx9tGrHpvNW','Ana Cruz, CPA','accountant@visayasstar.test','VSS-019',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(20,'treasurer','$2y$10$osD1MJX8osoHuVuvuqY2F.97dNwlKX4qlWlsHFoTzhVaY60OES5zS','Belinda Sy','treasurer@visayasstar.test','VSS-020',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(21,'arclerk','$2y$10$aT8KFZv8OOFurq1Gb0kb4u8tpQ3OSfWrICFF3yHp5IoL5gHap.v0u','Ben Garcia','arclerk@visayasstar.test','VSS-021',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(22,'apclerk','$2y$10$P1e3dhjwxErqJJGnTfWiyOK/tzOn0LqZthbWFZhc4Ypb3xkQpQl7e','Carla Lim','apclerk@visayasstar.test','VSS-022',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(23,'invclerk','$2y$10$nvTh2AAKUFxFRwBn2zAF8ezelGcWMilOg.SvRj.eGA7tTBZyu4svC','Dan Villanueva','invclerk@visayasstar.test','VSS-023',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(24,'salesrep','$2y$10$fMjpjLOTOqOOo8Md6qJAquz.j6h8zWnlcqYiPXl1z.q5tIcl3Re7u','Maria Santos','salesrep@visayasstar.test','VSS-024',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(25,'canteen1','$2y$10$zaYivRZEFb7Zo.qjWJyWaeQB6d6Nqb7PVnAWzCriC1UX0bUbOtl1S','Ella Ramos','canteen1@visayasstar.test','VSS-025',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(26,'purser','$2y$10$T/2ynyGglcrx/XD2zOu0Jeq6txq7kMnMVrizrRkMikRQ3nyMCvvX6','Fred Tan','purser@visayasstar.test','VSS-026',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(27,'auditor','$2y$10$47Xc/gyE2eRRCPrD7uFGbeRJaX18wYB5YD5tn47dE1VEVKNXt/CYm','Grace Uy','auditor@visayasstar.test','VSS-027',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL),
(28,'regulator','$2y$10$UAcHUUKhRocwaLJ1xjPWSOCOnK80ZszAnDJaCF.SJtPtTFvRbW7nC','PCG Inspector (read-only)','regulator@visayasstar.test','VSS-028',NULL,NULL,'active','approved','admin',NULL,NULL,NULL,0,1,'2026-10-31 22:42:53',0,NULL,NULL,NULL,'2026-10-31 22:42:53',NULL);

-- roles (26 rows)
INSERT INTO `roles` (`id`,`name`,`description`,`is_admin`,`is_system`,`all_sites`,`created_at`,`updated_at`) VALUES
(1,'Administrator','Company administrator: everything in every module of this company, including users, roles, page access, the closing date and the audit trail.',1,1,1,'2026-10-31 22:42:52',NULL),
(2,'General Manager','Sees everything; approves refunds, compensation, departure overrides, purchases and fare changes; gives the management release of safety holds.',0,1,1,'2026-10-31 22:42:52',NULL),
(3,'Operations Manager','Voyages, schedules, dispatch, holds, fuel, crew, manifests, cargo and RoRo operations, port fees; approves departure overrides and gives the management release of safety holds.',0,1,1,'2026-10-31 22:42:52',NULL),
(4,'Fleet Manager','Vessels, certificates, equipment, planned and corrective maintenance, dry-docking, purchasing for the fleet, fuel; approves work, deferrals and requisitions; management release of safety holds.',0,1,1,'2026-10-31 22:42:52',NULL),
(5,'Captain / Master','Departure readiness and release, voyage status and report, crew list, manifests, boarding, defects and fuel soundings of the vessel.',0,1,1,'2026-10-31 22:42:52',NULL),
(6,'Chief Engineer','Engine room, equipment and running hours, defects and work orders (inspects and signs the technical release of critical work), fuel, requisitions.',0,1,1,'2026-10-31 22:42:52',NULL),
(7,'Maintenance Planner','Equipment register, planned maintenance plans, work order planning, dry-docking projects, requisitions and maintenance reports.',0,1,1,'2026-10-31 22:42:52',NULL),
(8,'Engineer / Technician','Reports defects, carries out work orders and logs labor and parts. Cannot inspect or release their own work.',0,1,1,'2026-10-31 22:42:52',NULL),
(9,'Procurement Officer','Canvass of approved requisitions, purchase orders, supplier records; follows receiving and supplier invoices.',0,1,1,'2026-10-31 22:42:52',NULL),
(10,'Warehouse Keeper','Shore warehouse and vessel stores: receiving, transfers, spare parts issued to work orders, bunkering receipts, stock counts.',0,1,1,'2026-10-31 22:42:52',NULL),
(11,'Ticketing Manager','Ticketing, boarding and manifests; approves refunds and compensation, reviews cashier shifts, sets fares and passenger categories.',0,1,1,'2026-10-31 22:42:52',NULL),
(12,'Ticketing Agent / Cashier','Booking desk, ticket issuance and payment, cashier shift, refund requests and rebooking at the assigned ticket office.',0,1,1,'2026-10-31 22:42:52',NULL),
(13,'Boarding Officer','QR check-in and boarding at the gate, vehicle loading scans, the passenger count and the manifest of the voyage.',0,1,1,'2026-10-31 22:42:52',NULL),
(14,'Cargo Clerk','Cargo quotations, waybills, acceptance, loading tally, delivery and exceptions; vehicle bookings and loading lists; collects freight at the counter.',0,1,1,'2026-10-31 22:42:52',NULL),
(15,'Port Agent','Agent at an outport: sells tickets through the shared inventory, handles cargo and vehicles, sees the voyage and its manifest.',0,1,1,'2026-10-31 22:42:52',NULL),
(16,'Customer Service','Support cases and claims, notices to passengers, booking assistance and free rebooking of cancelled voyages; requests refunds and compensation.',0,1,1,'2026-10-31 22:42:52',NULL),
(17,'Accountant','Financial set-up and reports, journal entries, fixed assets, banking, BIR reports, shipping posting accounts, port fee remittance and every shipping report.',0,1,1,'2026-10-31 22:42:52',NULL),
(18,'Treasurer','Banking, deposits of ticket collections, bank reconciliation, supplier payments, cashier shift review, refund pay-outs and port fee remittance.',0,1,1,'2026-10-31 22:42:52',NULL),
(19,'A/R Clerk','Customers module (corporate and cargo accounts, invoices, collections) and the receivables reports.',0,1,1,'2026-10-31 22:42:52',NULL),
(20,'A/P Clerk','Vendors module (bills, supplier debit memos, pay bills, advances), payables reports and BIR Form 2307 for suppliers.',0,1,1,'2026-10-31 22:42:52',NULL),
(21,'Inventory Clerk','Items, sites, receiving, adjustments, transfers, stock counts and inventory reports.',0,1,1,'2026-10-31 22:42:52',NULL),
(22,'Corporate Sales','Corporate, travel-agency and cargo accounts: estimates, sales orders, invoices and the sales reports.',0,1,1,'2026-10-31 22:42:52',NULL),
(23,'Canteen Cashier','Onboard canteen POS sales, returns and cash drops at the assigned terminal only.',0,1,1,'2026-10-31 22:42:52',NULL),
(24,'Purser (POS Supervisor)','Onboard canteen supervision: approves voids, refunds and overrides, runs X- and Z-readings.',0,1,1,'2026-10-31 22:42:52',NULL),
(25,'Internal Auditor','View-only access to every record, report and the audit trail. Cannot create, change, approve or post anything.',0,1,1,'2026-10-31 22:42:52',NULL),
(26,'Regulator (read-only)','Authorized regulator access (for example a PCG or MARINA inspector): voyages, manifests, vessel certificates and safety reports, read-only. No finance.',0,1,1,'2026-10-31 22:42:52',NULL);

-- user_roles (28 rows)
INSERT INTO `user_roles` (`user_id`,`role_id`) VALUES
(1,1),
(2,2),
(3,3),
(4,4),
(5,5),
(6,6),
(7,7),
(8,8),
(9,8),
(10,9),
(11,10),
(12,11),
(13,12),
(14,12),
(15,13),
(16,14),
(17,15),
(18,16),
(19,17),
(20,18),
(21,19),
(22,20),
(23,21),
(24,22),
(25,23),
(26,24),
(27,25),
(28,26);

-- modules (17 rows)
INSERT INTO `modules` (`id`,`code`,`name`,`icon`,`sort_order`) VALUES
(1,'home','Home','house',1),
(2,'ops','Voyage Operations','compass',2),
(3,'tkt','Ticketing & Boarding','ticket-perforated',3),
(4,'cgo','Cargo & RoRo','boxes',4),
(5,'fleet','Fleet & Maintenance','tools',5),
(6,'care','Customer Service','headset',6),
(7,'shp','Shipping Set-up','sliders',7),
(8,'coa','Financial','journal-bookmark',10),
(9,'fa','Fixed Assets','building',11),
(10,'cust','Customers','people',12),
(11,'vend','Vendors','truck',13),
(12,'inv','Inventory','box-seam',14),
(13,'pos','Onboard Point of Sale','cart',15),
(14,'bank','Banking','bank',16),
(15,'bir','BIR Reports','file-earmark-ruled',17),
(16,'admin','System Administration','shield-lock',18),
(17,'company','Company','buildings',19);

-- pages (268 rows)
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(1,'home.dashboard',1,'Home Dashboard','center','Home',10,1,NULL),
(2,'home.reports',1,'Report Center','center','Home',20,1,NULL),
(3,'home.memorized',1,'Memorized Reports','list','Home',30,1,NULL),
(4,'home.report_layouts',1,'Saved Report Layouts','list','Home',40,1,NULL),
(5,'home.search',1,'Search','center','Home',50,0,NULL),
(6,'home.find',1,'Find','center','Home',60,1,NULL),
(7,'rpt.find',1,'Find Results','report','Reports',70,0,NULL),
(8,'home.notices',1,'My Notices','list','Home',80,0,NULL),
(9,'ops.voyages',2,'Voyages & Dispatch','center','Operations',90,1,NULL),
(10,'ops.schedule',2,'Schedule Planner','transaction','Operations',100,1,NULL),
(11,'ops.holds',2,'Vessel Holds & Release','transaction','Operations',110,1,NULL),
(12,'ops.fuel',2,'Fuel & Bunkering','transaction','Operations',120,1,NULL),
(13,'ops.crew',2,'Crew & Certificates','list','Operations',130,1,NULL),
(14,'ops.approvals',2,'Approvals Queue','transaction','Operations',140,1,NULL),
(15,'rpt.shp_voyage_pnl',2,'Voyage Profit & Loss','report','Operations Reports',150,1,NULL),
(16,'rpt.shp_vessel_pnl',2,'Vessel & Route Profitability','report','Operations Reports',160,1,NULL),
(17,'rpt.shp_punctuality',2,'Departure Punctuality','report','Operations Reports',170,1,NULL),
(18,'rpt.shp_fuel',2,'Fuel Consumption & ROB Variance','report','Operations Reports',180,1,NULL),
(19,'rpt.shp_crew_certs',2,'Crew Certificate Expiry','report','Operations Reports',190,1,NULL),
(20,'tkt.booking',3,'Booking Desk','transaction','Ticketing',200,1,NULL),
(21,'tkt.bookings',3,'Bookings & Tickets','center','Ticketing',210,1,NULL),
(22,'tkt.boarding',3,'Boarding Scanner','transaction','Ticketing',220,1,NULL),
(23,'tkt.manifest',3,'Passenger Manifests','center','Ticketing',230,1,NULL),
(24,'tkt.cashier',3,'Cashier Shifts','transaction','Ticketing',240,1,NULL),
(25,'tkt.refunds',3,'Refunds & Cancellations','transaction','Ticketing',250,1,NULL),
(26,'tkt.manual',3,'Manual Ticket Contingency','transaction','Ticketing',260,1,NULL),
(27,'rpt.shp_sales',3,'Passenger Sales','report','Ticketing Reports',270,1,NULL),
(28,'rpt.shp_sold_boarded',3,'Sold vs Paid vs Boarded','report','Ticketing Reports',280,1,NULL),
(29,'rpt.shp_load_factor',3,'Load Factor by Voyage','report','Ticketing Reports',290,1,NULL),
(30,'rpt.shp_discounts',3,'Statutory Discounts Register','report','Ticketing Reports',300,1,NULL),
(31,'rpt.shp_refunds',3,'Refunds & Cancellations Register','report','Ticketing Reports',310,1,NULL),
(32,'rpt.shp_cashier',3,'Cashier Collections vs Deposits','report','Ticketing Reports',320,1,NULL),
(33,'rpt.shp_advance',3,'Advance Bookings','report','Ticketing Reports',330,1,NULL),
(34,'cgo.shipments',4,'Cargo Shipments & Waybills','center','Cargo',340,1,NULL),
(35,'cgo.vehicles',4,'RoRo Loading & Vehicle Passes','center','Cargo',350,1,NULL),
(36,'cgo.port_fees',4,'Port Fee Reconciliation & Remittance','transaction','Cargo',360,1,NULL),
(37,'rpt.shp_cargo_volume',4,'Cargo Tonnage & Volume','report','Cargo Reports',370,1,NULL),
(38,'rpt.shp_cargo_exceptions',4,'Cargo Exceptions & Claims','report','Cargo Reports',380,1,NULL),
(39,'rpt.shp_lane_util',4,'Vehicle Lane Utilization','report','Cargo Reports',390,1,NULL),
(40,'rpt.shp_port_fees',4,'Port & Terminal Fees by Voyage','report','Cargo Reports',400,1,NULL),
(41,'fleet.vessels',5,'Vessel Registry','list','Fleet',410,1,NULL),
(42,'fleet.certificates',5,'Vessel Certificates','list','Fleet',420,1,NULL),
(43,'fleet.equipment',5,'Equipment Hierarchy','list','Maintenance',430,1,NULL),
(44,'fleet.pm',5,'Planned Maintenance','transaction','Maintenance',440,1,NULL),
(45,'fleet.defects',5,'Defect Reports','transaction','Maintenance',450,1,NULL),
(46,'fleet.workorders',5,'Work Orders','center','Maintenance',460,1,NULL),
(47,'fleet.drydock',5,'Dry-docking Projects','transaction','Maintenance',470,1,NULL),
(48,'fleet.requisitions',5,'Purchase Requisitions & Canvass','transaction','Purchasing',480,1,NULL),
(49,'rpt.shp_wo_status',5,'Work Order Status','report','Maintenance Reports',490,1,NULL),
(50,'rpt.shp_pm_due',5,'Planned Maintenance Due & Overdue','report','Maintenance Reports',500,1,NULL),
(51,'rpt.shp_repair_spend',5,'Repair Spend by Vessel & Equipment','report','Maintenance Reports',510,1,NULL),
(52,'rpt.shp_reliability',5,'Reliability: Downtime, Failures, MTTR','report','Maintenance Reports',520,1,NULL),
(53,'rpt.shp_cert_expiry',5,'Vessel Certificate Expiry','report','Maintenance Reports',530,1,NULL),
(54,'rpt.shp_spares',5,'Spare Parts Issued to Work Orders','report','Maintenance Reports',540,1,NULL),
(55,'care.cases',6,'Support Cases & Claims','center','Service',550,1,NULL),
(56,'care.notifications',6,'Notifications & Notices','list','Service',560,1,NULL),
(57,'rpt.shp_cases',6,'Support Cases by Category & SLA','report','Service Reports',570,1,NULL),
(58,'shp.ports',7,'Ports & Terminals','setup','Masters',580,1,NULL),
(59,'shp.routes',7,'Routes','setup','Masters',590,1,NULL),
(60,'shp.fares',7,'Fare Matrix & Rates','setup','Masters',600,1,NULL),
(61,'shp.categories',7,'Passenger Categories & Discounts','setup','Masters',610,1,NULL),
(62,'shp.fees',7,'Fees & Third-party Charges','setup','Masters',620,1,NULL),
(63,'shp.vehicle_classes',7,'Vehicle Classes','setup','Masters',630,1,NULL),
(64,'shp.offices',7,'Ticket Offices & Outlets','setup','Masters',640,1,NULL),
(65,'shp.settings',7,'Shipping Policies & Posting Accounts','setup','Policies',650,1,NULL),
(66,'shp.delegations',7,'Approval Delegations','setup','Policies',660,1,NULL),
(67,'shp.compliance',7,'Compliance Register','setup','Policies',670,1,NULL),
(68,'rpt.shp_exceptions',7,'Control Exceptions (Anti-fraud Flags)','report','Control Reports',680,1,NULL),
(69,'rpt.shp_reconcile',7,'Ticket, Ledger & Manifest Reconciliation','report','Control Reports',690,1,NULL),
(70,'coa.accounts',8,'Chart of Accounts','list','Lists',700,1,NULL),
(71,'coa.classes',8,'Class List','list','Lists',710,1,NULL),
(72,'coa.names',8,'Employees & Other Names','list','Lists',720,1,NULL),
(73,'coa.memorized_txns',8,'Memorized Transactions','list','Lists',730,1,NULL),
(74,'coa.budgets',8,'Set Up Budgets','setup','Setup',740,1,NULL),
(75,'coa.journal',8,'Make Journal Entries','transaction','Transactions',750,1,NULL),
(76,'coa.default_accounts',8,'Module Default Accounts','setup','Setup',760,1,NULL),
(77,'coa.lists',8,'Terms, Tax Codes & Other Lists','setup','Setup',770,1,NULL),
(78,'rpt.trial_balance',8,'Trial Balance','report','Reports',780,1,NULL),
(79,'rpt.trial_balance_class',8,'Trial Balance by Class','report','Reports',790,1,NULL),
(80,'rpt.balance_sheet',8,'Balance Sheet','report','Reports',800,1,NULL),
(81,'rpt.balance_sheet_class',8,'Balance Sheet by Class','report','Reports',810,1,NULL),
(82,'rpt.trial_balance_range',8,'Trial Balance by Date Range','report','Reports',820,1,NULL),
(83,'rpt.trial_balance_totals',8,'Trial Balance of Totals','report','Reports',830,1,NULL),
(84,'rpt.balance_sheet_range',8,'Balance Sheet by Date Range','report','Reports',840,1,NULL),
(85,'rpt.budget_income',8,'Income Statement vs Budget','report','Reports',850,1,NULL),
(86,'rpt.budget_balance',8,'Balance Sheet vs Budget','report','Reports',860,1,NULL),
(87,'rpt.income_statement',8,'Income Statement','report','Reports',870,1,NULL),
(88,'rpt.income_statement_class',8,'Income Statement by Class','report','Reports',880,1,NULL),
(89,'rpt.cmp_is_month',8,'Comparative Income Statement by Month','report','Reports',890,1,NULL),
(90,'rpt.cmp_is_quarter',8,'Comparative Income Statement by Quarter','report','Reports',900,1,NULL),
(91,'rpt.cmp_is_year',8,'Comparative Income Statement by Year','report','Reports',910,1,NULL),
(92,'rpt.cmp_is_custom',8,'Comparative Income Statement by Custom Range','report','Reports',920,1,NULL),
(93,'rpt.cmp_bs_month',8,'Comparative Balance Sheet by Month','report','Reports',930,1,NULL),
(94,'rpt.cmp_bs_quarter',8,'Comparative Balance Sheet by Quarter','report','Reports',940,1,NULL),
(95,'rpt.cmp_bs_year',8,'Comparative Balance Sheet by Year','report','Reports',950,1,NULL),
(96,'rpt.cmp_bs_custom',8,'Comparative Balance Sheet by Custom Date','report','Reports',960,1,NULL),
(97,'rpt.cash_flow',8,'Statement of Cash Flows','report','Reports',970,1,NULL),
(98,'rpt.cash_flow_class',8,'Statement of Cash Flows by Class','report','Reports',980,1,NULL),
(99,'rpt.general_ledger',8,'General Ledger','report','Reports',990,1,NULL),
(100,'rpt.subsidiary_ledger',8,'Subsidiary Ledger','report','Reports',1000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(101,'rpt.schedule_of_accounts',8,'Schedule of Accounts','report','Reports',1010,1,NULL),
(102,'rpt.journal',8,'Journal','report','Reports',1020,1,NULL),
(103,'fa.register',9,'Fixed Asset Register','center','Transactions',1030,1,NULL),
(104,'fa.depreciation',9,'Run Depreciation','transaction','Transactions',1040,1,NULL),
(105,'fa.disposal',9,'Dispose of Assets','transaction','Transactions',1050,1,NULL),
(106,'fa.maintenance',9,'Asset Maintenance','transaction','Transactions',1060,1,NULL),
(107,'fa.pm',9,'Preventive Maintenance','transaction','Transactions',1070,1,NULL),
(108,'fa.categories',9,'Asset Categories','setup','Setup',1080,1,NULL),
(109,'fa.locations',9,'Asset Locations','setup','Setup',1090,1,NULL),
(110,'rpt.fa_register',9,'Fixed Asset Register Report','report','Reports',1100,1,NULL),
(111,'rpt.fa_schedule',9,'Depreciation Schedule by Asset','report','Reports',1110,1,NULL),
(112,'rpt.fa_by_category',9,'Depreciation by Category','report','Reports',1120,1,NULL),
(113,'rpt.fa_additions',9,'Additions and Disposals','report','Reports',1130,1,NULL),
(114,'rpt.fa_maint_history',9,'Maintenance History','report','Reports',1140,1,NULL),
(115,'rpt.fa_maint_cost',9,'Maintenance Cost by Asset','report','Reports',1150,1,NULL),
(116,'rpt.fa_maint_parts',9,'Spare Parts Used','report','Reports',1160,1,NULL),
(117,'rpt.fa_maint_labor',9,'Maintenance Labor','report','Reports',1170,1,NULL),
(118,'rpt.fa_pm_upcoming',9,'Upcoming Preventive Maintenance','report','Reports',1180,1,NULL),
(119,'rpt.fa_pm_log',9,'Completed Preventive Maintenance','report','Reports',1190,1,NULL),
(120,'cust.center',10,'Customer Center','center','Transactions',1200,1,NULL),
(121,'cust.estimates',10,'Create Estimates','transaction','Transactions',1210,1,NULL),
(122,'cust.sales_orders',10,'Create Sales Orders','transaction','Transactions',1220,1,NULL),
(123,'cust.invoices',10,'Create Invoices','transaction','Transactions',1230,1,NULL),
(124,'cust.batch_invoices',10,'Create Batch Invoices','transaction','Transactions',1240,1,NULL),
(125,'cust.sales_receipts',10,'Enter Sales Receipts','transaction','Transactions',1250,1,NULL),
(126,'cust.statement_charges',10,'Enter Statement Charges','transaction','Transactions',1260,1,NULL),
(127,'cust.statements',10,'Create Statements','transaction','Transactions',1270,1,NULL),
(128,'cust.finance_charges',10,'Assess Finance Charges','transaction','Transactions',1280,1,NULL),
(129,'cust.forecast',10,'Set Up Forecast','setup','Transactions',1290,1,NULL),
(130,'cust.payments',10,'Receive Payments','transaction','Transactions',1300,1,NULL),
(131,'cust.credit_memos',10,'Create Credit Memos/Refunds','transaction','Transactions',1310,1,NULL),
(132,'rpt.ar_aging_summary',10,'A/R Aging Summary','report','Customer and Receivables Reports',1320,1,NULL),
(133,'rpt.ar_aging_detail',10,'A/R Aging Detail','report','Customer and Receivables Reports',1330,1,NULL),
(134,'rpt.customer_balance_summary',10,'Customer Balance Summary','report','Customer and Receivables Reports',1340,1,NULL),
(135,'rpt.customer_balance_detail',10,'Customer Balance Detail','report','Customer and Receivables Reports',1350,1,NULL),
(136,'rpt.open_invoices',10,'Open Invoices','report','Customer and Receivables Reports',1360,1,NULL),
(137,'rpt.collections',10,'Collections Report','report','Customer and Receivables Reports',1370,1,NULL),
(138,'rpt.avg_days_summary',10,'Average Days to Pay Summary','report','Customer and Receivables Reports',1380,1,NULL),
(139,'rpt.avg_days_detail',10,'Average Days to Pay','report','Customer and Receivables Reports',1390,1,NULL),
(140,'rpt.ar_graph',10,'Accounts Receivable Graph','report','Customer and Receivables Reports',1400,1,NULL),
(141,'rpt.unbilled_costs',10,'Unbilled Cost by Job','report','Customer and Receivables Reports',1410,1,NULL),
(142,'rpt.txn_list_customer',10,'Transaction List by Customer','report','Customer and Receivables Reports',1420,1,NULL),
(143,'rpt.item_price_list',10,'Item Price List','report','Customer and Receivables Reports',1430,1,NULL),
(144,'rpt.sales_customer_summary',10,'Sales by Customer Summary','report','Sales Reports',1440,1,NULL),
(145,'rpt.forecast_vs_actual',10,'Forecast vs Actual by Customer','report','Sales Reports',1450,1,NULL),
(146,'rpt.sales_customer_detail',10,'Sales by Customer Detail','report','Sales Reports',1460,1,NULL),
(147,'rpt.income_customer_summary',10,'Income by Customer Summary','report','Sales Reports',1470,1,NULL),
(148,'rpt.income_customer_detail',10,'Income by Customer Detail','report','Sales Reports',1480,1,NULL),
(149,'rpt.sales_item_summary',10,'Sales by Item Summary','report','Sales Reports',1490,1,NULL),
(150,'rpt.sales_item_detail',10,'Sales by Item Detail','report','Sales Reports',1500,1,NULL),
(151,'rpt.sales_item_group',10,'Sales by Item Group','report','Sales Reports',1510,1,NULL),
(152,'rpt.sales_rep_summary',10,'Sales by Rep Summary','report','Sales Reports',1520,1,NULL),
(153,'rpt.sales_rep_detail',10,'Sales by Rep Detail','report','Sales Reports',1530,1,NULL),
(154,'rpt.inventory_sales_site',10,'Inventory Sales by Site','report','Sales Reports',1540,1,NULL),
(155,'rpt.sales_inv_movement',10,'Sales Report Through Inventory Movement','report','Sales Reports',1550,1,NULL),
(156,'rpt.pending_sales',10,'Pending Sales','report','Sales Reports',1560,1,NULL),
(157,'rpt.open_so_customer',10,'Open Sales Orders by Customer','report','Sales Reports',1570,1,NULL),
(158,'rpt.open_so_item',10,'Open Sales Orders by Item','report','Sales Reports',1580,1,NULL),
(159,'vend.center',11,'Vendor Center','center','Transactions',1590,1,NULL),
(160,'vend.bill_tracker',11,'Bill Tracker','center','Transactions',1600,1,NULL),
(161,'vend.bills',11,'Enter Bills','transaction','Transactions',1610,1,NULL),
(162,'vend.debit_memos',11,'Supplier Debit Memos','transaction','Transactions',1620,1,NULL),
(163,'vend.pay_bills',11,'Pay Bills','transaction','Transactions',1630,1,NULL),
(164,'vend.receipts',11,'Receipt Management','transaction','Transactions',1640,1,NULL),
(165,'vend.purchase_orders',11,'Create Purchase Orders','transaction','Transactions',1650,1,NULL),
(166,'vend.receive_bill',11,'Receive Items and Enter Bills','transaction','Transactions',1660,1,NULL),
(167,'vend.receive_items',11,'Receive Items','transaction','Transactions',1670,1,NULL),
(168,'vend.bill_received',11,'Enter Bill for Received Items','transaction','Transactions',1680,1,NULL),
(169,'vend.advances',11,'Advances to Suppliers','transaction','Transactions',1690,1,NULL),
(170,'rpt.ap_aging_summary',11,'A/P Aging Summary','report','Vendors and Payables Reports',1700,1,NULL),
(171,'rpt.ap_aging_detail',11,'A/P Aging Detail','report','Vendors and Payables Reports',1710,1,NULL),
(172,'rpt.vendor_balance_summary',11,'Vendor Balance Summary','report','Vendors and Payables Reports',1720,1,NULL),
(173,'rpt.vendor_balance_detail',11,'Vendor Balance Detail','report','Vendors and Payables Reports',1730,1,NULL),
(174,'rpt.unpaid_bills',11,'Unpaid Bills Detail','report','Vendors and Payables Reports',1740,1,NULL),
(175,'rpt.txn_list_vendor',11,'Transaction List by Vendor','report','Vendors and Payables Reports',1750,1,NULL),
(176,'rpt.vendor_contacts',11,'Vendor Contact List','report','Vendors and Payables Reports',1760,1,NULL),
(177,'rpt.expenses_vendor_summary',11,'Expenses by Vendor Summary','report','Vendors and Payables Reports',1770,1,NULL),
(178,'rpt.expenses_vendor_detail',11,'Expenses by Vendor Detail','report','Vendors and Payables Reports',1780,1,NULL),
(179,'rpt.vendor_advances',11,'Advances to Suppliers','report','Vendors and Payables Reports',1790,1,NULL),
(180,'inv.center',12,'Inventory Center','center','Transactions',1800,1,NULL),
(181,'inv.items',12,'Item List','list','Lists',1810,1,NULL),
(182,'inv.categories',12,'Category List','list','Lists',1820,1,NULL),
(183,'inv.sites',12,'Inventory Site List','list','Lists',1830,1,NULL),
(184,'inv.uom',12,'U/M Set List','list','Lists',1840,1,NULL),
(185,'inv.formulas',12,'Processing Formulas (Multi-Output BOM)','list','Lists',1850,1,NULL),
(186,'inv.transfers',12,'Transfer Inventory','transaction','Transactions',1860,1,NULL),
(187,'inv.adjust',12,'Adjust Quantity/Value on Hand','transaction','Transactions',1870,1,NULL),
(188,'inv.build',12,'Build Assemblies','transaction','Transactions',1880,1,NULL),
(189,'inv.process',12,'Disassemble / Process Items','transaction','Transactions',1890,1,NULL),
(190,'inv.physical_count',12,'Physical Inventory Count','transaction','Transactions',1900,1,NULL),
(191,'inv.change_prices',12,'Change Item Prices','transaction','Transactions',1910,1,NULL),
(192,'inv.set_prices',12,'Set Item Prices and Levels','transaction','Transactions',1920,1,NULL),
(193,'rpt.qoh_by_site',12,'Quantity on Hand by Site','report','Inventory Reports',1930,1,NULL),
(194,'rpt.inv_valuation_summary',12,'Inventory Valuation Summary','report','Inventory Reports',1940,1,NULL),
(195,'rpt.inv_valuation_site',12,'Inventory Valuation Summary by Site','report','Inventory Reports',1950,1,NULL),
(196,'rpt.inv_valuation_detail',12,'Inventory Valuation Detail','report','Inventory Reports',1960,1,NULL),
(197,'rpt.avg_cost_changes',12,'Average Cost Changes','report','Inventory Reports',1970,1,NULL),
(198,'rpt.stock_status_item',12,'Inventory Stock Status by Item','report','Inventory Reports',1980,1,NULL),
(199,'rpt.stock_status_vendor',12,'Inventory Stock Status by Vendor','report','Inventory Reports',1990,1,NULL),
(200,'rpt.stock_status_site',12,'Inventory Stock Status by Site','report','Inventory Reports',2000,1,NULL);
INSERT INTO `pages` (`id`,`page_key`,`module_id`,`name`,`page_type`,`menu_group`,`sort_order`,`show_in_menu`,`description`) VALUES
(201,'rpt.physical_worksheet',12,'Physical Inventory Worksheet','report','Inventory Reports',2010,1,NULL),
(202,'rpt.pending_builds',12,'Pending Builds','report','Inventory Reports',2020,1,NULL),
(203,'rpt.pending_builds_site',12,'Pending Builds by Site','report','Inventory Reports',2030,1,NULL),
(204,'rpt.processing_register',12,'Processing & Disassembly Register','report','Inventory Reports',2040,1,NULL),
(205,'rpt.coproduct_costs',12,'Co-product & By-product Cost Allocation','report','Inventory Reports',2050,1,NULL),
(206,'rpt.process_yield',12,'Processing Yield Analysis','report','Inventory Reports',2060,1,NULL),
(207,'rpt.negative_inventory',12,'Negative Inventory Listing','report','Inventory Reports',2070,1,NULL),
(208,'rpt.inv_no_movement',12,'No. of Days Inventory Has No Movement','report','Inventory Reports',2080,1,NULL),
(209,'rpt.inv_custom',12,'Customized Inventory Report','report','Inventory Reports',2090,1,NULL),
(210,'rpt.inv_movement_summary',12,'Inventory Movement Summary Report','report','Inventory Reports',2100,1,NULL),
(211,'rpt.inv_movement_detail',12,'Inventory Movement Detailed Report','report','Inventory Reports',2110,1,NULL),
(212,'rpt.inv_movement_txns',12,'Inventory Movement Transactions','report','Inventory Reports',2120,0,NULL),
(213,'pos.register',13,'POS Register','transaction','Transactions',2130,1,NULL),
(214,'pos.shifts',13,'Shifts, Cash Drops & Readings','transaction','Transactions',2140,1,NULL),
(215,'pos.receipts',13,'POS Receipts, Returns & Voids','transaction','Transactions',2150,1,NULL),
(216,'pos.approval_codes',13,'Approval Codes','transaction','Transactions',2160,1,NULL),
(217,'pos.terminals',13,'POS Terminals','setup','Setup',2170,1,NULL),
(218,'rpt.pos_sales_txn',13,'POS Sales by Transaction No. (detail)','report','Reports',2180,1,NULL),
(219,'rpt.pos_sales_item',13,'POS Sales by Item','report','Reports',2190,1,NULL),
(220,'rpt.pos_sales_item_group',13,'POS Sales by Item Group','report','Reports',2200,1,NULL),
(221,'rpt.pos_sales_category',13,'POS Sales by Category','report','Reports',2210,1,NULL),
(222,'rpt.pos_sales_cashier',13,'POS Sales by Cashier & Payment Method','report','Reports',2220,1,NULL),
(223,'rpt.pos_sales_payment',13,'POS Sales by Payment Method','report','Reports',2230,0,NULL),
(224,'rpt.pos_sales_hour',13,'POS Sales by Hour','report','Reports',2240,1,NULL),
(225,'rpt.pos_daily_summary',13,'POS Daily Sales Summary','report','Reports',2250,1,NULL),
(226,'rpt.pos_readings',13,'X- and Z-Reading Summary','report','Reports',2260,1,NULL),
(227,'rpt.pos_discount_log',13,'POS Discount Log','report','Reports',2270,1,NULL),
(228,'rpt.pos_void_log',13,'POS Void and Refund Log','report','Reports',2280,1,NULL),
(229,'rpt.pos_ending_inventory',13,'Ending Inventory - POS Cashier','report','Reports',2290,1,NULL),
(230,'bank.write_checks',14,'Write Checks','transaction','Transactions',2300,1,NULL),
(231,'bank.cash_voucher',14,'Cash Voucher','transaction','Transactions',2310,1,NULL),
(232,'bank.register',14,'Use Register','center','Transactions',2320,1,NULL),
(233,'bank.deposits',14,'Make Deposits','transaction','Transactions',2330,1,NULL),
(234,'bank.transfers',14,'Transfer Funds','transaction','Transactions',2340,1,NULL),
(235,'bank.reconcile',14,'Reconcile','transaction','Transactions',2350,1,NULL),
(236,'rpt.recon_summary',14,'Reconciliation Summary','report','Reports',2360,1,NULL),
(237,'rpt.recon_detail',14,'Reconciliation Detail','report','Reports',2370,1,NULL),
(238,'rpt.recon_discrepancy',14,'Reconciliation Discrepancy','report','Reports',2380,1,NULL),
(239,'rpt.check_register',14,'Check Register','report','Reports',2390,1,NULL),
(240,'bir.form_2307',15,'Print BIR Form 2307','transaction','Transactions',2400,1,NULL),
(241,'bir.wht_table',15,'Withholding Tax Table','setup','Setup',2410,1,NULL),
(242,'rpt.bir_wht_summary',15,'Summary of Taxes Withheld from Vendors/Suppliers','report','BIR Reports',2420,1,NULL),
(243,'rpt.bir_ewt_register',15,'Expanded Withholding Tax Register (1% / 2%)','report','BIR Reports',2430,1,NULL),
(244,'rpt.bir_remittance',15,'Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)','report','BIR Reports',2440,1,NULL),
(245,'rpt.bir_vat_withholding',15,'VAT Withholding Register (1600-VT)','report','BIR Reports',2450,1,NULL),
(246,'rpt.bir_sales_register',15,'Output VAT / Sales Register','report','BIR Reports',2460,1,NULL),
(247,'rpt.bir_purchase_register',15,'Input VAT / Purchases Register','report','BIR Reports',2470,1,NULL),
(248,'rpt.bir_cvat_withheld',15,'Creditable VAT Withheld Schedule (2550Q Schedule 3)','report','BIR Reports',2480,1,NULL),
(249,'rpt.bir_2550q',15,'Quarterly VAT Return Worksheet (2550Q)','report','BIR Reports',2490,1,NULL),
(250,'admin.users',16,'User Accounts','admin','Setup',2500,1,NULL),
(251,'admin.roles',16,'User Role Configuration','admin','Setup',2510,1,NULL),
(252,'admin.page_access',16,'Page Access Configuration','admin','Setup',2520,1,NULL),
(253,'admin.effective_access',16,'Effective Access (View as User)','admin','Setup',2530,1,NULL),
(254,'admin.settings',16,'Company, Security & Closing Date','admin','Setup',2540,1,NULL),
(255,'admin.utilities',16,'Utilities: Verify & Rebuild Data','admin','Setup',2550,1,NULL),
(256,'admin.import',16,'Import from CSV','admin','Utilities',2560,1,NULL),
(257,'admin.backup',16,'Backup & Restore','admin','Utilities',2570,1,NULL),
(258,'admin.multi_company',16,'Multi-Company Setup','admin','Setup',2580,1,NULL),
(259,'admin.audit_trail',16,'Audit Trail','report','Audit Reports',2590,1,NULL),
(260,'admin.txn_history',16,'Transaction History','report','Audit Reports',2600,1,NULL),
(261,'rpt.voided_deleted_summary',16,'Voided and Deleted Transactions Summary','report','Audit Reports',2610,1,NULL),
(262,'rpt.voided_deleted_detail',16,'Voided and Deleted Transactions Detail','report','Audit Reports',2620,1,NULL),
(263,'rpt.user_activity',16,'User Activity','report','Audit Reports',2630,1,NULL),
(264,'rpt.login_history',16,'Login History','report','Audit Reports',2640,1,NULL),
(265,'rpt.user_access',16,'User Access and Permission Changes','report','Audit Reports',2650,1,NULL),
(266,'company.profile',17,'Company Profile & Code','admin','Company',2660,1,NULL),
(267,'company.users',17,'Employees & Users','admin','Company',2670,1,NULL),
(268,'company.join_requests',17,'Join Requests','admin','Company',2680,1,NULL);

-- role_page_access (1180 rows)
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,1,1,0,0,0,1,0),
(2,2,1,0,0,0,1,0),
(2,3,1,0,0,0,1,0),
(2,4,1,0,0,0,1,0),
(2,5,1,0,0,0,1,0),
(2,6,1,0,0,0,1,0),
(2,7,1,0,0,0,1,0),
(2,8,1,0,0,0,1,0),
(2,9,1,0,1,0,1,1),
(2,10,1,0,0,0,1,0),
(2,11,1,0,1,0,1,1),
(2,12,1,0,0,0,1,1),
(2,13,1,0,0,0,1,0),
(2,14,1,1,1,0,1,1),
(2,15,1,0,0,0,1,0),
(2,16,1,0,0,0,1,0),
(2,17,1,0,0,0,1,0),
(2,18,1,0,0,0,1,0),
(2,19,1,0,0,0,1,0),
(2,20,1,0,0,0,1,0),
(2,21,1,0,0,0,1,0),
(2,22,1,0,0,0,1,0),
(2,23,1,0,0,0,1,0),
(2,24,1,0,0,0,1,1),
(2,25,1,0,0,0,1,1),
(2,26,1,0,0,0,1,0),
(2,27,1,0,0,0,1,0),
(2,28,1,0,0,0,1,0),
(2,29,1,0,0,0,1,0),
(2,30,1,0,0,0,1,0),
(2,31,1,0,0,0,1,0),
(2,32,1,0,0,0,1,0),
(2,33,1,0,0,0,1,0),
(2,34,1,0,0,0,1,0),
(2,35,1,0,0,0,1,0),
(2,36,1,0,0,0,1,0),
(2,37,1,0,0,0,1,0),
(2,38,1,0,0,0,1,0),
(2,39,1,0,0,0,1,0),
(2,40,1,0,0,0,1,0),
(2,41,1,0,0,0,1,0),
(2,42,1,0,0,0,1,0),
(2,43,1,0,0,0,1,0),
(2,44,1,0,0,0,1,0),
(2,45,1,0,0,0,1,0),
(2,46,1,0,0,0,1,1),
(2,47,1,0,0,0,1,0),
(2,48,1,0,0,0,1,1),
(2,49,1,0,0,0,1,0),
(2,50,1,0,0,0,1,0),
(2,51,1,0,0,0,1,0),
(2,52,1,0,0,0,1,0),
(2,53,1,0,0,0,1,0),
(2,54,1,0,0,0,1,0),
(2,55,1,1,1,0,1,1),
(2,56,1,0,0,0,1,0),
(2,57,1,0,0,0,1,0),
(2,58,1,0,0,0,1,0),
(2,59,1,0,0,0,1,0),
(2,60,1,1,1,0,1,1),
(2,61,1,0,0,0,1,0),
(2,62,1,0,0,0,1,0),
(2,63,1,0,0,0,1,0),
(2,64,1,0,0,0,1,0),
(2,65,1,0,0,0,1,0),
(2,66,1,1,1,1,1,0),
(2,67,1,0,0,0,1,0),
(2,68,1,0,0,0,1,0),
(2,69,1,0,0,0,1,0),
(2,70,1,0,0,0,1,0),
(2,71,1,0,0,0,1,0),
(2,72,1,0,0,0,1,0),
(2,73,1,0,0,0,1,0),
(2,74,1,0,0,0,1,0),
(2,75,1,0,0,0,1,0),
(2,76,1,0,0,0,1,0),
(2,77,1,0,0,0,1,0),
(2,78,1,0,0,0,1,0),
(2,79,1,0,0,0,1,0),
(2,80,1,0,0,0,1,0),
(2,81,1,0,0,0,1,0),
(2,82,1,0,0,0,1,0),
(2,83,1,0,0,0,1,0),
(2,84,1,0,0,0,1,0),
(2,85,1,0,0,0,1,0),
(2,86,1,0,0,0,1,0),
(2,87,1,0,0,0,1,0),
(2,88,1,0,0,0,1,0),
(2,89,1,0,0,0,1,0),
(2,90,1,0,0,0,1,0),
(2,91,1,0,0,0,1,0),
(2,92,1,0,0,0,1,0),
(2,93,1,0,0,0,1,0),
(2,94,1,0,0,0,1,0),
(2,95,1,0,0,0,1,0),
(2,96,1,0,0,0,1,0),
(2,97,1,0,0,0,1,0),
(2,98,1,0,0,0,1,0),
(2,99,1,0,0,0,1,0),
(2,100,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,101,1,0,0,0,1,0),
(2,102,1,0,0,0,1,0),
(2,103,1,0,0,0,1,0),
(2,104,1,0,0,0,1,0),
(2,105,1,0,0,0,1,0),
(2,106,1,0,0,0,1,0),
(2,107,1,0,0,0,1,0),
(2,108,1,0,0,0,1,0),
(2,109,1,0,0,0,1,0),
(2,110,1,0,0,0,1,0),
(2,111,1,0,0,0,1,0),
(2,112,1,0,0,0,1,0),
(2,113,1,0,0,0,1,0),
(2,114,1,0,0,0,1,0),
(2,115,1,0,0,0,1,0),
(2,116,1,0,0,0,1,0),
(2,117,1,0,0,0,1,0),
(2,118,1,0,0,0,1,0),
(2,119,1,0,0,0,1,0),
(2,120,1,0,0,0,1,0),
(2,121,1,0,0,0,1,0),
(2,122,1,0,0,0,1,0),
(2,123,1,0,0,0,1,0),
(2,124,1,0,0,0,1,0),
(2,125,1,0,0,0,1,0),
(2,126,1,0,0,0,1,0),
(2,127,1,0,0,0,1,0),
(2,128,1,0,0,0,1,0),
(2,129,1,0,0,0,1,0),
(2,130,1,0,0,0,1,0),
(2,131,1,0,0,0,1,0),
(2,132,1,0,0,0,1,0),
(2,133,1,0,0,0,1,0),
(2,134,1,0,0,0,1,0),
(2,135,1,0,0,0,1,0),
(2,136,1,0,0,0,1,0),
(2,137,1,0,0,0,1,0),
(2,138,1,0,0,0,1,0),
(2,139,1,0,0,0,1,0),
(2,140,1,0,0,0,1,0),
(2,141,1,0,0,0,1,0),
(2,142,1,0,0,0,1,0),
(2,143,1,0,0,0,1,0),
(2,144,1,0,0,0,1,0),
(2,145,1,0,0,0,1,0),
(2,146,1,0,0,0,1,0),
(2,147,1,0,0,0,1,0),
(2,148,1,0,0,0,1,0),
(2,149,1,0,0,0,1,0),
(2,150,1,0,0,0,1,0),
(2,151,1,0,0,0,1,0),
(2,152,1,0,0,0,1,0),
(2,153,1,0,0,0,1,0),
(2,154,1,0,0,0,1,0),
(2,155,1,0,0,0,1,0),
(2,156,1,0,0,0,1,0),
(2,157,1,0,0,0,1,0),
(2,158,1,0,0,0,1,0),
(2,159,1,0,0,0,1,0),
(2,160,1,0,0,0,1,0),
(2,161,1,0,0,0,1,0),
(2,162,1,0,0,0,1,0),
(2,163,1,0,0,0,1,0),
(2,164,1,0,0,0,1,0),
(2,165,1,0,0,0,1,0),
(2,166,1,0,0,0,1,0),
(2,167,1,0,0,0,1,0),
(2,168,1,0,0,0,1,0),
(2,169,1,0,0,0,1,0),
(2,170,1,0,0,0,1,0),
(2,171,1,0,0,0,1,0),
(2,172,1,0,0,0,1,0),
(2,173,1,0,0,0,1,0),
(2,174,1,0,0,0,1,0),
(2,175,1,0,0,0,1,0),
(2,176,1,0,0,0,1,0),
(2,177,1,0,0,0,1,0),
(2,178,1,0,0,0,1,0),
(2,179,1,0,0,0,1,0),
(2,180,1,0,0,0,1,0),
(2,181,1,0,0,0,1,0),
(2,182,1,0,0,0,1,0),
(2,183,1,0,0,0,1,0),
(2,184,1,0,0,0,1,0),
(2,185,1,0,0,0,1,0),
(2,186,1,0,0,0,1,0),
(2,187,1,0,0,0,1,0),
(2,188,1,0,0,0,1,0),
(2,189,1,0,0,0,1,0),
(2,190,1,0,0,0,1,0),
(2,191,1,0,0,0,1,0),
(2,192,1,0,0,0,1,0),
(2,193,1,0,0,0,1,0),
(2,194,1,0,0,0,1,0),
(2,195,1,0,0,0,1,0),
(2,196,1,0,0,0,1,0),
(2,197,1,0,0,0,1,0),
(2,198,1,0,0,0,1,0),
(2,199,1,0,0,0,1,0),
(2,200,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(2,201,1,0,0,0,1,0),
(2,202,1,0,0,0,1,0),
(2,203,1,0,0,0,1,0),
(2,204,1,0,0,0,1,0),
(2,205,1,0,0,0,1,0),
(2,206,1,0,0,0,1,0),
(2,207,1,0,0,0,1,0),
(2,208,1,0,0,0,1,0),
(2,209,1,0,0,0,1,0),
(2,210,1,0,0,0,1,0),
(2,211,1,0,0,0,1,0),
(2,212,1,0,0,0,1,0),
(2,213,1,0,0,0,1,0),
(2,214,1,0,0,0,1,0),
(2,215,1,0,0,0,1,0),
(2,216,1,0,0,0,1,0),
(2,217,1,0,0,0,1,0),
(2,218,1,0,0,0,1,0),
(2,219,1,0,0,0,1,0),
(2,220,1,0,0,0,1,0),
(2,221,1,0,0,0,1,0),
(2,222,1,0,0,0,1,0),
(2,223,1,0,0,0,1,0),
(2,224,1,0,0,0,1,0),
(2,225,1,0,0,0,1,0),
(2,226,1,0,0,0,1,0),
(2,227,1,0,0,0,1,0),
(2,228,1,0,0,0,1,0),
(2,229,1,0,0,0,1,0),
(2,230,1,0,0,0,1,0),
(2,231,1,0,0,0,1,0),
(2,232,1,0,0,0,1,0),
(2,233,1,0,0,0,1,0),
(2,234,1,0,0,0,1,0),
(2,235,1,0,0,0,1,0),
(2,236,1,0,0,0,1,0),
(2,237,1,0,0,0,1,0),
(2,238,1,0,0,0,1,0),
(2,239,1,0,0,0,1,0),
(2,240,1,0,0,0,1,0),
(2,241,1,0,0,0,1,0),
(2,242,1,0,0,0,1,0),
(2,243,1,0,0,0,1,0),
(2,244,1,0,0,0,1,0),
(2,245,1,0,0,0,1,0),
(2,246,1,0,0,0,1,0),
(2,247,1,0,0,0,1,0),
(2,248,1,0,0,0,1,0),
(2,249,1,0,0,0,1,0),
(2,259,1,0,0,0,1,0),
(2,260,1,0,0,0,1,0),
(2,261,1,0,0,0,1,0),
(2,262,1,0,0,0,1,0),
(2,263,1,0,0,0,1,0),
(2,264,1,0,0,0,1,0),
(2,265,1,0,0,0,1,0),
(3,1,1,0,0,0,1,0),
(3,2,1,0,0,0,1,0),
(3,3,1,0,0,0,1,0),
(3,4,1,0,0,0,1,0),
(3,5,1,0,0,0,1,0),
(3,6,1,0,0,0,1,0),
(3,7,1,0,0,0,1,0),
(3,8,1,0,0,0,1,0),
(3,9,1,1,1,0,1,1),
(3,10,1,1,1,0,1,1),
(3,11,1,1,1,0,1,1),
(3,12,1,1,1,0,1,1),
(3,13,1,1,1,0,1,1),
(3,14,1,1,1,0,1,1),
(3,15,1,0,0,0,1,0),
(3,16,1,0,0,0,1,0),
(3,17,1,0,0,0,1,0),
(3,18,1,0,0,0,1,0),
(3,19,1,0,0,0,1,0),
(3,21,1,0,0,0,1,0),
(3,22,1,0,0,0,1,0),
(3,23,1,1,1,0,1,0),
(3,27,1,0,0,0,1,0),
(3,28,1,0,0,0,1,0),
(3,29,1,0,0,0,1,0),
(3,30,1,0,0,0,1,0),
(3,31,1,0,0,0,1,0),
(3,32,1,0,0,0,1,0),
(3,33,1,0,0,0,1,0),
(3,34,1,1,1,0,1,0),
(3,35,1,1,1,0,1,0),
(3,36,1,1,1,0,1,0),
(3,37,1,0,0,0,1,0),
(3,38,1,0,0,0,1,0),
(3,39,1,0,0,0,1,0),
(3,40,1,0,0,0,1,0),
(3,41,1,0,0,0,1,0),
(3,42,1,0,0,0,1,0),
(3,45,1,1,1,0,1,0),
(3,46,1,0,0,0,1,0),
(3,49,1,0,0,0,1,0),
(3,50,1,0,0,0,1,0),
(3,51,1,0,0,0,1,0),
(3,52,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(3,53,1,0,0,0,1,0),
(3,54,1,0,0,0,1,0),
(3,55,1,0,0,0,1,0),
(3,56,1,0,0,0,1,0),
(3,57,1,0,0,0,1,0),
(3,58,1,1,1,0,1,0),
(3,59,1,1,1,0,1,0),
(3,64,1,0,0,0,1,0),
(3,67,1,1,1,0,1,0),
(3,68,1,0,0,0,1,0),
(3,69,1,0,0,0,1,0),
(4,1,1,0,0,0,1,0),
(4,2,1,0,0,0,1,0),
(4,3,1,0,0,0,1,0),
(4,4,1,0,0,0,1,0),
(4,5,1,0,0,0,1,0),
(4,6,1,0,0,0,1,0),
(4,7,1,0,0,0,1,0),
(4,8,1,0,0,0,1,0),
(4,9,1,0,0,0,1,0),
(4,11,1,1,1,0,1,1),
(4,12,1,1,1,0,1,1),
(4,13,1,1,1,0,1,0),
(4,14,1,1,1,0,1,1),
(4,15,1,0,0,0,1,0),
(4,16,1,0,0,0,1,0),
(4,17,1,0,0,0,1,0),
(4,18,1,0,0,0,1,0),
(4,19,1,0,0,0,1,0),
(4,27,1,0,0,0,1,0),
(4,28,1,0,0,0,1,0),
(4,29,1,0,0,0,1,0),
(4,30,1,0,0,0,1,0),
(4,31,1,0,0,0,1,0),
(4,32,1,0,0,0,1,0),
(4,33,1,0,0,0,1,0),
(4,37,1,0,0,0,1,0),
(4,38,1,0,0,0,1,0),
(4,39,1,0,0,0,1,0),
(4,40,1,0,0,0,1,0),
(4,41,1,1,1,1,1,1),
(4,42,1,1,1,1,1,1),
(4,43,1,1,1,1,1,1),
(4,44,1,1,1,1,1,1),
(4,45,1,1,1,1,1,1),
(4,46,1,1,1,1,1,1),
(4,47,1,1,1,1,1,1),
(4,48,1,1,1,1,1,1),
(4,49,1,0,0,0,1,0),
(4,50,1,0,0,0,1,0),
(4,51,1,0,0,0,1,0),
(4,52,1,0,0,0,1,0),
(4,53,1,0,0,0,1,0),
(4,54,1,0,0,0,1,0),
(4,57,1,0,0,0,1,0),
(4,68,1,0,0,0,1,0),
(4,69,1,0,0,0,1,0),
(4,103,1,0,0,0,1,0),
(4,165,1,0,0,0,1,0),
(4,167,1,0,0,0,1,0),
(4,180,1,0,0,0,1,0),
(4,181,1,0,0,0,1,0),
(4,193,1,0,0,0,1,0),
(5,1,1,0,0,0,0,0),
(5,8,1,0,0,0,0,0),
(5,9,1,0,1,0,1,0),
(5,11,1,0,0,0,1,0),
(5,12,1,1,1,0,1,0),
(5,13,1,0,0,0,1,0),
(5,17,1,0,0,0,1,0),
(5,18,1,0,0,0,1,0),
(5,19,1,0,0,0,1,0),
(5,22,1,1,0,0,1,0),
(5,23,1,1,1,0,1,0),
(5,34,1,0,0,0,1,0),
(5,35,1,0,1,0,1,0),
(5,41,1,0,0,0,1,0),
(5,42,1,0,0,0,1,0),
(5,45,1,1,1,0,1,0),
(6,1,1,0,0,0,0,0),
(6,8,1,0,0,0,0,0),
(6,9,1,0,0,0,1,0),
(6,11,1,0,1,0,1,0),
(6,12,1,1,1,0,1,0),
(6,14,1,1,1,0,1,1),
(6,18,1,0,0,0,1,0),
(6,41,1,0,0,0,1,0),
(6,42,1,0,0,0,1,0),
(6,43,1,1,1,0,1,0),
(6,44,1,1,1,0,1,0),
(6,45,1,1,1,0,1,1),
(6,46,1,1,1,0,1,1),
(6,47,1,0,0,0,1,0),
(6,48,1,1,1,0,1,0),
(6,49,1,0,0,0,1,0),
(6,50,1,0,0,0,1,0),
(6,51,1,0,0,0,1,0),
(6,52,1,0,0,0,1,0),
(6,53,1,0,0,0,1,0),
(6,54,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(6,181,1,0,0,0,1,0),
(7,1,1,0,0,0,0,0),
(7,8,1,0,0,0,0,0),
(7,41,1,0,0,0,1,0),
(7,42,1,1,1,0,1,0),
(7,43,1,1,1,0,1,0),
(7,44,1,1,1,1,1,0),
(7,45,1,1,1,0,1,0),
(7,46,1,1,1,0,1,0),
(7,47,1,1,1,0,1,0),
(7,48,1,1,1,0,1,0),
(7,49,1,0,0,0,1,0),
(7,50,1,0,0,0,1,0),
(7,51,1,0,0,0,1,0),
(7,52,1,0,0,0,1,0),
(7,53,1,0,0,0,1,0),
(7,54,1,0,0,0,1,0),
(7,180,1,0,0,0,1,0),
(7,181,1,0,0,0,1,0),
(8,1,1,0,0,0,0,0),
(8,8,1,0,0,0,0,0),
(8,43,1,0,0,0,1,0),
(8,44,1,0,0,0,1,0),
(8,45,1,1,0,0,1,0),
(8,46,1,0,1,0,1,0),
(8,48,1,1,0,0,1,0),
(9,1,1,0,0,0,1,0),
(9,2,1,0,0,0,1,0),
(9,3,1,0,0,0,1,0),
(9,4,1,0,0,0,1,0),
(9,5,1,0,0,0,1,0),
(9,6,1,0,0,0,1,0),
(9,7,1,0,0,0,1,0),
(9,8,1,0,0,0,1,0),
(9,48,1,1,1,0,1,0),
(9,158,1,0,0,0,0,0),
(9,159,1,1,1,0,1,0),
(9,165,1,1,1,0,1,0),
(9,167,1,0,0,0,1,0),
(9,168,1,0,0,0,1,0),
(9,175,1,0,0,0,1,0),
(9,176,1,0,0,0,1,0),
(9,180,1,0,0,0,1,0),
(9,181,1,0,0,0,1,0),
(10,1,1,0,0,0,0,0),
(10,8,1,0,0,0,0,0),
(10,12,1,1,0,0,1,0),
(10,46,1,0,1,0,1,0),
(10,48,1,1,0,0,1,0),
(10,54,1,0,0,0,1,0),
(10,165,1,0,0,0,1,0),
(10,167,1,1,1,0,1,0),
(10,180,1,0,0,0,1,0),
(10,181,1,0,0,0,1,0),
(10,183,1,0,0,0,1,0),
(10,186,1,1,1,0,1,0),
(10,187,1,1,0,0,1,0),
(10,190,1,0,0,0,1,0),
(10,193,1,0,0,0,1,0),
(10,198,1,0,0,0,1,0),
(10,200,1,0,0,0,1,0),
(10,201,1,0,0,0,1,0),
(10,207,1,0,0,0,1,0),
(11,1,1,0,0,0,1,0),
(11,2,1,0,0,0,1,0),
(11,3,1,0,0,0,1,0),
(11,4,1,0,0,0,1,0),
(11,5,1,0,0,0,1,0),
(11,6,1,0,0,0,1,0),
(11,7,1,0,0,0,1,0),
(11,8,1,0,0,0,1,0),
(11,9,1,0,0,0,1,0),
(11,10,1,0,0,0,1,0),
(11,14,1,1,1,0,1,1),
(11,20,1,1,1,1,1,1),
(11,21,1,1,1,1,1,1),
(11,22,1,1,1,1,1,1),
(11,23,1,1,1,1,1,1),
(11,24,1,1,1,1,1,1),
(11,25,1,1,1,1,1,1),
(11,26,1,1,1,1,1,1),
(11,27,1,1,1,1,1,1),
(11,28,1,1,1,1,1,1),
(11,29,1,1,1,1,1,1),
(11,30,1,1,1,1,1,1),
(11,31,1,1,1,1,1,1),
(11,32,1,1,1,1,1,1),
(11,33,1,1,1,1,1,1),
(11,35,1,1,1,0,1,0),
(11,40,1,0,0,0,1,0),
(11,55,1,1,1,0,1,1),
(11,56,1,1,1,0,1,1),
(11,57,1,1,1,0,1,1),
(11,60,1,1,1,0,1,0),
(11,61,1,1,1,0,1,0),
(11,62,1,0,0,0,1,0),
(11,64,1,1,1,0,1,0),
(11,68,1,0,0,0,1,0),
(11,69,1,0,0,0,1,0),
(12,1,1,0,0,0,0,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(12,8,1,0,0,0,0,0),
(12,20,1,1,0,0,1,0),
(12,21,1,0,1,0,1,0),
(12,24,1,1,0,0,1,0),
(12,25,1,1,0,0,1,0),
(12,27,1,0,0,0,1,0),
(12,35,1,0,0,0,1,0),
(12,55,1,1,0,0,1,0),
(13,1,1,0,0,0,0,0),
(13,8,1,0,0,0,0,0),
(13,22,1,1,0,0,1,0),
(13,23,1,0,0,0,1,0),
(13,35,1,0,1,0,1,0),
(14,1,1,0,0,0,0,0),
(14,8,1,0,0,0,0,0),
(14,24,1,1,0,0,1,0),
(14,34,1,1,1,0,1,0),
(14,35,1,1,1,0,1,0),
(14,37,1,0,0,0,1,0),
(14,38,1,0,0,0,1,0),
(14,39,1,0,0,0,1,0),
(14,40,1,0,0,0,1,0),
(14,55,1,1,0,0,1,0),
(14,120,1,0,0,0,1,0),
(15,1,1,0,0,0,0,0),
(15,8,1,0,0,0,0,0),
(15,9,1,0,0,0,1,0),
(15,20,1,1,0,0,1,0),
(15,21,1,0,0,0,1,0),
(15,23,1,0,0,0,1,0),
(15,24,1,1,0,0,1,0),
(15,34,1,1,1,0,1,0),
(15,35,1,0,1,0,1,0),
(15,36,1,0,0,0,1,0),
(16,1,1,0,0,0,0,0),
(16,8,1,0,0,0,0,0),
(16,9,1,0,0,0,1,0),
(16,21,1,0,1,0,1,0),
(16,25,1,1,0,0,1,0),
(16,34,1,0,0,0,1,0),
(16,55,1,1,1,0,1,0),
(16,56,1,1,1,0,1,0),
(16,57,1,1,1,0,1,0),
(17,1,1,0,0,0,1,0),
(17,2,1,0,0,0,1,0),
(17,3,1,0,0,0,1,0),
(17,4,1,0,0,0,1,0),
(17,5,1,0,0,0,1,0),
(17,6,1,0,0,0,1,0),
(17,7,1,0,0,0,1,0),
(17,8,1,0,0,0,1,0),
(17,9,1,0,0,0,1,0),
(17,15,1,0,0,0,1,0),
(17,16,1,0,0,0,1,0),
(17,17,1,0,0,0,1,0),
(17,18,1,0,0,0,1,0),
(17,19,1,0,0,0,1,0),
(17,21,1,0,0,0,1,0),
(17,24,1,0,0,0,1,1),
(17,27,1,0,0,0,1,0),
(17,28,1,0,0,0,1,0),
(17,29,1,0,0,0,1,0),
(17,30,1,0,0,0,1,0),
(17,31,1,0,0,0,1,0),
(17,32,1,0,0,0,1,0),
(17,33,1,0,0,0,1,0),
(17,34,1,0,0,0,1,0),
(17,36,1,1,1,0,1,0),
(17,37,1,0,0,0,1,0),
(17,38,1,0,0,0,1,0),
(17,39,1,0,0,0,1,0),
(17,40,1,0,0,0,1,0),
(17,49,1,0,0,0,1,0),
(17,50,1,0,0,0,1,0),
(17,51,1,0,0,0,1,0),
(17,52,1,0,0,0,1,0),
(17,53,1,0,0,0,1,0),
(17,54,1,0,0,0,1,0),
(17,57,1,0,0,0,1,0),
(17,65,1,1,1,0,1,0),
(17,68,1,0,0,0,1,0),
(17,69,1,0,0,0,1,0),
(17,70,1,1,1,1,1,0),
(17,71,1,1,1,1,1,0),
(17,72,1,1,1,1,1,0),
(17,73,1,1,1,1,1,0),
(17,74,1,1,1,1,1,0),
(17,75,1,1,1,1,1,0),
(17,76,1,1,1,1,1,0),
(17,77,1,1,1,1,1,0),
(17,78,1,1,1,1,1,0),
(17,79,1,1,1,1,1,0),
(17,80,1,1,1,1,1,0),
(17,81,1,1,1,1,1,0),
(17,82,1,1,1,1,1,0),
(17,83,1,1,1,1,1,0),
(17,84,1,1,1,1,1,0),
(17,85,1,1,1,1,1,0),
(17,86,1,1,1,1,1,0),
(17,87,1,1,1,1,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,88,1,1,1,1,1,0),
(17,89,1,1,1,1,1,0),
(17,90,1,1,1,1,1,0),
(17,91,1,1,1,1,1,0),
(17,92,1,1,1,1,1,0),
(17,93,1,1,1,1,1,0),
(17,94,1,1,1,1,1,0),
(17,95,1,1,1,1,1,0),
(17,96,1,1,1,1,1,0),
(17,97,1,1,1,1,1,0),
(17,98,1,1,1,1,1,0),
(17,99,1,1,1,1,1,0),
(17,100,1,1,1,1,1,0),
(17,101,1,1,1,1,1,0),
(17,102,1,1,1,1,1,0),
(17,103,1,1,1,1,1,0),
(17,104,1,1,1,1,1,0),
(17,105,1,1,1,1,1,0),
(17,106,1,1,1,1,1,0),
(17,107,1,1,1,1,1,0),
(17,108,1,1,1,1,1,0),
(17,109,1,1,1,1,1,0),
(17,110,1,1,1,1,1,0),
(17,111,1,1,1,1,1,0),
(17,112,1,1,1,1,1,0),
(17,113,1,1,1,1,1,0),
(17,114,1,1,1,1,1,0),
(17,115,1,1,1,1,1,0),
(17,116,1,1,1,1,1,0),
(17,117,1,1,1,1,1,0),
(17,118,1,1,1,1,1,0),
(17,119,1,1,1,1,1,0),
(17,120,1,0,0,0,1,0),
(17,123,1,0,0,0,1,0),
(17,130,1,0,0,0,1,0),
(17,132,1,0,0,0,1,0),
(17,133,1,0,0,0,1,0),
(17,134,1,0,0,0,1,0),
(17,135,1,0,0,0,1,0),
(17,136,1,0,0,0,1,0),
(17,137,1,0,0,0,1,0),
(17,138,1,0,0,0,1,0),
(17,139,1,0,0,0,1,0),
(17,140,1,0,0,0,1,0),
(17,141,1,0,0,0,1,0),
(17,142,1,0,0,0,1,0),
(17,143,1,0,0,0,1,0),
(17,144,1,0,0,0,1,0),
(17,145,1,0,0,0,1,0),
(17,146,1,0,0,0,1,0),
(17,147,1,0,0,0,1,0),
(17,148,1,0,0,0,1,0),
(17,149,1,0,0,0,1,0),
(17,150,1,0,0,0,1,0),
(17,151,1,0,0,0,1,0),
(17,152,1,0,0,0,1,0),
(17,153,1,0,0,0,1,0),
(17,154,1,0,0,0,1,0),
(17,155,1,0,0,0,1,0),
(17,156,1,0,0,0,1,0),
(17,157,1,0,0,0,1,0),
(17,158,1,0,0,0,1,0),
(17,159,1,0,0,0,1,0),
(17,160,1,0,0,0,1,0),
(17,161,1,0,0,0,1,0),
(17,163,1,0,0,0,1,0),
(17,169,1,0,0,0,1,0),
(17,170,1,0,0,0,1,0),
(17,171,1,0,0,0,1,0),
(17,172,1,0,0,0,1,0),
(17,173,1,0,0,0,1,0),
(17,174,1,0,0,0,1,0),
(17,175,1,0,0,0,1,0),
(17,176,1,0,0,0,1,0),
(17,177,1,0,0,0,1,0),
(17,178,1,0,0,0,1,0),
(17,179,1,0,0,0,1,0),
(17,180,1,0,0,0,1,0),
(17,181,1,0,0,0,1,0),
(17,182,1,0,0,0,1,0),
(17,184,1,0,0,0,1,0),
(17,187,1,0,0,0,1,0),
(17,193,1,0,0,0,1,0),
(17,194,1,0,0,0,1,0),
(17,195,1,0,0,0,1,0),
(17,196,1,0,0,0,1,0),
(17,197,1,0,0,0,1,0),
(17,198,1,0,0,0,1,0),
(17,199,1,0,0,0,1,0),
(17,200,1,0,0,0,1,0),
(17,201,1,0,0,0,1,0),
(17,202,1,0,0,0,1,0),
(17,203,1,0,0,0,1,0),
(17,204,1,0,0,0,1,0),
(17,205,1,0,0,0,1,0),
(17,206,1,0,0,0,1,0),
(17,207,1,0,0,0,1,0),
(17,208,1,0,0,0,1,0),
(17,209,1,0,0,0,1,0),
(17,210,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(17,211,1,0,0,0,1,0),
(17,212,1,0,0,0,1,0),
(17,218,1,0,0,0,1,0),
(17,219,1,0,0,0,1,0),
(17,220,1,0,0,0,1,0),
(17,221,1,0,0,0,1,0),
(17,222,1,0,0,0,1,0),
(17,223,1,0,0,0,1,0),
(17,224,1,0,0,0,1,0),
(17,225,1,0,0,0,1,0),
(17,226,1,0,0,0,1,0),
(17,227,1,0,0,0,1,0),
(17,228,1,0,0,0,1,0),
(17,229,1,0,0,0,1,0),
(17,230,1,1,1,1,1,0),
(17,231,1,1,1,1,1,0),
(17,232,1,1,1,1,1,0),
(17,233,1,1,1,1,1,0),
(17,234,1,1,1,1,1,0),
(17,235,1,1,1,1,1,0),
(17,236,1,1,1,1,1,0),
(17,237,1,1,1,1,1,0),
(17,238,1,1,1,1,1,0),
(17,239,1,1,1,1,1,0),
(17,240,1,1,1,1,1,0),
(17,241,1,1,1,1,1,0),
(17,242,1,1,1,1,1,0),
(17,243,1,1,1,1,1,0),
(17,244,1,1,1,1,1,0),
(17,245,1,1,1,1,1,0),
(17,246,1,1,1,1,1,0),
(17,247,1,1,1,1,1,0),
(17,248,1,1,1,1,1,0),
(17,249,1,1,1,1,1,0),
(17,255,1,0,0,0,1,0),
(18,1,1,0,0,0,1,0),
(18,2,1,0,0,0,1,0),
(18,3,1,0,0,0,1,0),
(18,4,1,0,0,0,1,0),
(18,5,1,0,0,0,1,0),
(18,6,1,0,0,0,1,0),
(18,7,1,0,0,0,1,0),
(18,8,1,0,0,0,1,0),
(18,24,1,0,0,0,1,1),
(18,25,1,0,1,0,1,0),
(18,32,1,0,0,0,1,0),
(18,36,1,1,1,0,1,0),
(18,40,1,0,0,0,1,0),
(18,132,1,0,0,0,1,0),
(18,159,1,0,0,0,1,0),
(18,163,1,1,1,0,1,1),
(18,170,1,0,0,0,1,0),
(18,174,1,0,0,0,1,0),
(18,230,1,1,1,1,1,0),
(18,231,1,1,1,1,1,0),
(18,232,1,1,1,1,1,0),
(18,233,1,1,1,1,1,0),
(18,234,1,1,1,1,1,0),
(18,235,1,1,1,1,1,0),
(18,236,1,1,1,1,1,0),
(18,237,1,1,1,1,1,0),
(18,238,1,1,1,1,1,0),
(18,239,1,1,1,1,1,0),
(19,1,1,0,0,0,1,0),
(19,2,1,0,0,0,1,0),
(19,3,1,0,0,0,1,0),
(19,4,1,0,0,0,1,0),
(19,5,1,0,0,0,1,0),
(19,6,1,0,0,0,1,0),
(19,7,1,0,0,0,1,0),
(19,8,1,0,0,0,1,0),
(19,21,1,0,0,0,1,0),
(19,34,1,0,0,0,1,0),
(19,120,1,1,1,0,1,0),
(19,121,1,1,1,0,1,0),
(19,122,1,1,1,0,1,0),
(19,123,1,1,1,0,1,0),
(19,124,1,1,1,0,1,0),
(19,125,1,1,1,0,1,0),
(19,126,1,1,1,0,1,0),
(19,127,1,1,1,0,1,0),
(19,128,1,1,1,0,1,0),
(19,129,1,1,1,0,1,0),
(19,130,1,1,1,0,1,0),
(19,131,1,1,1,0,1,0),
(19,132,1,0,0,0,1,0),
(19,133,1,0,0,0,1,0),
(19,134,1,0,0,0,1,0),
(19,135,1,0,0,0,1,0),
(19,136,1,0,0,0,1,0),
(19,137,1,0,0,0,1,0),
(19,138,1,0,0,0,1,0),
(19,139,1,0,0,0,1,0),
(19,140,1,0,0,0,1,0),
(19,141,1,0,0,0,1,0),
(19,142,1,0,0,0,1,0),
(19,143,1,0,0,0,1,0),
(19,181,1,0,0,0,0,0),
(20,1,1,0,0,0,1,0),
(20,2,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(20,3,1,0,0,0,1,0),
(20,4,1,0,0,0,1,0),
(20,5,1,0,0,0,1,0),
(20,6,1,0,0,0,1,0),
(20,7,1,0,0,0,1,0),
(20,8,1,0,0,0,1,0),
(20,48,1,0,0,0,1,0),
(20,159,1,1,1,0,1,0),
(20,160,1,1,1,0,1,0),
(20,161,1,1,1,0,1,0),
(20,162,1,1,1,0,1,0),
(20,163,1,1,1,0,1,0),
(20,164,1,1,1,0,1,0),
(20,165,1,1,1,0,1,0),
(20,166,1,1,1,0,1,0),
(20,167,1,1,1,0,1,0),
(20,168,1,1,1,0,1,0),
(20,169,1,1,1,0,1,0),
(20,170,1,0,0,0,1,0),
(20,171,1,0,0,0,1,0),
(20,172,1,0,0,0,1,0),
(20,173,1,0,0,0,1,0),
(20,174,1,0,0,0,1,0),
(20,175,1,0,0,0,1,0),
(20,176,1,0,0,0,1,0),
(20,177,1,0,0,0,1,0),
(20,178,1,0,0,0,1,0),
(20,179,1,0,0,0,1,0),
(20,181,1,0,0,0,0,0),
(20,240,1,0,0,0,1,0),
(20,241,1,0,0,0,0,0),
(20,242,1,0,0,0,1,0),
(20,243,1,0,0,0,1,0),
(20,244,1,0,0,0,1,0),
(20,245,1,0,0,0,1,0),
(21,1,1,0,0,0,1,0),
(21,2,1,0,0,0,1,0),
(21,3,1,0,0,0,1,0),
(21,4,1,0,0,0,1,0),
(21,5,1,0,0,0,1,0),
(21,6,1,0,0,0,1,0),
(21,7,1,0,0,0,1,0),
(21,8,1,0,0,0,1,0),
(21,165,1,0,0,0,1,0),
(21,167,1,1,1,0,1,0),
(21,180,1,0,0,0,1,0),
(21,181,1,1,1,0,1,0),
(21,182,1,1,1,1,1,0),
(21,183,1,1,1,0,1,0),
(21,184,1,1,1,0,1,0),
(21,186,1,1,1,0,1,0),
(21,187,1,1,1,0,1,0),
(21,188,1,1,1,0,1,0),
(21,190,1,0,0,0,1,0),
(21,193,1,0,0,0,1,0),
(21,198,1,0,0,0,1,0),
(21,199,1,0,0,0,1,0),
(21,200,1,0,0,0,1,0),
(21,201,1,0,0,0,1,0),
(21,207,1,0,0,0,1,0),
(21,208,1,0,0,0,1,0),
(21,210,1,0,0,0,1,0),
(21,211,1,0,0,0,1,0),
(21,212,1,0,0,0,1,0),
(22,1,1,0,0,0,1,0),
(22,2,1,0,0,0,1,0),
(22,3,1,0,0,0,1,0),
(22,4,1,0,0,0,1,0),
(22,5,1,0,0,0,1,0),
(22,6,1,0,0,0,1,0),
(22,7,1,0,0,0,1,0),
(22,8,1,0,0,0,1,0),
(22,21,1,0,0,0,1,0),
(22,27,1,0,0,0,1,0),
(22,34,1,1,0,0,1,0),
(22,37,1,0,0,0,1,0),
(22,120,1,0,0,0,1,0),
(22,121,1,1,1,0,1,0),
(22,122,1,1,1,0,1,0),
(22,123,1,1,1,0,1,0),
(22,144,1,0,0,0,1,0),
(22,145,1,0,0,0,1,0),
(22,146,1,0,0,0,1,0),
(22,147,1,0,0,0,1,0),
(22,148,1,0,0,0,1,0),
(22,149,1,0,0,0,1,0),
(22,150,1,0,0,0,1,0),
(22,151,1,0,0,0,1,0),
(22,152,1,0,0,0,1,0),
(22,153,1,0,0,0,1,0),
(22,154,1,0,0,0,1,0),
(22,155,1,0,0,0,1,0),
(22,156,1,0,0,0,1,0),
(22,157,1,0,0,0,1,0),
(22,158,1,0,0,0,1,0),
(23,1,1,0,0,0,0,0),
(23,213,1,1,0,0,1,0),
(23,214,1,1,0,0,1,0),
(23,215,1,1,0,1,1,0),
(23,229,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(24,1,1,0,0,0,0,0),
(24,2,1,0,0,0,0,0),
(24,213,1,1,1,1,1,1),
(24,214,1,1,1,1,1,1),
(24,215,1,1,1,1,1,1),
(24,216,1,1,1,1,1,1),
(24,217,1,0,0,0,1,0),
(24,218,1,0,0,0,1,0),
(24,219,1,0,0,0,1,0),
(24,220,1,0,0,0,1,0),
(24,221,1,0,0,0,1,0),
(24,222,1,0,0,0,1,0),
(24,223,1,0,0,0,1,0),
(24,224,1,0,0,0,1,0),
(24,225,1,0,0,0,1,0),
(24,226,1,0,0,0,1,0),
(24,227,1,0,0,0,1,0),
(24,228,1,0,0,0,1,0),
(24,229,1,0,0,0,1,0),
(25,1,1,0,0,0,1,0),
(25,2,1,0,0,0,1,0),
(25,3,1,0,0,0,1,0),
(25,4,1,0,0,0,1,0),
(25,5,1,0,0,0,1,0),
(25,6,1,0,0,0,1,0),
(25,7,1,0,0,0,1,0),
(25,8,1,0,0,0,1,0),
(25,9,1,0,0,0,1,0),
(25,10,1,0,0,0,1,0),
(25,11,1,0,0,0,1,0),
(25,12,1,0,0,0,1,0),
(25,13,1,0,0,0,1,0),
(25,14,1,0,0,0,1,0),
(25,15,1,0,0,0,1,0),
(25,16,1,0,0,0,1,0),
(25,17,1,0,0,0,1,0),
(25,18,1,0,0,0,1,0),
(25,19,1,0,0,0,1,0),
(25,21,1,0,0,0,1,0),
(25,23,1,0,0,0,1,0),
(25,25,1,0,0,0,1,0),
(25,26,1,0,0,0,1,0),
(25,27,1,0,0,0,1,0),
(25,28,1,0,0,0,1,0),
(25,29,1,0,0,0,1,0),
(25,30,1,0,0,0,1,0),
(25,31,1,0,0,0,1,0),
(25,32,1,0,0,0,1,0),
(25,33,1,0,0,0,1,0),
(25,34,1,0,0,0,1,0),
(25,35,1,0,0,0,1,0),
(25,36,1,0,0,0,1,0),
(25,37,1,0,0,0,1,0),
(25,38,1,0,0,0,1,0),
(25,39,1,0,0,0,1,0),
(25,40,1,0,0,0,1,0),
(25,41,1,0,0,0,1,0),
(25,42,1,0,0,0,1,0),
(25,43,1,0,0,0,1,0),
(25,44,1,0,0,0,1,0),
(25,45,1,0,0,0,1,0),
(25,46,1,0,0,0,1,0),
(25,47,1,0,0,0,1,0),
(25,48,1,0,0,0,1,0),
(25,49,1,0,0,0,1,0),
(25,50,1,0,0,0,1,0),
(25,51,1,0,0,0,1,0),
(25,52,1,0,0,0,1,0),
(25,53,1,0,0,0,1,0),
(25,54,1,0,0,0,1,0),
(25,55,1,0,0,0,1,0),
(25,56,1,0,0,0,1,0),
(25,57,1,0,0,0,1,0),
(25,58,1,0,0,0,1,0),
(25,59,1,0,0,0,1,0),
(25,60,1,0,0,0,1,0),
(25,61,1,0,0,0,1,0),
(25,62,1,0,0,0,1,0),
(25,63,1,0,0,0,1,0),
(25,64,1,0,0,0,1,0),
(25,65,1,0,0,0,1,0),
(25,66,1,0,0,0,1,0),
(25,67,1,0,0,0,1,0),
(25,68,1,0,0,0,1,0),
(25,69,1,0,0,0,1,0),
(25,70,1,0,0,0,1,0),
(25,71,1,0,0,0,1,0),
(25,72,1,0,0,0,1,0),
(25,73,1,0,0,0,1,0),
(25,74,1,0,0,0,1,0),
(25,75,1,0,0,0,1,0),
(25,76,1,0,0,0,1,0),
(25,77,1,0,0,0,1,0),
(25,78,1,0,0,0,1,0),
(25,79,1,0,0,0,1,0),
(25,80,1,0,0,0,1,0),
(25,81,1,0,0,0,1,0),
(25,82,1,0,0,0,1,0),
(25,83,1,0,0,0,1,0),
(25,84,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(25,85,1,0,0,0,1,0),
(25,86,1,0,0,0,1,0),
(25,87,1,0,0,0,1,0),
(25,88,1,0,0,0,1,0),
(25,89,1,0,0,0,1,0),
(25,90,1,0,0,0,1,0),
(25,91,1,0,0,0,1,0),
(25,92,1,0,0,0,1,0),
(25,93,1,0,0,0,1,0),
(25,94,1,0,0,0,1,0),
(25,95,1,0,0,0,1,0),
(25,96,1,0,0,0,1,0),
(25,97,1,0,0,0,1,0),
(25,98,1,0,0,0,1,0),
(25,99,1,0,0,0,1,0),
(25,100,1,0,0,0,1,0),
(25,101,1,0,0,0,1,0),
(25,102,1,0,0,0,1,0),
(25,103,1,0,0,0,1,0),
(25,104,1,0,0,0,1,0),
(25,105,1,0,0,0,1,0),
(25,106,1,0,0,0,1,0),
(25,107,1,0,0,0,1,0),
(25,108,1,0,0,0,1,0),
(25,109,1,0,0,0,1,0),
(25,110,1,0,0,0,1,0),
(25,111,1,0,0,0,1,0),
(25,112,1,0,0,0,1,0),
(25,113,1,0,0,0,1,0),
(25,114,1,0,0,0,1,0),
(25,115,1,0,0,0,1,0),
(25,116,1,0,0,0,1,0),
(25,117,1,0,0,0,1,0),
(25,118,1,0,0,0,1,0),
(25,119,1,0,0,0,1,0),
(25,120,1,0,0,0,1,0),
(25,121,1,0,0,0,1,0),
(25,122,1,0,0,0,1,0),
(25,123,1,0,0,0,1,0),
(25,124,1,0,0,0,1,0),
(25,125,1,0,0,0,1,0),
(25,126,1,0,0,0,1,0),
(25,127,1,0,0,0,1,0),
(25,128,1,0,0,0,1,0),
(25,129,1,0,0,0,1,0),
(25,130,1,0,0,0,1,0),
(25,131,1,0,0,0,1,0),
(25,132,1,0,0,0,1,0),
(25,133,1,0,0,0,1,0),
(25,134,1,0,0,0,1,0),
(25,135,1,0,0,0,1,0),
(25,136,1,0,0,0,1,0),
(25,137,1,0,0,0,1,0),
(25,138,1,0,0,0,1,0),
(25,139,1,0,0,0,1,0),
(25,140,1,0,0,0,1,0),
(25,141,1,0,0,0,1,0),
(25,142,1,0,0,0,1,0),
(25,143,1,0,0,0,1,0),
(25,144,1,0,0,0,1,0),
(25,145,1,0,0,0,1,0),
(25,146,1,0,0,0,1,0),
(25,147,1,0,0,0,1,0),
(25,148,1,0,0,0,1,0),
(25,149,1,0,0,0,1,0),
(25,150,1,0,0,0,1,0),
(25,151,1,0,0,0,1,0),
(25,152,1,0,0,0,1,0),
(25,153,1,0,0,0,1,0),
(25,154,1,0,0,0,1,0),
(25,155,1,0,0,0,1,0),
(25,156,1,0,0,0,1,0),
(25,157,1,0,0,0,1,0),
(25,158,1,0,0,0,1,0),
(25,159,1,0,0,0,1,0),
(25,160,1,0,0,0,1,0),
(25,161,1,0,0,0,1,0),
(25,162,1,0,0,0,1,0),
(25,163,1,0,0,0,1,0),
(25,164,1,0,0,0,1,0),
(25,165,1,0,0,0,1,0),
(25,166,1,0,0,0,1,0),
(25,167,1,0,0,0,1,0),
(25,168,1,0,0,0,1,0),
(25,169,1,0,0,0,1,0),
(25,170,1,0,0,0,1,0),
(25,171,1,0,0,0,1,0),
(25,172,1,0,0,0,1,0),
(25,173,1,0,0,0,1,0),
(25,174,1,0,0,0,1,0),
(25,175,1,0,0,0,1,0),
(25,176,1,0,0,0,1,0),
(25,177,1,0,0,0,1,0),
(25,178,1,0,0,0,1,0),
(25,179,1,0,0,0,1,0),
(25,180,1,0,0,0,1,0),
(25,181,1,0,0,0,1,0),
(25,182,1,0,0,0,1,0),
(25,183,1,0,0,0,1,0),
(25,184,1,0,0,0,1,0);
INSERT INTO `role_page_access` (`role_id`,`page_id`,`allowed`,`can_create`,`can_edit`,`can_delete`,`can_print`,`can_approve`) VALUES
(25,185,1,0,0,0,1,0),
(25,186,1,0,0,0,1,0),
(25,187,1,0,0,0,1,0),
(25,188,1,0,0,0,1,0),
(25,189,1,0,0,0,1,0),
(25,190,1,0,0,0,1,0),
(25,191,1,0,0,0,1,0),
(25,192,1,0,0,0,1,0),
(25,193,1,0,0,0,1,0),
(25,194,1,0,0,0,1,0),
(25,195,1,0,0,0,1,0),
(25,196,1,0,0,0,1,0),
(25,197,1,0,0,0,1,0),
(25,198,1,0,0,0,1,0),
(25,199,1,0,0,0,1,0),
(25,200,1,0,0,0,1,0),
(25,201,1,0,0,0,1,0),
(25,202,1,0,0,0,1,0),
(25,203,1,0,0,0,1,0),
(25,204,1,0,0,0,1,0),
(25,205,1,0,0,0,1,0),
(25,206,1,0,0,0,1,0),
(25,207,1,0,0,0,1,0),
(25,208,1,0,0,0,1,0),
(25,209,1,0,0,0,1,0),
(25,210,1,0,0,0,1,0),
(25,211,1,0,0,0,1,0),
(25,212,1,0,0,0,1,0),
(25,215,1,0,0,0,1,0),
(25,216,1,0,0,0,1,0),
(25,217,1,0,0,0,1,0),
(25,218,1,0,0,0,1,0),
(25,219,1,0,0,0,1,0),
(25,220,1,0,0,0,1,0),
(25,221,1,0,0,0,1,0),
(25,222,1,0,0,0,1,0),
(25,223,1,0,0,0,1,0),
(25,224,1,0,0,0,1,0),
(25,225,1,0,0,0,1,0),
(25,226,1,0,0,0,1,0),
(25,227,1,0,0,0,1,0),
(25,228,1,0,0,0,1,0),
(25,229,1,0,0,0,1,0),
(25,230,1,0,0,0,1,0),
(25,231,1,0,0,0,1,0),
(25,232,1,0,0,0,1,0),
(25,233,1,0,0,0,1,0),
(25,234,1,0,0,0,1,0),
(25,235,1,0,0,0,1,0),
(25,236,1,0,0,0,1,0),
(25,237,1,0,0,0,1,0),
(25,238,1,0,0,0,1,0),
(25,239,1,0,0,0,1,0),
(25,240,1,0,0,0,1,0),
(25,241,1,0,0,0,1,0),
(25,242,1,0,0,0,1,0),
(25,243,1,0,0,0,1,0),
(25,244,1,0,0,0,1,0),
(25,245,1,0,0,0,1,0),
(25,246,1,0,0,0,1,0),
(25,247,1,0,0,0,1,0),
(25,248,1,0,0,0,1,0),
(25,249,1,0,0,0,1,0),
(25,259,1,0,0,0,1,0),
(25,260,1,0,0,0,1,0),
(25,261,1,0,0,0,1,0),
(25,262,1,0,0,0,1,0),
(25,263,1,0,0,0,1,0),
(25,264,1,0,0,0,1,0),
(25,265,1,0,0,0,1,0),
(26,1,1,0,0,0,0,0),
(26,9,1,0,0,0,1,0),
(26,11,1,0,0,0,1,0),
(26,17,1,0,0,0,1,0),
(26,19,1,0,0,0,1,0),
(26,23,1,0,0,0,1,0),
(26,28,1,0,0,0,1,0),
(26,41,1,0,0,0,1,0),
(26,42,1,0,0,0,1,0),
(26,53,1,0,0,0,1,0);

-- role_special_permissions (73 rows)
INSERT INTO `role_special_permissions` (`role_id`,`perm_key`,`perm_value`) VALUES
(2,'design_reports','1'),
(2,'shp_approve_limit',''),
(2,'shp_departure_override','1'),
(2,'shp_emergency_purchase','1'),
(2,'shp_fare_override','1'),
(2,'shp_safety_release','1'),
(2,'shp_view_pii','1'),
(2,'view_bank_balances','1'),
(2,'view_costs','1'),
(3,'shp_approve_limit','100000'),
(3,'shp_departure_override','1'),
(3,'shp_safety_release','1'),
(3,'shp_view_pii','1'),
(3,'view_costs','1'),
(4,'shp_approve_limit','250000'),
(4,'shp_emergency_purchase','1'),
(4,'shp_safety_release','1'),
(4,'view_costs','1'),
(5,'shp_view_pii','1'),
(6,'shp_approve_limit','50000'),
(6,'shp_emergency_purchase','1'),
(6,'shp_technical_release','1'),
(6,'view_costs','1'),
(7,'view_costs','1'),
(9,'view_costs','1'),
(10,'max_amount','20000'),
(10,'view_costs','0'),
(11,'max_amount','50000'),
(11,'shp_approve_limit','50000'),
(11,'shp_fare_override','1'),
(11,'shp_view_pii','1'),
(12,'max_amount','0'),
(12,'shp_view_pii','0'),
(13,'shp_view_pii','1'),
(14,'credit_limit_override','0'),
(16,'shp_view_pii','1'),
(17,'closed_period_changes','0'),
(17,'coa_changes','1'),
(17,'credit_limit_override','0'),
(17,'design_reports','1'),
(17,'max_amount',''),
(17,'max_discount_pct','0'),
(17,'max_price_override_pct','0'),
(17,'view_bank_balances','1'),
(17,'view_costs','1'),
(18,'max_amount',''),
(18,'view_bank_balances','1'),
(19,'credit_limit_override','0'),
(19,'max_amount','50000'),
(19,'max_discount_pct','10'),
(19,'max_price_override_pct','5'),
(19,'view_bank_balances','0'),
(19,'view_costs','0'),
(20,'max_amount','100000'),
(20,'view_bank_balances','0'),
(20,'view_costs','1'),
(21,'max_amount','20000'),
(21,'view_costs','0'),
(22,'max_discount_pct','5'),
(22,'max_price_override_pct','0'),
(22,'view_costs','0'),
(23,'max_amount','0'),
(23,'max_discount_pct','5'),
(23,'max_price_override_pct','0'),
(23,'view_costs','0'),
(24,'max_amount','20000'),
(24,'max_discount_pct','30'),
(24,'max_price_override_pct','20'),
(24,'view_costs','0'),
(25,'shp_view_pii','1'),
(25,'view_bank_balances','1'),
(25,'view_costs','1'),
(26,'shp_view_pii','1');

-- audit_trail (1 rows)
INSERT INTO `audit_trail` (`id`,`created_at`,`user_id`,`username`,`user_roles`,`ip_address`,`workstation`,`site_id`,`terminal_id`,`module`,`page_key`,`action`,`record_type`,`record_id`,`record_ref`,`amount`,`changes`,`snapshot`,`reason`,`approved_by`) VALUES
(1,'2026-10-31 22:42:54',1,'admin','Administrator','cli','CLI',NULL,NULL,'admin','admin.multi_company','multi_company_setup','company',1,'Visayas Star Shipping Lines, Inc.',NULL,NULL,NULL,'Registered as Company 1 of the platform. Company Owner: admin. Super Admin: superadmin.',NULL);

-- sequences (37 rows)
INSERT INTO `sequences` (`seq_key`,`prefix`,`next_no`,`pad`) VALUES
('adjustment','ADJ-',1,5),
('advance_apply','AAP-',1,5),
('asset','FA-',1,5),
('bill','BILL-',1,5),
('build','BLD-',1,5),
('cash_voucher','CAV-',1,5),
('check','',1001,4),
('credit_memo','CM-',1,5),
('debit_memo','DM-',1,5),
('deposit','DEP-',1,5),
('estimate','EST-',1,5),
('finance_charge','FC-',1,5),
('inv_transfer','IT-',1,5),
('invoice','INV-',1,5),
('item_receipt','IR-',1,5),
('journal','JE-',1,5),
('maintenance','MNT-',1,5),
('payment','PMT-',1,5),
('pos','POS-',1,6),
('processing','PRC-',1,5),
('purchase_order','PO-',1,5),
('sales_order','SO-',1,5),
('sales_receipt','SR-',1,6),
('shp_case','CS-',1,6),
('shp_defect','DR-',1,6),
('shp_drydock','DD-',1,4),
('shp_payment','PAY-',1,7),
('shp_pr','PR-',1,6),
('shp_refund','RF-',1,6),
('shp_shift','SH-',1,6),
('shp_ticket','ET-',1,8),
('shp_vehicle','VP-',1,7),
('shp_waybill','WB-',1,7),
('shp_wo','WO-',1,6),
('statement_charge','SC-',1,5),
('transfer','TRF-',1,5),
('vendor_advance','ADV-',1,5);

-- accounts (115 rows)
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`) VALUES
(1,'1000','Cash','Bank','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(2,'1001','Cash on Hand - Ticket Offices','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(3,'1002','Cash on Hand - Onboard Canteens','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(4,'1005','Petty Cash Fund','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(5,'1006','Revolving Fund - Vessels','Bank','Asset','Debit',1,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(6,'1010','Cash in Bank - BDO Current','Bank','Asset','Debit',1,NULL,'0054-1188-2207',0,1,0,'2026-10-31 22:42:52',NULL),
(7,'1020','Cash in Bank - Landbank Savings','Bank','Asset','Debit',1,NULL,'3361-0092-41',0,1,0,'2026-10-31 22:42:52',NULL),
(8,'1050','Undeposited Funds','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(9,'1060','Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(10,'1065','E-wallet Clearing (GCash / Maya)','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(11,'1070','P.O. Card Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(12,'1075','Online Payment Gateway Clearing','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(13,'1100','Accounts Receivable - Trade','Accounts Receivable','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(14,'1150','Allowance for Doubtful Accounts','Other Current Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(15,'1200','Inventory - Spare Parts','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(16,'1210','Inventory - Fuel and Lubricants','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(17,'1220','Inventory - Canteen Merchandise','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(18,'1230','Inventory - Ship Stores and Consumables','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(19,'1300','Input Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(20,'1310','Creditable Withholding Tax','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(21,'1315','Creditable VAT Withheld','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(22,'1400','Prepaid Expenses','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(23,'1405','Prepaid Insurance - Hull and Machinery','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(24,'1410','Advances to Suppliers','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(25,'1420','Advances to Officers and Crew','Other Current Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(26,'1500','Land','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(27,'1510','Terminal Buildings and Improvements','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(28,'1515','Accumulated Depreciation - Buildings','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(29,'1520','Vessels and Floating Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(30,'1525','Accumulated Depreciation - Vessels','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(31,'1526','Dry-docking Costs (capitalized)','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(32,'1527','Accumulated Amortization - Dry-docking','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(33,'1530','Transportation Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(34,'1535','Accumulated Depreciation - Transportation','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(35,'1540','Furniture and Fixtures','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(36,'1545','Accumulated Depreciation - Furniture','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(37,'1550','Computer and Ticketing Equipment','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(38,'1555','Accumulated Depreciation - Computer and Ticketing','Fixed Asset','Asset','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(39,'1560','Capital Work in Progress - Vessel Repairs','Fixed Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(40,'1600','Security Deposits','Other Asset','Asset','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(41,'2000','Accounts Payable - Trade','Accounts Payable','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(42,'2050','Company Credit Card','Credit Card','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(43,'2100','Output Tax','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(44,'2110','Withholding Tax Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(45,'2115','VAT Withheld Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(46,'2120','Gift Vouchers Outstanding','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(47,'2130','Customer Deposits','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(48,'2140','Deferred Passenger Revenue','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(49,'2145','Deferred Freight Revenue','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(50,'2150','Terminal Fee Payable - Port Authority','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(51,'2155','Insurance Premium Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(52,'2160','Refunds Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(53,'2170','Agent Commissions Payable','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(54,'2200','Accrued Expenses','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(55,'2210','Accrued Crew Wages','Other Current Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(56,'2500','Loans Payable - Vessel Financing','Long Term Liability','Liability','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(57,'3000','Capital Stock','Equity','Equity','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(58,'3100','Dividends Declared','Equity','Equity','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(59,'3200','Retained Earnings','Equity','Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(60,'3900','Opening Balance Equity','Equity','Equity','Credit',NULL,NULL,NULL,0,1,1,'2026-10-31 22:42:52',NULL),
(61,'4000','Passenger Fare Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(62,'4005','Fuel Surcharge Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(63,'4010','Vehicle / RoRo Freight Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(64,'4020','Cargo Freight Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(65,'4025','Cargo Handling and Insurance Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(66,'4030','Excess Baggage Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(67,'4040','Ticket Change and Cancellation Fees','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(68,'4045','Charter Revenue','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(69,'4050','Sales Returns and Allowances','Income','Income','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(70,'4060','Sales Discounts','Income','Income','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(71,'4065','Statutory Fare Discounts','Income','Income','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(72,'4070','Item Group Price Variance','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(73,'4080','Onboard Canteen Sales','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(74,'4090','Forfeited / No-show Fares','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(75,'4110','Reimbursable Expense Income','Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(76,'4100','Finance Charge Income','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(77,'4200','Interest Income','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(78,'4300','Gain (Loss) on Disposal of Assets','Other Income','Income','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(79,'5000','Cost of Canteen Sales','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(80,'5050','Purchase Discounts','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(81,'5100','Inventory Adjustments and Shrinkage','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(82,'5200','Internal Labor Absorbed','Cost of Goods Sold','Expense','Credit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(83,'5300','Voyage Fuel Expense','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(84,'5305','Fuel Shrinkage and Variance','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(85,'5310','Lubricants Expense','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(86,'5320','Port Charges and Dockage','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(87,'5330','Pilotage and Tug Assistance','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(88,'5340','Crew Meal and Travel Allowances','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(89,'5345','Crew Salaries and Wages','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(90,'5350','Vessel Repairs and Maintenance','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(91,'5355','Spare Parts Consumed','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(92,'5360','Hull and Machinery Insurance','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(93,'5380','Cargo Handling and Arrastre','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(94,'5390','Agent Commissions','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(95,'5395','Fresh Water and Ship Stores','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(96,'5400','Cargo Claims and Passenger Compensation','Cost of Goods Sold','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(97,'6000','Salaries and Wages - Shore Staff','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(98,'6010','Rent Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(99,'6020','Utilities Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(100,'6030','Repairs and Maintenance - Office','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL);
INSERT INTO `accounts` (`id`,`code`,`name`,`account_type`,`category`,`normal_balance`,`parent_id`,`description`,`bank_account_no`,`with_sl`,`is_active`,`is_system`,`created_at`,`updated_at`) VALUES
(101,'6040','Office Supplies Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(102,'6050','Fuel and Oil - Service Vehicles','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(103,'6060','Meals and Representation','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(104,'6070','Professional Fees','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(105,'6080','Bank, Gateway and Merchant Charges','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(106,'6090','Depreciation Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(107,'6095','Amortization of Dry-docking','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(108,'6100','Freight and Delivery','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(109,'6110','Purchases - Non-inventory','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(110,'6200','Cash Over and Short','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(111,'6300','Advertising and Promotions','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(112,'6400','Licenses, Permits and Certificates','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(113,'6500','Communication Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(114,'6900','Miscellaneous Expense','Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL),
(115,'7000','Interest Expense','Other Expense','Expense','Debit',NULL,NULL,NULL,0,1,0,'2026-10-31 22:42:52',NULL);

-- default_accounts (53 rows)
INSERT INTO `default_accounts` (`setting_key`,`label`,`module`,`account_id`) VALUES
('ap','Accounts Payable','vend',41),
('ar','Accounts Receivable','cust',13),
('card_clearing','Card Clearing','pos',9),
('cash_on_hand','Cash on Hand (Register)','pos',3),
('cash_over_short','Cash Over and Short','pos',110),
('cogs','Cost of Goods Sold','inv',79),
('cvat_withheld','Creditable VAT Withheld','cust',21),
('cwt','Creditable Withholding Tax','cust',20),
('default_expense','Default Purchases / Expense','vend',109),
('ewallet_clearing','E-wallet Clearing','pos',10),
('finance_charge_income','Finance Charge Income','cust',76),
('gain_loss_disposal','Gain / Loss on Disposal of Assets','fa',78),
('group_price_variance','Item Group Price Variance','inv',72),
('input_tax','Input Tax','vend',19),
('interest_income','Interest Income','bank',77),
('inventory_adjustment','Inventory Adjustment (Shrinkage)','inv',81),
('inventory_asset','Inventory Asset','inv',15),
('merchant_fees','Bank / Merchant Charges','bank',105),
('opening_equity','Opening Balance Equity','coa',60),
('output_tax','Output Tax','cust',43),
('processing_absorbed','Processing Labor & Overhead Absorbed','inv',82),
('processing_variance','Processing / Disassembly Variance','inv',81),
('purchase_discounts','Purchase Discounts','vend',80),
('reimbursable_income','Reimbursable Expense Income','cust',75),
('retained_earnings','Retained Earnings','coa',59),
('sales_discounts','Sales Discounts','cust',70),
('sales_income','Default Sales Income','cust',73),
('sales_returns','Sales Returns and Allowances','cust',69),
('shp_capex_wip','Capital Work in Progress - Vessel Repairs / Dry-docking','shp',39),
('shp_cargo_revenue','Cargo Freight Revenue','shp',64),
('shp_change_fees','Ticket Change & Cancellation Fees','shp',67),
('shp_claims_expense','Cargo Claims & Passenger Compensation','shp',96),
('shp_crew_allowance','Crew Meal & Travel Allowances','shp',88),
('shp_deferred_freight','Deferred Freight Revenue (vehicles and prepaid cargo)','shp',49),
('shp_deferred_pax','Deferred Passenger Revenue (tickets sold, voyage not yet sailed)','shp',48),
('shp_fare_revenue','Passenger Fare Revenue','shp',61),
('shp_fuel_expense','Voyage Fuel Expense','shp',83),
('shp_fuel_variance','Fuel Shrinkage / Variance','shp',84),
('shp_handling_revenue','Cargo Handling & Insurance Revenue','shp',65),
('shp_insurance_payable','Passenger Insurance Premium Payable','shp',51),
('shp_labor_absorbed','Internal Labor Absorbed (job cards)','shp',82),
('shp_noshow_revenue','Forfeited / No-show Fares','shp',74),
('shp_port_charges','Port Charges & Dockage','shp',86),
('shp_repairs_expense','Vessel Repairs & Maintenance','shp',90),
('shp_spares_expense','Spare Parts Consumed','shp',91),
('shp_statutory_disc','Statutory Fare Discounts (Senior, PWD, Student, Medal of Valor)','shp',71),
('shp_surcharge_revenue','Fuel Surcharge Revenue','shp',62),
('shp_terminal_payable','Terminal Fee Payable (collected for the port authority)','shp',50),
('shp_vehicle_revenue','Vehicle / RoRo Freight Revenue','shp',63),
('undeposited_funds','Undeposited Funds','cust',8),
('vat_withheld_payable','VAT Withheld Payable','vend',45),
('vendor_advances','Advances to Suppliers','vend',24),
('wht_payable','Withholding Tax Payable (Expanded)','vend',44);

-- terms (5 rows)
INSERT INTO `terms` (`id`,`name`,`net_days`,`discount_pct`,`discount_days`,`is_active`) VALUES
(1,'Due on receipt',0,'0.00',0,1),
(2,'Net 15',15,'0.00',0,1),
(3,'Net 30',30,'0.00',0,1),
(4,'2% 10 Net 30',30,'2.00',10,1),
(5,'Net 60',60,'0.00',0,1);

-- tax_codes (4 rows)
INSERT INTO `tax_codes` (`id`,`code`,`name`,`rate`,`sales_account_id`,`purchase_account_id`,`is_active`) VALUES
(1,'VAT12','VAT 12%','12.000',43,19,1),
(2,'ZR','Zero-rated','0.000',43,19,1),
(3,'EX','VAT-exempt (Senior / PWD and other exempt sales)','0.000',43,19,1),
(4,'NT','Non-taxable (collections for third parties)','0.000',43,19,1);

-- payment_methods (10 rows)
INSERT INTO `payment_methods` (`id`,`name`,`method_type`,`clearing_account_id`,`is_active`,`show_in_pos`) VALUES
(1,'Cash','cash',NULL,1,1),
(2,'Check','check',NULL,1,0),
(3,'Credit/Debit Card','card',9,1,1),
(4,'GCash','ewallet',10,1,1),
(5,'Maya','ewallet',10,1,1),
(6,'Bank Transfer','bank_transfer',NULL,1,0),
(7,'Gift Voucher','voucher',46,1,1),
(8,'P.O. Card','card',11,1,0),
(9,'Bank','bank_transfer',6,1,0),
(10,'Online Payment Gateway','card',12,1,0);

-- price_levels (10 rows)
INSERT INTO `price_levels` (`id`,`name`,`adjust_pct`,`is_active`) VALUES
(1,'Corporate Rate','-5.00',1),
(2,'Travel Agent Net','-8.00',1),
(3,'Crew / Staff','-20.00',1),
(4,'Price Level 4','0.00',1),
(5,'Price Level 5','0.00',1),
(6,'Price Level 6','0.00',1),
(7,'Price Level 7','0.00',1),
(8,'Price Level 8','0.00',1),
(9,'Price Level 9','0.00',1),
(10,'Price Level 10','0.00',1);

-- customer_types (6 rows)
INSERT INTO `customer_types` (`id`,`name`) VALUES
(1,'Walk-in Passenger'),
(2,'Corporate Account'),
(3,'Travel Agency'),
(4,'Cargo Shipper'),
(5,'Government'),
(6,'Bus Operator');

-- billing_groups (1 rows)
INSERT INTO `billing_groups` (`id`,`name`) VALUES
(1,'Monthly Cargo Accounts');

-- customers (1 rows)
INSERT INTO `customers` (`id`,`parent_id`,`name`,`company_name`,`contact_person`,`phone`,`mobile`,`email`,`billing_address`,`shipping_address`,`tin`,`wht_ids`,`terms_id`,`credit_limit`,`price_level_id`,`tax_code_id`,`payment_method_id`,`customer_type_id`,`sales_rep_id`,`billing_group_id`,`allow_pos_charge`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,NULL,'Walk-in Passenger',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'0.00',NULL,1,1,1,NULL,NULL,0,'Individual passengers paying at the counter or on the portal are receipted under this name; the passengers themselves are on the tickets.',1,'2026-10-31 22:42:53',NULL);

-- vendors (2 rows)
INSERT INTO `vendors` (`id`,`name`,`company_name`,`contact_person`,`phone`,`fax`,`email`,`address`,`tin`,`terms_id`,`credit_limit`,`default_expense_account_id`,`tax_code_id`,`account_no`,`wht_ids`,`notes`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Philippine Ports Authority - PMO Cebu','Philippine Ports Authority - PMO Cebu','Revenue Section','(032) 232-8400',NULL,'pmocebu@ppa.test','Cebu Baseport, Cebu City',NULL,1,'0.00',86,4,NULL,NULL,NULL,1,'2026-10-31 22:42:53',NULL),
(2,'Pioneer Marine Insurance','Pioneer Marine Insurance','Marine Desk','(02) 8812-7777',NULL,'marine@pioneer.test','Makati City',NULL,3,'0.00',92,4,NULL,NULL,NULL,1,'2026-10-31 22:42:53',NULL);

-- sites (1 rows)
INSERT INTO `sites` (`id`,`code`,`name`,`site_type`,`address`,`is_active`) VALUES
(1,'SHORE-CEB','Cebu Shore Warehouse','warehouse','Pier 4, North Reclamation Area, Cebu City',1);

-- item_categories (5 rows)
INSERT INTO `item_categories` (`id`,`name`) VALUES
(1,'Shipping Services'),
(2,'Fuel and Lubricants'),
(3,'Spare Parts'),
(4,'Ship Stores'),
(5,'Canteen');

-- uom_sets (5 rows)
INSERT INTO `uom_sets` (`id`,`name`,`uom_kind`,`base_unit`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'Piece','count','pc',1,'2026-10-31 22:42:52',NULL),
(2,'Weight (kg)','weight','kg',1,'2026-10-31 22:42:52',NULL),
(3,'Volume (L)','volume','L',1,'2026-10-31 22:42:52',NULL),
(4,'Length (m)','length','m',1,'2026-10-31 22:42:52',NULL),
(5,'Time (hour)','time','hr',1,'2026-10-31 22:42:52',NULL);

-- uom_units (15 rows)
INSERT INTO `uom_units` (`id`,`set_id`,`code`,`name`,`factor`,`is_base`,`is_sales_default`,`is_purchase_default`,`sort_order`) VALUES
(1,1,'pc','Piece','1.000000',1,1,0,0),
(2,1,'set','Set','1.000000',0,0,0,1),
(3,1,'box','Box of 24','24.000000',0,0,1,2),
(4,1,'case','Case of 48','48.000000',0,0,0,3),
(5,2,'kg','Kilogram','1.000000',1,1,1,0),
(6,2,'g','Gram','0.001000',0,0,0,1),
(7,2,'t','Metric ton','1000.000000',0,0,0,2),
(8,3,'L','Liter','1.000000',1,1,1,0),
(9,3,'drum','Drum of 200 L','200.000000',0,0,0,1),
(10,3,'kl','Kiloliter (1,000 L)','1000.000000',0,0,0,2),
(11,4,'m','Meter','1.000000',1,1,1,0),
(12,4,'cm','Centimeter','0.010000',0,0,0,1),
(13,4,'roll','Roll of 100 m','100.000000',0,0,0,2),
(14,5,'hr','Hour','1.000000',1,1,1,0),
(15,5,'day','Day (8 hours)','8.000000',0,0,0,1);

-- items (12 rows)
INSERT INTO `items` (`id`,`item_type`,`code`,`barcode`,`name`,`description`,`purchase_description`,`category_id`,`unit`,`uom_set_id`,`sales_uom_id`,`purchase_uom_id`,`cost`,`avg_cost`,`sales_price`,`discount_pct`,`income_account_id`,`cogs_account_id`,`asset_account_id`,`expense_account_id`,`tax_code_id`,`group_variance_account_id`,`preferred_vendor_id`,`reorder_point`,`max_stock`,`build_point`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'service','SHP-FARE',NULL,'Passenger Fare','Passenger fare (deferred until the voyage is completed)',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',48,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(2,'service','SHP-SURCH',NULL,'Fuel Surcharge','Fuel surcharge',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',48,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(3,'service','SHP-VEH',NULL,'Vehicle / RoRo Freight','Rolling cargo / vehicle freight',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',49,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(4,'service','SHP-CARGO',NULL,'Cargo Freight','Cargo freight',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',49,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(5,'service','SHP-HANDL',NULL,'Cargo Handling Fee','Cargo handling',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',65,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(6,'service','SHP-CINS',NULL,'Cargo Insurance (for the insurer)','Cargo insurance premium collected for the insurer',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',51,79,NULL,NULL,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(7,'service','SHP-TFEE',NULL,'Terminal Fee (for the port authority)','Passenger terminal fee collected for the port authority',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',50,79,NULL,NULL,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(8,'service','SHP-PINS',NULL,'Passenger Insurance (for the insurer)','Passenger accident insurance premium collected for the insurer',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',51,79,NULL,NULL,4,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(9,'service','SHP-CHG',NULL,'Ticket Change / Rebooking Fee','Ticket change, rebooking or revalidation fee',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',67,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(10,'service','SHP-BAG',NULL,'Excess Baggage','Excess baggage charge',NULL,1,'ea',NULL,NULL,NULL,'0.0000','0.0000','0.0000','0.00',66,79,NULL,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(11,'inventory','FUEL-MDO',NULL,'Marine Diesel Oil','Marine diesel oil (liters)','Marine diesel oil',2,'L',3,NULL,NULL,'62.5000','0.0000','0.0000','0.00',73,83,16,NULL,1,NULL,NULL,'0.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL),
(12,'inventory','LUBE-40',NULL,'Engine Lube Oil SAE 40','Marine engine lube oil SAE 40 (liters)','Lube oil SAE 40',2,'L',3,NULL,NULL,'185.0000','0.0000','0.0000','0.00',73,85,16,NULL,1,NULL,NULL,'200.0000','0.0000','0.0000',1,'2026-10-31 22:42:53',NULL);

-- withholding_taxes (3 rows)
INSERT INTO `withholding_taxes` (`id`,`code`,`atc`,`name`,`tax_kind`,`rate`,`base_type`,`applies_to`,`payable_account_id`,`receivable_account_id`,`description`,`sort_order`,`is_active`,`created_at`,`updated_at`) VALUES
(1,'WT-GOODS','WC158','WT on Goods','income','1.0000','net_of_vat','both',NULL,NULL,'Goods bought from regular suppliers by top withholding agents (RR 11-2018)',10,1,'2026-10-31 22:42:52',NULL),
(2,'WT-SERVICES','WC160','WT on Services','income','2.0000','net_of_vat','both',NULL,NULL,'Services bought from regular suppliers by top withholding agents (RR 11-2018)',20,1,'2026-10-31 22:42:52',NULL),
(3,'WT-VAT','WV010','WT on VAT','vat','5.0000','net_of_vat','both',NULL,NULL,'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)',30,1,'2026-10-31 22:42:52',NULL);

-- asset_categories (6 rows)
INSERT INTO `asset_categories` (`id`,`name`,`asset_account_id`,`accum_dep_account_id`,`dep_expense_account_id`,`default_method`,`default_life_years`,`is_active`) VALUES
(1,'Vessels and Floating Equipment',29,30,106,'straight_line','20.00',1),
(2,'Dry-docking (amortized to the next dry-dock)',31,32,107,'straight_line','2.50',1),
(3,'Terminal Buildings and Improvements',27,28,106,'straight_line','25.00',1),
(4,'Transportation Equipment',33,34,106,'declining_balance','5.00',1),
(5,'Furniture and Fixtures',35,36,106,'straight_line','10.00',1),
(6,'Computer and Ticketing Equipment',37,38,106,'declining_balance','3.00',1);

-- shp_ports (14 rows)
INSERT INTO `shp_ports` (`id`,`code`,`name`,`city`,`province`,`region`,`authority`,`berths`,`fee_vendor_id`,`is_active`,`notes`,`created_at`) VALUES
(1,'CEB','Cebu Baseport','Cebu City','Cebu','VII','PPA PMO Cebu','Pier 1, Pier 3, Pier 4, Pier 5',1,1,NULL,'2026-10-31 22:42:53'),
(2,'TAG','Tagbilaran Port','Tagbilaran City','Bohol','VII','PPA PMO Bohol','Berth 1, Berth 2',NULL,1,NULL,'2026-10-31 22:42:53'),
(3,'ORM','Ormoc Port','Ormoc City','Leyte','VIII','PPA PMO Ormoc','Berth 1, Berth 2',NULL,1,NULL,'2026-10-31 22:42:53'),
(4,'DGT','Dumaguete Port','Dumaguete City','Negros Oriental','VII','PPA PMO Negros Oriental','Berth 1',NULL,1,NULL,'2026-10-31 22:42:53'),
(5,'CGY','Cagayan de Oro Port','Cagayan de Oro City','Misamis Oriental','X','PPA PMO Misamis Oriental','Berth 3, Berth 4',NULL,1,NULL,'2026-10-31 22:42:53'),
(6,'ILO','Iloilo Port','Iloilo City','Iloilo','VI','PPA PMO Iloilo','Fort San Pedro, Berth 2',NULL,1,NULL,'2026-10-31 22:42:53'),
(7,'BCD','Bacolod (BREDCO) Port','Bacolod City','Negros Occidental','VI','Private port operator','BREDCO 1',NULL,1,NULL,'2026-10-31 22:42:53'),
(8,'BTG','Batangas Port','Batangas City','Batangas','IV-A','PPA PMO Batangas','Terminal 1, Terminal 2',NULL,1,NULL,'2026-10-31 22:42:53'),
(9,'CPN','Calapan Port','Calapan City','Oriental Mindoro','MIMAROPA','PPA PMO Mindoro','RoRo Ramp 1, RoRo Ramp 2',NULL,1,NULL,'2026-10-31 22:42:53'),
(10,'MNL','Manila North Harbor','Manila','Metro Manila','NCR','Manila North Harbour Port Inc.','Pier 4, Pier 6',NULL,1,NULL,'2026-10-31 22:42:53'),
(11,'SUR','Surigao Port','Surigao City','Surigao del Norte','XIII','PPA PMO Surigao','Berth 1',NULL,1,NULL,'2026-10-31 22:42:53'),
(12,'LIL','Liloan Port','Liloan','Southern Leyte','VIII','PPA TMO Liloan','RoRo Ramp',NULL,1,NULL,'2026-10-31 22:42:53'),
(13,'STB','Santander Port','Santander','Cebu','VII','Cebu Port Authority','RoRo Ramp',NULL,1,NULL,'2026-10-31 22:42:53'),
(14,'CAT','Caticlan Jetty Port','Malay','Aklan','VI','Provincial Government of Aklan','Jetty 1',NULL,1,NULL,'2026-10-31 22:42:53');

-- shp_offices (2 rows)
INSERT INTO `shp_offices` (`id`,`code`,`name`,`port_id`,`office_type`,`address`,`cash_account_id`,`class_id`,`is_active`,`created_at`) VALUES
(1,'CEB-P4','Cebu Pier 4 Ticket Office',1,'ticket_office','Pier 4 Passenger Terminal, Cebu City',NULL,NULL,1,'2026-10-31 22:42:53'),
(2,'WEB','Online Portal',NULL,'online',NULL,NULL,NULL,1,'2026-10-31 22:42:53');

-- shp_fare_categories (7 rows)
INSERT INTO `shp_fare_categories` (`id`,`code`,`name`,`discount_pct`,`vat_exempt`,`is_statutory`,`requires_id`,`id_label`,`legal_basis`,`min_age`,`max_age`,`fee_exempt`,`effective_from`,`is_active`,`sort_order`) VALUES
(1,'REG','Regular (adult)','0.00',0,0,0,NULL,NULL,NULL,NULL,0,'2026-01-01',1,10),
(2,'SC','Senior Citizen','20.00',1,1,1,'OSCA / Senior Citizen ID','RA 9994 (Expanded Senior Citizens Act)',60,NULL,0,'2026-01-01',1,20),
(3,'PWD','Person with Disability','20.00',1,1,1,'PWD ID','RA 10754 (benefits and privileges of PWD)',NULL,NULL,0,'2026-01-01',1,30),
(4,'STU','Student','20.00',0,1,1,'School ID / enrolment form','RA 11314 (Student Fare Discount Act)',NULL,NULL,0,'2026-01-01',1,40),
(5,'MOV','Medal of Valor awardee / dependent','20.00',0,1,1,'AFP Medal of Valor ID','RA 9049',NULL,NULL,0,'2026-01-01',1,50),
(6,'CHD','Child (3 to 11 years)','50.00',0,0,0,NULL,'Company policy',3,11,0,'2026-01-01',1,60),
(7,'INF','Infant (below 3, on lap)','100.00',0,0,0,NULL,'Company policy (no seat; counted on the manifest)',0,2,1,'2026-01-01',1,70);

-- shp_fees (3 rows)
INSERT INTO `shp_fees` (`id`,`code`,`name`,`fee_type`,`applies_to`,`calc`,`amount`,`ownership`,`liability_account_id`,`revenue_account_id`,`vendor_id`,`port_id`,`exempt_categories`,`is_active`,`sort_order`) VALUES
(1,'TFEE','Passenger Terminal Fee','terminal','passenger','per_pax','30.00','agent',50,NULL,1,NULL,'INF',1,10),
(2,'PINS','Passenger Accident Insurance','insurance','passenger','per_pax','20.00','agent',51,NULL,2,NULL,'INF',1,20),
(3,'VTFEE','Vehicle Terminal Fee','terminal','vehicle','per_vehicle','100.00','agent',50,NULL,1,NULL,NULL,1,30);

-- shp_vehicle_classes (7 rows)
INSERT INTO `shp_vehicle_classes` (`id`,`code`,`name`,`max_length_m`,`max_weight_t`,`lane_m`,`sort_order`,`is_active`) VALUES
(1,'MC','Motorcycle / scooter','2.50','0.40','1.00',10,1),
(2,'CAR','Car / SUV (up to 5 m)','5.00','2.50','6.00',20,1),
(3,'VAN','Van / pick-up (up to 6 m)','6.00','3.50','7.00',30,1),
(4,'TRK6','Light truck (up to 8 m)','8.00','10.00','9.50',40,1),
(5,'BUS','Bus (up to 12 m)','12.00','16.00','13.50',50,1),
(6,'TRK10','Heavy truck / 10-wheeler (up to 12 m)','12.00','30.00','13.50',60,1),
(7,'TRL','Tractor-trailer (up to 18 m)','18.00','40.00','19.50',70,1);

-- shp_compliance (9 rows)
INSERT INTO `shp_compliance` (`id`,`title`,`authority`,`reference`,`description`,`frequency`,`due_date`,`owner_user_id`,`status`,`evidence_note`,`completed_by`,`completed_at`,`created_at`) VALUES
(1,'Register the invoicing system and invoice series (sales invoices, receipts) with the RDO','BIR','RR 7-2024 / RMC 77-2024','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','once',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(2,'Assess coverage and timing of electronic invoicing (EIS) for the company; plan the upgrade','BIR','RR 11-2025, RR 26-2025','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','once',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(3,'Computerized accounting system registration: keep the permit and the list of books current','BIR','CAS / CBA','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','annual',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(4,'File the monthly / quarterly VAT and withholding returns from the BIR Reports','BIR','RR 16-2005','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','monthly',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(5,'Review the Shipping Online Ticketing System requirements and their phased dates for each vessel','MARINA','MC DS 2026-01 (SOTS)','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','once',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(6,'Keep every vessel certificate (CPC, safety, load line, radio, tonnage) valid and uploaded','MARINA','Certificates','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','monthly',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(7,'Passenger manifest and pre-departure clearance before every voyage','PCG','Pre-departure inspection','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','once',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(8,'Remit the passenger and vehicle terminal fees collected, with the reconciliation','PPA','Terminal fees','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','monthly',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53'),
(9,'Privacy notice on the portal, retention schedule of manifests and IDs, breach response plan, DPO registration','NPC','Data Privacy Act (RA 10173)','Configured as a task for review by the company and its external accountant / counsel. The system does not certify compliance.','annual',NULL,NULL,'needs_review',NULL,NULL,NULL,'2026-10-31 22:42:53');

-- audit trail protection (created after the data is loaded)
-- ---------------------------------------------------------------------
--  Audit trail protection: entries can never be changed or deleted,
--  not even by administrators or directly in the database.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_audit_trail_no_update;
DROP TRIGGER IF EXISTS trg_audit_trail_no_delete;

DELIMITER $$
CREATE TRIGGER trg_audit_trail_no_update BEFORE UPDATE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are read-only and cannot be changed.';
END$$

CREATE TRIGGER trg_audit_trail_no_delete BEFORE DELETE ON audit_trail
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit trail entries are permanent and cannot be deleted.';
END$$
DELIMITER ;


-- =====================================================================
--  PLATFORM (MASTER) DATABASE: gsmart_shipping_master
--  Multi-company is switched on by storage/platform/multi_company.json in the program folder.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `gsmart_shipping_master` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gsmart_shipping_master`;

DROP TABLE IF EXISTS `registration_attempts`;
DROP TABLE IF EXISTS `platform_settings`;
DROP TABLE IF EXISTS `platform_audit`;
DROP TABLE IF EXISTS `company_provisioning_log`;
DROP TABLE IF EXISTS `user_company_links`;
DROP TABLE IF EXISTS `user_company_memberships`;
DROP TABLE IF EXISTS `company_codes`;
DROP TABLE IF EXISTS `company_databases`;
DROP TABLE IF EXISTS `companies`;
DROP TABLE IF EXISTS `super_admins`;

-- =====================================================================
--  G-SMaRT Accounting - master (platform) database
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+
--
--  One master database serves every company. It holds the companies,
--  the database each company uses, their Company Codes, which company
--  every username belongs to, the Super Admin accounts and the
--  platform's own audit log. Company records (transactions, lists,
--  users and their roles) stay in each company's own database.
--
--  The system creates this database and runs this file itself when the
--  first company is registered (System Administration > Multi-Company
--  Setup). Every statement can be run again safely.
-- =====================================================================


-- Super Admin accounts: they manage the platform and every company, and belong to no company
CREATE TABLE IF NOT EXISTS super_admins (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(120) NOT NULL,
  email                VARCHAR(120) NULL,
  status               ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at  DATETIME     NULL,
  failed_attempts      INT          NOT NULL DEFAULT 0,
  last_login_at        DATETIME     NULL,
  last_login_ip        VARCHAR(45)  NULL,
  created_by           INT UNSIGNED NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_super_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The companies. A company is usable only while its status is 'active'.
CREATE TABLE IF NOT EXISTS companies (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  address             VARCHAR(255) NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(60)  NULL,
  email               VARCHAR(120) NULL,
  tin                 VARCHAR(40)  NULL,
  status              ENUM('provisioning','active','failed','inactive') NOT NULL DEFAULT 'provisioning',
  owner_user_id       INT UNSIGNED NULL,               -- the Company Owner: a user id inside the company database
  require_approval    TINYINT(1)   NOT NULL DEFAULT 1, -- employees who join with the Company Code wait for approval
  join_role_id        INT UNSIGNED NULL,               -- role suggested for (or, without approval, given to) employees who join
  template_company_id INT UNSIGNED NULL,               -- the company whose set-up was copied
  notes               VARCHAR(255) NULL,
  last_error          TEXT         NULL,               -- why the last set-up attempt failed
  created_by          INT UNSIGNED NULL,               -- super_admins.id
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at        DATETIME     NULL,
  deactivated_at      DATETIME     NULL,
  updated_at          DATETIME     NULL,
  UNIQUE KEY uq_companies_name (name),
  KEY ix_companies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company -> database. The database name is made by the system and never typed by a user.
CREATE TABLE IF NOT EXISTS company_databases (
  company_id          INT UNSIGNED NOT NULL PRIMARY KEY,
  db_name             VARCHAR(64)  NOT NULL,
  is_primary          TINYINT(1)   NOT NULL DEFAULT 0, -- the original database (Company 1): never created or dropped by the platform
  created_by_platform TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = the platform created it (and may drop it if its set-up fails)
  table_count         INT          NULL,
  triggers_ok         TINYINT(1)   NOT NULL DEFAULT 0, -- audit trail protection triggers are in place
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at         DATETIME     NULL,
  UNIQUE KEY uq_company_databases_name (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company Codes: an employee types one to say which company they are joining. It is never a password.
-- Only one code of a company is 'active'; a regenerated code retires the old one for good.
CREATE TABLE IF NOT EXISTS company_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id  INT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  status      ENUM('active','disabled','retired') NOT NULL DEFAULT 'active',
  expires_at  DATETIME     NULL,
  created_by  VARCHAR(80)  NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at  DATETIME     NULL,
  UNIQUE KEY uq_company_codes_code (code),
  KEY ix_company_codes_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which company every username belongs to (the log-in page reads it). Usernames are unique across companies.
-- The account itself (password, roles, lock-out) stays in the company database.
CREATE TABLE IF NOT EXISTS user_company_memberships (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id      INT UNSIGNED NOT NULL,
  company_user_id INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  full_name       VARCHAR(120) NULL,
  email           VARCHAR(120) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active, inactive, locked, pending, rejected
  created_via     VARCHAR(12)  NOT NULL DEFAULT 'admin',   -- admin, registration, provisioning, setup (never 'link': see user_company_links)
  joined_at       DATETIME     NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_memberships_username (username),
  UNIQUE KEY uq_memberships_company_user (company_id, company_user_id),
  KEY ix_memberships_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access to another company: a user of one company (home) who may also work in another company with the same
-- log-in, switching between them from the top bar. The other company holds a linked account (users.created_via =
-- 'link') with its own roles; it never logs in by itself. Made by that company's administrator (User Accounts).
CREATE TABLE IF NOT EXISTS user_company_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  home_company_id INT UNSIGNED NOT NULL,
  home_user_id    INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  company_id      INT UNSIGNED NOT NULL,              -- the company the user may switch to
  company_user_id INT UNSIGNED NOT NULL,              -- the linked account in that company's database
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',  -- active, removed
  granted_by      VARCHAR(80)  NULL,
  granted_at      DATETIME     NULL,
  removed_by      VARCHAR(80)  NULL,
  removed_at      DATETIME     NULL,
  removed_reason  VARCHAR(255) NULL,
  last_switch_at  DATETIME     NULL,
  UNIQUE KEY uq_links_home_company (home_company_id, home_user_id, company_id),
  UNIQUE KEY uq_links_company_user (company_id, company_user_id),
  KEY ix_links_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every step of setting up a company database, for the Super Admin to follow and to recover from
CREATE TABLE IF NOT EXISTS company_provisioning_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  attempt    INT          NOT NULL DEFAULT 1,
  step       VARCHAR(60)  NOT NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 1,
  message    TEXT         NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_provisioning_company (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The platform's own audit log: companies created, changed, switched on and off, owners, codes,
-- Super Admin log-ins and every time a Super Admin opened a company
CREATE TABLE IF NOT EXISTS platform_audit (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type  VARCHAR(12)  NOT NULL,              -- super_admin, owner, user, guest, system
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(80)  NULL,
  company_id  INT UNSIGNED NULL,
  action      VARCHAR(40)  NOT NULL,
  details     TEXT         NULL,
  ip_address  VARCHAR(45)  NULL,
  KEY ix_platform_audit_company (company_id, id),
  KEY ix_platform_audit_action (action, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform-wide settings (database name prefix for new companies, default approval rule, ...)
CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attempts on the join page, so Company Codes cannot be guessed by trying many of them
CREATE TABLE IF NOT EXISTS registration_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45)  NOT NULL,
  ok          TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_registration_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- data

-- super_admins (1 rows)
INSERT INTO `super_admins` (`id`,`username`,`password_hash`,`full_name`,`email`,`status`,`must_change_password`,`password_changed_at`,`failed_attempts`,`last_login_at`,`last_login_ip`,`created_by`,`created_at`) VALUES
(1,'superadmin','$2y$10$JdMqMVaHJZ5BD3Q5UXMaQOkdDq0E/bNMbIqR8hs9LiM3NKxpVr4su','Platform Super Admin','superadmin@gsmart.test','active',1,'2026-10-31 22:42:54',0,NULL,NULL,NULL,'2026-10-31 22:42:54');

-- companies (1 rows)
INSERT INTO `companies` (`id`,`name`,`address`,`contact_person`,`phone`,`email`,`tin`,`status`,`owner_user_id`,`require_approval`,`join_role_id`,`template_company_id`,`notes`,`last_error`,`created_by`,`created_at`,`activated_at`,`deactivated_at`,`updated_at`) VALUES
(1,'Visayas Star Shipping Lines, Inc.','Pier 4, North Reclamation Area, Cebu City 6000','System Administrator','(032) 412-5500','reservations@visayasstar.test','000-482-917-000','active',1,1,NULL,NULL,'The original database, registered as Company 1',NULL,NULL,'2026-10-31 22:42:53','2026-10-31 22:42:53',NULL,NULL);

-- company_databases (1 rows)
INSERT INTO `company_databases` (`company_id`,`db_name`,`is_primary`,`created_by_platform`,`table_count`,`triggers_ok`,`created_at`,`verified_at`) VALUES
(1,'gsmart_shipping',1,0,140,1,'2026-10-31 22:42:53','2026-10-31 22:42:53');

-- company_codes (1 rows)
INSERT INTO `company_codes` (`id`,`company_id`,`code`,`status`,`expires_at`,`created_by`,`created_at`,`retired_at`) VALUES
(1,1,'VSS8SJ95','active',NULL,'setup:admin','2026-10-31 22:42:54',NULL);

-- user_company_memberships (28 rows)
INSERT INTO `user_company_memberships` (`id`,`company_id`,`company_user_id`,`username`,`full_name`,`email`,`status`,`created_via`,`joined_at`,`updated_at`) VALUES
(1,1,1,'admin','System Administrator','admin@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(2,1,2,'gm','Ramon Villafuerte','gm@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(3,1,3,'opsmgr','Teresa Abellana','opsmgr@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(4,1,4,'fleetmgr','Engr. Arnel Pacquiao','fleetmgr@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(5,1,5,'captain','Capt. Rodrigo Saavedra','captain@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(6,1,6,'chiefeng','Engr. Victor Labra','chiefeng@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(7,1,7,'planner','Liza Montenegro','planner@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(8,1,8,'tech1','Joel Sumalinog','tech1@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(9,1,9,'tech2','Ricky Bacalso','tech2@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(10,1,10,'procure','Marivic Ouano','procure@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(11,1,11,'warehouse','Dennis Cabahug','warehouse@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(12,1,12,'tktmgr','Grace Lim-Uy','tktmgr@visayasstar.test','active','admin','2026-10-31 22:42:52','2026-10-31 22:42:54'),
(13,1,13,'ticket1','Analyn Tumulak','ticket1@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(14,1,14,'ticket2','Paolo Ybanez','ticket2@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(15,1,15,'boarding1','Jessa Mae Rosales','boarding1@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(16,1,16,'cargoclerk','Noel Alcuizar','cargoclerk@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(17,1,17,'portagent','Mylene Gacasan','portagent@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(18,1,18,'csagent','Kristine Dumaguit','csagent@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(19,1,19,'accountant','Ana Cruz, CPA','accountant@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(20,1,20,'treasurer','Belinda Sy','treasurer@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(21,1,21,'arclerk','Ben Garcia','arclerk@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(22,1,22,'apclerk','Carla Lim','apclerk@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(23,1,23,'invclerk','Dan Villanueva','invclerk@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(24,1,24,'salesrep','Maria Santos','salesrep@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(25,1,25,'canteen1','Ella Ramos','canteen1@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(26,1,26,'purser','Fred Tan','purser@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(27,1,27,'auditor','Grace Uy','auditor@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54'),
(28,1,28,'regulator','PCG Inspector (read-only)','regulator@visayasstar.test','active','admin','2026-10-31 22:42:53','2026-10-31 22:42:54');

-- platform_audit (1 rows)
INSERT INTO `platform_audit` (`id`,`created_at`,`actor_type`,`actor_id`,`actor_name`,`company_id`,`action`,`details`,`ip_address`) VALUES
(1,'2026-10-31 22:42:54','user',1,'admin',1,'platform_setup','The existing database was registered as Company 1 (Visayas Star Shipping Lines, Inc.); owner admin; Super Admin superadmin created','cli');

-- platform_settings (1 rows)
INSERT INTO `platform_settings` (`setting_key`,`setting_value`) VALUES
('default_require_approval','1');

-- the platform audit log is permanent
DROP TRIGGER IF EXISTS trg_platform_audit_no_update;
DROP TRIGGER IF EXISTS trg_platform_audit_no_delete;

DELIMITER $$
CREATE TRIGGER trg_platform_audit_no_update BEFORE UPDATE ON platform_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are read-only.';
END$$

CREATE TRIGGER trg_platform_audit_no_delete BEFORE DELETE ON platform_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are permanent.';
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
