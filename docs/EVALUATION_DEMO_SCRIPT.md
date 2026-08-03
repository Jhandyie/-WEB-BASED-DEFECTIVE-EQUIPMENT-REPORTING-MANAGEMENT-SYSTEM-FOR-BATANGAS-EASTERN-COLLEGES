# Evaluation Demonstration Script

## BEC Web-Based Defective Equipment Reporting Management System

**Institution:** Batangas Eastern Colleges — Property Management Office
**Purpose:** Guided system demonstration preceding administration of the evaluation instrument
**Respondent groups:** Reporters (students and faculty), PMO Administrators, Maintenance Technicians
**Estimated duration:** 25–30 minutes per respondent group
**Date of administration:** Week of 03 August 2026

---

## 1. Purpose and Scope of This Script

This document contains the verbatim narration to be delivered by the facilitator during the
system evaluation sessions. It standardises what every respondent hears and sees, so that
differences in the evaluation ratings reflect differences in the system's perceived quality
rather than differences in how the demonstration was conducted.

Each segment is presented in a **Say / Do** format. Lines marked **Say** are spoken aloud;
lines marked **Do** are the corresponding on-screen actions. Facilitators should follow the
sequence without deviation, and should refrain from volunteering opinions about the system's
merits, as this may bias the respondent's ratings.

---

## 2. Pre-Session Preparation (facilitator checklist)

- [ ] Apache and the database connection verified; landing page loads without error
- [ ] Demonstration accounts prepared: one reporter email, one administrator account (OTP), one technician account
- [ ] Inventory populated, so the equipment and location searches return results during the walkthrough
- [ ] Mobile handset charged, connected, with the technician portal already reachable
- [ ] Mailbox open in a separate browser tab to display notification emails as they arrive
- [ ] Test data cleared so the dashboards display a clean, representative state
- [ ] Printed copies of the evaluation instrument and informed-consent forms on hand
- [ ] Fallback screenshots available in the event of a connectivity failure

---

## 3. Opening Statement (delivered once, to all respondent groups)

> **Say:** "Good morning, and thank you for taking part in this evaluation. I am [name], and
> this system is part of our capstone study, the *Web-Based Defective Equipment Reporting
> Management System* for the Batangas Eastern Colleges Property Management Office.
>
> At present, defective equipment is reported informally — verbally, by note, or by chat —
> and the office has no single record of what was reported, who acted on it, or how long the
> repair took. This system was developed to place that entire process on one platform, from
> the moment a defect is noticed until the repair is verified and closed.
>
> For the next twenty to thirty minutes, I will walk you through the part of the system that
> corresponds to your role. You are welcome to interrupt with questions at any point. Please
> note that we are evaluating the *system*, not you — there are no incorrect responses, and
> nothing you say will be attributed to you by name.
>
> After the walkthrough, I will ask you to complete a short questionnaire on the system's
> functionality, usability, reliability, and security. Your honest assessment, including any
> criticism, is what makes this evaluation useful. Shall we begin?"

**Do:** Distribute the consent form, obtain the signature, and open the landing page at
`http://localhost/-WEB-BASED/` (or the public demonstration URL).

---

## 4. Segment A — The Reporter Portal

*Audience: students and faculty. Duration: approximately 10 minutes.*

### A.1 Framing the role

> **Say:** "In this system you take the role of the *reporter* — the person who first notices
> that a piece of equipment is defective. The design goal for this portal was that filing a
> report should require no permanent account, no training, and no more than a few minutes of
> your time."

### A.2 The reporting portal

**Do:** Open the reporter portal on the handset or the workstation.

> **Say:** "This is the official reporting page of the Property Management Office. Before you
> begin, it tells you plainly what to prepare — the equipment, its location, and a short
> description of the problem — and what will happen after you submit. It also states the data
> privacy notice: the office collects your name, email address, and the report details, for
> maintenance and record-keeping, under the Data Privacy Act of 2012."

### A.3 Identity verification

> **Say:** "Please enter your name and your institutional email address, and tick the privacy
> consent. The system checks the address against the official BEC directory, so only members of
> the institution can file a report. This is what prevents outsiders from submitting nuisance
> entries, while still sparing you the burden of maintaining an account and a password."

**Do:** Allow the respondent to sign in.

> **Say:** "You are then asked for your department, your course or programme, and your year
> level, with a contact number if you wish to give one. The office asked for these because it
> needs to know which unit of the school a report came from when it plans its maintenance."

### A.4 Identifying the equipment

> **Say:** "Now describe what is broken. Begin typing the name of the equipment — the field
> searches the office's inventory as you type, so you select the actual registered unit rather
> than typing a description of it. When you select it, the category fills in automatically, and
> if the unit carries a visible asset tag you may add it. The location works the same way: type
> the room or building and choose from the list."

**Do:** Let the respondent search and select the equipment and location unaided.

> **Say:** "Selecting from the inventory rather than typing free text is what keeps the office's
> records consistent — it is how the system can later tell you which specific unit has failed
> most often."

### A.5 Completing and submitting the report

> **Say:** "Describe the defect in your own words — one or two sentences is sufficient — give the
> date and time you noticed it, and indicate whether the equipment is still usable, partially
> usable, or completely broken. That last answer is what helps the office decide how urgent the
> repair is. Attach a photograph if the problem is visible, then submit."

**Do:** Let the respondent complete the form unaided. Observe silently; hesitations are
themselves evaluation data. Only assist if the respondent is genuinely blocked.

> **Say:** "If someone has already reported the same unit, the system warns you before it
> accepts the report, so the office does not receive the same fault three times from three
> people. You may still proceed if you believe yours is a different problem."

> **Say:** "The system has issued you a ticket number, and a confirmation has been sent to your
> email address. May I show you the message that just arrived?"

**Do:** Switch to the mailbox tab and display the confirmation email.

### A.6 Tracking the report

**Do:** Open **Track Report** and paste the ticket number.

> **Say:** "This is the tracking page. Without logging in, you can see the current stage of your
> report — received, approved, assigned, under repair, completed, verified — together with the
> date of each step. When the repair is finished, you will be asked here whether the issue was
> genuinely resolved, and your answer is recorded as feedback to the office.
>
> That is the whole of the reporter's role. Do you have any questions before we move on?"

---

## 5. Segment B — The Administrator (PMO) Portal

*Audience: PMO staff and designated administrators. Duration: approximately 12 minutes.*

### B.1 Framing the role

> **Say:** "You take the role of the PMO administrator — the officer who decides what happens to
> each report. This portal was designed around three requirements the office raised during
> consultation: that access be secure, that decisions be traceable, and that nothing be lost
> between the report and the repair."

### B.2 Secure sign-in

**Do:** Open the administrator login and enter the credentials.

> **Say:** "Administrator access requires a password and, in addition, a one-time code sent to
> the registered institutional email. The code expires after three minutes. This means that a
> stolen password alone is not sufficient to enter the system — a point I will ask you to
> consider when you rate the security items on the questionnaire."

**Do:** Retrieve the code from the mailbox and complete the sign-in.

### B.3 The dashboard

> **Say:** "This is the command dashboard. The upper cards summarise the current workload:
> reports received, reports in progress, reports completed, and those approaching or past their
> service deadline. Below them, the equipment health indicators show which units are failing
> most frequently — the information the office needs when it justifies a replacement request
> rather than another repair."

### B.4 Reviewing and approving a report

**Do:** Open **Defect Reports** and select the report just filed in Segment A.

> **Say:** "Here is the report we submitted a few minutes ago, with the reporter's details, the
> equipment record, and the photograph. The first action is to acknowledge it."

**Do:** Click **Mark as Received**, then show the notification arriving in the reporter's mailbox.

> **Say:** "The reporter has now been informed automatically that the office has seen the report.
> This single step addresses the most frequent complaint in the current process — that reporters
> never learn whether their report reached anyone.
>
> The second action is the decision itself: approve the report and set the responsible department
> and the priority level, or reject it with a reason. Approving it creates the work order
> automatically; no separate form is encoded."

**Do:** Approve the report, assigning department and priority.

### B.5 Assignment

**Do:** Open **Assign Technicians**.

> **Say:** "The system displays each technician together with their current workload, so the
> assignment is made on the basis of who is actually available rather than who is remembered
> first. Please select a technician."

**Do:** Assign, then display the technician's notification email.

> **Say:** "The technician has received a notification containing a direct link to this specific
> task."

### B.6 Verification, audit trail, and data safeguards

> **Say:** "When the technician has finished, the report returns here for verification, and only
> the office can close it. Two further features are relevant to your evaluation.
>
> First, the audit log."

**Do:** Open **Audit Log**.

> **Say:** "Every action taken in the system — every approval, assignment, and closure — is
> recorded with the user and the timestamp. Nothing can be altered without leaving a trace.
>
> Second, backup and recovery."

**Do:** Open **Backup & Recovery**.

> **Say:** "The system takes an automatic daily backup of all records, and a backup can also be
> taken on demand. If data were ever deleted in error, it can be restored from any of these
> snapshots. Reports and inventory can also be exported to PDF and Excel for the office's
> official filing.
>
> Are there any questions on the administrator's functions?"

---

## 6. Segment C — The Technician Portal

*Audience: maintenance technicians. Duration: approximately 10 minutes.*

### C.1 Framing the role

> **Say:** "You take the role of the technician who performs the repair. Because your work is
> done in the field rather than at a desk, this portal was designed for the phone first — it
> installs on the home screen and opens like an ordinary mobile application."

**Do:** Hand over the handset with the technician portal open, and demonstrate **Add to Home
Screen** if it is not already installed.

### C.2 Receiving the task

**Do:** Sign in and open the task assigned in Segment B.

> **Say:** "This is the task just assigned to you. It carries the equipment details, its
> location, the reporter's description, and the photograph — so you know what you are attending
> to before you leave the shop.
>
> The bar across the top is the workflow tracker: Received, In Progress, Materials, Completed,
> Verified. The chip beside it shows the remaining time before the service deadline, which is
> set by the priority the office assigned."

### C.3 Performing the repair

> **Say:** "Please tap **Receive Task** to acknowledge it."

**Do:** Allow the respondent to tap.

> **Say:** "The office can now see that you have taken the job. When you begin work, tap **Start
> Repair** — this starts the repair timer, which records how long the work actually took. That
> figure is what the office uses to measure its service performance."

**Do:** Allow the respondent to start the repair.

> **Say:** "If the repair cannot proceed — a part is unavailable, or the unit is beyond economical
> repair — you can place it on hold for materials or recommend replacement to the office instead
> of completing it. The report is never abandoned; it simply moves to a state the office can see."

### C.4 The completion report

**Do:** Open the completion form.

> **Say:** "When the work is done, you file the completion report: what was found, what was done,
> the parts consumed, and photographs of the unit before, during, and after the repair. The
> required fields are enforced — the form will not submit while any of them is blank, which is
> what guarantees the office receives a complete service record every time."

**Do:** Complete and submit the report; then show that the record has returned to the
administrator for verification.

> **Say:** "The task is now with the office for verification, and the reporter has been told the
> repair is finished. Your part of the workflow is complete.
>
> Do you have any questions about the technician's functions?"

---

## 7. Closing Statement and Handover to the Instrument

> **Say:** "That completes the demonstration. To summarise what you have seen: a reporter files
> a report against a specific registered unit in a few minutes; the office receives it
> immediately, approves it, and
> assigns it on the basis of actual workload; the technician records the repair from the field;
> and the office verifies and closes it — with the reporter informed at every stage and every
> action written to an audit log.
>
> I will now give you the evaluation questionnaire. It asks you to rate the system on
> functionality, usability, reliability, security, and overall satisfaction, using a four-point
> scale, and it ends with an open item for your comments and recommendations.
>
> I would ask you to answer candidly. Findings that identify weaknesses are more valuable to
> this study than uniformly favourable ratings, because they are what allow the system to be
> improved before it is deployed. Please take as much time as you need, and tell me if any item
> is unclear. Thank you again for your participation."

**Do:** Administer the instrument. Do not remain within reading distance of the respondent while
it is being completed. Collect the form, confirm no item was skipped, and log the respondent's
group and the session date.

---

## 8. Anticipated Questions and Prepared Responses

| Question | Response |
|---|---|
| "Is my personal information safe?" | Only your name, institutional email, and the report itself are stored. Access is restricted by role, all sessions are protected, and every access is logged. |
| "What if I cannot find the equipment in the list?" | Report the nearest matching unit and state the difficulty in the description; the office can correct the equipment record when it reviews the report. |
| "What if the internet is down?" | Reports require a connection, but the technician portal retains the last loaded task on the device, and no data is lost when connectivity returns. |
| "Who can delete a report?" | No user can delete a report outright. Reports are rejected or closed with a recorded reason, and the action remains in the audit log. |
| "Will this replace our existing forms?" | The system generates the equivalent PDF and Excel records, so existing filing requirements continue to be met. |
| "What happens if an email fails to send?" | The in-application notification is always delivered; email is a secondary channel, and a failed send is logged without interrupting the workflow. |

---

## 9. Contingency Procedures

| Situation | Facilitator action |
|---|---|
| Connectivity fails mid-session | Continue using the prepared screenshots and narrate the sequence; record the incident on the session log |
| One-time code is delayed | Explain the three-minute expiry, request a resend, and use the interval to answer questions |
| Respondent becomes stuck on a step | Wait five seconds before intervening, then assist; record the difficulty, as it is a usability finding |
| Session overruns | Reduce Segment B to sign-in, approval, and assignment, and omit the audit log and backup demonstration |
| Respondent declines to continue | Stop immediately, thank them, and record the withdrawal without further questions |

---

*Prepared as the demonstration protocol for the system evaluation component of the capstone study
"BEC Web-Based Defective Equipment Reporting Management System," Batangas Eastern Colleges —
Property Management Office, August 2026.*
