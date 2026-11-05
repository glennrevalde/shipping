# G-SMaRT Shipping System

A web system for a Philippine shipping line: voyages and dispatch, passenger ticketing and boarding, cargo and RoRo,
fleet maintenance, customer service and a passenger portal, all running on the complete G-SMaRT accounting core
(general ledger, receivables, payables, inventory, banking, fixed assets, onboard canteen POS and BIR reports).
Every shipping event posts its own accounting document once, so the books, the voyage P&L and the operations never
need to be keyed twice. One installation can serve several shipping companies, each in its own database.

Plain PHP, MySQL, HTML, CSS and JavaScript. No framework, no build step, no outside service required. It runs on
XAMPP, WAMP, Laragon or any PHP host.

---

## 1. Requirements

| | |
|---|---|
| PHP | 8.1 or newer (8.2+ recommended), with `pdo_mysql` and `mbstring` (both on by default in XAMPP) |
| Database | **MySQL 5.5.3 or newer** (5.5, 5.6, 5.7, 8.x) or MariaDB 5.5 or newer. Every table, query and file is written for MySQL 5.5. |
| Web server | Apache (the `.htaccess` files are included) or nginx (section 11) |
| Browser | Any current browser. The boarding scanner's camera needs `https://` or `http://localhost`. |

**PHP and MySQL versions are separate.** The database can be MySQL 5.5, but the program needs PHP 8.1 or newer. Old
XAMPP packages that bundle MySQL 5.5 come with PHP 5, which cannot run this system: install a current XAMPP, or point a
PHP 8 install at your MySQL 5.5 server in `config/config.php`. MySQL 5.5 no longer receives security fixes from Oracle;
keep the database server off the internet and plan a move to 5.7 / 8.x or MariaDB, which this system also supports
without changes.

Bootstrap 5.3.3, Bootstrap Icons, the Plus Jakarta Sans font and the two QR libraries are included in `assets/vendor/`,
so the system works without internet.

## 2. Install with XAMPP and phpMyAdmin

1. Unzip the folder into `C:\xampp\htdocs\` so you have `C:\xampp\htdocs\gsmart-shipping\index.php`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open <http://localhost/phpmyadmin>, click **Import** (with no database selected), choose **one** of the two files and
   click **Go**:
   - `database/gsmart_shipping_clean.sql`: the **clean database**. The complete structure and set-up, ready for real
     work: chart of accounts for a shipping line, posting accounts, tax codes, 27 roles with their page access, the
     Philippine ports, passenger categories with the statutory discounts (Senior Citizen, PWD, Student, Medal of Valor,
     child, infant), vehicle classes, terminal fee and passenger insurance, compliance tasks, the staff log-ins of every role
     (each asked for a new password at first log-in) and `superadmin`. No vessels, routes, voyages or transactions.
   - `database/gsmart_shipping_sample.sql`: the **sample database**. The same set-up plus a working shipping line,
     *Visayas Star Shipping Lines, Inc.*, with three vessels, six routes, three weeks of voyages (past, sailing today and
     on sale), about 2,100 bookings, boarding scans, manifests, cargo, vehicles, fuel, maintenance, purchasing,
     refunds, a cancelled sailing, support cases, every accounting document they posted, and 28 staff log-ins. A second,
     empty company (*Mindanao Ferry Express Corp.*) shows the multi-company platform.

   Each file creates `gsmart_shipping` (the first company) and `gsmart_shipping_master` (the platform); the sample file
   also creates `gsmart_shipping_company_002`. The sample file is about 32 MB: if phpMyAdmin refuses it, raise
   `upload_max_filesize` and `post_max_size` to 64M in `C:\xampp\php\php.ini` and restart Apache, or import it from the
   command line: `C:\xampp\mysql\bin\mysql -u root -p < database\gsmart_shipping_sample.sql`.
4. If your MySQL `root` user has a different password (the delivered `config/config.php` uses `Jeglen`), or you use
   another MySQL user, edit `config/config.php`.
5. Open <http://localhost/gsmart-shipping/> and log in. The passenger portal is
   <http://localhost/gsmart-shipping/portal.php>.

## 3. Install from the command line

```bash
php tools/install.php --drop --platform           # the clean database, multi-company switched on
php tools/install.php --drop --demo --platform    # the sample database
php tools/export_sql.php --out=database/backup.sql  # every database of the installation in one importable file
```

`--drop` deletes the databases first (and the companies the platform created). Without `--platform` the system runs
as one company until **System Administration → Multi-Company Setup** is used.

**Acceptance tests.** `tools/acceptance_tests.php` checks the controls on a test copy of the sample database: six
counters selling the last three places at the same moment (three sold, three refused), a group that does not fit,
a payment sent twice, duplicate / wrong-voyage / forged boarding scans, refund limits and maker-checker, a critical
defect stopping the departure with no override, a technician inspecting their own work, stock issued beyond what is on
hand, cash into a closed shift, posting before the closing date, and the books balancing:

```bash
DB_NAME=gsmart_test php tools/install.php --drop --demo
DB_NAME=gsmart_test php tools/acceptance_tests.php       # 18 checks, each PASS or FAIL
```

## 4. Log-ins

**`USER_ACCOUNTS.md`** lists every account from the lowest level of access to the Super Admin, with what each role
does. In short:

| Who | Username | Password |
|---|---|---|
| 26 staff roles of the sample company (gate, cashier, cargo, technicians, managers, accountant ...) | `regulator`, `canteen1`, `boarding1`, `ticket1` ... `gm` | `Demo@12345` |
| Company administrator and Company Owner | `admin` | `Admin@12345` (new password at first log-in) |
| Owner of the second sample company | `mfeowner` | `Owner@12345` (new password at first log-in) |
| Super Admin (adds and manages companies) | `superadmin` | `Super@12345` (new password at first log-in) |
| Passenger portal account | `juan.delacruz@mail.test` | `Sakay@2026` |

Everyone logs in on the same page; the system finds the company from the username.

## 5. The shipping modules

The menu groups the work the way a shipping line is organized. Each role sees only its own pages.

**Home.** A dashboard for each role: sailings today and tomorrow with their paid and boarded counts and a moving ship on
the route line, passengers and collections of the day, bookings and load for the next seven days, safety holds, open
work orders and PM due, open cases, approvals waiting, receivables, payables, deferred fares, a 14-day collections chart,
bank and cash position, and a *Needs attention* list (holds, expiring vessel and crew certificates, critical defects,
fuel variances, late cases, refunds to pay out). Notices to the user (bell) and approvals waiting (check) are in the top bar.

**Voyage Operations.**
- *Voyages & Dispatch*: the voyage board, and for each voyage its status flow (published, on sale, boarding, ready,
  departed, arrived, completed, or cancelled), seats by class, paid / checked-in / boarded / no-show counts, the
  **departure readiness** checks worked out from the records (no-sail holds, certificates valid through arrival,
  boarded count within the limit, passenger information complete, final manifest equal to the boarded count, crew
  complement with Master and Chief Engineer, crew certificates, weather advisory, PCG clearance, checklist, lane meters,
  cargo capacity), the crew signed on, vehicles and cargo aboard, fuel, voyage costs (billed or paid, posted with the
  vessel's class), the **voyage P&L from the ledger**, every accounting document of the voyage, the voyage log and the
  Master's report. Reschedule (passengers told), cancel with reason and authority, request a departure override.
- *Schedule Planner*: generates a season of voyages from departure times and weekdays, and shows the week by vessel.
- *Vessel Holds & Release*: no-sail and restricted holds; a critical defect or an expired critical certificate puts
  one on by itself. Lifting a no-sail hold takes **two different people**: the technical release (Chief Engineer, after
  the work order passed inspection) and then the safety/management release; neither may be the one who did the work.
- *Fuel & Bunkering*: tank ROB per vessel, bunkering receipts (a supplier bill into the vessel's fuel stock), fuel
  issued per voyage from the departure and arrival ROB (an inventory adjustment to voyage fuel expense at average
  cost), soundings with variances above the set percentage sent for approval.
- *Crew & Certificates*, *Approvals Queue* (maker-checker for refunds, compensation, departure overrides, fuel
  adjustments, work-order deferrals, requisitions and fare changes, with delegations and amount limits).
- Reports: Voyage Profit & Loss (direct and full cost), Vessel & Route Profitability, Departure Punctuality, Fuel
  Consumption & ROB Variance, Crew Certificate Expiry.

**Ticketing & Boarding.**
- *Booking Desk*: voyage, passengers (name as on the ID, birth date, sex, nationality, category, ID number for a
  discount, class, seat from the seat map), vehicles with driver, promo code, an **itemized price** (fare, statutory
  discount, VAT, fuel surcharge, terminal fee and insurance on their own lines), then *Hold* and *Pay* (cash in the
  cashier's open shift, card, GCash, Maya, bank transfer, check, or on account for corporate and agent sales). The
  last seats can never be sold twice: every sale of a voyage locks that voyage until it is saved, a group is held whole
  or not at all, and a payment carries a one-time key so a double click or a resent gateway callback pays once.
- *Bookings & Tickets*: find by reference, ticket, name, phone or voyage; pay a held booking (portal reservations are
  paid here), print e-tickets and vehicle passes (QR code signed with the company's secret key), request a refund,
  move a ticket to another sailing of the same route. Personal data is masked unless the role may see it.
- *Boarding Scanner*: the phone or tablet camera reads the QR code (or type the ticket number); check-in or board;
  accepted, duplicate, wrong voyage, cancelled, unpaid and forged codes are told apart and every scan is logged. With
  the connection down, scans queue on the device and are sent when it is back.
- *Passenger Manifests*: draft and final versions from the boarded scans (passengers, crew, vehicles), signed, printed
  in the MARINA / PCG layout and exported to CSV; each export is logged.
- *Cashier Shifts* (opening float, totals by method, count, over/short posted, review by another person), *Refunds &
  Cancellations* (requested, approved, paid; the passengers of a cancelled sailing, refunded in full or moved free of
  charge), *Manual Ticket Contingency* (pre-numbered manual tickets during an outage, reconciled into bookings later).
- Reports: Passenger Sales, Sold vs Paid vs Boarded, Load Factor, Statutory Discounts Register, Refunds Register,
  Cashier Collections vs Deposits, Advance Bookings.

**Cargo & RoRo.** Waybills from quotation to delivery (tariff by commodity, weight or volume, declared value and
insurance, prepaid / collect / credit terms with the credit limit checked), loading tally, in transit, landed,
delivered with proof of delivery, short-landed / damaged / claimed; the RoRo loading list with lane and deck
assignment; terminal fee and passenger insurance collected per voyage against what was remitted, and remittance by
check. Reports: tonnage and volume, exceptions and claims, lane utilization, port fees by voyage.

**Fleet & Maintenance.** Vessel registry (particulars, accommodation plan and seat maps, linked to its fixed asset, its
accounting class and its fuel and stores site), vessel certificates with expiry, the equipment hierarchy with running
hours, planned maintenance by calendar or hours, defect reports with triage, **work orders** (labor, spare parts from
stock, outside services billed, deferral by approval, technical release and inspection by someone other than the
technician), dry-docking projects with milestones and budget against actual, and **purchase requisitions** with canvass
(two quotes or a reason, lowest or a reason) to the accounting purchase order, receipt and bill. Reports: work order
status, PM due and overdue, repair spend by vessel and equipment, reliability (downtime, failures, MTTR), certificate
expiry, spares issued.

**Customer Service.** Support cases and claims by category with service times by priority and escalation, linked to the
booking, ticket, voyage or waybill; compensation by voucher or cash through approval; the log of every notice sent to
passengers and staff.

**Shipping Set-up.** Ports and terminals, routes with stops, the **fare matrix** (fares by route and class with
effective dates and history, peak dates, vehicle rates, cargo tariffs, promos that never stack on a statutory
discount), passenger categories and discounts, fees and third-party charges, vehicle classes, ticket offices and the
users assigned to them, shipping policies and the posting accounts, approval delegations, the compliance register, and
two control reports: control exceptions (anti-fraud flags) and the ticket / ledger / manifest reconciliation.

## 6. How shipping posts to the books

Each event posts its accounting document once (the event key is recorded, so a retry or a second click never posts
twice), with the vessel's **class** on every line so the books give a P&L per vessel. The accounts are set on
**Shipping Set-up → Shipping Policies & Posting Accounts** and **Financial → Module Default Accounts**.

| Event | Document | Debit | Credit |
|---|---|---|---|
| Ticket paid before sailing | Sales receipt | Cash / card or e-wallet clearing | Deferred Passenger Revenue, Output VAT, fee payables |
| Corporate / agent ticket | Invoice | Accounts Receivable | the same |
| Voyage completed | Journal | Deferred Passenger Revenue, Statutory Fare Discounts | Passenger Fare Revenue, Fuel Surcharge, Forfeited Fares (no-shows) |
| Vehicle fare, prepaid cargo earned | Journal | Deferred Freight Revenue | Vehicle Freight, Cargo Freight |
| Cargo on credit, after delivery | Invoice | Accounts Receivable | Cargo Freight, Output VAT |
| Terminal fee / insurance remitted | Check | Terminal Fee / Insurance Payable | Bank |
| Fuel burnt on a voyage | Inventory adjustment | Voyage Fuel | Fuel inventory (average cost) |
| Spare part to a repair | Inventory adjustment | Spares Consumed or Capex WIP | Spare parts inventory |
| Job-card labor | Journal | Repairs and Maintenance | Internal Labor Absorbed |
| Bunkering, supplier invoice, outside repair | Bill | Inventory / expense, Input VAT | Accounts Payable |
| Refund approved and paid | Credit memo, refund, journal | Deferred revenue, Output VAT, fee payables | Refund payable, then cash; kept fare to Change and Cancellation Fees |
| Cashier over/short | Journal | Cash / Cash Over-Short | Cash Over-Short / Cash |
| Compensation | Credit memo or check | Claims and Compensation | Customer credit or bank |

Senior citizen and PWD fares are VAT-exempt with the 20% discount on the fare net of VAT; the discount is shown on its
own account. Published fares include VAT (a setting). Revenue can instead be recognized on sale (a setting).

## 7. Controls built in

- **Separation of duties**: the maker of a refund, compensation, override, deferral, requisition or fare change cannot
  approve it; a technician cannot inspect or release their own work; the two releases of a no-sail hold are two people;
  a cashier's shift is reviewed by someone else. Approval limits and delegations by amount.
- **No double sale, no double posting, no double payment**: voyage-level locking for seats, lane meters and capacity;
  one-time payment keys and unique gateway references; one accounting document per event.
- **Signed QR codes**: a ticket number changed or moved to another voyage fails the signature check at the gate.
- **Departure safety**: a no-sail hold or more passengers boarded than the approved limit can never be overridden.
- **Personal data** shown in full only to roles with that permission; manifests and exports logged.
- **Audit trail** of every change, approval, log-in, denied page and export, protected by database triggers so it cannot
  be edited or deleted.
- Control reports: anti-fraud flags (refunds by the seller, fare overrides, unreconciled manual tickets, rejected scans,
  large refunds, cash over/short) and the reconciliation of tickets, ledger and manifests.

## 8. The passenger portal (`portal.php`)

Passengers search sailings by port and date (fares and places left per class), create an account, enter their
passengers, see the itemized fare, and **reserve**. A reservation is held for the time set in the policies (2 hours by
default) and paid at any ticket office with its booking reference; the e-tickets with their QR codes then appear under
*My bookings*, ready to show at the gate or print. Passengers can cancel an unpaid reservation, check whether their boat
is on time with the booking reference and a last name (no account needed), and send a concern to customer service.
Portal accounts are separate from staff accounts and use their own session. With several companies the passenger
first chooses the shipping line.

## 9. Integrations: what is connected and what is not

The system makes no call to any outside service. Be aware of what that means:

- **E-mail and SMS**: every notice (reservation, e-ticket, delay, cancellation, boarding open, refund, case update) is
  written to the notification log with its text and recipient. With no provider named in *Shipping Policies*, e-mail and
  SMS notices are logged as *no provider* and **not sent**; in-app notices to staff work. Connecting a provider means
  adding its sending code to `app/Notify.php`.
- **Online payment gateway**: not connected. Portal reservations are paid at a ticket office. The payment records are
  already built for a gateway (provider reference, one-time key, duplicate callbacks refused).
- **BIR**: tickets are recorded as sales receipts or invoices in the books. Using the e-ticket as the official receipt
  or invoice needs a BIR-registered system (a setting, off).
- **MARINA / PCG**: manifests are printed and exported as CSV in the expected layout; nothing is transmitted.

## 10. Several companies (multi-company)

The installation is delivered with multi-company switched on: the first company (`gsmart_shipping`) is Company 1, and the
**Super Admin** adds other shipping companies from **Companies → New company**. Each new company gets a database of its
own with the same structure and a copy of the set-up lists only: settings (with its own name and details, and its own
QR signing key), modules, pages, roles with page access and special permissions, numbering, chart of accounts, posting
accounts, tax codes, terms, payment methods, units, categories, fixed asset categories, and the shipping lists (ports,
passenger categories and statutory discounts, vehicle classes, third-party fees, compliance tasks). Vessels, routes,
fares, users other than its owner and every record start empty.

**Roles.** The **Super Admin** manages the platform: every company, its owner, its Company Code and its database. Each company has a **Company Owner**, who manages that company's users: approving or rejecting employees who join, creating and editing users, giving them roles, switching them off and on, resetting passwords, and the Company Code. Below the owner come the company's administrators and employees, with the roles and page access the company already had.

**Setting it up on an installation without it.** The delivered databases already have it. On a database installed without `--platform`, log in as an administrator and open **System Administration → Multi-Company Setup**: choose the Company Owner from the company's users, choose the Super Admin's username and password, tick that a backup was made, and press **Set up**. The platform database (`gsmart_shipping_master`: the company database name followed by `_master`; `config/config.php` can name another one with `'master' => ['name' => '...']`) is created, the database is registered as Company 1 without changing any record in it, and its users keep logging in as before.

**The platform pages (Super Admin).** **Companies** lists every company with its code, owner, status, number of users, waiting employees and database. **Open** works inside a company as the Super Admin (a yellow strip says so, and every change is in that company's audit trail under an account of the Super Admin's own that nobody can log into). **Details** shows and edits the company's information, assigns or changes the Company Owner (one of its users, or a new account), regenerates the code, switches joining off or sets an expiry date, changes whether employees need approval, shows the company's users, its database (name, tables, audit trail protection, pending updates, file folder), the set-up steps and the platform activity, and switches the company off or on. **Database updates** runs the upgrade scripts of a new version on every company database. **Super Admins**, **Platform settings** (the name prefix of new databases, the approval default, session and log-in limits) and the **Platform audit log** complete it.

**Creating a company.** **New company** asks for the company's details, the company whose set-up is copied (Company 1 unless another is chosen), whether employees need approval, and the owner's account (or the owner is assigned later). Pressing **Create company**:

1. generates a unique Company Code;
2. names a database for it (`gsmart_shipping_company_002`, `_003` ...; a name that already exists on the server is never used);
3. creates the database and every table exactly as the copied company has them today, with all columns, keys, indexes and foreign keys, and the counters starting from 1;
4. copies the set-up lists only (see above) and adds one inventory site, Main;
5. puts the audit trail protection triggers in place;
6. creates the owner's account with the company's full-access role (the owner must change the temporary password at the first log-in);
7. checks the new database (every table there, the structure matching the program, no customer, supplier, item or transaction in it);
8. registers the company, its database and its users on the platform and creates its file folder;
9. and only then switches the company on.

Nothing else is copied: no customers, suppliers, items, employees or other names, users, transactions, sales, payments, bills, journals, balances, budgets, fixed assets, POS records, audit trail or history. Every step is logged. If one fails, the company is marked **Set-up failed** with the step and the reason, the database that attempt created is dropped again, nobody can log in to it or join it, and the Super Admin can set it up again once the cause is fixed, or delete the failed record.

**The Company Code and joining.** Every company has a code such as `ABD-7K2QX`: its initials and five random characters, unique on the platform and never reused, saying nothing about the database. The owner hands it out; new employees open **Join your company** from the log-in page (`register.php`) and enter their full name, e-mail, employee number (optional), username, password and the code. The code is checked on the server; a wrong, old, switched-off or expired code, or the code of an inactive company, all get the same message ("Invalid or inactive Company Code. Please verify the code with your Company Owner."), and ten wrong codes from one address stop that address for 15 minutes. The account is created in that company's database only and, unless the company switched approval off, waits for approval: the employee is told so when trying to log in, and the owner finds the request under **Company → Join Requests** (the menu shows how many are waiting) to approve it with a role or reject it with a reason. The code is never a password: it only says which company; the employee always logs in with their own username and password. Regenerating the code (owner or Super Admin) retires the old one at once; everyone who already joined keeps their account.

**The Company menu (owner).** **Company Profile & Code** shows the company, its owner, its users by status and the code with *Copy code* and *Copy join link*; the owner can regenerate it, switch joining off, set an expiry date, and choose whether new employees need approval and the role they start with (a full-access role is never given without approval). **Employees & Users** lists the users with tabs for all, join requests, active, inactive, locked and rejected, a search box and a role filter, and shows each person's roles, status, how the account was made (by an administrator, with the Company Code, with the company, by the Super Admin), when, and the last log-in. From there the owner approves or rejects, changes roles, resets a password, unlocks, deactivates and activates; *New user* and the full details (sites, POS terminals) are the usual User Accounts page. Company administrators can use these pages too, but only the owner can change the code or the joining rules, and nobody but the owner or the Super Admin can change the owner's account.

**Logging in.** Usernames are unique across all companies and the Super Admins. The log-in page looks the username up on the platform, which says which company it belongs to; the company has to be active; the password, lock-out, approval and password rules are then checked in that company's own database, and the session is tied to that company. On every click the system checks again that the company is still active and that the user still belongs to it. A company switched off by the Super Admin logs its users out at their next click and keeps its database, users and records until it is switched on again.

**One user, two companies.** A user of one company can also work in another company with the same log-in, without being a Super Admin. An administrator of the other company opens **User Accounts → Add user from another company**, types the person's username and gives them roles, sites and POS terminals in that company; the platform keeps the link (`user_company_links`, created by itself the first time) and that company holds a linked account that never logs in by itself. The person logs in at their own company as always; the company name in the top bar becomes a switch between their companies, and a blue strip shows while they work in the other one. On every click the link, the person's own account and both companies are checked again. Either company can remove the access (the linked account is switched off, so its name stays on what it entered), and it ends when the person's own account is deactivated. Their password and password rules stay those of their own company; their work and every switch are in the audit trail of the company they work in.

**Isolation.** The company database is chosen in one place only (`app/Tenant.php`), from the platform's records and the server-side session, never from the address, a form or a cookie; with several companies and none chosen, the program refuses to read any company database. Database names are made by the system and never typed by anyone. Each company's logo, pictures, receipts and backups are kept in its own folder. A restore on the Backup & Restore page can only rebuild the company's own tables: statements that switch to or name another database, create databases, users, triggers, routines or views, read files or copy data with a SELECT are refused and listed, and the audit trail protection is put back afterwards. Backup files of the other companies are named after the company (`company_002_...`), not after the database.

**What the MySQL user needs.** The user in `config/config.php` creates and drops the company databases, so it needs CREATE and DROP on databases, and CREATE, ALTER, INDEX, REFERENCES, INSERT, SELECT, UPDATE, DELETE and TRIGGER in them (on MySQL 5.5 with binary logging switched on, creating triggers also needs SUPER, or `log_bin_trust_function_creators = 1`). XAMPP's `root` has all of them. On shared hosting where databases can only be made in the control panel, create the platform database there first (and give the user full rights on it); new companies then need the same treatment and a database name prefix set in **Platform settings** (for example `cpuser_company_`).

**Maintenance.** `tools/send_scheduled_reports.php` and `tools/shipping_tasks.php` serve every active company's scheduled reports from the same cron line (or one company with `--company=N`). `php tools/platform.php list | reset-superadmin <username> <password> | sync <company id> | update-all` lists the companies, resets a Super Admin's password, rebuilds a company's list of usernames, and runs pending upgrade scripts on every company database. To switch multi-company off in an emergency, move `storage/platform/multi_company.json` away: the system runs as Company 1 alone with its own users; put the file back to return. Back up the platform database together with the company databases.

## 11. Configuration and maintenance

- **`config/config.php`:** database connection, company time zone (default `Asia/Manila`), currency symbol, and debug mode. Set the environment variable `APP_DEBUG=1` only while troubleshooting.
- **System Administration → Company, Security & Closing Date:** company details, password rules, lockout, session timeout, finance-charge rate, negative-stock rule, and closing date.
- **Financial → Module Default Accounts:** which accounts each module posts to.
- **Shipping housekeeping (every 15 minutes):** releases the seats of reservations whose hold ran out, puts a no-sail hold on a vessel whose critical certificate expired, escalates late support cases. The pages do the same when opened, so this only keeps things on time.
  - Linux cron: `*/15 * * * * php /path/to/gsmart-shipping/tools/shipping_tasks.php`
  - Windows Task Scheduler: `C:\xampp\php\php.exe C:\xampp\htdocs\gsmart-shipping\tools\shipping_tasks.php`
- **Shipping policies:** Shipping Set-up → Shipping Policies & Posting Accounts: revenue recognition, hold times, sales cut-off, group limit, cancellation and change fees, refund rules, fuel variance threshold, job-card labor, offline scanning, service times of support cases, and the e-mail / SMS / payment providers.
- **Scheduled report e-mails:** run this once a day. It uses PHP `mail()`, so configure SMTP in `php.ini` (XAMPP: `sendmail.ini`).
  - Linux cron: `0 6 * * * php /path/to/gsmart-shipping/tools/send_scheduled_reports.php`
  - Windows Task Scheduler: `C:\xampp\php\php.exe C:\xampp\htdocs\gsmart-shipping\tools\send_scheduled_reports.php`
- **Bluetooth receipt printer:** **System Administration → Company, Security & Closing Date → Bluetooth receipt printer** turns on a *Bluetooth printer* button beside *Print* on every receipt. Choose the paper width (32 characters for a 58 mm roll, which is what the Officom OC-PT210 takes; 42 or 48 for an 80 mm roll), how many blank lines to feed, whether the printer cuts the paper, and whether a receipt prints the moment it opens. *Test print* on the same page pairs the printer and checks the width. The browser does the talking, which puts two conditions on it and neither can be worked around from inside the program: it must be **Chrome, Edge, Opera or Samsung Internet on Android**, or Chrome or Edge on a computer (Firefox, iPhone and iPad, and the browsers built into Facebook and Messenger cannot reach Bluetooth at all), and the page must be opened on an **`https://`** address or on **`http://localhost/`**. Opened on a plain address such as `http://192.168.1.10/` the browser refuses, and the receipt page says so in plain words rather than failing quietly. The simplest way to get `https://` on a local network is a self-signed certificate in Apache, or a tunnel; until then, printing from the till itself at `http://localhost/gsmart-shipping/` works.
- **Default site & class for POS transactions:** **System Administration → Company, Security & Closing Date → Default site & class for POS transactions** lists every user with a Default site and a Default class box. Both are optional and set one user at a time. A user given a site has their POS Register open on that site's counter; a user given a class has every new sale start with that class already chosen. Anyone left blank works exactly as before — the counter is the first one they are allowed to use, and the class comes from the counter or from the sale before it — and the cashier can still change either one on the sale in front of them. Every change is written to the audit trail.
- **Phones and tablets:** every page is laid out for a small screen. The menu becomes a panel behind the button at the top left, a table wider than the screen is swiped sideways inside its own box (with the edge shaded while there are columns still out of sight) instead of dragging the whole page with it (each report line keeps to one line so it can be followed across, and only long memos and descriptions wrap), boxes and buttons are large enough to tap, and text boxes are set at a size that stops a phone zooming in when one is tapped. Searchable boxes are drawn in the part of the screen the on-screen keyboard is not covering, so the choices stay in view while typing, on a phone held either way up.
- **Backups:** **System Administration → Backup & Restore** makes and restores backups from inside the program, and keeps them in `storage/backups`. From the command line, `php tools/export_sql.php --out=backup-2026-09-30.sql` writes a complete, importable SQL file. You can also use phpMyAdmin → Export. Whichever you use, keep a copy off the server.
- **Receipts:** uploads are stored in `storage/receipts/`, which the web server cannot serve directly; files are sent only to logged-in users with access. For e-mailed receipts, have a mail rule save attachments to `storage/receipts/inbox/`, then click *import them* on the Receipt Management page. Details are read from text, e-mail, and text-based PDF files and from file names such as `Petron_2026-09-01_1250.00.jpg`. For photos, connect an OCR service in `app/ReceiptReader.php` (`ocrText()`).
- **Long forms (bills and invoices of hundreds of lines):** PHP reads at most `max_input_vars` fields of a form (1,000 in a standard `php.ini`) and quietly drops the rest, which used to cut a bill of more than about 80 lines short. The program now sends a long form as one packed field and unpacks it on the server, so the number of lines is no longer limited by that setting and nothing needs to be changed in `php.ini`. The only limit left is `post_max_size` (8 MB in a standard XAMPP), which is room for several thousand lines. A page opened before the update and saved with more fields than PHP reads is refused with a message (nothing is saved) instead of being saved without its last lines; reloading the page (Ctrl+F5) fixes it.
- **Memorized transactions from the forms:** every transaction form has **Memorize Transaction** (keep what is on the form as a pro-forma, without recording it) and, on a new form, **Load Memorized Transaction**. They are kept in the existing `memorized_txns` table; item receipts, batches of invoices and inventory adjustments can be memorized too (the last two are only ever loaded into their form). The reasons offered for inventory adjustments are kept in the `settings` table (`inventory_adjust_reasons`) and changed on the adjustment page with **Edit reasons**.
- The `storage/` folder must be writable by the web server. It also holds the company logo (`storage/company/`), the maintenance pictures (`storage/maintenance/`) and the database backups (`storage/backups/`); include it in your backups. With several companies, the first company keeps these folders and every other company has its own under `storage/companies/c002/`, `c003/` and so on; `storage/platform/` holds the switch that turns multi-company on.

### nginx

```nginx
root /var/www/gsmart-shipping;
index index.php;
location ~ ^/(app|config|database|modules|storage|tools|views)/ { deny all; }
location ~ /\.  { deny all; }
location ~ \.(sql|md)$ { deny all; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
```

## 12. How access control works

1. A user can open a page if **any of their roles** allows it, unless a **user override** says otherwise. Overrides always win.
2. On each page, roles grant actions separately: create, edit, delete/void, print, approve.
3. **Administrator** roles can open everything.
4. Users restricted to certain **sites** or **POS terminals** can only post to those, and lists, site pickers and site-based reports show only their sites.
5. **Supervisor approval:** actions beyond a user's limits need another user who has *approve* on that page. Examples are voids, refunds, large discounts, price overrides, and amounts above the user's maximum. The approver types their username and password on the same form, or the user types a one-time **approval code** the approver generated on Point of Sale → Approval Codes (section 13). You cannot approve your own transaction, nor use a code you generated yourself.
6. Every denied page, failed approval, and permission change is written to the audit trail.
7. With several companies (section 10), each company has a **Company Owner**, who can always open every page of that company whatever the roles and overrides say, and whose account only the owner (or the Super Admin) can change. A **Super Admin** belongs to no company: they manage the companies from the platform pages and can open any company to work inside it. Nobody can reach another company's records: the company a session works on is decided on the server when the user logs in, and nothing in the address, a form or a cookie can change it.

## 13. The accounting core

The accounting functions are those of G-SMaRT Accounting, unchanged, and the shipping modules post through them. The **Onboard Point of Sale** is the canteen and store on board (terminals per vessel, cashier shifts, purser approvals).

- **Financial**: account list with sub-accounts, journal entries (including opening balances), module default accounts, terms and tax codes. The **Class List** holds classes you name yourself (branch, department, product line, project) and **Employees & Other Names** holds the people who are neither customers nor suppliers. Reports: trial balance, balance sheet, income statement, the **comparative income statements** (by month, quarter, fiscal year and up to four ranges of your own) and **comparative balance sheets** (at the end of each month, quarter or fiscal year, and as of up to four dates of your own), each with the change from the period before, cash flows, general ledger, journal, **Income Statement by Class**, **Balance Sheet by Class**, **Trial Balance by Class** (a debit and a credit column per class, or one net column per class, and the company totals), **Statement of Cash Flows by Class**, **Trial Balance by Date Range**, **Trial Balance of Totals** (the debits and the credits of each account for the period, not the balance) and **Balance Sheet by Date Range**, **Subsidiary Ledger** and **Schedule of Accounts**. An account can be marked **With SL** so it keeps a listing of the names that transacted it. Choosing a name on the Subsidiary Ledger and leaving the account blank lists every account marked With SL for that name, including the ones it has no entries on in the period, so the whole of a supplier's or customer's subsidiary ledger is seen at once. The **Memorized Transaction List** keeps any transaction you use again and again and enters it on a schedule, automatically or after a reminder on the Home page. **Set Up Budgets** holds twelve monthly figures per account for the income statement or the balance sheet, and the two budget reports put the actual figures beside them. Reports can show columns by month, quarter, or year, and every financial report has a Class filter. The journal entry print-out shows Account, Description, Name, Class, Debit and Credit with totals, and Prepared by / Checked by / Approved by lines. On journal entries and cash vouchers the account and name boxes search as you type (by account description or by name), and the class of the entry fills the class of each line.
- **Classes on every transaction:** each transaction form (invoices, sales receipts, payments, bills, checks, cash vouchers, deposits, transfers, item receipts, inventory adjustments and transfers, builds, processing orders, depreciation, POS sales) has a Class box. The class is copied to every ledger line the transaction posts; journal entries and cash vouchers can also set a class per line. A POS terminal can have a default class. When a transaction is saved with no class chosen, the system asks for confirmation first (Choose a class / Continue saving).
- **Fixed Assets:** asset register (with a Location list and a default class per asset; the register, the asset reports and Run Depreciation can be filtered by location), categories, and a monthly depreciation run. When no class is chosen on the run, each asset is depreciated under its own default class. Methods: straight-line, declining balance, sum-of-the-years'-digits, and units of production. Supports opening accumulated depreciation, changes in estimate, and disposal with gain/loss. Reports: register, schedule, by category, additions and disposals.
- **Asset maintenance:** maintenance records per asset with the supplier, spare parts (part no., serial no., model no., size, brand, warranty), labor (man-hours or a bulk charge), the maintenance description, and pictures of the parts. The cost is disbursed through accounting: saving a record enters a Bill, writes a Check Voucher or makes a Cash Voucher, and keeps it in step when the record is edited or voided. **Preventive maintenance** tasks fall due by date and / or by meter (vehicle registration renewal, change oil, cleaning of air-conditioning units, computers and solar systems, anything else). A due task reminds through the bell in the top bar, the Home page and a pop-up after log-in, and keeps reminding until a user acknowledges that it is complete; a repeating task then moves to its next due date. Reports: maintenance history, cost by asset, spare parts used, labor, upcoming preventive maintenance, completed preventive maintenance.
- **Customers:** estimates → sales orders → invoices (partial fulfilment), batch invoices, sales receipts, statement charges, statements, finance charges, payments (early-payment discounts and the taxes the customer withheld, ticked by type: 1% / 2% to Creditable Withholding Tax, 5% VAT to Creditable VAT Withheld; the amount applied can never be more than the amount received, and available credits can be applied to open invoices on the same screen), credit memos and refunds, billable costs, and pending invoices. **Set Up Forecast** holds a sales forecast per customer (or all customers in one sheet) for the year, and **Forecast vs Actual by Customer** shows how the year is going against it. The **Customer Center** narrows its list of customers as you type. Also includes A/R aging, collections, average days to pay, and 20+ sales reports, among them **Income by Customer Summary** and **Income by Customer Detail** (the sales of the items sold to each customer less what they cost: the income or loss, with the margin) and the **Sales Report Through Inventory Movement** (the quantity sold worked out as beginning inventory + deliveries - withdrawals - ending inventory, at the average price of the invoices, batch invoices, sales receipts and POS sales, by site, class, cashier and POS shift, to the minute, with Exclude zero sales).
- **Vendors:** purchase orders → item receipts → bills (an item receipt not billed yet can be **edited**: date, slip number, site, memo and lines, with the purchase order following), pay bills (by check or card, with the **taxes withheld** from the supplier in a Withholding column: one or more types of the Withholding Tax Table ticked per bill, each worked out on the open amount net of VAT and editable; a bill payment can be **edited** afterwards: its date, account, check number, the bills it pays, the amounts and the taxes withheld, with the bills' balances worked out again), **advances to suppliers** (money paid before the goods or services come, posted to Advances to Suppliers and applied to the supplier's bills in Pay Bills, with an Advances to Suppliers report), each supplier's usual withholding taxes on the vendor record, **supplier debit memos** (returns and billing corrections, applied to the supplier's outstanding bills on their own screen or in Pay Bills), bill tracker, and receipt management (upload photos, PDFs, or e-mails; details are read automatically; then match or create a bill). The **Vendor Center** narrows its list of suppliers as you type. Also includes A/P aging and vendor reports, among them **Expenses by Vendor Summary** (by month, quarter or year if wanted) and **Expenses by Vendor Detail** (every expense charged to each supplier, with its account and class).
- **Average cost on the books (v31):** the average cost is worked out on the movements themselves, never on the quick-lookup quantity table, which is kept with insert-or-update and put right by itself when an item moves. **Inventory → Reports → Average Cost Changes** shows every change of an item's average (before, after, change, change %) and what moved it: a processing output at its share of the cost, a bill at another price, a receipt while below zero, a back-dated entry, a value adjustment; it can be narrowed to processing orders, disassemblies and builds.
- **Inventory order within a day:** on one date, stock received (bills, item receipts, returns, adjustments up) is counted before the stock used or sold that day (processing orders, builds and transfers, then sales), whatever order the documents were typed in: in the Inventory Center's transaction history (whose balances count from the first movement, with the balance brought forward when older lines are hidden, dates and Latest 100 / 500 / All), on Inventory Valuation Detail, in Rebuild Data and in its date-order check. A back-dated processing order may not take stock that only came in after its date.
- **Costs on every sale:** an invoice, sales receipt, statement charge or POS sale cannot sell an inventory part (or an item of an item group) that has neither a unit cost nor an average unit cost, and a processing order cannot be posted while an input has no cost or an output would be received at no cost (two settings, on as delivered). An item with a unit cost but nothing received yet goes out at that unit cost.
- **Inventory:** items (inventory, non-inventory, service, assembly, group, discount, subtotal, payment, and other charge), multiple sites, transfers, quantity/value adjustments, assembly builds (including pending builds; transfers, adjustments and builds can be **edited** after they are saved and are posted again, with the reason in the audit trail), physical counts, price changes, a **Category List** (add, rename and delete item categories; a category still in use is deleted only after its items are moved to another category or to none, so two categories can be merged), and ten price levels per item, each with a name of your own (**Rename price levels** on Set Item Prices and Levels renames them, sets their percentage and switches them on or off). **Set Item Prices and Levels** puts the default price and every price level of every item (or one type or category) in a single grid, so a whole price list is set and saved in one pass; a column can be filled from the cost, from the default price, or from each level's own percentage. **Adjust Quantity/Value on Hand** offers two ways in: **Per Item Adjustment**, the QuickBooks layout (pick items on lines, or many at once with **Find & Select Items**, type the new quantity or the difference, with the U/M and a class on each line, a Customer:Job, **Set Qty to Zero**, and the total value and number of item adjustments below), and **Per Batch Adjustment**, the grid of every item of the site, narrowed to one or several **categories** ticked in a drop-down list; the choice is remembered. The **Inventory Center** has a search box that narrows the item list as you type and a **Site** filter: with a site chosen, the quantities, the open documents and the movement history shown are that site's alone. The inventory reports include **No. of Days Inventory Has No Movement** (days since each item was last sold to a customer, with its last movement of any kind, by site and category), **Quantity on Hand by Site**, the **Negative Inventory Listing** and the **Physical Inventory Worksheet** as of any date, the **Customized Inventory Report** (one line per item with the columns each user picks: item details, stock and value, gross profit per unit and gross profit %, and the sales, cost of sales, gross profit and purchases of a period), **Inventory Valuation Summary** for one inventory site or all, item groups with a **Group price**, the **Consolidated price** (the sum of the prices of the items in the group) and a **Price variance GL account**, sold on one line at the group price on estimates, sales orders, invoices, sales receipts and at the POS (their items recorded at their own prices and the difference posted to the variance account; each document viewed and edited by item group or by item details; the groups sold kept apart for **Sales by Item Group** and **POS Sales by Item Group**), and the **Inventory Movement Summary Report** and **Inventory Movement Detailed Report** (beginning balance, what came in and what went out by kind of transaction, and the ending balance of each item over a period, by site and class, every figure drilled down to its source transactions; with a time after each date, a Cashier and a POS Shift filter, so a business open 24 hours sees each shift). Processing orders print the total of their inputs, the total of their outputs and the variance between the two, the unit cost of each input, and the variance quantity (the input quantity less the output quantity), which the order, its list and the Processing & Disassembly Register show too. The transfer list shows the From and To sites of every transfer, and the printed transfer names both. Costing is weighted-average per item; a sale that brings an item to zero takes all of the remaining value to Cost of Goods Sold. Processing orders show a running total of the allocated cost. Includes valuation and stock-status reports.
- **Point of Sale:** terminals, cashier shifts with opening float, barcode scanning, split tenders (cash, card, P.O. Card, bank, e-wallet, voucher; choose which ones the register offers under Terms, Tax Codes & Other Lists → Payment methods), customer price levels applied automatically, quantity-then-item entry with a **drop-down of matching items** under the item box (it appears as soon as the box is tapped, narrows with every letter and works on a phone, where the browser's own list of choices does not; with the phone on its side it is drawn in the part of the screen the keyboard leaves, one product to a line, so several can be read while typing), customer and class kept for the next sale, customer name on the receipt, receipt title and footer wording editable in System Administration → Company, Security & Closing Date, suspend/recall, charge to account, counter payments, returns and voids with supervisor approval, cash drops, X and Z readings, cash over/short posting, the **opening cash float** shown on the shift, the shift list and the readings, a page per shift where an administrator or supervisor **corrects the closing entries** (the float, a cash drop or the cash counted) with the Cash Over/Short journal entry changed, made or voided to match and every correction kept in the audit trail, and POS sales reports (sales by item, by category, by hour, by day (for one class if wanted), by transaction, discount and void logs, X/Z readings, and **POS Sales by Cashier & Payment Method**, which lists every cashier's sales with the payment methods under each). **Approval Codes:** a supervisor who is not at the counter generates a one-time code on Point of Sale → Approval Codes and reads it out; the cashier types it in the Supervisor approval box instead of the supervisor's username and password. Each code works once and then expires (or lapses on its own after the time chosen), can be limited to one user and one page, and the page lists every code as Open, Used (by whom, when and on which transaction), Expired or Cancelled. Each user can be given a **default site and class** (see section 11), and the register then opens on that site and starts every new sale with that class. On a phone held upright the cart fits the screen without swiping: the discount box and its % / amount picker are stacked, so the quantity, the price and the amount of every line are fully readable. A receipt can also be sent straight from the browser to a **Bluetooth roll printer** such as the Officom OC-PT210, with nothing installed on the phone or the till — see section 11. **Ending Inventory - POS Cashier** (Point of Sale → Reports) lists the quantity on hand at the cashier's outlet at the end of a shift (the times are taken from the shift opened and closed, or typed to the minute), as report type A (item code and balance) or B (item description and balance), with or without the zero balances, and prints on the POS roll printer with the signature lines "Inventory Turnovered by:" and "Inventory Received by:".
- **Banking:** write checks (printed as a **Check Voucher**: Account, Memo, Class, Debit and Credit with totals; deposits and fund transfers print the same way, and Prepared by / Checked by / Approved by / Received by), **cash vouchers** for petty cash and revolving fund payments (paid to an employee, customer, supplier or other name, with a voucher list by date range and a printable voucher), account registers, deposits (including card and e-wallet settlements with fees: each clearing account listed once with its unsettled balance, the account's General Ledger balance on the deposit date as on the Trial Balance, and a **Details** window with the sales, refunds and settlements behind it and the receipts not yet settled, where the receipts a provider paid can be ticked under **Settle**, their total going into Gross settled and the ticked receipts no longer unsettled once the deposit is saved; a saved deposit can be **edited**: payments and POS shifts taken off or added, other receipts, date and account, and a deposit follows a later correction of a POS shift's closing entries), transfers, reconciliation with discrepancy tracking, and a check register.
- **BIR Reports:** the **Withholding Tax Table** (code, BIR ATC, kind of tax, rate, base net of VAT or gross, payments to suppliers / from customers, accounts; flexible: add, change or deactivate rates), whether the company is a withholding agent, and the payor details of BIR Form 2307. **Print BIR Form 2307** prints the Certificate of Creditable Tax Withheld at Source for each supplier and quarter (one or all), from the taxes withheld in Pay Bills: income payments by ATC and month of the quarter, the tax withheld, and the money payments subject to withholding of business tax. Reports (the group *BIR Reports*, following the company's VAT and withholding tax guide): **Summary of Taxes Withheld from Vendors/Suppliers**, **Expanded Withholding Tax Register (1% / 2%)** (Form E), **Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT)** with the due dates, **VAT Withholding Register (1600-VT)** (Form F), **Output VAT / Sales Register** (Form B), **Input VAT / Purchases Register** (Form C), **Creditable VAT Withheld Schedule (2550Q Schedule 3)** (Form D) and the **Quarterly VAT Return Worksheet (2550Q)** (Form A, with the 2550Q item numbers). They are internal worksheets for the official BIR returns.
- **System Administration:**
  - **User Accounts:** create, deactivate, unlock, reset password, and assign roles, sites, and POS terminals.
  - **User Role Configuration:** role permissions per page (open / create / edit / delete / print / approve). Special permissions include: see costs, see bank balances, maximum discount %, maximum price override %, maximum amount, credit-limit override, chart-of-accounts changes, closed-period changes, and designing report layouts. Site restrictions are also set here.
  - **Page Access Configuration:** choose, page by page, whether a role can open each page. You can also override a single user (allow or deny regardless of role). **Effective Access** shows exactly what a user can see and why.
  - **Company, Security & Closing Date:** company logo (JPG, PNG and similar) with a tick list of every report and every kind of voucher it is printed on, password policy, lockout after failed logins, session timeout, and the closing date.
  - **Import from CSV:** loads the chart of accounts, inventory items, customers, suppliers, fixed assets, sales receipts, invoices, sales orders, purchase orders, receive items and enter bills, write checks, POS transactions, cash vouchers and journal entries from spreadsheet files. Each type has a blank template to download with its columns and one sample line. **Check the file** reads every line and lists every problem with its line number without writing anything; when the file is in order, the result shows what will be added or updated and an **Import these records** button imports exactly the file that was checked, without choosing it again. Nothing is written unless every line is in order. Files saved by Excel in its own Windows encoding (curly quotes, dashes, accented letters) are read correctly, as are UTF-8 and UTF-16 files.
  - **Inventory items** (also reached from **Inventory → Item List → Import from CSV**): one line per item with its code, display name, type (Inventory part when blank; also non-inventory parts, services, assemblies, other charges, groups, discounts, subtotals and payments), barcode, sales and purchase descriptions, category (added when it is new), unit or U/M set with its sales and purchase units, cost, sales price, a price for each active price level, discount %, tax code, income, cost of sales, inventory and expense accounts (the module defaults when blank), preferred supplier, reorder point, maximum stock and build point, components of assemblies and groups (`BOLT x 4; NUT x 4`, from the Item List or earlier in the file), and whether the item is active. An item whose code is already there is updated, and only the columns in the file are changed. New inventory parts and assemblies can bring their **opening quantity** with its cost per unit, site and date: the quantities go on hand at their sites and one inventory adjustment per site and date is posted against Opening Balance Equity. Opening stock is refused for items already on the list, so importing the same file twice never doubles the stock.
  - **Backup & Restore:** writes the whole database to one `.sql` file (compressed if the server can), lists the backups kept in `storage/backups`, downloads one, and reads one back. A restore always makes a fresh backup of what is there now before it changes anything, and asks you to type RESTORE to confirm.
  - **Audit Trail:** every create/edit/void/delete (with old and new values), login, failed login, lockout, denied page, approval, and permission change. Entries cannot be edited or deleted, even directly in the database, because MySQL triggers block it. Also includes transaction history, voided/deleted reports, user activity, login history, and access changes.

**Subsidiary ledgers:** an account in the Chart of Accounts can be marked **With SL**, meaning it keeps a listing of the names that transacted it. While the flag is on, a journal entry, cash voucher or check that posts to that account must name a customer, supplier, employee or other name; saving without one is refused and says which account is asking. Two reports read those names: **Subsidiary Ledger** lists one name's entries — every account it transacted or just one, either between two dates or everything up to a date — with the Date, Ref ID, Account, Description, Debit, Credit and a running Balance; **Schedule of Accounts** lists, for one account or for every account marked With SL, each name and what it stands at, and can be narrowed to one class (with none chosen, every class and the entries with no class are included). Each name on the schedule links straight through to its own subsidiary ledger.

**Searching for items:** every transaction form lists its items either as the item name/code followed by the display name (`PORK CHOP - Pork chop, cut`) or the other way round. One choice covers them all, and any transaction can be set on its own — System Administration → Company, Security & Closing Date → Searching for items lists all of them with a choice each. Typing any part of either the item name/code or the display name finds the item, whichever order is chosen.

**Documents made from other documents:** a sales order made from an estimate, an invoice made from a sales order or an estimate (including a progress invoice for part of it), and a bill made from a purchase order all carry the tax of each line across, so the Subtotal, Tax and Total on the new form are the ones the source document showed before anything is saved.

**Find:** the **Find** button in the top bar (also Home → Find) looks for transactions in every module at once: estimates, sales orders, invoices, sales receipts, statement and finance charges, customer payments, credit memos and refunds, purchase orders, item receipts, bills, supplier debit memos, bill payments, checks, cash vouchers, deposits, transfers, journal entries and opening balances, inventory adjustments and transfers, builds, processing orders, POS sales and returns, cash over/short, depreciation and asset disposals. The **Simple** tab asks for the usual few things: the kind of transaction, the name, a date range, the number and the amount. The **Advanced** tab has a list of more than forty filters that can be combined in any way. They include account (one account or a whole account type), name (any customer, supplier, employee or other name, or all of one kind; a customer also finds its jobs), the name's address, phone, e-mail, TIN, contact or account number, transaction type, module, date, entered/modified date, due date and ship date (with presets such as this month or last fiscal quarter), number and reference (is, starts with, contains or from/to), amount and quantity (equal to, at most, at least or between), days past due, memo, item, preferred supplier, class, site, fixed asset, tax code, terms, sales rep, payment method, POS terminal, status, paid / open, posting / non-posting, voided, printed, cleared, billable, received in full, entered by, approved by and import batch. Every filter set is listed under **Current Choices** with a button to take it off again, and the results can be shown one line per transaction or one line per detail line. Clicking a transaction in the results (or choosing it and pressing Go To) opens it. **Report** turns the results into the Find Results report, which prints, memorizes and designs like any other report, and **Export** saves them as a CSV file. Find only shows the kinds of transaction the user may open and the sites the user may see.

Every report supports filters, date presets, sorting, drill-down to the transaction, CSV export, print/PDF (via the browser), memorizing, and scheduled e-mailing.

**Modify Report:** users who have the special permission **Can design report layouts** (Administrators always do) see a **Modify Report** button on every report, as in QuickBooks. It opens a panel above the report, and most changes show on the report straight away, before anything is saved:

- **Columns:** which columns appear, their order (drag them, or use the first / up / down / last buttons), a heading of your own for each, a width (narrow, medium or wide, or left to fit the text) and how each lines up (left, centre or right). The Date or the Name column can be moved anywhere. Under the report's own columns are the **related fields** of the records its rows come from, ready to be ticked on: from the transaction (type, date, number, name, reference, memo, class, site, terms, due and ship dates, status, total, entered by and on, approved by), from the customer or supplier (company, contact, phone, mobile or fax, e-mail, address, TIN, terms, type, sales rep, price level, credit limit, balance owed today), from the item (code, name, barcode, description, category, type, unit, cost and average cost, sales price, reorder point, preferred supplier, on hand today), from the account (number, name, type, category, parent, description, bank account number) and from the fixed asset (number, name, category, location, serial number, date acquired, cost, status). A search box finds a column or field by name, and the fields are looked up only when they print, so a report without them is as fast as before. Cost fields follow the "see costs" right and the bank account number the "see bank balances" right.
- **Rows:** leave out lines that are all zero, keep only the summary lines, leave out the subtotal lines or the grand total, keep only the lines that contain a word, stop after a number of lines, and take any single line out with the eye button beside it (a list shows the ones taken out, each with **Put back**). The totals stay those of the whole report, and a note says so.
- **Heading:** the title and a line under it, extra heading lines, whether the company name, address, TIN, logo, the basis line and a "Prepared on ... by ..." line show, the heading lined up left, centre or right, a rule under it, and the title in capital letters.
- **Fonts and look:** the font and size of the heading, the title and the company name, the heading colour; the font, size, weight, capitals, shading and line-up of the column headings; the font of the body; row height, zebra shading, column lines, a border round the table, shaded group lines and how the totals are drawn.
- **Figures:** negatives with a minus sign, in brackets, in red or red in brackets; zeros shown, blank or as a dash; decimals; thousands separators; amounts in thousands or millions; and the currency sign on the totals or on every amount.
- **Footer:** a footer text lined up left, centre or right, a line above it, the printed-on line, signature lines with your own captions (Prepared by / Checked by / Approved by or anything else) and the names under them, and page numbers.
- **Page:** the printer's own paper, A4, Letter, Folio (8.5 × 13) or Legal, upright or sideways, narrow, normal or wide margins, and the title repeated at the top of every page after the first.

The modified report can be saved as a layout (its added fields included), shared with the other users and made the one that opens by default; **Home → Saved Report Layouts** lists them all. Users without the permission see the report the way the default layout sets it and can pick any shared layout from the **Layouts** menu, but cannot change or save one.

### Accounting notes

- Prices and amounts on sales and purchase forms are **VAT-exclusive**; VAT (12% by default) is added per line and posted to Output Tax or Input Tax.
- Inventory uses **weighted-average cost** per item across all sites. The average is always the remaining value of the item's movements divided by the remaining quantity of the same movements, so rounding never leaves a value behind when the quantity reaches zero. A receipt into stock that was sold or used below zero (for example a processing order posted after the day's sales of its cuts) settles that shortfall at its own cost and posts the difference to cost of goods sold, so the few units left do not carry it. The **Average Cost Changes** report lists every transaction that moved an item's average, with the cause. Quantities are tracked per site. Selling below zero is allowed unless you turn it off in settings; when it is allowed, a sales invoice, sales receipt, batch of invoices, POS sale, inventory adjustment or transfer that would take an item below zero at a site first asks "Saving the transaction will lead the inventory to a negative balance. Do you want to continue?" (Yes saves, No goes back to the form). Negative stock is listed in the *Negative Inventory Listing* report.
- **Withholding taxes** are worked out on the amount **net of VAT** (a bill of P112,000 with 12% VAT is a base of P100,000: 1% is P1,000, 5% is P5,000). A bill payment debits Accounts Payable with the bill amount settled and credits the bank with the cash paid and **Withholding Tax Payable** (1% / 2%, remitted with 0619-E and 1601-EQ) and **VAT Withheld Payable** (5%, remitted with 1600-VT) with the taxes withheld. On a customer payment the 1% / 2% go to **Creditable Withholding Tax** (an income-tax credit) and the 5% to **Creditable VAT Withheld** (a VAT credit, 2550Q Item 16), never to Input Tax. An **advance to a supplier** debits Advances to Suppliers; applying it to a bill debits Accounts Payable and credits Advances to Suppliers.
- Estimates, sales orders, purchase orders, pending invoices/builds, and suspended POS sales **do not post** to the ledger.
- **Void** keeps the document with zero amounts. **Delete** removes it. Both require a reason and are recorded in full in the audit trail. Deleting or voiding is blocked while payments are applied.
- Transactions dated on or before the **closing date**, and reconciled bank lines, can only be changed by users with the *closed-period changes* permission. Changes to reconciled amounts are listed in the Reconciliation Discrepancy report.
- The sample database runs from four weeks before the day it was made to a week after it; reinstall it with `php tools/install.php --drop --demo --platform` to bring the dates to today.
- For MySQL 5.5 compatibility, the automatic "created" date columns (`created_at`, `opened_at`, and so on) are `TIMESTAMP` columns, the only type MySQL 5.5 fills in automatically. That type is limited to dates up to 2038-01-19. Business dates (transaction, due, and acquisition dates) are ordinary `DATE` columns with no such limit. `updated_at` is filled in by the application.

## 14. Folder structure

```
index.php, login.php     entry points (index.php?p=<page key>)
portal.php               the passenger portal (its own accounts and session)
register.php             the join page: an employee creates an account with the Company Code
platform.php             the Super Admin pages (several companies)
manual.php               the User's Manual (the Help button of every page opens it at that page's section)
app/                     core classes: DB, Auth, Access, Audit, Txn, Posting, Stock, Depreciation, Pos, Report ...
app/Ship*.php, Booking.php, Fares.php, Voyages.php, Boarding.php, Manifest.php, Cargo.php, Fuel.php, Cmms.php,
  Procure.php, Holds.php, Cashier.php, Care.php, PortFees.php, Fleet.php, Notify.php
                         the shipping services; ShipGL.php posts every shipping event to the books once
app/registry.php         every page (drives the menus and Page Access Configuration)
app/reports/             report definitions by module (shipping.php: the shipping reports)
modules/shipping/        the shipping pages
modules/                 the accounting pages, one folder per module
views/layout.php         page layout and menu; views/ship_ui.php: the shipping theme's pieces
assets/css/shipping.css  the shipping theme; assets/js/ship.js: its motion and QR codes
assets/vendor/           Bootstrap, Bootstrap Icons, the font, the QR code writer and reader
config/config.php        database and app settings
database/                schema.sql, triggers.sql, master_schema.sql, gsmart_shipping_clean.sql, gsmart_shipping_sample.sql
database/migrations/     upgrade scripts of the accounting core (for older accounting databases; a new install needs none)
tools/                   installer, sample data, SQL export, shipping_tasks.php, acceptance_tests.php, scheduled reports, platform.php
storage/                 receipts, backups, company files, storage/platform/multi_company.json (none web-accessible)
USER_ACCOUNTS.md         every log-in of the delivered databases
```

## 15. Before going live

- Import `gsmart_shipping_clean.sql` (or run `php tools/install.php --drop --platform`).
- Change the passwords of `admin` and `superadmin` (both are asked at first log-in) and keep the Super Admin's safe.
- Enter the company details, the closing date, your vessels (Fleet → Vessel Registry: particulars, accommodation plan,
  certificates), ports and routes, fares, ticket offices and their users, and check the posting accounts.
- Create a MySQL user just for this program instead of `root` (with the rights listed in section 10) and put it in
  `config/config.php`.
- Use HTTPS (the session cookies become secure, and the boarding scanner's camera needs it on phones).
- Keep `APP_DEBUG` off. Schedule `tools/shipping_tasks.php`.
- Back up all the databases (the platform one too) before each month-end close, and keep a copy off the server.

## 16. User's Manual

The manual is built in: every page has a **Help** button that opens it at that page's section, and it is linked from the
user menu and the log-in page. The shipping chapter covers the shipping modules and the passenger portal; the
accounting chapters come from the accounting core, so their examples use a retail demo company and their screenshots
show the accounting screens before the shipping theme. The manual is `manual.php`, `app/Manual.php`, `views/manual/` and
`assets/manual/`.
#   s h i p p i n g  
 