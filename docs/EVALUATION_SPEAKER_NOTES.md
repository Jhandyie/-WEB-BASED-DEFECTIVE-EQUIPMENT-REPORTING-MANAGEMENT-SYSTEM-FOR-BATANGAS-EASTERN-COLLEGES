# Evaluation Demo — Speaker Notes

**Web-Based Equipment Reporting and Maintenance Management System
for Batangas Eastern Colleges**

Casala, Cyril B. · Colegio, Jaymiel M. · De Castro, Jhan Mark C. ·
Sumague, Shane R. · Tanyag, Mark Roi S. · Villar, Francis Miguel Z. — 4th Year BSIS
Adviser: Michelle A. Dino, MIT · Week of 03 August 2026

> Cue cards, not a speech. Left column = what you do. Right column = the point to land.
> Say it your own way; bolded phrases are the ones worth keeping.

---

## Read this first — before any session

**Three items on the questionnaire ask about a module the system no longer has.** The budget
request feature was removed in July 2026 (`scripts/2026_07_drop_budget_dean_finance.sql`). The
affected items are **Part II-E #4**, **Part III-A #4**, and **Part III-B #4**. A technician
cannot request a budget at all — only a **cost estimate**. Decide with your adviser *before*
Monday whether to reword them, strike them, or mark them Not Applicable. Do not leave a
respondent to rate a feature that isn't there.

**Match the demo to the instrument.** Respondents rate seven ISO/IEC 25010 characteristics plus
UI, UX and a role-specific section. Anything you skip, they still have to rate — so cover the
checklist in the last section before you close.

---

## Before you start

- Apache running · landing page loads · internet up
- Logged in: reporter mailbox tab, admin account, technician account
- Inventory populated so equipment and location searches return results
- Phone charged, technician portal open on it
- Test reports cleared — dashboards should look clean
- Questionnaires + consent printed · fallback screenshots ready

---

## Opening — 2 min

| Do | Say |
|---|---|
| Introduce the group | 4th year **BSIS** capstone, adviser **Ms. Dino** |
| Name the system | **Web-Based Equipment Reporting and Maintenance Management System** |
| Frame the problem | Today: **untracked handwritten reports, delayed repairs, no real-time monitoring** |
| Frame the goal | **One platform, from the moment a defect is noticed to a verified repair** |
| Set expectations | 20–30 min · **questions any time** |
| Match the consent form | **Voluntary** · **withdraw any time without penalty** · **no right or wrong answers** · confidential · **anonymous unless you write your name** |
| Consent | Get the tick and signature, then open the landing page |

---

## A · Reporter — 10 min

*Students (college and SHS), faculty, administrative staff*

| Do | Say |
|---|---|
| Open the reporting portal | You're the person who **notices something is broken** |
| Point at "what to prepare" | It tells you up front what you need and what happens next |
| Point at the privacy notice | Name, email, report details only — **Data Privacy Act of 2012** |
| Name + BEC email, tick consent | Checked against the **official BEC directory** — **no account, no password to maintain** *(Part II-A #6)* |
| Department / course / year level | The office needs to know **which unit a report came from** |
| They type in the equipment field | It **searches the inventory as you type** — you pick the real registered unit |
| Category auto-fills · asset tag optional | |
| They search the location | Same behaviour — type the room, pick from the list |
| — | Picking from inventory is **how the office later sees which unit fails most often** |
| Defect description · date/time · usability · photo | **Still usable / partially / completely broken** is what sets the urgency |
| **Stay quiet. Wait 5 seconds before helping** | *(Hesitation is usability data — note where they pause)* |
| *(If you can)* leave a required field blank | Show the **validation message** — they rate error recovery *(UX #4)* |
| Duplicate warning, if it appears | Warns you the unit is **already reported** — you can still proceed |
| Submit → ticket number | Here's your **ticket number** *(Part II-A #3)* |
| Switch to mailbox | Confirmation **already in your inbox** *(UX #5)* |
| Track Report — paste the ticket | **No login needed** · every stage with its date |
| Point at the end of the timeline | When it's fixed it asks **"was this actually resolved?"** — your answer is recorded *(UX #6)* |
| **Open it on the phone too** | Same page, **adjusts to the screen** — they rate this *(Portability #1, #5)* |

---

## B · Administrator — 12 min

*PMO personnel and ITSO staff*

| Do | Say |
|---|---|
| Frame the role | You decide **what happens to each report**. The office asked for **secure access, traceable decisions, nothing lost** |
| Enter admin credentials | Password **plus a one-time code** emailed to you, **expires in 3 minutes** |
| Fetch code, sign in | A **stolen password alone is not enough** *(Part II-E #2)* |
| Dashboard — KPI cards | Received · in progress · completed · **approaching or past deadline** *(III-A #1)* |
| Equipment health | Which units **fail most often** — justifies **replacing instead of repairing again** |
| Defect Reports → open the new one | Reporter details, equipment record, photo |
| **Mark as Received** | |
| Switch to mailbox | The reporter now knows **someone has seen it** — the **biggest complaint** in the current process |
| **Approve** + department + priority | Approving **creates the work order automatically** — no second form *(III-A #2)* |
| *(Mention)* | Or reject **with a reason** — on record either way |
| Assign Technicians | Each card shows **current workload** — assign on **who's actually free** *(III-A #3)* |
| Assign → show the email | Technician gets a **direct link to that exact task** |
| **Preventive Maintenance** *(open briefly)* | Scheduled servicing, not just breakdowns *(III-A #4)* |
| **Inventory** *(open briefly)* | The equipment register the reporter's search draws from *(III-A #4)* |
| **Analytics** *(open briefly)* | **Repeat offenders** — supports **repair-or-replace and procurement** decisions *(III-A #7)* |
| **Audit Log** | Every approval, assignment, closure — **user and timestamp**, nothing changes without a trace *(III-A #5)* |
| **Export PDF / Excel** | Official documentation on **BEC letterhead** *(III-A #5)* |
| **User Management** *(mention)* | Create users, **invite technicians**, reset passwords *(III-A #8)* |
| **Backup & Recovery** | **Daily automatic backup** · deleted data **restorable from any snapshot** |
| *(Mention)* | Admin portal is **desktop-first** — the office works at a desk |

> **After this group:** PMO and ITSO also answer the **Open-Ended Questionnaire** — 14 items,
> 5 sections. Hand it over with the rating sheet and allow extra time.

---

## C · Technician — 10 min

*Maintenance technicians*

| Do | Say |
|---|---|
| Hand over the phone | Your work is **in the field, not at a desk** — built **phone-first** |
| Add to Home Screen *(if not installed)* | **Installs like a normal app**, no app store *(Portability #4)* |
| Open the assigned task | Equipment, location, description, photo — **you know what you're facing before you leave the shop** *(III-B #2)* |
| Point at the stepper | Received → In Progress → Materials → Completed → Verified *(III-B #8)* |
| Point at the SLA chip | **Time left before the deadline**, set by the office's priority *(III-B #6)* |
| **Receive Task** | The office can see **you've taken the job** |
| **Start Repair** | Starts the **repair timer** — the office's **performance measure** *(III-B #3)* |
| *(Mention — don't click)* | Part missing or beyond repair: **hold for materials** or **recommend replacement**. Never abandoned |
| **Cost Estimate** *(open it)* | Materials + labor + miscellaneous for the office to act on. **This is an estimate — there is no budget-request approval in the system** *(see III-B #4)* |
| Completion form | Found · did · parts used · **before / during / after photos** *(III-B #7)* |
| *(Show once)* submit with a blank required field | **Won't submit while a required field is empty** — guarantees a **complete service record** |
| Complete and submit | Back to the office to verify · **reporter has been told it's done** *(III-B #1)* |

---

## Closing — 2 min

| Do | Say |
|---|---|
| Summarise the loop | Filed against a **real registered unit** → office **approves and assigns by workload** → technician records it **from the field** → office **verifies and closes** → reporter **informed throughout** → **all of it in the audit log** |
| Introduce the questionnaire | **Four-point scale — 4 Strongly Agree, 1 Strongly Disagree** |
| Name the parts | **Part II** seven quality characteristics + UI and UX · **Part III** only your role's section · **Part IV** overall satisfaction · **comments box** |
| Reporters only | You **skip Part III** — it's for administrators and technicians |
| Ask for candour | **Weaknesses help more than good scores** — they're what let us fix it before deployment |
| Step away | Don't stand where you can read their answers |
| Collect | Check nothing was skipped · log their **role and date** |

---

## Coverage check — did they see what they must rate?

*Glance at this before you close. Anything unticked, they'll be rating blind.*

| Instrument section | Shown by |
|---|---|
| A · Functional Suitability | Full loop: file → approve → assign → repair → verify · ticket status · no-account reporting |
| B · Reliability | Say it plainly: **nothing lost, daily backups, restorable** — they judge the rest from use |
| C · Performance Efficiency | Let **them** click. Don't narrate over a slow page — that's the data |
| D · UI | Every portal seen: landing, reporter, admin, technician · **consistent colours and icons** |
| D · UX | Validation message · notification email · **"was it resolved?"** · vs. the paper process |
| E · Security | **OTP + password** · role-restricted portals · audit log · privacy notice |
| F · Maintainability | Show one **clear error message** · mention **modules work independently** |
| G · Portability | **Phone and desktop** · **any browser, no install** · technician app installs |
| III-A · Administrators | Dashboard · review · assign · preventive · inventory · analytics · audit · exports · users |
| III-B · Technicians | Notification · workspace · status updates · cost estimate · phone · SLA · documentation |
| IV · Satisfaction | The closing summary |

---

## If they ask

| Question | One-line answer |
|---|---|
| Is my data safe? | Name, email, report only · **role-restricted** · every access logged |
| Can't find the equipment? | Pick the closest, say so in the description — the office corrects it on review |
| Internet down? | Filing needs a connection · technician's **last task stays on the phone** · nothing lost |
| Who can delete a report? | **No one.** Rejected or closed **with a reason**, kept in the audit log |
| Does this replace our forms? | No — it **generates the same PDF and Excel records** |
| Email fails? | **In-app notification always fires** · email is secondary · failure logged, workflow continues |
| Where's the budget module? | **Removed in July 2026** to match the study's scope. Technicians file a **cost estimate**; the office acts on it outside the system |

---

## If it breaks

| Problem | Do |
|---|---|
| Internet dies | Screenshots, keep narrating · note it on the session log |
| OTP slow | Explain the 3-minute expiry, resend, **take questions while you wait** |
| They get stuck | Wait 5 seconds, then help · **write down where** — that's a finding |
| Running long | Cut Segment B to **sign-in → approve → assign** · skip preventive, analytics, backup |
| They want to stop | Stop immediately · thank them · no follow-up questions |

---

*Companion to docs/EVALUATION_DEMO_SCRIPT.md (full narration and methodology notes).
Aligned to System-Evaluation-Instrument and the ISO/IEC 25010 characteristics it uses.*
