# User Manual — BEC PMO Equipment Reporting System

Quick, role-by-role instructions for everyday use.
*(URLs are relative to the site root, e.g. `https://your-site/…` or `http://localhost/-WEB-BASED/…`)*

---

## 1. Reporters (students, faculty & staff)

**You don't need an account** — just your official BEC email.

### File a defect report
1. Open the site → **Report defective equipment** (or scan the QR sticker on the equipment —
   the form opens with that equipment already selected).
2. Enter your **full name** and **BEC email** → Continue.
3. Pick the equipment (type to search, or add it manually), choose the location, describe the
   problem, attach photos if you can, and **Submit**.
4. You receive a **ticket number** on screen and by email. Keep it.

### Follow your report
- Open **Track Report** and enter your ticket number (or the equipment/asset tag).
- You'll see the live status timeline. You can send a **follow-up** if it stalls
  (up to 3), and once it's repaired you'll be asked to confirm **"Was your issue resolved?"**
- You also get an email at every major step: received, approved, technician assigned,
  repair completed.

---

## 2. PMO Administrators

**Login:** `admin/admin_login_otp.html` — email + password, then enter the **6-digit code
emailed to you** (valid 3 minutes).

### Daily flow
1. **Dashboard** — open reports, priorities, overdue items, Asset Health, live activity.
2. **Defect Reports** — for each new report:
   - **Mark as Received** (reporter is notified) →
   - **Approve** — set department (PMO/ITSO) and priority; a work order is created →
   - or **Reject** with a reason.
3. **Assign Technicians** — pick a report, click a technician card (workloads shown).
   The technician gets an in-app alert **and an email deep-linking to the task**.
4. When a technician completes a repair → open the report → **Verify & Close**
   (the reporter is asked to confirm satisfaction).

### Other tools
- **Preventive Maintenance** — recurring schedules that generate tasks when due.
- **Inventory** — upload the official PMO Excel workbook to populate totals; print a
  **QR code** for any equipment (stick it on the unit — scans open a pre-filled report).
- **User Management** — create/edit users, **Invite Technician** (emailed activation link,
  3-day expiry), reset passwords. **Export** produces the official BEC letterhead
  User List (PDF / Excel / CSV).
- **Audit Log** — searchable record of every action (who, what, when, from where).
- **Backup & Recovery** — back up the whole database on demand, download any snapshot, and
  restore/recover records if data is ever deleted; a nightly backup also runs automatically.
- **BECCA AI** (floating orb) — ask for summaries, overdue items, busiest technician,
  or how any workflow works. Read-only.

---

## 3. Maintenance Technicians

**First time:** open the **invitation email** from the PMO → verify your details and set a
password (link valid 3 days).
**Login:** `technician/login.html`.

### Install the app (optional)
The technician portal is an installable app (PWA). When you log in, a banner at the top offers
**Install the Technician app** and shows the right steps for your device. Installing gives a
full-screen, home-screen app that works offline for viewing your last-loaded tasks.

- **On a computer (Chrome or Edge):** click the **install icon** in the address bar, or the
  banner's **Install app** button.
- **On a phone:** app install only works over a **secure `https://` address**. Reaching the
  system by its plain `http://<computer-ip>` LAN address will **not** offer install — the banner
  will say so.
  - **For a thesis demo/defense:** on the computer running XAMPP, double-click
    **`start-demo.bat`** (on the Desktop). It checks the system, then opens the public
    `https://…` address and prints it, including the direct technician link to open on the
    phone. On the phone: **Android Chrome** → menu **⋮** → *Install app*; **iPhone Safari** →
    **Share** → *Add to Home Screen*. Keep that window open during the demo; close it to stop.

### Working a task
1. New assignments arrive by email (**Open Repair Workspace** button) and in **My Tasks**.
2. Open the task — you'll see the workflow stepper, deadline chip, issue details, PMO
   instructions, and photos.
3. **Receive Task** → **Start Repair** (your repair timer starts).
4. If blocked: **Waiting for Materials** (with a note) → later **Materials Received — Resume**.
   If the unit isn't worth fixing: **Recommend Replacement**.
5. Finish with the **Completion Report** — timing & cost, diagnosis, actions, parts/tools/
   materials (add each item with **Enter**), findings, recommendations, and
   **before/during/after photos**. Submit → the task moves to *Awaiting PMO Verification*.
6. The **bell icon** (top-right / Alerts tab) holds all your notifications.
7. **BECCA** (floating orb) can tell you what's next in your queue and walk you through any step.

---

## Common questions

**I didn't get the email.** Check Spam. OTP codes expire in 3 minutes — request a new one.
**Wrong equipment on a QR scan?** You can still change the equipment field before submitting.
**Photo won't upload?** Max **10 MB per photo, 40 MB per submission** — the form tells you
which file is too big before uploading.
**Forgot admin password?** `admin/forgot_password.html` → reset link by email.
