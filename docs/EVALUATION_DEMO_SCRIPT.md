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

The facilitator role is **distributed across the six members of the research group**, one segment
each, so that no single presenter delivers the whole session. The allocation of segments, the
transition lines between them, and the standing duties held by the members who are not speaking
are set out in **`docs/EVALUATION_SPEAKER_NOTES.md`**, which is the rehearsal script for the same
session. This document remains the authoritative record of the protocol itself.

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

### 2.1 Respondent eligibility for the reporting walkthrough

The reporter portal issues a **six-digit verification code to the respondent's own mailbox** before
admitting them, and it issues that code only to an address recorded in the official BEC directory.
Any respondent who is to file a report during Segment A must therefore hold a directory-listed
`@bec.edu.ph` address **and** be able to open that mailbox during the session. This is to be
confirmed for each respondent on the day before the session.

Where a respondent cannot open their mailbox, the facilitator uses the **prepared reporter account**,
whose inbox is already displayed on the facilitator's screen, and allows the respondent to perform
every remaining step of the form. A browser that has completed verification is remembered for
**thirty days**, so a rehearsal device may present a one-tap continuation and omit the code step
entirely; Segment A provides narration for both cases.

### 2.2 Matters to be settled with the adviser before administration

1. **Instrument items referring to the budget request module.** That module was withdrawn in July
   2026 to align the system with the declared scope of the study. Items **II-E #4**, **III-A #4** and
   **III-B #4** refer to it. Technicians prepare a **cost estimate**; no budget approval workflow
   exists in the system. The items are to be reworded, struck, or marked Not Applicable before
   administration — a respondent must not be asked to rate a function that is absent.
2. **The audit trail has no browsable viewer.** Every lifecycle action continues to be recorded
   server-side with the acting user and the timestamp; the administrative viewing page was removed in
   August 2026. Facilitators state the audit trail verbally, as scripted in §5, B.7, and do not offer
   to display it.
3. **The rating scale and item numbering** are to be verified against the final printed instrument.

---

## 3. Opening Statement (delivered once, to all respondent groups)

> **Say:** "Good morning, and thank you for taking part in this evaluation. I am [name], and these
> are the other members of our research group, [names]. This system is our capstone study, the
> *Web-Based Defective Equipment Reporting Management System* for the Batangas Eastern Colleges
> Property Management Office. Each of us will present the portion of it that belongs to one role.
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
`http://localhost/bec-pmo/` (or the public demonstration URL).

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
> consent. The system will then send a **six-digit code to that mailbox, which is valid for three
> minutes**. Entering it confirms that the person filing the report genuinely holds the address, so
> the office can rely on a report having come from an actual member of the institution — while still
> sparing you the burden of maintaining an account and a password.
>
> You may also notice that the message on screen is worded identically whether or not the address is
> recognised. That is deliberate: a page which answered differently could be used to establish who is
> on the school's roster."

**Do:** Allow the respondent to retrieve the code from their own mailbox and complete the
verification. Where the browser has verified the address within the preceding thirty days, the portal
presents a one-tap continuation instead:

> **Say:** "This browser has already verified this address within the last thirty days, so the system
> recognises it and does not ask again. On a new device, or after thirty days, the verification step
> returns."

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
> date of each step; the ticket number alone is sufficient to read it.
>
> Two actions on this page are reserved to you as the reporter and require your signed-in session:
> raising a follow-up question with the office, and the final step of the workflow. When the repair is
> finished, you are asked here whether the issue was genuinely resolved, and your answer is recorded as
> feedback to the office. That verdict may be given only once, which is why it is not left open to
> anyone in possession of the ticket number.
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
> service deadline, so that an overdue case cannot pass unnoticed. Below them, the equipment health
> indicators show which units are failing most frequently — the information the office needs when it
> justifies a replacement request rather than another repair.
>
> One characteristic of what you are seeing should be noted: reports are **scoped by unit**. An
> administrator of the Property Management Office and an administrator of the ITSO do not see the same
> queue, because they are not accountable for the same equipment."

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
> and the priority level, or reject it with a reason. Approving it moves the report straight to
> Ready for Assignment; no separate form is encoded."

**Do:** Approve the report, assigning department and priority.

### B.5 Assignment

**Do:** Open **Assign Technicians**.

> **Say:** "The system displays each technician together with their current workload, so the
> assignment is made on the basis of who is actually available rather than who is remembered
> first. Please select a technician."

**Do:** Assign, then display the technician's notification email.

> **Say:** "The technician has received a notification containing a direct link to this specific
> task."

### B.6 The supporting modules

**Do:** Open each of the following briefly, allowing no more than thirty seconds to each. All four are
the subject of items in Part III-A, and a respondent who has not seen them cannot rate them.

> **Say:** "Four further areas of the administrator's portal bear upon the items you will be rating.
>
> **Preventive Maintenance** schedules servicing in advance of failure, so that the office is not
> confined to responding to breakdowns.
>
> **Inventory** is the equipment register — the same register from which the reporter's search drew its
> results. The office maintains it by uploading its official Excel workbook, and each item may be
> issued a printable QR code.
>
> **Analytics** presents the failure patterns: the categories, and the individual units, which account
> for the greatest number of reports. This is the evidence that supports repair-or-replace and
> procurement decisions.
>
> **User Management** is where the office creates accounts, invites technicians, and resets passwords.
>
> Records may additionally be **exported to PDF and to Excel** on the official letterhead, so that the
> system produces documentation the office can file directly, rather than requiring records to be
> transcribed by hand."

### B.7 Verification, audit trail, and data safeguards

> **Say:** "When the technician has finished, the report returns here for verification, and only
> the office can close it. Two further matters are relevant to your evaluation.
>
> First, every action taken in the system — every approval, assignment, rejection and closure — is
> recorded with the user who performed it and the time at which it was performed, so that nothing can
> be altered without leaving a trace. Which brings me to backup and recovery."

> **Facilitator note — do not read aloud.** The browsable Audit Log page was removed in August 2026.
> Logging still occurs on every lifecycle action; there is simply no administrative viewer to open.
> State the audit trail as scripted above and **do not offer to display it.** If a respondent asks to
> see it, say plainly that the record is kept server-side and that this version provides no viewing
> page.

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
> installs on the home screen and opens like an ordinary mobile application.
>
> Your account is issued to you by the office; you sign in with your email address and a password,
> and repeated failed attempts are throttled."

**Do:** Hand over the handset with the technician portal open, and demonstrate **Add to Home
Screen** if it is not already installed.

### C.2 Receiving the task

**Do:** Open the task assigned in Segment B.

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

### C.4 The cost estimate

**Do:** Open the cost estimate worksheet.

> **Say:** "Where the repair carries a cost, you record the materials, the labour, and any
> miscellaneous charges, and the worksheet prints in the format the office uses. I should be precise
> about what this is: **it is an estimate prepared for the office to act upon. The system contains no
> budget approval process** — that decision rests with the office, outside the system."

*Facilitators: deliver the final sentence deliberately. Until the budget items identified in §2.2 have
been resolved on the printed instrument, it is the only thing that allows a respondent to answer them
honestly.*

### C.5 The completion report

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
| "Why a code by email rather than a password for reporters?" | A password is one more credential for an occasional user to keep and to lose. The code establishes possession of the mailbox at the moment of filing, and the browser is remembered for thirty days thereafter. |
| "Where is the budget request feature?" | It was withdrawn in July 2026 to align the system with the scope of this study. Technicians prepare a cost estimate, upon which the office acts outside the system. |
| "May I see the audit log?" | The record is kept server-side for every action, with the user and the timestamp. This version provides no browsable viewing page. |
| "Can the system serve the whole institution?" | The database is hosted and professionally managed, and the system has been exercised against report volumes substantially beyond the office's present load. |

---

## 9. Contingency Procedures

| Situation | Facilitator action |
|---|---|
| Connectivity fails mid-session | Continue using the prepared screenshots and narrate the sequence; record the incident on the session log |
| One-time code is delayed | Explain the three-minute expiry, request a resend after twenty-five seconds, and use the interval to answer questions. Should it still not arrive, transfer to the prepared reporter account |
| Respondent's address is not in the BEC directory, and no code is issued | Do not debate the roster in the respondent's presence. Transfer to the prepared reporter account and allow the respondent to perform every remaining step |
| Respondent becomes stuck on a step | Wait five seconds before intervening, then assist; record the difficulty, as it is a usability finding |
| Session overruns | Reduce Segment B to sign-in, approval, and assignment; omit the supporting modules and the backup demonstration, and state them verbally instead |
| Respondent declines to continue | Stop immediately, thank them, and record the withdrawal without further questions |

---

*Prepared as the demonstration protocol for the system evaluation component of the capstone study
"BEC Web-Based Defective Equipment Reporting Management System," Batangas Eastern Colleges —
Property Management Office, August 2026.*

*Revised 08 August 2026 against the running system. The revision records the reporter verification
code and the thirty-day remembered device, the restriction of the follow-up and satisfaction actions to
the reporter's own session, technician sign-in by email and password, the supporting administrator
modules, the withdrawal of the budget request module, and the removal of the audit log viewer.
Rehearsal script and segment allocation: `docs/EVALUATION_SPEAKER_NOTES.md`.*
