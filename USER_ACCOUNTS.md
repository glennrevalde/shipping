# G-SMaRT Shipping System: user accounts

Every account below is in the **sample database** (`database/gsmart_shipping_sample.sql`). The **clean database**
(`database/gsmart_shipping_clean.sql`) has the same 28 staff accounts and `superadmin`, but every one of them is asked
for a new password at the first log-in; deactivate the ones you do not need. The second company and the passenger
portal account exist only in the sample database.

Everyone logs in on the same page (`login.php`); the system finds the company from the username.
Accounts marked *new password* are asked to choose one at their first log-in. Change every password
before going live.

Company 1 is **Visayas Star Shipping Lines, Inc.** (database `gsmart_shipping`). Its demo e-mail addresses
end in `@visayasstar.test`.

## Staff of Visayas Star Shipping Lines, from the lowest level of access to the highest

| # | Username | Password | Name | Role | What the role does |
|---|----------|----------|------|------|--------------------|
| 1 | `regulator` | `Demo@12345` | PCG Inspector (read-only) | Regulator (read-only) | Authorized PCG / MARINA inspector: voyages, manifests, vessel certificates and safety reports, view only. No finance. |
| 2 | `canteen1` | `Demo@12345` | Ella Ramos | Canteen Cashier | Onboard canteen POS sales, returns and cash drops at the assigned terminal only. |
| 3 | `boarding1` | `Demo@12345` | Jessa Mae Rosales | Boarding Officer | QR check-in and boarding at the gate, vehicle loading scans, passenger count and the voyage manifest. |
| 4 | `ticket1` | `Demo@12345` | Analyn Tumulak | Ticketing Agent / Cashier | Booking desk at the Cebu Pier 4 ticket office: tickets, payments, cashier shift, refund requests, rebooking. |
| 5 | `ticket2` | `Demo@12345` | Paolo Ybanez | Ticketing Agent / Cashier | Same, at Cebu Pier 4 and the Ormoc terminal counter. |
| 6 | `portagent` | `Demo@12345` | Mylene Gacasan | Port Agent | Outport agent (Tagbilaran, Cagayan de Oro): sells from the shared seat inventory, handles cargo and vehicles. |
| 7 | `cargoclerk` | `Demo@12345` | Noel Alcuizar | Cargo Clerk | Cargo quotations, waybills, acceptance, loading tally, delivery and exceptions; vehicle bookings and loading lists. |
| 8 | `csagent` | `Demo@12345` | Kristine Dumaguit | Customer Service | Support cases and claims, passenger notices, free rebooking of cancelled voyages; requests refunds and compensation. |
| 9 | `tech1` | `Demo@12345` | Joel Sumalinog | Engineer / Technician | Reports defects, carries out work orders, logs labor and parts. Cannot inspect or release their own work. |
| 10 | `tech2` | `Demo@12345` | Ricky Bacalso | Engineer / Technician | Same as tech1 (two technicians, to show the separation of duties). |
| 11 | `warehouse` | `Demo@12345` | Dennis Cabahug | Warehouse Keeper | Shore warehouse and vessel stores: receiving, transfers, spares issued to work orders, bunkering receipts, counts. |
| 12 | `invclerk` | `Demo@12345` | Dan Villanueva | Inventory Clerk | Items, sites, receiving, adjustments, transfers, stock counts and inventory reports. |
| 13 | `arclerk` | `Demo@12345` | Ben Garcia | A/R Clerk | Corporate and cargo accounts, invoices, collections and receivables reports. |
| 14 | `apclerk` | `Demo@12345` | Carla Lim | A/P Clerk | Bills, supplier debit memos, bill payments, advances, payables reports and BIR Form 2307. |
| 15 | `salesrep` | `Demo@12345` | Maria Santos | Corporate Sales | Corporate, travel-agency and cargo accounts: estimates, sales orders, invoices, sales reports. |
| 16 | `purser` | `Demo@12345` | Fred Tan | Purser (POS Supervisor) | Canteen supervision: approves voids, refunds and overrides, runs X- and Z-readings. |
| 17 | `procure` | `Demo@12345` | Marivic Ouano | Procurement Officer | Canvass of approved requisitions, purchase orders, supplier records. |
| 18 | `planner` | `Demo@12345` | Liza Montenegro | Maintenance Planner | Equipment register, planned maintenance, work order planning, dry-docking projects, requisitions. |
| 19 | `tktmgr` | `Demo@12345` | Grace Lim-Uy | Ticketing Manager | Ticketing, boarding and manifests; approves refunds and compensation, reviews cashier shifts, sets fares. |
| 20 | `chiefeng` | `Demo@12345` | Engr. Victor Labra | Chief Engineer | Engine room, running hours, defects and work orders; signs the technical release of critical work. |
| 21 | `captain` | `Demo@12345` | Capt. Rodrigo Saavedra | Captain / Master | Departure readiness and release, voyage status and report, crew list, manifests, defects, fuel soundings. |
| 22 | `accountant` | `Demo@12345` | Ana Cruz, CPA | Accountant | Financial set-up and reports, journals, fixed assets, banking, BIR reports, shipping posting accounts, every shipping report. |
| 23 | `treasurer` | `Demo@12345` | Belinda Sy | Treasurer | Deposits of ticket collections, bank reconciliation, supplier payments, shift review, refund pay-outs, port fee remittance. |
| 24 | `auditor` | `Demo@12345` | Grace Uy | Internal Auditor | View-only access to every record, report and the audit trail. |
| 25 | `fleetmgr` | `Demo@12345` | Engr. Arnel Pacquiao | Fleet Manager | Vessels, certificates, maintenance, dry-docking, fleet purchasing; approves work, deferrals and requisitions; management release of safety holds. |
| 26 | `opsmgr` | `Demo@12345` | Teresa Abellana | Operations Manager | Voyages, schedules, dispatch, holds, fuel, crew, manifests, cargo; approves departure overrides; management release of safety holds. |
| 27 | `gm` | `Demo@12345` | Ramon Villafuerte | General Manager | Sees everything; approves refunds, compensation, overrides, purchases and fare changes. |
| 28 | `admin` | `Admin@12345` (*new password*) | System Administrator | Administrator, **Company Owner** | Everything in every module of the company, including users, roles, page access, the closing date and the audit trail. Owner of Company 1 on the platform. |

## The second company (sample database only)

The Super Admin added **Mindanao Ferry Express Corp.** from the platform pages, with its own database copied from
Company 1's set-up (chart of accounts, roles, ports, passenger categories, vehicle classes, fees) and no records.

| Username | Password | Name | Role |
|----------|----------|------|------|
| `mfeowner` | `Owner@12345` (*new password*) | Rogelio Abarquez | Administrator, **Company Owner** of Mindanao Ferry Express Corp. |

## The platform

| Username | Password | Name | Role |
|----------|----------|------|------|
| `superadmin` | `Super@12345` (*new password*) | Platform Super Admin | **Super Admin**: adds and manages shipping companies (Companies, New company, Database updates, Super Admins, Platform settings, Platform audit log). Belongs to no company; can open any company to work inside it. |

## Passenger portal (`portal.php`)

Passengers have their own accounts on the passenger portal, separate from staff accounts. Anyone can create one there.

| E-mail | Password | Name | In the sample data |
|--------|----------|------|--------------------|
| `juan.delacruz@mail.test` | `Sakay@2026` | Juan Dela Cruz | One paid trip (two passengers, e-tickets with QR codes) and one reservation waiting for payment. |
