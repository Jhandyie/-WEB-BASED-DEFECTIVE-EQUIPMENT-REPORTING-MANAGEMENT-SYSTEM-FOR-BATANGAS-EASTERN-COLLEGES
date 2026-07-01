# BEC WEB-BASED DEFECTIVE EQUIPMENT REPORTING MANAGEMENT SYSTEM
# DETAILED SYSTEM CONSULTATION REPORT
### Findings · Root-Cause Analysis · Required Improvements · Workflows · Acceptance Criteria · Implementation Notes

---

**Prepared in the capacity of:** Senior UI/UX Architect · Enterprise Software Designer · Product Manager · Human-Computer Interaction (HCI) Specialist · Full-Stack System Analyst
**Experience applied:** 20+ years designing institutional management systems, maintenance management systems (CMMS), ERP platforms, educational dashboards, and enterprise-level web applications.
**System under review:** BEC Web-Based Defective Equipment Reporting Management System — Reporter (Student / Teacher / Staff) portal, Administrator (Property Management Office, "PMO") console, and Technician console.

**Document promise:** Every concern raised during the consultation is preserved **in full and verbatim**. Nothing is removed. Nothing is summarized away. Each raised concern appears under the heading **"As Raised in Consultation (verbatim)"**, followed by a detailed professional treatment.

---

## TABLE OF CONTENTS
1. Executive Summary
2. Consultation Methodology & How to Read This Report
3. System Roles & Personas
4. Target Status Lifecycle (Corrected End-to-End Flow)
5. Reporter Notification Matrix
6. Detailed Findings
   - F1 — BEC User Import & Identity Verification (Admin)
   - F2 — Real-Time "Track My Reports" (Reporter)
   - F3 — Defect-Reports Flow, "Mark as Received," Notifications & Admin-Side Gap
   - F4 — Reporter Follow-Up / Bump (Limited Triggers)
   - F5 — Status Correction: "Received by Technician" before "In Progress"
   - F6 — PMO Receipt Updates & Notifies the User (Full Coverage)
   - F7 — Filtered Data Export for Admin
   - F8 — Technician Account Creation via Email Self-Verification
   - F9 — Reporter Photo Upload: More Photos & Larger Size
   - F10 — Professional Smart Technician Assignment
   - F11 — Remove "Busy Technician"
   - F12 — Budget Request Panel: Missing Sidebar
   - F13 — Friendlier Technician Dashboard (Less Detail Up Front)
7. Cross-Cutting Non-Functional Requirements
8. Consolidated Findings & Status Matrix
9. Appendix A — Original Consultation Statement (Unedited)
10. Professional Mandate

---

## 1. EXECUTIVE SUMMARY

The consultation identified thirteen (13) distinct improvement areas spanning all three user surfaces of the system. The concerns cluster into four themes that are characteristic of maturing a prototype into an institution-grade CMMS:

- **Identity & Security** — the system must guarantee that only legitimate BEC users (verified against an official, admin-imported directory) can file reports, and that technician accounts belong to verified individuals (F1, F8).
- **Transparency & Communication** — reporters must experience a truthful, real-time, delivery-style tracking journey; must be notified at every milestone; and must be able to politely follow up on stalled reports. The PMO must have explicit acknowledgement actions and must never be left wondering whether a report was received (F2, F3, F4, F6).
- **Workflow Correctness** — the lifecycle must reflect reality: a technician *receiving/accepting* a task is a distinct, explicit step before *In Progress* (F5).
- **Operational UX for Management** — administrators (and higher personnel) need professional tooling for technician assignment, filtered exports for decision-making, consistent navigation chrome, and a technician console that reduces cognitive load instead of overwhelming the field worker (F7, F10, F11, F12, F13, F9).

**Outcome:** all 13 findings have been specified in detail and implemented; each finding below records both the requirement and its delivery status. The work was performed without removing any existing functionality.

---

## 2. CONSULTATION METHODOLOGY & HOW TO READ THIS REPORT

Each finding is documented using a consistent enterprise template so it can be traced from raw concern → analysis → solution → verification:

- **Severity / Priority** — business impact and urgency.
- **As Raised in Consultation (verbatim)** — your exact words, preserved.
- **Current State (As-Is)** — how the system behaves today / behaved before the change.
- **Root-Cause / Professional Analysis** — why it is a problem, from a CMMS/ERP/HCI standpoint.
- **Proposed State (To-Be) / Required Improvement** — the complete, detailed specification.
- **Business & Institutional Value** — why it matters to BEC.
- **Representative User Story** — the human outcome.
- **Workflow / Interaction Detail** — step-by-step behavior.
- **Data & Technical Considerations** — fields, states, integration points.
- **Risk if Not Addressed** — the cost of inaction.
- **Acceptance Criteria** — objective "done" conditions.
- **Implementation Status** — what was actually built.

---

## 3. SYSTEM ROLES & PERSONAS

| Role | Who | Primary Goals |
|------|-----|---------------|
| **Reporter** | Student, Teacher/Faculty, Staff/Janitor — "any reporter within school premises" using their BEC account | Quickly report a defect with evidence; know it was received; track real progress; follow up if stalled. |
| **Administrator (PMO)** | The Property Management Office; managed by higher personnel | Receive & acknowledge reports; review/approve/reject; assign the right technician; monitor and export; keep reporters informed; manage the official user directory and technician onboarding. |
| **Technician** | Verified maintenance personnel created by the PMO | Receive/accept assigned tasks; perform and document repairs with photos; request materials/budget; submit completion for verification. |

---

## 4. TARGET STATUS LIFECYCLE (CORRECTED END-TO-END FLOW)

The corrected lifecycle makes every real-world action an explicit, observable state. This single model underpins F2 (tracking), F3 (acknowledgement), F5 (technician acceptance), and F6 (notifications).

```
            ┌──────────────┐
            │  Submitted   │  (reporter files; real timestamp recorded)
            └──────┬───────┘
                   ▼
         ┌───────────────────┐
         │ Pending PMO Review │
         └─────────┬─────────┘
                   ▼
          ┌──────────────────┐         ┌────────────┐
          │ Received by PMO   │────────▶│  Rejected  │  (terminal)
          └─────────┬────────┘         └────────────┘
                    ▼
              ┌──────────┐
              │ Approved │
              └────┬─────┘
                   ▼
        ┌─────────────────────┐
        │ Technician Assigned │
        └──────────┬──────────┘
                   ▼
        ┌───────────────────────┐
        │ Received by Technician │   ← MUST exist (F5)
        └──────────┬────────────┘
                   ▼
            ┌──────────────┐
            │ In Progress  │   ← only AFTER technician acceptance (F5)
            └──────┬───────┘
                   ▼
            ┌──────────────┐
            │  Completed   │
            └──────┬───────┘
                   ▼
            ┌──────────────┐
            │   Closed /   │
            │   Verified   │  (terminal)
            └──────────────┘
```

Every transition above is **timestamped**, **audit-logged**, and **broadcast to the reporter** (see Section 5). In the reporter's tracker, a stage only shows a date once it is actually reached — never before (F2).

---

## 5. REPORTER NOTIFICATION MATRIX

This matrix operationalizes F3 and F6 ("always notify the reporter," "pag na-received ng PMO, update and notify user"). Channels: **In-App** (notification center) and **Email** (when the reporter's email is on record).

| Event | Reporter In-App | Reporter Email | Admin In-App | Audit Log |
|-------|:---:|:---:|:---:|:---:|
| Report Submitted | ✓ | ✓ | ✓ (new report) | ✓ |
| Received by PMO ("Mark as Received") | ✓ | ✓ | ✓ | ✓ |
| Approved | ✓ | ✓ | — | ✓ |
| Rejected | ✓ | ✓ | — | ✓ |
| Technician Assigned | ✓ | ✓ | — | ✓ |
| Received by Technician | ✓ | ✓ | — | ✓ |
| Repair In Progress | ✓ | ✓ | — | ✓ |
| Repair Completed | ✓ | ✓ | ✓ (verify queue) | ✓ |
| Closed / Verified | ✓ | ✓ | — | ✓ |
| Reporter Follow-Up / Bump | — | — | ✓ ("Reporter has requested a follow-up regarding Ticket #BEC-XXXXXX.") | ✓ |

---

# 6. DETAILED FINDINGS

---

## F1 — BEC USER IMPORT & IDENTITY VERIFICATION (ADMIN MODULE)

**Severity / Priority:** Critical (security & identity backbone).

### As Raised in Consultation (verbatim)
> "About sa user report UI or 'yung sa reporter which is the student, teacher, etc. — sabi ni ma'am dapat magkaroon kami ng additional features para admin where admin can import an Excel na naglalaman ng mga information of the users, for example it their name, and email address where it is a BEC account, na magagamit para sa security and verification na legit na BEC user 'yung magrereport. Since admin side it ay wala sa ngayon 'yung iimport nila, but we need this feature in admin."

### Current State (As-Is)
Any person can type a name and an email and submit a report. There is no authoritative check that the email belongs to a real BEC student, teacher, or staff member. The admin has **no import facility** at all.

### Root-Cause / Professional Analysis
The intake has **no source of truth for identity**. In institutional systems, unverified intake invites spam and impersonation, pollutes analytics, and breaks accountability (you cannot hold "an anonymous email" responsible). Ma'am's request is precisely the correct control: maintain an **official directory** of BEC users and validate every submission against it.

### Proposed State (To-Be) / Required Improvement
1. An **Administrator-only Import Management Module**.
2. Import an Excel/CSV file containing the official BEC users with fields:
   - Full Name
   - BEC Email Address (the verification key)
   - Employee Number
   - Student Number
   - Department
   - Program
   - User Type — **Student / Faculty / Staff**
3. Imported records become the **authoritative list** of authorized BEC users.
4. **Verification behavior on report submission:** check whether the entered email exists in the imported directory.
   - **Found →** allow.
   - **Not found →** reject with exactly: *"This email is not registered under the official Batangas Eastern Colleges directory."*
5. Only verified BEC email accounts may report — a security **and** identity-verification feature.

### Business & Institutional Value
Guarantees every report is traceable to a real, current member of the BEC community; protects the system from abuse; gives the PMO confidence the workload is legitimate.

### Representative User Story
*"As the PMO admin, I import our official roster so that only real BEC students, faculty, and staff can file reports, and impostors are turned away with a clear message."*

### Workflow / Interaction Detail
Admin → BEC Directory → upload file → system parses, validates emails, upserts records → shows totals (Students / Faculty / Staff). On the reporter gate, the entered email is matched against the directory before a session is granted.

### Data & Technical Considerations
- Directory table keyed by **email (unique)**; re-imports update existing rows and insert new ones (no duplicates).
- A **safe fallback**: when the directory is empty (before the first import), the system uses the `@bec.edu.ph` domain rule so it remains usable.

### Risk if Not Addressed
Fraudulent/anonymous reports, unreliable metrics, and a weak audit trail — unacceptable for a graded institutional system.

### Acceptance Criteria
- Admin can upload and see record counts and a searchable list.
- Re-import updates/append without duplicates.
- In-directory email is admitted; out-of-directory email is rejected with the exact message.
- A working fallback exists before import.

### Implementation Status — DELIVERED
New **BEC Directory** admin page (`admin_bec_directory.php`): CSV upload, downloadable template, searchable table, Student/Faculty/Staff stats, Clear-All. Backed by a `bec_directory` table (upsert by email). The reporter gate (`student_index.php`) verifies against the directory and rejects non-listed accounts with the exact message; empty-directory fallback to `@bec.edu.ph`. (Native `.xlsx` needs the PHP `zip` extension, currently disabled in XAMPP; CSV works now and `.xlsx` activates once `extension=zip` is enabled.)

---

## F2 — REAL-TIME "TRACK MY REPORTS" (REPORTER)

**Severity / Priority:** High (trust & transparency).

### As Raised in Consultation (verbatim)
> "Yung sa track my reports, na nasa side naman ni reporter or student, kailangan ay make more realtime updates. Hindi pwede may date na nakalagay agad doon sa track my reports, since mag-uupdate lang 'yun if received na ba ng admin or process na ba ng technician, like that. For example sa mga Shopee tracking, its order."

### Current State (As-Is)
The tracker shows dates/statuses immediately, implying progress that has not occurred.

### Root-Cause / Professional Analysis
Pre-filled dates are **misleading** and break trust. The correct model is **event-driven parcel tracking** (Shopee/Lazada): a stage receives a timestamp only when the action truly happens; the current step is shown as "in progress"; future steps are dateless.

### Proposed State (To-Be) / Required Improvement
1. Redesign tracking to be **real-time and event-driven**.
2. The timeline updates **only when an actual action occurs** (received by admin / processed by technician, etc.).
3. **No future dates. No placeholder dates.**
4. Example: Report Submitted (real time) → Pending PMO Review → Received by PMO → Approved → Technician Assigned → Received by Technician → Repair In Progress → Repair Completed → Closed.
5. Must feel dynamic and alive, like delivery tracking.

### Business & Institutional Value
Reporters trust the system; fewer "what's happening?" inquiries to the PMO.

### Representative User Story
*"As a reporter, I open tracking and see exactly which real step my report is on — with timestamps only for things that actually happened — just like tracking a parcel."*

### Workflow / Interaction Detail
A status hero shows the current stage + progress ring; a vertical timeline marks completed stages with timestamps, the active stage as "In progress," and future stages dimmed and dateless.

### Data & Technical Considerations
Timestamps are sourced from real transition columns; pending stages render no date by design.

### Risk if Not Addressed
Perceived dishonesty; reporters lose confidence and escalate manually.

### Acceptance Criteria
- Completed stages → real timestamps; current → active; future → no date.
- No placeholder/future dates anywhere.

### Implementation Status — DELIVERED
`track_report.php` rebuilt: delivery-style **status hero** (current stage + circular progress ring, e.g., "60% · 3 of 5 stages"), **vertical timeline** with connecting line, **educational descriptions** per stage, animated **"In progress"** indicator, and **timestamps only on completed stages** ("Up next — no action yet" for pending).

---

## F3 — DEFECT-REPORTS FLOW, "MARK AS RECEIVED," NOTIFICATIONS & ADMIN-SIDE GAP

**Severity / Priority:** High (core operations + communication).

### As Raised in Consultation (verbatim)
> "Another issue found is in the admin dashboard — its located in defect report, where it need to have a better flow and UX/UI of this because it look like simple, and it need to have an action where the admin can see a button where masasabi na na-received na siya 'yung report, tapos magno-notify ito sa reporter about sa kaniyang ni-report na received na ito ng admin, and always notify the reporter sa status ng kaniyang ni-report. And also there have a gap where hindi nagno-notify si admin kung na-received niya na ba 'yung report or hindi pa."

### Current State (As-Is)
Defect Reports reads as a flat, "simple" list with no explicit acknowledgement action and no clear signal of whether a report has been received.

### Root-Cause / Professional Analysis
The intake stage lacks **visibility, acknowledgement, and communication**. Two issues: (1) no explicit "Mark as Received" action that notifies the reporter; (2) an **admin-side gap** — the PMO cannot tell whether a report has been received yet.

### Proposed State (To-Be) / Required Improvement
1. New reports are clearly flagged for the admin.
2. A **"Mark as Received"** button that, on click, automatically: updates status (Received by PMO), **creates an audit log**, **records a timestamp**, and **notifies the reporter**: *"Your report has been received by the Property Management Office and is currently under review."*
3. This acknowledgement is **visible in report tracking**.
4. **Always notify the reporter** of every status change (closing the admin-side gap).

### Business & Institutional Value
Reporters get the all-important "we received it" assurance; the PMO always knows what has/has not been acknowledged.

### Representative User Story
*"As the PMO admin, I press 'Mark as Received' and the reporter is instantly informed, the timeline advances, and I can see at a glance which reports I've already acknowledged."*

### Workflow / Interaction Detail
New report → admin sees acknowledge card → "Mark as Received" → status = Received by PMO, audit + timestamp written, reporter notified → tracker shows the new stage → Approve/Reject remain available.

### Data & Technical Considerations
A receipt timestamp column; audit entries per action; a reusable reporter-notification routine for all transitions.

### Risk if Not Addressed
Reports linger in ambiguity; reporters feel ignored; accountability suffers.

### Acceptance Criteria
- "Mark as Received" present on new reports; advances status + audit + timestamp + reporter notification.
- "Received by PMO" appears in the reporter tracker.
- Every later transition also notifies the reporter.

### Implementation Status — DELIVERED
**"Mark as Received"** on `admin_defect_reports.php` → status **Received by PMO**, **audit log**, **timestamp** (`received_by_pmo_at`), reporter notification (exact message). Review card persists through "Received by PMO" so Approve/Reject still work. A reusable `notifyReporter()` (in-app + email, institutional template) fires at **every** major transition.

---

## F4 — REPORTER FOLLOW-UP / BUMP (LIMITED TRIGGERS)

**Severity / Priority:** Medium-High (responsiveness & accountability).

### As Raised in Consultation (verbatim)
> "Kaya naman may features din sa reporter side na kung saan pwede nila i-bump or i-notify si admin na may report silang ganto or ganyan, like it only take 2-3 trigger to notify them. Like 2-3 na walang notify si admin sa reporter, pwede i-notify ni reporter si admin about sa kaniyang pending report."

### Current State (As-Is)
The reporter has no way to nudge the PMO about a stalled report — a communication gap.

### Root-Cause / Professional Analysis
Without a controlled escalation path, forgotten reports stay forgotten. The fix is a **rate-limited follow-up ("bump")**: enough to guarantee attention, capped (the consultation specified ~**2–3**) to prevent notification flooding/abuse.

### Proposed State (To-Be) / Required Improvement
1. A **Follow-Up / Bump** control on the reporter side.
2. **Limit** the follow-ups (2–3; implemented at **max 3**): Follow-Up #1, #2, #3; then the button is **disabled**.
3. Admin receives: *"Reporter has requested a follow-up regarding Ticket #BEC-XXXXXX."*
4. Purpose: prevent forgotten reports; improve responsiveness; maintain accountability.

### Business & Institutional Value
Reporters feel heard; the PMO is prompted on neglected items; abuse is prevented by the cap.

### Representative User Story
*"As a reporter whose report hasn't moved, I tap 'Send Follow-Up' to remind the PMO — up to three times — and then the button locks so I can't spam them."*

### Workflow / Interaction Detail
Tracking page → "Send Follow-Up #N" → counter increments, admin notified → at the cap (or when resolved) the button disables with clear messaging.

### Data & Technical Considerations
A `follow_up_count` column; admin notifications per bump; clear UI states (sent / limit reached / already resolved).

### Risk if Not Addressed
Stalled reports are never surfaced; reporters resort to off-system complaints.

### Acceptance Criteria
- Follow-up increments the counter and notifies admin each time.
- After the limit (and for resolved reports) the control disables.

### Implementation Status — DELIVERED
**"Send Follow-Up"** on the tracking page: progress dots, **max 3**, auto-disable at limit and when resolved, one admin notification per follow-up ("Reporter has requested a follow-up #N regarding Ticket …"). Verified 1 → 2 → 3 → blocked.

---

## F5 — STATUS CORRECTION: "RECEIVED BY TECHNICIAN" BEFORE "IN PROGRESS"

**Severity / Priority:** High (workflow correctness & metrics).

### As Raised in Consultation (verbatim)
> "Received by technician then tsaka lang ang in-progress."

### Current State (As-Is)
The flow jumped directly to **In Progress** on assignment, conflating *assigned*, *accepted*, and *actively working*.

### Root-Cause / Professional Analysis
These are three different operational realities. Skipping acceptance hides accountability ("did the technician even see this?") and corrupts cycle-time analytics. An explicit **"Received by Technician"** acceptance must precede **In Progress**.

### Proposed State (To-Be) / Required Improvement
The **"Received by Technician"** status must exist; **In Progress** must occur **only after** technician acceptance (see the lifecycle in Section 4).

### Business & Institutional Value
Accurate accountability and truthful cycle-time measurement (assignment → acceptance → work → completion).

### Representative User Story
*"As a technician, I first 'Receive' the task to acknowledge it, and only when I actually begin do I mark it 'In Progress' — so management sees the true state."*

### Workflow / Interaction Detail
Assigned → technician taps **Receive Task** (status = Received by Technician) → technician taps **Start Repair** (status = In Progress).

### Data & Technical Considerations
A dedicated acceptance state and acceptance timestamp; both new stages surface in the reporter tracker.

### Risk if Not Addressed
Inflated "in progress" counts; no visibility into unaccepted assignments.

### Acceptance Criteria
- Assignment → "Technician Assigned"; explicit acceptance → "Received by Technician"; only then "In Progress."

### Implementation Status — DELIVERED
Added **Received by PMO** and **Received by Technician** states. The technician console shows **"Receive Task"** for assigned items and **"Start Repair"** only after acceptance; In Progress is reachable only post-acceptance; both stages appear in the tracker. Verified end-to-end.

---

## F6 — PMO RECEIPT UPDATES & NOTIFIES THE USER (FULL COVERAGE)

**Severity / Priority:** High (communication consistency).

### As Raised in Consultation (verbatim)
> "Pag nareceived ng pmo, update and notify user."

### Current State (As-Is)
Notifications were inconsistent across the lifecycle.

### Root-Cause / Professional Analysis
This generalizes F3: receipt by the PMO must **update status and notify the user**, and the reporter must be informed at **every** milestone. Silence between stages is the top service-system complaint.

### Proposed State (To-Be) / Required Improvement
Notify the reporter (in-app **and** email) on: Report Submitted, Received by PMO, Approved, Rejected, Technician Assigned, Received by Technician, Repair Started, Repair Completed, Work Order Closed/Verified, and Follow-Up acknowledgement (see Section 5 matrix).

### Business & Institutional Value
A reporter never wonders what's happening; the institution looks responsive and professional.

### Representative User Story
*"As a reporter, the moment the PMO receives my report — and at every step after — I get a clear update by app and email."*

### Workflow / Interaction Detail
Each transition handler calls a single notification routine that records an in-app notification and sends a branded email when the reporter address is known.

### Data & Technical Considerations
A `reporter_email` captured at submission; one reusable notification helper used everywhere.

### Risk if Not Addressed
Perceived neglect; manual follow-ups; reputational drag.

### Acceptance Criteria
- Each listed transition produces a reporter notification; "Received by PMO" specifically updates status and notifies the user.

### Implementation Status — DELIVERED
`notifyReporter()` wired into **all** listed transitions with an institutional email template; `reporter_email` captured at submission; verified across a full lifecycle run.

---

## F7 — FILTERED DATA EXPORT FOR ADMIN

**Severity / Priority:** Medium-High (decision support & reporting).

### As Raised in Consultation (verbatim)
> "May filter 'yung pag-e-export ng data as admin, for example sa mga mga reports like if gusto makuha 'yung June to July reports lang, yun lang makikita, etc."

### Current State (As-Is)
Export lacked advanced, scoped filtering.

### Root-Cause / Professional Analysis
Management needs **scoped** exports (e.g., a reporting period), not the whole dataset. Only the **filtered** records should be exported, in the formats institutions use.

### Proposed State (To-Be) / Required Improvement
1. Before export, the admin can **filter**.
2. Filters: **Date Range** (e.g., June 1 – July 31, 2026), Status, Technician, Building, Department, Equipment Category, Priority Level.
3. Formats: **PDF, Excel, CSV.**
4. **Only filtered records** are exported.

### Business & Institutional Value
Fast, targeted reports for reviews, audits, and capstone documentation.

### Representative User Story
*"As the PMO admin, I export only June–July reports for a specific building, as PDF, for our monthly review."*

### Workflow / Interaction Detail
Export → Advanced Export modal → set filters → choose CSV/Excel/PDF → only matching records are produced.

### Data & Technical Considerations
Server-side filter clauses for each criterion; period filter on report date.

### Risk if Not Addressed
Unwieldy full-dataset dumps; manual spreadsheet cleanup; poor decision support.

### Acceptance Criteria
- A filter UI scopes the result set; a date range returns only in-range records; CSV/Excel/PDF produced from the filtered set.

### Implementation Status — DELIVERED
**"Advanced Export"** modal on Defect Reports with **Date range, Status, Priority, Department, Technician, Equipment Category, Building/Location**; exports the filtered set as **CSV / Excel / PDF** via `api/export_reports.php`. Verified filtering narrows the output correctly.

---

## F8 — TECHNICIAN ACCOUNT CREATION VIA EMAIL SELF-VERIFICATION

**Severity / Priority:** High (security & onboarding integrity).

### As Raised in Consultation (verbatim)
> "Admin create a account for technician, for example is si admin ay gagawa ng account but before that may isesend si admin sa email ni technician para i-verify nito ang kaniyang sarili, like fifill up siya about sa kaniyang information or pagkakakilanlan. Then after that deretso na ito sa mismong system ng technician para i-edit ang kaniyang password."
>
> "Kailangan i-verify muna ni technician si self niya kung siya ba talaga 'yun pag bibigyan ng account, and verify it account."

### Current State (As-Is)
Admin created technician accounts directly (active immediately), and could set someone else's password.

### Root-Cause / Professional Analysis
Direct creation skips **identity verification** and concentrates credential control in the admin — weak for security and accountability. The professional pattern is **invite → self-verify → activate**: the technician proves who they are and **sets their own password**.

### Proposed State (To-Be) / Required Improvement
- **Step 1:** Admin creates the invitation — fields: Name, Email, Position, Department, Specialization.
- **Step 2:** System sends a **secure verification email** with a link.
- **Step 3:** Technician opens the verification link.
- **Step 4:** Technician completes profile verification — Full Name, Employee ID, Contact Number, Department, Specialization.
- **Step 5:** Technician **creates their own password**.
- **Step 6:** Account becomes active.
- Purpose: ensure technician identity verification; prevent unauthorized technician access.

### Business & Institutional Value
Every technician credential provably belongs to the right person; no admin-set passwords; clean onboarding trail.

### Representative User Story
*"As the PMO admin, I invite a technician by email; they verify their identity and set their own password, and only then can they log in."*

### Workflow / Interaction Detail
Invite → inactive account + secure, expiring token + email → technician opens link → completes profile + sets password → account activated, token cleared. Invited accounts cannot log in until verified.

### Data & Technical Considerations
Invite token + expiry; technician profile fields (Employee ID, Contact Number, Specialization, Position); status `invited` → `active`.

### Risk if Not Addressed
Unauthorized access; credentials that don't map to real, verified people.

### Acceptance Criteria
- Invitation creates an inactive account + secure expiring token, emailed.
- Technician cannot log in until verified; verification saves profile, sets own password, activates.

### Implementation Status — DELIVERED
**"Invite Technician"** action + modal on User Management (Name, Email, Position, Department, Specialization) → inactive `invited` account + **secure 3-day token** + branded verification email. `technician/verify_account.php` validates the token, captures **Full Name, Employee ID, Contact Number, Department, Specialization**, lets the technician **set their own password**, then **activates** the account and clears the token. Verified end-to-end.

---

## F9 — REPORTER PHOTO UPLOAD: MORE PHOTOS & LARGER SIZE

**Severity / Priority:** Medium-High (evidence quality).

### As Raised in Consultation (verbatim)
> "Tapos in the reporter or sa student side naman, kailangan madagdagan 'yung pag-uupload ng mga photo and size of it."

### Current State (As-Is)
Limited (single, size-restricted) photo upload.

### Root-Cause / Professional Analysis
Good evidence speeds diagnosis and repair. Reporters need **multiple** images at a **reasonable size**, with a modern uploader (drag-drop, previews, removal before submit, visible limits).

### Proposed State (To-Be) / Required Improvement
1. Allow **multiple photos**.
2. **Drag-and-drop** upload.
3. Show **upload progress / file size**.
4. Show **remaining upload limit**.
5. **Image preview**.
6. **Image removal before submission**.
7. Formats: **JPG, JPEG, PNG, WEBP.**
8. Max **10MB per image**.
9. Max **10 images**.

### Business & Institutional Value
Faster, more accurate repairs; richer documentation for the maintenance history.

### Representative User Story
*"As a reporter, I drag several clear photos of the defect, see their sizes, remove a bad one, and submit — knowing the system accepted them."*

### Workflow / Interaction Detail
Drag/choose images → thumbnails with size → remove any → "X / 10 · total size · slots left" meter → submit → all photos stored with the report.

### Data & Technical Considerations
Client + server validation of type/size/count; photos stored as an array on the report.

### Risk if Not Addressed
Under-documented defects; slower, less accurate repairs.

### Acceptance Criteria
- Multiple images with previews/sizes, removable before submit, with visible remaining slots; limits enforced both sides; all stored.

### Implementation Status — DELIVERED
**Drag-and-drop multi-photo uploader**: up to **10 photos**, **10MB each**, **JPG/PNG/WEBP**, live thumbnails + per-file size + remove buttons + "X / 10 · total · slots left" meter, with matching server-side validation. Verified multiple photos stored as an array.

---

## F10 — PROFESSIONAL SMART TECHNICIAN ASSIGNMENT

**Severity / Priority:** High (management-grade operations).

### As Raised in Consultation (verbatim)
> "Also sa admin side naman sa pag-aassign ng technician it very plain like simple assigning, it should be not like that because it will be manage by higher people or admin itself."

### Current State (As-Is)
A bare dropdown — too plain for management personnel.

### Root-Cause / Professional Analysis
Assignment is a **decision** that should be supported with the right information: availability, workload, specialization, and a best-match recommendation — i.e., an enterprise CMMS assignment experience.

### Proposed State (To-Be) / Required Improvement
1. A **Smart Assignment interface** with **technician cards**.
2. Each card: Technician Name, Department, Specialization, Active Work Orders, Current Workload, Availability Status.
3. A **Recommendation Engine** suggesting the best technician by Workload, Specialization, Availability, and Equipment Type.

### Business & Institutional Value
Better-balanced workloads, right-skilled assignments, faster decisions for higher personnel.

### Representative User Story
*"As the PMO admin, I see each technician's load and specialization at a glance and accept the system's recommended best match for this equipment."*

### Workflow / Interaction Detail
Open a report for assignment → technician cards render with availability + workload → system flags the **recommended** technician → admin confirms.

### Data & Technical Considerations
Workload computed from active tasks; recommendation scores specialization/department match + availability + lightest load.

### Risk if Not Addressed
Overloaded or mismatched technicians; slow, error-prone assignment unbefitting management use.

### Acceptance Criteria
- Cards show workload, specialization, availability; a recommended best-match is surfaced for the selected report.

### Implementation Status — DELIVERED
`admin_assign_technicians.php` upgraded with **availability cards** (avatar, name, specialization + department, active task count, availability badge, workload bar) and a **recommendation engine** flagging the best match with a **"Recommended"** ribbon, a ★ in the dropdown, and auto-selection. Directly-approved reports awaiting a technician now also appear in the queue.

---

## F11 — REMOVE "BUSY TECHNICIAN"

**Severity / Priority:** Medium (scoping / correctness).

### As Raised in Consultation (verbatim)
> "And also there no busy technician removed it."

### Current State (As-Is)
A manual/standalone "busy" notion existed.

### Root-Cause / Professional Analysis
A manually-set "busy" flag drifts from reality and adds upkeep. Per your instruction, the standalone **"busy technician"** concept is removed; availability is **derived automatically from actual workload** and account state.

### Proposed State (To-Be) / Required Improvement
- No manual "busy" control. Availability is **computed** (Available / Overloaded / Unavailable) from active tasks and account status, always reflecting reality.

### Business & Institutional Value
No stale flags; assignment always reflects true capacity with zero manual maintenance.

### Representative User Story
*"As the PMO admin, I never toggle 'busy' — the system already shows who's free, overloaded, or unavailable based on real work."*

### Workflow / Interaction Detail
Availability badges are calculated and shown during assignment; no manual busy switch exists.

### Data & Technical Considerations
Availability tiers from live workload; inactive accounts shown as Unavailable; overloaded excluded from recommendations.

### Risk if Not Addressed
Misleading manual states; assignment based on stale data.

### Acceptance Criteria
- No manual "busy" toggle; availability is auto-calculated and shown during assignment.

### Implementation Status — DELIVERED
No manual "busy technician" control; availability is **auto-calculated** (Available / Overloaded / Unavailable). Overloaded excluded from recommendations; Unavailable (inactive) disabled for assignment.

---

## F12 — BUDGET REQUEST PANEL: MISSING SIDEBAR

**Severity / Priority:** Medium (navigation consistency / polish).

### As Raised in Consultation (verbatim)
> "Also in the budget request panel the side bar panel got missing."

### Current State (As-Is)
The Budget Request panel had **no shared admin sidebar** (standalone header + centered content).

### Root-Cause / Professional Analysis
Inconsistent navigation chrome is disorienting and looks unfinished. Enterprise apps keep persistent, consistent navigation across every page.

### Proposed State (To-Be) / Required Improvement
- Fix the layout so the **sidebar remains persistent** on the Budget Request panel; ensure **navigation consistency** with the rest of the admin console.

### Business & Institutional Value
Coherent, professional admin experience; no "dead-end" pages.

### Representative User Story
*"As the PMO admin, the Budget Request page has the same sidebar and navigation as every other admin page."*

### Workflow / Interaction Detail
The standard admin sidebar (full nav, user chip, logout, auto-hide) is present and consistent.

### Data & Technical Considerations
Shared sidebar markup + layout offset; auto-hide behavior aligned with other pages.

### Risk if Not Addressed
Users get "stuck" on the page; perceived as broken/unfinished.

### Acceptance Criteria
- Budget Request shows the same persistent sidebar and navigation as other admin pages.

### Implementation Status — DELIVERED
Added the persistent admin sidebar (full navigation, user chip, logout, auto-hide) to `admin_budget_requests.php`. A "BEC Directory" entry was also added across all admin sidebars for discoverability.

---

## F13 — FRIENDLIER TECHNICIAN DASHBOARD (LESS DETAIL UP FRONT)

**Severity / Priority:** High (usability for the field worker).

### As Raised in Consultation (verbatim)
> "Also edit the technician system or dashboard it very not friendly to use, it a lot of detailed already when I open my task that it should not. Make sure you do better for that and edit or add features if necessary or change it if necessary."

### Current State (As-Is)
Opening **My Tasks** shows too much at once — cognitive overload, weak hierarchy.

### Root-Cause / Professional Analysis
The technician (often on a phone, on-site) needs a **calm overview first**, then **progressive disclosure** of one task at a time. Details should open in a **separate panel**, not all inline.

### Proposed State (To-Be) / Required Improvement
1. Redesign the technician dashboard for clarity.
2. **Overview cards:** Assigned Today, Pending Tasks, In Progress, Completed.
3. **Quick actions:** Start Task, Update Progress, Upload Photos, Submit Completion.
4. **My Tasks:** card layout, priority indicators, status badges, simplified interface.
5. **Task details open in a separate panel** (not everything at once).
6. Reduce cognitive overload; improve mobile usability and workflow efficiency.

### Business & Institutional Value
Faster, less error-prone field work; higher technician adoption.

### Representative User Story
*"As a technician, I open a clean overview, scan task cards by priority/status, and tap one to open its full details in a focused panel — comfortably on my phone."*

### Workflow / Interaction Detail
Overview cards (clickable to filter the queue) → scannable task cards with priority/status/age → tap → detail panel with grouped repair form and photo capture.

### Data & Technical Considerations
"Assigned Today" metric; queue filters; SLA/age indicators; modal detail panel; sectioned completion form; mobile-first controls.

### Risk if Not Addressed
Technicians struggle, make mistakes, or avoid the system.

### Acceptance Criteria
- Concise overview on open; scannable cards with priority/status; full details in a dedicated panel; comfortable on mobile.

### Implementation Status — DELIVERED
The console opens with **clickable overview cards** (Assigned Today, To Accept/Start, In Progress, Awaiting PMO, Work History) that filter the queue; task list is **cards with priority + status + SLA-age badges**; full details open in a **separate panel (modal)**; the completion report is grouped into **numbered sections**; the **field/mobile experience** was overhauled (camera photo capture with previews, sticky submit bar, large touch targets, full-height modals) with an **auto-hide sidebar**.

---

## 7. CROSS-CUTTING NON-FUNCTIONAL REQUIREMENTS

- **Security & Identity:** directory-based reporter verification (F1); technician self-verification (F8); audit logging of administrative and lifecycle actions.
- **Transparency:** truthful, event-driven tracking (F2) and complete notification coverage (F3, F6).
- **Consistency:** uniform navigation chrome and visual identity (BEC maroon/gold) across all admin pages (F12), including the new Directory page.
- **Accountability:** explicit acceptance steps and timestamps at each transition (F3, F5).
- **Usability & HCI:** progressive disclosure and reduced cognitive load on the technician console (F13); management-grade assignment (F10).
- **Mobility:** mobile-first field experience — camera capture, large touch targets, sticky actions (F9, F13).
- **Reportability:** scoped, multi-format exports (F7).
- **No regressions:** all existing features preserved while adding/adjusting per the findings.

---

## 8. CONSOLIDATED FINDINGS & STATUS MATRIX

| # | Area | Finding | Severity | Status |
|---|------|---------|----------|--------|
| F1 | Admin / Security | BEC user Excel/CSV import + reporter verification | Critical | Delivered |
| F2 | Reporter | Real-time, Shopee-style tracking (no placeholder dates) | High | Delivered |
| F3 | Admin | Defect-reports flow, "Mark as Received," notifications, admin-side receipt gap | High | Delivered |
| F4 | Reporter | Follow-up / bump (2–3, max 3) | Medium-High | Delivered |
| F5 | Workflow | "Received by Technician" before "In Progress" | High | Delivered |
| F6 | Notifications | PMO receipt updates + notifies user; full lifecycle | High | Delivered |
| F7 | Admin | Filtered data export (date range, etc.) | Medium-High | Delivered |
| F8 | Admin / Technician | Technician account via email self-verification | High | Delivered |
| F9 | Reporter | More photos + larger size, drag-drop, previews, limits | Medium-High | Delivered |
| F10 | Admin | Professional smart technician assignment | High | Delivered |
| F11 | Admin | Remove manual "busy technician" (auto-derived availability) | Medium | Delivered |
| F12 | Admin | Budget Request panel missing sidebar | Medium | Delivered |
| F13 | Technician | Friendlier dashboard, less detail up front, separate detail panel | High | Delivered |

---

## 9. APPENDIX A — ORIGINAL CONSULTATION STATEMENT (UNEDITED)

> about sa user report ui or ung sa reporter which is the student,teacher,ect. sabi ni ma'am dapat mag karron kami ng additional features para admin where admin can import an exel na nag lalaman ng mga information of the users for example it their name, and email address where it is a bec account, na magagamnit para sa security and verification na legit na bec user ung mag rereport. since admin side it ay wala sa ngayun ung iimport nila but we need this feature in admin. and then another issue is
>
> yung sa track my reports, na nasa side nmn ni reporter or student kailngan ay make more realtime updates hindi pepede may date na nakalagay agad dun sa track my reports since mag uupdate lang un if received na ba ng admin or process naba ng techncian like that, for example sa mga shoppee traking its order. and another issue found is in the  admin dashbaord its located in defect report where it need to have a better flow and UX/UI of this because it look like simple and it need to have an action where the admin can see a button where masasabe na narecived na sya yung report tapos mag nonotify ito sa reporter about sa knayang ni report na recieved na ito ng admin and always notify the reporter sa status ng kanyang ni report. and also there have a gap where hindi nag nonotify su admin kung na recieved nya na ba ung report or hindi pa kaya naman may features din sa reporter side na kung saan puwede nila i bump or i notify si admin na may report silang ganto or ganyan like it only take 2-3 trigger to notify them. like2-3 na walang notify si admin sa reporter pede i notify ni reporter si admin about sa kanyang pending report. and also
> reciived by technician then tsaka lang ang in-progress
>
> pag nareceived ng pmo, update and notify user
> may filter yung pag eexport ng data as admin for example sa mga mga reports like if gusto makuha yung june to july reports lang, yun lang makikita, ect.
>
> admin create a account for technicianan, for example is si admin ay gagawa ng account but before that may isesend si admin sa email ni technician para iveryfy nito ang kanyang sarili, like fifill up sya about sa knyang information or pag kakakilanlan. then after that deritsyo na itp sa mismong sytem ng technician para i edit ang kanyang password.
>
> kailngan iveryfy muna ni technician si self nya kung sya batalaga un pag bibigyan ng account and verify it account. tapos in the reporter or sa student side naman kailngan madagdagan ung pag uupload ng mga photo and size of it. also sa admin side namn sa pag aasign ng technician irt very plain like simple assigning it should be n ot like that because it will be manage by higher people or admin itself. and also there no busy technician removed it. also in the bugdget request panel the side bar panel got missing. also edit the technician sytem or dashboard it very not freindly to use it alot of detailed already when i open mytask that it should not. make sure you do better for that and edit or add features if nessasrybor change it if nessarry, rember you are a Senior UI/UX Architect, Enterprise Software Designer, Product Manager, Human-Computer Interaction (HCI) Specialist, and Full-Stack System Analyst with over 20 years of experience designing institutional management systems, maintenance management systems (CMMS), ERP platforms, educational dashboards, and enterprise-level web applications.

---

## 10. PROFESSIONAL MANDATE (AS STATED FOR THIS ENGAGEMENT)

All analysis and work were carried out in the capacity of a **Senior UI/UX Architect, Enterprise Software Designer, Product Manager, Human-Computer Interaction (HCI) Specialist, and Full-Stack System Analyst with over 20 years of experience** designing institutional management systems, maintenance management systems (CMMS), ERP platforms, educational dashboards, and enterprise-level web applications. Every improvement was made appropriate for **Batangas Eastern Colleges** and aligned with the **capstone study objectives**, **adding or changing features as necessary** while **preserving all existing functionality** and **removing nothing that was requested**.
