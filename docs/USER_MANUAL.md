# User Manual — BEC PMO Equipment Reporting System

**Batangas Eastern Colleges · Property Management Office**

How to use the system, by role. Nothing here needs to be installed; everything is a web page.

> This manual was rewritten in September 2026 after the reporter form and the technician
> workspace were rebuilt. If anything below does not match what is on your screen, the screen
> is right and this file is out of date — tell the PMO.

---

## 1. Reporters — students, faculty and staff

**You do not need an account.** Your official BEC email is your identity, confirmed by a short
code instead of a password.

### Filing a report

1. Open the site and choose **Report defective equipment** — or scan the QR sticker on the
   equipment, which opens the form with that unit already filled in.
2. Enter your **BEC email** and tap whether you are a **Student**, **Teacher** or **Staff**.
   That one tap is the only thing the system asks about you.
3. Check your inbox for a **6-digit code**. It is valid for **3 minutes**, and you can request
   another after **25 seconds**.
4. First time only: if your name is not already on the BEC directory, you are asked for it once.
5. You land on **one screen with four questions**:
   - **What is broken?** — type the equipment name in your own words.
   - **Where is it?** — pick the room from the list.
   - **What is wrong with it?** — describe it plainly. English or Filipino, whichever is easier.
   - **A photo or a video** — **this is required.** Tap **Take a photo** or **Record a video** to
     use your camera, or choose a file from your gallery. At least one photo *or* one video;
     the form will not submit without it.
6. Press **Submit Report**. You get a **ticket number** on screen and by email. Keep it.

**What the system works out by itself**, so you are not asked: the equipment category, which
office handles it (PMO or ITSO), the priority, and your department or course. There is no
category to choose, no asset tag to find, no "is it still usable?" question.

**This phone remembers you for 30 days**, so next time you go straight in.

> Codes are only sent to addresses on the official BEC directory. If nothing arrives, check
> your spam folder, then ask the PMO to confirm your address is on file.

### Following your report

- Open **Track Report** and enter your **ticket number**. An equipment ID or an asset tag works
  too.
- You see the live status timeline.
- While signed in as the reporter you can send a **follow-up** if it stalls — **up to 3 times**.
- Once it is repaired you are asked **"Was your issue resolved?"** That answer can be given
  **once**, and only by you.

### Seeing what else is broken

**Public Reports** lists every report in the system with its status, so you can check whether
something has already been reported before filing it again. No sign-in needed.

---

## 2. PMO Administrators

**Sign in** at `admin/admin_login_otp.html` — email and password, then the **6-digit code emailed
to you** (valid 3 minutes). Accounts are created by an existing administrator in User
Management; nobody can register themselves.

### The daily flow

1. **Dashboard** — open reports, priorities, overdue items, live activity. The stat cards link
   into the queue already filtered.
2. **Defect Reports** — for each new report:
   - **Mark as Received** — the reporter is notified.
   - **Approve** — set the office (PMO or ITSO) and the priority. It moves to *Ready for
     Assignment*.
   - or **Reject** with a reason.
   - **Several at once:** tick the rows and use **Mark as received** or **Approve** in the bar
     that appears. Reports already past that stage are skipped, not forced.
3. **Assign Technicians** — pick a report, click a technician card. Workloads are shown. The
   technician gets an in-app alert and an email that links straight to the task.
4. When a technician finishes → open the report → **Verify & Close**. The reporter is then asked
   to confirm they are satisfied.

### Finding things

- **The search box at the top of the sidebar** searches reports, equipment, staff accounts and
  the BEC directory at once. A whole ticket number or a whole asset tag jumps straight to that
  record.
- **Sort any column** in the queue by clicking its header; click again to reverse.
- **Filters** for stage, office, origin, follow-ups and **overdue**. They survive every action,
  so working through a filtered queue does not reset it.
- **From a report** you can jump to every other report for that same unit, or everything that
  person has reported. A unit with three or more earlier reports is flagged in red.
- **From User Management** the report count beside a person links to their reports.

### The other tools

- **Preventive Maintenance** — recurring schedules that raise tasks when due. Exports to CSV.
- **Inventory** — upload the official PMO Excel workbook; print a **QR code** for any unit to
  stick on it, which opens a pre-filled report when scanned.
- **User Management** — create and edit accounts, **Invite Technician** (emailed activation
  link, 3-day expiry), reset passwords, export the branded User List.
  - **For an administrator, the department decides what they see.** A department naming **PMO**
    or **ITSO** limits their reports and notifications to that office. Anything else — including
    an academic department — means they receive **everything for both offices**. The
    *Neither office* filter lists those accounts.
- **BEC Directory** — import the roster from a file, and **edit, add or remove one person** when
  a single record is wrong. The directory is what fills in a reporter's department
  automatically, so a wrong email or department there shows up on their reports.
- **Venue Reservations** — the VRF workflow, including assessment and payment. Exports to CSV.
- **Work Orders** — the ledger of finished jobs, sortable and paged, with its own export.
- **Backup & Recovery** — back up on demand, download any snapshot, preview a restore before
  running it. A nightly backup runs automatically at 02:00.
- **BECCA AI** (floating orb) — ask for summaries, overdue items, or how a workflow works.
  Read-only; it cannot change anything.

Every lifecycle action is recorded server-side with who, what and when. There is **no browsable
Audit Log page** in this version — the viewer was removed in August 2026. The record itself is
unaffected; do not go looking for a menu item that is not there.

---

## 3. Maintenance Technicians

**Sign in** with the account the PMO created for you. On a phone, use **Install app** to add it
to your home screen and **Enable alerts** to get task notifications.

### Working a task

The workspace shows **one briefing and one button**. You are not asked to fill in a report.

1. New assignments arrive by email (**Open Repair Workspace**) and in **My Tasks**.
2. Open the task. You see what is broken, where it is, who reported it, the PMO's instructions
   and the reporter's photos.
3. Press the one button for where the task is: **Receive** → **Start the repair** → **Mark as
   fixed**.
4. **Finishing** asks four things, and no more:
   - **What did you do?** — English or Filipino, whichever is easier. Tap one of the suggested
     sentences to fill the box, then edit it.
   - **Parts used** — if any.
   - **A photo of the finished work** — **required.**
   - **Cost** — PMO technicians only, and only if you spent something.
5. Submit. The task moves to *Awaiting PMO Verification*.

**If you are stuck**, open **Having a problem?** under the main button:
- **Need parts first** — the task waits, and you resume it with **Parts arrived** later.
- **Can't be fixed** — recommends the unit for replacement.

The **bell** holds your notifications. **BECCA** can tell you what is next in your queue.

---

## Common questions

**I didn't get the email.** Check Spam. Codes expire in 3 minutes — ask for a new one.

**The form won't let me submit.** It needs a photo or a video. That is deliberate: a report
without evidence cannot be triaged without someone walking to the room to look.

**The camera button opens my files instead of the camera.** Use the **Take a photo** button
rather than the file area — that one opens the camera directly on iPhone and Android.

**I reported the wrong thing.** Send a follow-up on the Track Report page; the PMO reads them.

**Can I report without a BEC email?** No. Codes only go to addresses on the official directory.
Ask the PMO to add you.

---

*Batangas Eastern Colleges · Property Management Office*
