-- =====================================================================
--  G-SMaRT Shipping System - database schema (accounting core + shipping operations)
--  MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+ (XAMPP / WAMP / Laragon)
--  MySQL 5.5 rules followed here: only TIMESTAMP columns default to
--  CURRENT_TIMESTAMP (one per table), indexed text columns stay within
--  767 bytes (utf8mb4), and foreign key columns match exactly.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
