# Manuscript Alignment Prompt — Group 2, BSIS Capstone

Copy everything below the ruler into ChatGPT / Gemini / Claude, then attach or paste
`revised_GROUP_2_FINAL_1-3.docx` where marked at the bottom.

Built from a line-by-line reading of the actual manuscript, the actual source code, and the
BSIS Capstone Project Manuscript Format (rev. 5-19-2026).

---

You are acting as a **capstone manuscript technical editor and systems-documentation specialist**
for a Philippine college BSIS capstone. You are meticulous, you never invent facts, and you write
in formal academic English.

## WHAT THIS IS

I am giving you my capstone manuscript (Chapters 1–3, for title defense). The system it documents
has kept changing while the paper stood still, and the paper also departs from my college's
prescribed format in several places.

**The findings in Part 3 and Part 4 below are already verified** — against the running source code
and against the format guide. Do not re-derive them, do not question them, and do not ask me to
confirm them. Your job is to turn them into finished, paste-ready manuscript text, plus find
anything else of the same kind that I missed.

## PART 1 — GROUND TRUTH: THE SYSTEM AS BUILT

Verified from source code, 21 August 2026. Where the manuscript disagrees with this, the
manuscript is wrong.

**Identity.** Web-Based Equipment Reporting and Maintenance Management System for the Property
Management Office (PMO) of Batangas Eastern Colleges, San Juan, Batangas. Second administrative
unit: ITSO — Information Technology Services Office.

**Stack.** PHP 8 on Apache (XAMPP), no framework and no package manager. Cloud-hosted **Supabase
PostgreSQL**, reached by legacy mysqli-style calls through a hand-written mysqli→PostgreSQL
compatibility adapter (a few helpers use PDO directly). Vanilla JavaScript and a handcrafted CSS
design system. **Google Gemini API (free tier)** for the AI assistant. Gmail SMTP with a retry
outbox. PWA manifest + service worker + **Web Push**. Hand-rolled XLSX reader and writer.
**Every third-party asset — Chart.js, SheetJS, Font Awesome, all webfonts — is vendored locally;
there are no CDN links, so the system runs with no venue internet.**

**Roles — exactly three in the database:**

| Role | Who | Sign-in |
|---|---|---|
| `admin` | PMO **and** ITSO administrators, separated by `users.department` | Email + password, **then** an emailed OTP (true two-factor) |
| `technician` | Maintenance technicians | Username + password |
| `reporter` | Students, faculty, staff | **No password and no account registration** — name + institutional BEC email, then a 3-minute one-time code emailed to that address; a verified browser is remembered for 30 days |

Plus anonymous public users (tracker and transparency board, no sign-in at all). A reporter's
affiliation lives in `users.user_type` (student / faculty / staff / guest), separate from role.
Retired role values that no longer exist: `dean`, `finance`, `pmo`, `handler`, `student`,
`faculty`, `guest`.

**PMO / ITSO unit scoping.** `users.department` decides which reports and which equipment each
administrator sees. Equipment is categorised by unit, and new reports are auto-routed to the right
administrator. ITSO administrators may also sign in to the technician portal.

**Workflow — 12 statuses:**

```
reported (Submitted) → pmo_review (Received by PMO) → ready_for_assignment (Ready for Assignment)
→ assigned (Technician Assigned) → accepted (Received by Technician) → in_progress (In Progress)
→ completed (Completed) → verified (Verified) → closed (Closed)
```

Branches: `waiting_for_materials` (Waiting for Materials), `for_replacement` (For Replacement),
`rejected` (Rejected). There is **no status named "Approved"** — the correct label at that point is
**Ready for Assignment**.

Reporter-facing tracker, 7 steps: Submitted → Received by PMO → Technician Assigned → Received by
Technician → Repair In Progress → PMO Verification → Closed. `for_replacement` inserts a
"Replacement Required" step; `rejected` short-circuits to Submitted → Rejected.

**Modules as built.** Landing page (public, with a live reports preview and a report-an-issue
button) · Reporter portal (Data Privacy consent, guided report form, photo/video evidence with
in-browser camera capture, keyword-inferred priority) · Public tracker and transparency board
(track by ticket ID / equipment ID / asset tag, follow-up requests, reporter satisfaction
confirmation) · PMO/ITSO admin suite (dashboard, defect review, technician assignment and
workload, inventory with official XLSX workbook import and QR asset tags, preventive maintenance
scheduling, analytics with branded PDF and XLSX exports, user management, notification centre,
**Backup & Recovery page**, BEC Directory import, **Venue Reservations**) · Technician portal as
an installable PWA (queue → case → completion, live SLA and repair timers, workflow stepper,
photo-documented completion) · **Becca AI assistant in three role-specific variants** (reporter,
admin, technician; bilingual English/Filipino; falls back to a built-in rules brain when the API is
unreachable, so it always answers) · Notifications (in-app + branded email at every transition,
web push to the technician PWA, automated weekly PMO summary email) · SLA engine.

**Three printable formal documents** exist and are demonstrable: the Defect Report ticket (before
repair), the DepEd-method Total Service Cost estimate, and the formal Service Report (after
repair).

**Venue Reservations.** Digitises the PMO's paper Venue Reservation Form (VRF, Rev. 00):
applicant → department-head or organisation-adviser endorsement → PMO approval (or School
Administrator disapproval) → Accounting assessment → Cashier OR number. Adds what paper cannot do:
double-booking detection at the requested hour, and retrieval without opening the folder. Linked
publicly from the landing page.

**Backup & Recovery.** Automated daily snapshots via Windows Task Scheduler (rotating compressed
archives, 14-day rotation) **plus an admin page** to back up on demand, download any snapshot, and
restore records after accidental deletion or corruption — with an automatic pre-restore safety
snapshot.

**SLA windows** (`config/sla.php`): critical 24 h, urgent 24 h, high 72 h, medium 168 h (7 days),
low 336 h (14 days). Overdue flagging plus a batched escalation sweep.

**Security.** Admin two-factor OTP; reporter passwordless OTP; role-scoped session cookies keyed by
filename prefix (one person can hold three portal sessions at once); CSRF token on every
state-changing request; rate limiting and a per-IP login cap; upload validation by real image
content; `.htaccess` hardening and a Content Security Policy; secrets excluded from version
control; RA 10173 consent captured at reporter sign-in.

**Activity logging — read this carefully.** Lifecycle actions **are** still written to
`activity_log`. However, the **admin audit-log viewer page was removed in August 2026**. The system
therefore *records* administrative actions but no longer *displays* them to an administrator.

**Deployment.** XAMPP on an institutional machine, exposed for demonstration over public HTTPS via
a Cloudflare Tunnel. PHP OPcache is deliberately disabled (it destabilised Apache); a watchdog
script restarts Apache if it wedges.

**Honest limitations** (use for Chapter 5): pagination is client-side only, so page weight is the
real scaling ceiling (~11 MB at 5,000 reports) and server-side pagination is the one architectural
change still outstanding; **admin pages are desktop-only by design** (mobile support covers the
reporter, public and technician surfaces); bookable venues are free text, not a curated venue
master list; the system depends on connectivity to the cloud database and to Gmail SMTP.

**Quality assurance as actually practised.** No unit-test framework and no linter. QA runs through
purpose-built scripts: a full-lifecycle end-to-end test over real HTTP; a headless-browser UI test
that asserts the rendered DOM; a 15-point pre-demonstration health check; an AI key and model
reachability check; and the automated backup job.

## PART 2 — THE PRESCRIBED BEC BSIS FORMAT (rev. 5-19-2026)

**Chapter titles and required sections**

- **Chapter 1 INTRODUCTION** — Introduction, Project Context, Objectives of the Project, Scope /
  Delimitation / Limitations, Significance of the Study, Definition of Terms.
- **Chapter 2 REVIEW OF RELATED LITERATURE** — **Literature Review**, **System Review**,
  Theoretical or Conceptual Framework, Research Paradigm.
- **Chapter 3 METHODOLOGY** — Research Design, Materials and Tools (Software and Hardware),
  Development Methodology, Population of the Study, Sampling Design, Data Collection Instrument,
  Validation of the Questionnaire, Testing and Evaluation Procedures, Statistical Treatment,
  Ethical Consideration. Project Design "presents all the diagram needed in the project", and the
  guide explicitly names System Architecture, Use Case Diagram, **Context Diagram**, and Data Flow
  Diagram as models that may be included.
- **Chapter 4 PRESENTATION, ANALYSIS AND INTERPRETATION OF DATA** — organised **per objective**
  (Objective 1, Objective 2, …).
- **Chapter 5 SUMMARY OF FINDINGS, CONCLUSIONS AND RECOMMENDATIONS** — exactly three subheadings;
  the number of conclusions equals the number of objectives, and **each conclusion is one sentence**.

**Preliminaries required:** Title Page, Certification and Approval Sheet, Abstract (200–250 words,
double-spaced, 2–3 keywords, includes researchers' names, degree, date, exact title, adviser),
Acknowledgment, Dedication, Table of Contents, List of Tables, List of Figures. Roman numerals,
bottom centre.

**Appendices required:** A Communication Letters · B Survey Questionnaire · C Interview Transcripts
· D Functional Testing Results · E User Acceptance Testing Results · F ISO 25010 Evaluation Results
· G Screenshots · H Documentation · I Source Code Repository Link · J Grammarian Certificate ·
K Statistician Certificate · L Curriculum Vitae.

**Literature rules:** organised thematically; **at least fifteen authors per theme**, foreign and
local; minimum fifteen studies overall; each theme ends with a synthesis paragraph and the section
ends with a general synthesis; **literature must be no more than five (5) years old** (ten years for
conceptual theory).

**Objectives rules:** a General Objective, then Specific Objectives; objectives begin with an action
verb ("To develop…", "To evaluate…"); each must be Specific, Measurable, Attainable, Realistic and
Time-bounded, and state the action verb, the outcome, the time frame, and the criteria for
measuring accomplishment. **Objectives determine the methodology and drive Chapters 4 and 5.**

**Scope rules:** exactly three paragraphs — one for Scope (features included), one for Delimitation
(features intentionally excluded), one for Limitations (constraints beyond the researchers'
control).

**Definition of Terms rules:** alphabetical; term indented, bold, followed by a period; every
definition a complete sentence; abbreviations in parentheses after the spelled-out form.

**Testing and Evaluation must cover:** Unit Testing, Integration Testing, System Testing, User
Acceptance Testing; then Respondent Groups, Evaluation Instrument, Evaluation Criteria. The format
lists **eight** ISO 25010 characteristics: Functional Suitability, Performance Efficiency,
**Compatibility**, Usability, Reliability, Security, Maintainability, Portability.

**Tables and figures:** labels centred, bold, size 10. **Table label goes above the table; figure
label goes below the figure.** Numbering is continuous. Every table and every figure must be cited
in the text, using the words "Table" and "Figure" (never "table", "fig", "Fig."). Table body text
10 pt. A table continued onto another page repeats its column headings under "Continuation of
Table N".

**References:** APA latest edition, classified into Books, Unpublished Materials,
Journals/Periodicals, and Electronic Sources; first line flush left, subsequent lines indented 0.5";
single-spaced within an entry, double-spaced between entries.

**Typography:** 12-pt Times New Roman (14-pt title, 10-pt for compressed tables), double-spaced,
left margin 1.5", other margins 1". Chapter titles triple-spaced from the top, centred, bold,
ALL CAPS.

## PART 3 — VERIFIED FINDINGS: ACT ON THESE

### 3A. Wrong facts — the system changed and the paper did not

1. **"Anthropic Claude API" is wrong everywhere it appears.** It occurs in at least four places:
   the Chapter 3 Software list; the Figure 5 Application Layer discussion ("relays AI assistant
   messages to the Anthropic Cl…"); the Figure 6 Technical Infrastructure discussion (twice —
   the opening paragraph and the dedicated external-AI-service paragraph); and the Figure 7
   Physical Design paragraph. **Replace every occurrence with the Google Gemini API (free tier).**
   Figure 6 must be redrawn — the external AI service box is labelled with the wrong vendor.
2. **The report lifecycle is listed as seven or eight statuses in at least five places**
   ("Reported, Received by PMO, Approved, Assigned, In Progress, Completed, Verified/Closed, and
   Rejected"): the CSS3 entry in Software, the Figure 7 Logical Design paragraph, the Figure 14
   narrative, the Integration Testing paragraph, and the Chapter 2 functional-suitability
   discussion. **The system has twelve.** Two specific errors: there is no status called
   "**Approved**" (it is **Ready for Assignment**), and three real statuses are missing everywhere —
   **Received by Technician**, **Waiting for Materials**, and **For Replacement**.
3. **Definition of Terms, "Status Tracking"** says the system displays "Pending, In-Progress, and
   Completed". None of those are real status labels. Rewrite against the real vocabulary.
4. **The Figure 19 narrative** says a notification fires on "Pending, Approved, In Progress,
   Completed, or Rejected". Same problem; same fix.
5. **"Without account registration … providing only their name and institutional email address"**
   is now incomplete. It appears in the Chapter 1 Scope, RAD Phase 1, Phase 2, Phase 3, the
   Figure 5 Presentation Layer, the Figure 15 discussion, DFD Processes 1.0 and 2.0, and the TAM
   paragraph. **Reporters still create no account and still have no password — but since August
   2026 they must confirm a one-time code emailed to the institutional address they typed, and a
   verified browser is then remembered for thirty days.** Do not delete the no-registration claim;
   qualify it. Note that this *strengthens* the accountability argument the paper already makes.
6. **PMO / ITSO dual-unit administration is absent from the entire manuscript.** The system has two
   administrative units scoped by `users.department`; equipment is categorised by unit and reports
   are auto-routed. This affects the Use Case Diagram actors, DFD Process 1.0, the Role-Based
   Access Control definition, the Figure 22 admin flow, and User Management. **This is the single
   largest missing structural feature.**
7. **Role vocabulary.** The RBAC definition says access is granted to "administrators, technicians,
   faculty, and students". The database has three roles — admin, technician, reporter — with
   affiliation held separately in `user_type`. Reconcile every role list in the paper to this.
8. **The Venue Reservations module is absent from the entire manuscript** — objectives, scope,
   literature, and every figure. It is publicly linked from the landing page and it digitises a real
   PMO paper form. Decide with me whether to add it as an objective or to name it explicitly as a
   delimitation; do not leave it undocumented.
9. **Backup & Recovery is under-reported.** The paper mentions only the automated Task Scheduler
   job. The system also has an administrator page for on-demand backup, snapshot download, and
   restore with an automatic pre-restore safety snapshot.
10. **Web Push is absent.** Figure 19 and the notification module describe "email or the system's
    notification module". The system also pushes to the installed technician PWA and sends an
    automated weekly PMO summary email.
11. **The audit log claim is now half true.** The paper promises an audit log the administrator can
    reach under "Monitoring and Reports", and calls it "the persistent audit trail of administrative
    actions" in the Figure 22 discussion and the Technical Infrastructure security paragraph.
    Actions are still recorded, but **the admin-facing audit-log viewer page was removed in August
    2026.** Rewrite these to claim recording, not viewing — or flag for me that the page should be
    restored before the defense.
12. **"Responsive layouts that adapt from desktop monitors to mobile devices"** (Custom CSS Design
    System entry) contradicts the actual design decision: **admin pages are desktop-only by design**;
    mobile support covers the reporter, public and technician surfaces. State it as the deliberate
    decision it is.
13. **The Software list is missing** several tools that are actually in use and demonstrable:
    SheetJS / the hand-rolled XLSX writer behind the branded Excel exports, the Windows Task
    Scheduler backup job, the service worker and Web Push, and the Cloudflare Tunnel used to serve
    the system over public HTTPS. **It also omits the most defensible design decision in the
    stack: every third-party asset is vendored locally, with no CDN links, so the system runs with
    no venue internet.** That belongs in the paper.
14. **Three printable formal documents are undocumented**: the Defect Report ticket, the
    DepEd-method Total Service Cost estimate, and the formal Service Report. They are real outputs
    and are strong Chapter 4 material.
15. **"PDO-based database-compatibility adapter"** (Figure 5 Data Layer, Figure 6, Figure 7) is
    imprecise. Most call sites are legacy mysqli-style calls rewritten for PostgreSQL by a
    compatibility adapter; only some helpers use PDO directly. Correct the mechanism.

### 3B. Internal contradictions — the paper against itself

16. **The respondent count contradicts itself.** Chapter 1 Scope says "approximately 10 faculty/staff
    and 100–150 students". Chapter 3 Sampling Design computes Slovin at 10% on N = 2,500, gets
    96.15 ≈ 96, and adjusts upward to exactly 100. Pick one number and make every mention agree.
    (The arithmetic itself is correct — do not change it.)
17. **The Scope contains a circular self-reference**: "This sample size falls within the 100–150
    range stated in the scope of the study." It is inside the scope. Delete or rewrite.
18. **The ISO characteristic count contradicts itself.** Chapter 1 Scope, the objectives, and the
    Data Collection Instrument all say seven characteristics. Chapter 3 Sampling Design says the IT
    experts assess **five** (functional suitability, reliability, usability, performance efficiency,
    security). Reconcile.
19. **The qualitative strand has no instrument.** Research Design commits to a convergent
    mixed-methods design with semi-structured interviews, but the Data Collection Instrument section
    describes only the questionnaire. The format requires Construction, Administration and Scoring
    for **each** instrument, and Appendix C is Interview Transcripts. Write the missing interview
    guide subsection.
20. **The population is described two different ways.** Population of the Study says "college and
    senior high school students"; Sampling Design says "students enrolled in laboratory and
    equipment-dependent courses" with N = 2,500. Reconcile and state clearly what N counts.
21. **RAD Phase 1 claims Context Diagrams were developed** — no Context Diagram appears anywhere in
    the manuscript.
22. **RAD Phase 2 claims a database schema was designed** — no Entity Relationship Diagram and no
    data dictionary appear anywhere in the manuscript.
23. **Mechanical errors to fix while you are in there:** an orphan quotation mark in Population of
    the Study (`Specifically, "...the study involved`); a missing space in Sampling Design
    (`collection.In addition`); doubled periods in Sampling Design, Integration Testing, and
    Composite Mean; a stray leading period before the Figure 13 caption; and "Chart." truncated
    where "Chart.js" is meant, in the Laptop hardware paragraph.

### 3C. Format-compliance gaps

24. Chapter 1 is titled **"THE PROBLEM"**; the format requires **"INTRODUCTION"**.
25. Chapter 2 is titled **"REVIEW OF RELATED LITERATURE AND STUDIES"**; the format says
    **"REVIEW OF RELATED LITERATURE"**.
26. **Chapter 2 has no "System Review" section.** The format requires a review of existing related
    systems — purpose, features, strengths, weaknesses, and the opportunity each leaves open that
    justifies this system. **This is a required section that is entirely missing.** Draft it, using
    genuinely comparable systems (institutional CMMS / helpdesk / facility-reporting platforms), and
    mark every citation you cannot verify as `[VERIFY WITH DEVELOPER]`.
27. **Chapter 2 has no "Literature Review" heading.** The themes sit directly under the chapter
    title. Nest them under the prescribed heading.
28. **Three themes fall far short of fifteen authors**: Artificial Intelligence Assistance in
    Maintenance Systems (~2), Preventive Maintenance Scheduling and Service-Level Targets (~3), and
    Mobile and Multi-Platform Access (~2). Tell me how many more each needs and what to search for.
29. **Twenty-three references are older than the five-year rule** — twenty dated 2020, two dated
    2019, one dated 2013. Davis (1989) for TAM is a classical theory and is exempt. List every
    reference that must be replaced or justified.
30. **References are one flat alphabetical list.** The format requires classification into Books,
    Unpublished Materials, Journals/Periodicals, and Electronic Sources.
31. **Figure 1 (TAM) cites Wikipedia as its source.** Replace with Davis (1989) or another
    peer-reviewed source and redraw.
32. **Compatibility is excluded from the ISO evaluation.** The paper justifies this (compatibility
    was covered by cross-browser testing instead). The format explicitly lists **eight**
    characteristics including Compatibility. Give me both options with a recommendation — adding
    Compatibility as an eighth evaluated characteristic is the safer route, and the system has real
    evidence for it (four browsers, desktop and mobile, PWA install).
33. **The paper cites "ISO/IEC 25010:2023" but uses the 2011 characteristic names.** The 2023
    revision renames Usability to Interaction Capability and Portability to Flexibility, and adds
    Safety. Either cite the 2011 model or adopt the 2023 names consistently — the present mix is a
    citation error a panellist can catch.
34. **Table label punctuation is inconsistent**: Tables 1–3 use "Table 1." while Tables 4–5 use
    "Table 4:". The format uses a period.
35. **Figure 9's caption sits above its image**, where the format requires figure labels below.
    Audit all twenty-two.
36. **Every preliminary page is missing** except the title page: Certification and Approval Sheet,
    Abstract (200–250 words with 2–3 keywords), Acknowledgment, Dedication, Table of Contents, List
    of Tables, List of Figures. Draft the Abstract and both lists from the finished manuscript.
37. **All twelve appendices (A–L) are missing.** Tell me which I can produce now from the existing
    system and which must wait for Chapter 4 data.
38. **Objectives need restructuring** — no explicit "General Objective" and "Specific Objectives"
    labels, verbs are not in infinitive form ("Develop…" instead of "To develop…"), and no objective
    states a time frame or a measurable criterion as the format's SMART guidance requires.

### 3D. Verified correct — do NOT change these

Table 4 (Defect Priority Levels) matches the system's SLA configuration exactly, keyword triggers
and all five windows — leave it alone. Also already correct and current: Supabase PostgreSQL as the
database, the PWA technician portal, administrator two-factor OTP, keyword-inferred priority, the
preventive-maintenance sweep, the XLSX inventory import, branded PDF and Excel exports, Chart.js
analytics, Gmail SMTP notifications, the Slovin computation, the RAD methodology narrative, and the
Chapter 1 Project Context description of the manual Dean → Finance paper routing (that is the
**existing** process, correctly described — the proposed system deliberately eliminates it, which
the Scope already excludes as "budget request management").

## PART 4 — FIGURE REGISTER: ALL 22 EXISTING, PLUS WHAT IS MISSING

Go through every figure. For each, tell me: keep as is / edit caption only / **redraw**. For every
redraw, give me the new content **twice** — once as a node-and-edge list I can rebuild in Draw.io,
and once as a Mermaid code block I can render immediately.

| Fig | Title | What I already know |
|---|---|---|
| 1 | Technology Acceptance Model (TAM) | Wikipedia source must be replaced (3C-31) |
| 2 | ISO/IEC 25010 Software Quality Model | Check against the standard edition actually cited (3C-33) |
| 3 | Conceptual Framework (IPO) | Input list must match the final objectives — redraw after objectives are fixed |
| 4 | Rapid Application Development | Source is a vendor blog; verify acceptability |
| 5 | Three-Tier System Architecture | **Redraw** — Gemini not Claude; add PMO/ITSO scoping, Web Push, Venue Reservations, Backup & Recovery |
| 6 | Technical Infrastructure | **Redraw** — external AI service box is labelled Anthropic Claude |
| 7 | System Design Framework | **Redraw** — Logical Design lists the wrong status set; Physical Design names Claude |
| 8 | Existing Process Flow (manual) | Correct as the AS-IS process — verify it is labelled unambiguously as the existing manual procedure |
| 9 | Login and Authentication with OTP (Admin) | Caption placement wrong (3C-35); verify flow against the real two-step admin login |
| 10 | Administrator 2FA Verification UI | Screenshot — confirm it matches the current interface |
| 11 | Secure Login Verification Interface (OTP) | Screenshot — confirm current |
| 12 | Administrator Dashboard | Screenshot — confirm current; caption mentions SLA and quick actions |
| 13 | **Use Case Diagram** | **Redraw** — three actors not four; add PMO vs ITSO scoping, venue reservation, backup & recovery, preventive maintenance, AI assistance, reporter OTP. Stray leading period in the caption |
| 14 | Report Submission flow | **Redraw** — narrative lists 7 statuses; add the reporter OTP step |
| 15 | Reporting Interface | Screenshot — the "no registration" claim in the caption needs the OTP qualifier |
| 16 | Report Tracking and Public Reports | Screenshot — confirm current |
| 17 | Report Validation flow | Verify against the real `pmo_review → ready_for_assignment` transition |
| 18 | Work Order Assignment flow | **Redraw** — must include `accepted` (Received by Technician) and the `waiting_for_materials` branch |
| 19 | Status Tracking and Notification Alert | **Redraw** — wrong status names; add Web Push and the weekly summary email |
| 20 | Data Flow Diagram (Level 1) | **Redraw** — Process 1.0 needs reporter OTP and PMO/ITSO scoping; consider a Process 6.0 for venue reservations |
| 21 | Technician Process Flow | **Redraw** — add Received/accepted, Waiting for Materials, For Replacement, and the printable Service Report and cost estimate |
| 22 | Admin Process Flow | **Redraw** — audit log viewer no longer exists; add unit scoping, Backup & Recovery, Venue Reservations |

**Figures that should exist and do not.** Specify each one fully, the same two ways:

- **Context Diagram (DFD Level 0)** — claimed in RAD Phase 1 but never shown. External entities:
  Reporter, PMO Administrator, ITSO Administrator, Technician, Public User, Gmail SMTP service,
  Google Gemini API, Supabase PostgreSQL.
- **Entity Relationship Diagram** — claimed in RAD Phase 2 but never shown. Core entities: users,
  equipment, categories, defect_reports, work_orders, maintenance_history, preventive_schedules,
  venue_reservations, notifications, push_subscriptions, email_otp, activity_log, ticket_counters,
  bec_directory.
- **Data Dictionary** — one table per principal entity: field, type, constraint, description.
- **System Flowchart of the complete 12-status lifecycle**, with the `waiting_for_materials`,
  `for_replacement` and `rejected` branches drawn.
- **Site map / navigation hierarchy** per portal (public, reporter, admin, technician).
- Tell me whether a **Gantt chart** and **sequence diagrams** are expected for this format, and if
  so, specify them.

## PART 5 — WHAT TO PRODUCE

**Part A — Drift summary.** One table: area | what the manuscript says | what is true | severity
(Critical / Major / Minor). Critical = a panellist would catch it during the live demonstration.

**Part B — Chapter-by-chapter edits.** Walk Chapters 1–3 in order. For each edit: exact location
(chapter, section, paragraph) | the current wording quoted | **ready-to-paste replacement wording**
in the manuscript's existing voice and tense | one-line reason.

**Part C — Rewritten Objectives of the Project.** A full replacement section: a General Objective
plus numbered Specific Objectives in infinitive form, SMART-compliant, **covering every module that
actually exists**, and ending with the ISO 25010 evaluation objective. Flag any module I should
deliberately exclude instead of claiming. This is the highest-leverage edit in the paper — Chapter 4
is organised per objective and Chapter 5 gives one conclusion per objective, so every later chapter
inherits whatever this section says.

**Part D — Figure register.** Part 4 above, completed, with Draw.io node lists and Mermaid blocks.

**Part E — The missing "System Review" section**, drafted in full.

**Part F — Tables to rebuild**, finished and populated, not instructions: user roles and privileges;
functional and non-functional requirements; the twelve workflow statuses with definitions; the data
dictionary; the test-case matrix; the ISO 25010 evaluation criteria; respondent distribution.

**Part G — Terminology lock list.** Global find-and-replace: old term → correct term → why. Must
include Anthropic Claude → Google Gemini, "Approved" → "Ready for Assignment", and the correct
expanded first use of PMO, ITSO, OTP, SLA, PWA, CSRF, RBAC, VRF, XLSX.

**Part H — Consistency and numbering audit.** Figure and table renumbering after insertions, the
List of Figures and List of Tables, in-text cross-references, the table of contents, and every place
a count is stated twice (modules, roles, statuses, respondents, ISO characteristics) that must now
agree.

**Part I — Priority checklist.** Numbered checkboxes ordered by risk: must-fix before the title
defense first, then should-fix, then polish, with a realistic time estimate per group.

## PART 6 — RULES

1. **Never invent a feature, a table, a metric, or a citation.** Use only Part 1, Part 2, the
   verified findings, and what the manuscript already contains.
2. Anything you cannot verify from those sources gets **`[VERIFY WITH DEVELOPER]`** and a precise
   statement of what you need. Do not fill gaps with plausible-sounding text. This applies with
   full force to any citation you suggest for the System Review or the thin literature themes —
   **a fabricated reference is worse than a gap.**
3. **Preserve the manuscript's structure, numbering, tense, citation style and voice.** You are
   aligning the paper to the system and to the format, not rewriting the paper.
4. Quote the manuscript whenever you identify a problem, so I can find the passage by searching.
5. Every replacement passage must be paste-ready — correct academic English, no placeholders.
6. **Output clean structured Markdown**: headings, tables, numbered lists, Mermaid blocks. No
   preamble, no filler, and do not restate these instructions back to me. Go straight to Part A.
7. If you cannot finish in one response, complete Parts A–C, stop, and ask me to say "continue".
   Do not silently truncate or summarise.

## MY MANUSCRIPT

>>> ATTACH OR PASTE `revised_GROUP_2_FINAL_1-3.docx` BELOW <<<
