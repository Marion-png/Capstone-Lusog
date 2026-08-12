# LUSOG — Progress Report by User Role

**System:** LUSOG (Learner Utilization and Status of Growth) — DepEd School Clinic Management System
**Stack:** Laravel 12 · PHP 8.2 · PostgreSQL 18.4 · Blade + Tailwind CSS 4
**Date:** 12 August 2026 · **Tests:** 430 total — 418 passing, 12 failing (one defect)

---

## Overview

| # | Role | Modules | Status |
|---|---|---|---|
| 1 | School Nurse | 12 | Functional — most complete |
| 2 | Class Adviser | 6 | Functional |
| 3 | Feeding Coordinator | 5 | Functional — 1 defect |
| 4 | Nutrition Coordinator | 7 | Functional |
| 5 | School Head | 2 | Partial |
| 6 | System Admin | 2 | Partial |
| 7 | Clinic Staff | 1 | Minimal |

---

## 1. School Nurse

Manages the clinic end to end: examines health cards submitted by advisers, logs consultations, and tracks medicine stock. The most developed role, with 12 working modules.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/school-nurse` | Clinic overview with headline figures and recent consultations |
| 2 | Health Records | `/dashboard/student-health-records` | Learner profiles with growth chart and medical documents |
| 3 | Review Queue | `/nurse` | Adviser submissions awaiting medical examination |
| 4 | Consultation Log | `/dashboard/consultation-log` | Consultation history with monthly trends |
| 5 | Medicine Inventory | `/dashboard/medicine-inventory` | Stock levels with predictive reorder panel |

**Gap:** Dispensing Log and Generate Reports are placeholder links.

---

## 2. Class Adviser

Encodes learner data and issues parental consent forms. Entered records are saved to the database immediately, so nothing is lost if the session expires or the server restarts.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/class-adviser` | Class overview with nutritional status summary |
| 2 | Student Entry | `/adviser/create` | Learner data entry form |
| 3 | Consent Forms | `/dashboard/class-adviser/consent-forms` | Parental consent issuing and tracking |
| 4 | Feeding Status | `/dashboard/class-adviser/feeding-status` | Per-learner feeding participation |

**Gap:** Settings is a placeholder link.

---

## 3. Feeding Coordinator

Runs the Supplementary Feeding Program. Attendance must be uploaded first — by spreadsheet or by photograph — before anything else unlocks. At-risk learners are calculated from attendance alone, never tagged by hand.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/feedingcor-dashboard` | Participation and nutritional movement |
| 2 | Feeding Program | `/dashboard/feedingcor-program` | Attendance upload and at-risk beneficiaries |
| 3 | Attendance Review | `/dashboard/feedingcor-program/attendance/review` | Confirming unclear scanned marks |
| 4 | SBFP Forms | `/dashboard/feedingcor-sbfp-forms` | Auto-tabulated DepEd BMI report |

**Note for your write-up:** photo scanning sends the class roster with the image, so the system matches a mark to a learner already on the roster. It cannot invent a learner, and is never used to identify anyone from a face. Marks nobody has confirmed count as neither present nor absent.

**Gap:** spreadsheet attendance import is not saving rows (see Known Issues).

---

## 4. Nutrition Coordinator (NutriCor)

Consolidates nutrition data across schools and produces the reports the division needs.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/nutricor-dashboard` | Cross-school nutrition overview |
| 2 | Analytics | `/dashboard/nutricor-analytics` | Nutritional status breakdown |
| 3 | At-Risk Learners | `/dashboard/nutricor-atrisk` | Learners needing attention |
| 4 | Consolidated Report | `/dashboard/nutricor-consolidated` | Division-level summary |

---

## 5. School Head

Views school-level metrics and reports. The dashboard refreshes on its own without reloading the page.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/school-head` | Live school health metrics |
| 2 | Reports | `/dashboard/school-head/reports` | School reporting view |

**Gap:** deworming approval is listed as this role's responsibility but has no screen yet.

---

## 6. System Administrator

Approves new account requests and reviews the audit trail. The only role not tied to a single school.

**Screenshot these:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Admin Dashboard | `/dashboard/system-admin` | Account request approval |
| 2 | Audit Logs | `/dashboard/system-admin/audit-logs` | Append-only record of data access |

**Gap:** approval only — no account editing, deactivation, or password reset.

---

## 7. Clinic Staff

Least developed role. Has a single dashboard that links into the nurse's screens; it owns no modules of its own.

**Screenshot this:**

| # | Tab | URL | Caption |
|---|---|---|---|
| 1 | Dashboard | `/dashboard/clinic-staff` | Clinic staff landing page |

---

## System-Wide Features

**Encryption at rest.** Learner names, contacts, medical data, consent answers, and signatures are all encrypted with AES-256, as are uploaded documents and session data.

**Audit trail.** Every access to personal data is logged with before/after values — including logins, failed logins, page views, and downloads. Records are append-only and never edited.

**Multi-school separation.** Every query is scoped to the signed-in user's school. Usernames are unique per school, so a teacher working at two schools holds one account at each.

**Unified interface.** All seven roles share one design system: the same logo, one emerald palette, and a common component set. Colour carries fixed meaning — green healthy, amber monitoring, orange at-risk, coral critical. Chart colours are checked against colour-blindness and contrast standards.

**Cloud database.** PostgreSQL 18.4 deployed on Railway with the full dataset restored and verified. Reachable only through an authenticated tunnel — no public listener.

*Suggested screenshot: the Audit Logs tab at `/dashboard/system-admin/audit-logs` evidences both the audit trail and encryption.*

---

## Known Issues

| # | Issue | Severity |
|---|---|---|
| 1 | Spreadsheet attendance import saves no rows — all 12 failing tests trace here. The photograph path works. | High |
| 2 | Condition catalogue (34 items) is defined in code but empty in both databases; the seeder has not been run. | Medium |
| 3 | One migration (attendance photo-scan columns) not yet applied to either database. | Medium |
| 4 | Three placeholder links: Dispensing Log, Generate Reports, Settings. | Low |

---

## Next Steps

1. Fix the spreadsheet attendance import.
2. Run the database seeder and apply the outstanding migration.
3. Build the School Head's deworming approval screen.
4. Give Clinic Staff its own modules, or fold the role into School Nurse.
5. Implement or remove the three placeholder links.

---

## Appendix — Test Logins

All accounts use the password `password123` at **Sta. Ana National High School**.

| Role | Username |
|---|---|
| Class Adviser | `adviser1` |
| School Nurse | `nurse1` |
| Clinic Staff | `clinic1` |
| School Head | `head1` |
| Feeding Coordinator | `feeding1` |
| Nutrition Coordinator | `nutri1` |

System Administrator signs in separately at `/admin-login` with `systemadmin` / `admin123`.

*Before taking screenshots, run the database seeder so the condition catalogue and sample data appear.*
