# Evaluation Speaker Notes

**Web-Based Defective Equipment Reporting and Maintenance Management System
for Batangas Eastern Colleges — Property Management Office**

| | |
|---|---|
| **Occasion** | Guided system demonstration preceding administration of the evaluation instrument |
| **Respondent groups** | Reporters (students, faculty, staff) · PMO and ITSO administrators · Maintenance technicians |
| **Presenters** | Casala, Cyril B. · Colegio, Jaymiel M. · De Castro, Jhan Mark C. · Sumague, Shane R. · Tanyag, Mark Roi S. · Villar, Francis Miguel Z. |
| **Programme** | Fourth Year, Bachelor of Science in Information Systems |
| **Adviser** | Michelle A. Dino, MIT |
| **Running time** | 35 minutes of narration, plus questionnaire time |

> **This is the script the six of you rehearse from.** Its companion,
> `docs/EVALUATION_DEMO_SCRIPT.md`, is the formal protocol for the thesis appendix — same session,
> written as methodology rather than as speaking parts. Where the two differ in wording, follow this
> one; where they differ in fact, neither should — both were corrected together on 08 August 2026.

---

## How to use this document

Lines under **Say** are written to be spoken as they stand. They are deliberately formal: the
respondents are members of the faculty and staff of the institution, and the session is part of a
research protocol. Put them in your own voice if you prefer, but keep the **bolded phrases** — those
are the statements the questionnaire items rest on.

Lines under **Do** are the matching on-screen actions. Lines under **Hand over** are the exact
transition sentence: say it, then step aside so the next presenter takes the floor cleanly.

References like *(→ II-A #3)* point to the instrument item that the action in front of them is
evidence for. They are there so that nothing on the questionnaire is rated blind.

One discipline matters more than any wording here: **when a respondent is using the system, stop
talking.** Their hesitation is the usability data this study exists to collect. Wait five full
seconds before offering help, and have the documenter write down where they paused.

---

## Speaking assignment

| Order | Presenter | Segment | Minutes |
|---|---|---|---|
| 1 | **Casala, Cyril B.** | Opening, purpose of the session, informed consent | 3 |
| 2 | **Colegio, Jaymiel M.** | Background, objectives, and system overview | 3 |
| 3 | **Sumague, Shane R.** | Segment A — the Reporter portal | 8 |
| 4 | **De Castro, Jhan Mark C.** | Segment B — the Administrator (PMO/ITSO) portal | 10 |
| 5 | **Tanyag, Mark Roi S.** | Segment C — the Technician portal | 7 |
| 6 | **Villar, Francis Miguel Z.** | Closing, orientation to the instrument, collection | 4 |

The assignment is a recommendation, not a constraint. If you swap parts, swap them in this table
first, so that everyone rehearses against one authoritative version.

### Duties while you are not speaking

Every presenter holds one standing duty for the whole session, including during their own segment.

| Presenter | Standing duty |
|---|---|
| Casala | **Timekeeper.** Signal the current speaker at the two-minute and thirty-second marks. |
| Colegio | **Documenter.** Record every hesitation, error message and question, with the step it happened on. This is data, not housekeeping. |
| Sumague | **Mailbox operator.** Keep the reporter mailbox tab ready and display each notification the moment it arrives. |
| De Castro | **Technical backstop.** Take any question the current speaker cannot answer, and handle anything that goes wrong on screen. |
| Tanyag | **Device handler.** Charge, unlock and pass the handset; make sure the technician portal is already open before it changes hands. |
| Villar | **Instrument custodian.** Consent forms, questionnaires, pens and the session log; verify no item is left blank on collection. |

---

## Before the session

### Shared checklist

- [ ] Apache running; the landing page loads without error at the demonstration URL
- [ ] Internet verified — the database, the email notifications and the assistant all require it
- [ ] Inventory populated, so the equipment and location searches return real results
- [ ] Rehearsal and test reports cleared, so the dashboards show a clean, representative state
- [ ] Reporter mailbox open in its own tab; administrator mailbox reachable for the one-time code
- [ ] Handset charged, connected, already signed in to the technician portal
- [ ] Consent forms, questionnaires and pens counted against the number of respondents
- [ ] Fallback screenshots of every step available offline, in case the venue connection fails

### The one requirement that will stop the session if it is missed

**The reporter portal sends a six-digit code to the respondent's own mailbox before it will let them
in.** The code goes only to an address that appears in the official BEC directory. Two consequences,
both to be settled the day before:

1. Any respondent who will file a report during the walkthrough needs a **@bec.edu.ph address that
   is already in the directory**, and must be able to open that mailbox in the room. Confirm this
   respondent by respondent.
2. If they cannot open their mailbox, do not improvise. Fall back to the **prepared reporter
   account** whose inbox is already on the facilitator's screen, and let the respondent drive every
   other step of the form.

A browser that has verified once is **remembered for thirty days**, so on your own rehearsal laptop
the portal may greet you with a one-tap *Continue* and never show the code. Segment A carries a line
for each case.

### Three things to settle with the adviser beforehand

1. **The budget items have no feature behind them any more.** The budget request module was removed
   in July 2026 to match the study's scope. Instrument items **II-E #4**, **III-A #4** and
   **III-B #4** refer to it. A technician can file a **cost estimate**; there is no budget approval
   workflow in the system. Decide whether to reword, strike, or mark them Not Applicable. Do not
   leave a respondent rating a feature that does not exist.
2. **There is no Audit Log page to open.** Every lifecycle action is still recorded server-side with
   the user and the timestamp, but the browsable viewer was removed in August 2026. State the audit
   trail verbally, as scripted in B.7, and do not offer to display it.
3. **Check the item numbers and the scale against your final printed instrument.** The references in
   this script came from an earlier working copy which tagged *III-A #4* twice — once for the budget
   item and once for preventive maintenance and inventory. Reconcile it once, here, rather than in
   front of a respondent. These notes assume a four-point scale, 4 Strongly Agree to 1 Strongly
   Disagree; if your copy differs, correct Segment 6 before you rehearse.

---

# Segment 1 · Opening and Informed Consent

**Casala, Cyril B. — 3 minutes**

> **Your goal:** the respondent understands why they are here, that they are evaluating the system
> rather than being evaluated themselves, and that taking part is voluntary. Nothing is demonstrated
> until the consent form is signed.

**Do:** Stand where everyone can see you. The screen shows the landing page but is not yet discussed.

> **Say:** "Good morning, and thank you for giving us your time. My name is Cyril Casala, and with me
> are Jaymiel Colegio, Jhan Mark De Castro, Shane Sumague, Mark Roi Tanyag and Francis Miguel Villar.
> We are fourth year Bachelor of Science in Information Systems students of Batangas Eastern Colleges,
> working under our adviser, Ms. Michelle Dino.
>
> You have been invited as a respondent to the evaluation of our capstone system, the **Web-Based
> Defective Equipment Reporting and Maintenance Management System**, developed for the Property
> Management Office.
>
> Allow me to explain how the next forty minutes will run. Each of us will present the portion of the
> system that belongs to one role — the person who reports a defect, the office that acts on it, and
> the technician who carries out the repair. Where the portion concerns your own role, we will ask you
> to operate the system yourself rather than watch us do it. Afterwards we will ask you to complete a
> short questionnaire.
>
> Four points before we begin, and they are the same terms printed on the consent form in front of
> you. **Your participation is entirely voluntary, and you may withdraw at any point, without giving a
> reason and without any penalty.** **We are evaluating the system, not you** — there are no correct
> or incorrect answers, and any difficulty you meet is a finding about our work, not about you.
> **Your responses are confidential**, and they stay **anonymous unless you choose to write your
> name.** And **you are welcome to interrupt us with a question at any moment** — please do not wait
> for the end.
>
> May I ask you to read the consent form, and to sign it if you are willing to proceed?"

**Do:** Distribute the consent forms. Wait for the signature. Do not begin the demonstration until
every form is signed and collected by Villar.

> **Hand over:** "Thank you. Jaymiel will now explain what the system was built to solve."

---

# Segment 2 · Background, Objectives and System Overview

**Colegio, Jaymiel M. — 3 minutes**

> **Your goal:** the respondent can state, in one sentence, the problem this system addresses.
> Everything shown afterwards should read as an answer to the problem you describe here. Describe the
> present process without disparaging the office or its staff.

**Do:** Bring up the landing page.

> **Say:** "Thank you, Cyril. Before we open the system, let me describe the situation it was built
> for, because that is what we are asking you to judge it against.
>
> At present a defective item of equipment at Batangas Eastern Colleges is reported informally —
> verbally, on a written note, or through a chat message. The consequence is not that the office is
> inattentive; it is that **the office has no single record of what was reported, who acted on it and
> how long the repair took.** A reporter who has filed a report has no way of knowing whether it
> reached anyone. The office cannot readily show which unit of equipment has failed most often when it
> needs to justify a replacement. And none of it leaves documentation that can be filed.
>
> Our objective was to place that whole process on **one platform, from the moment a defect is
> noticed until the repair has been verified and closed** — with the reporter kept informed at every
> stage, and every action recorded.
>
> What you are looking at is the landing page, the single entry point for the institution. From here
> each audience reaches its own portal: **the reporter**, who files the report; **the Property
> Management Office**, which reviews, approves and assigns it; and **the technician**, who carries out
> the repair in the field. A fourth area is public — anyone may track a report or read the board of
> published reports without signing in at all.
>
> A report moves through a fixed sequence: **reported, received by the office, approved, assigned,
> in progress, completed, verified, closed** — with further stages for a repair held for materials, or
> an item recommended for replacement. Every one of those transitions notifies the people it concerns,
> both inside the system and by email.
>
> You will now see that sequence performed end to end, beginning with the reporter."

> **Hand over:** "Shane will take you through the reporter's portal."

---

# Segment 3 · Segment A — The Reporter Portal

**Sumague, Shane R. — 8 minutes**
*Primary audience: students, faculty and administrative staff*

> **Your goal:** the respondent files a real report, unaided, and receives a ticket number and a
> confirmation email. This is the segment where silence matters most.

### A.1 Framing the role

**Do:** Open the reporter portal, on the handset as well as the screen if you can.

> **Say:** "In this part of the system you take the role of the **reporter** — the person who first
> notices that something is broken. Our requirement here was that filing a report should need **no
> permanent account, no training and no more than a few minutes** *(→ II-A #6)*, because a reporter
> who finds the process troublesome simply will not file at all.
>
> Before the form itself, the page tells you plainly what to prepare — the equipment, its location and
> a short description of the problem — and what will happen after you submit. It also carries the data
> privacy notice: the office collects your name, your institutional email address and the details of
> your report, for maintenance and record-keeping, under the **Data Privacy Act of 2012**."

### A.2 Signing in

> **Say:** "May I ask you to enter your full name and your official BEC email address, and to tick the
> privacy consent.
>
> The system will then send a **six-digit code to that mailbox, valid for three minutes**. This is
> what confirms that the person filing the report actually holds the address, so the office can rely
> on a report having come from a real member of the institution — while still sparing you an account
> and a password to maintain *(→ II-E #2)*. Notice too that the message on screen is worded the same
> way whether or not the address is recognised, so the page cannot be used to find out who is on the
> school's roster."

**Do:** Let the respondent fetch the code from their own mailbox and enter it.

> *If the browser is already remembered and offers a one-tap Continue instead:*
> **Say:** "This browser verified this address within the last thirty days, so the system recognises
> it and does not ask again. On a new device, or after thirty days, the code step returns."

> **Say:** "You are then asked for your department, your course or programme and your year level, with
> a contact number if you care to give one. The office asked for these because when it plans its
> maintenance it needs to know **which unit of the school a report came from**."

### A.3 Identifying the equipment

> **Say:** "Now, please describe what is broken. Begin typing the name of the equipment — the field
> **searches the office's inventory as you type**, so you select the actual registered unit instead of
> typing a description of it. When you select it the category fills in by itself, and if the unit
> carries a visible asset tag you may add it. The location behaves the same way: type the room or the
> building and choose from the list."

**Do:** Let the respondent search and select unassisted. Say nothing for five seconds if they pause.
Colegio records where.

> **Say:** "Selecting from the inventory rather than typing free text is what keeps the office's
> records consistent. It is exactly what lets the system tell the office, later, **which specific unit
> has failed most often**."

### A.4 Completing and submitting

> **Say:** "Please describe the defect in your own words — one or two sentences will do — give the date
> and time you noticed it, and indicate whether the equipment is **still usable, partially usable or
> completely broken**. That last answer is what helps the office judge how urgent the repair is. Attach
> a photograph if the fault can be seen, then submit."

**Do:** Let the respondent complete the form unaided.

> *If a required field is left blank, do not correct it — let the validation message appear.*
> **Say:** "The system has told you exactly which field it needs, and it has kept everything you had
> already typed. You will be asked to rate that behaviour under error recovery *(→ UX #4)*."

> *If the duplicate warning appears:*
> **Say:** "The system is telling you this unit **already has an open report**, and offering you the
> existing ticket to follow instead. You may still proceed if you believe yours is a different fault.
> This is what stops the office receiving one failure three times from three people."

> **Say:** "You have been issued a **ticket number** *(→ II-A #3)*, and a confirmation has been sent
> to your address. May I show you the message that has just arrived?"

**Do:** Sumague displays the mailbox and the confirmation email *(→ UX #5)*.

### A.5 Tracking

**Do:** Open **Track Report** and enter the ticket number.

> **Say:** "This is the tracking page, and **reading it requires no login at all** — the ticket number
> is enough. You can see the stage your report has reached, with the date of each step.
>
> Two actions on this page are reserved to you as the reporter, and need your signed-in session: asking
> the office a follow-up question, and the last step of the workflow — when the repair is finished,
> this page asks you **whether the problem was genuinely resolved**, and your answer is recorded as
> feedback to the office *(→ UX #6)*. That verdict can only be given once, which is why it is not left
> open to anyone holding the ticket number.
>
> One last point before I hand over. Please open the same page on your phone." **Do:** hand over the
> handset. "It is the same system, fitting itself to the screen — there is nothing to install and no
> separate mobile application for reporters *(→ Portability #1, #5)*.
>
> That is the whole of the reporter's role. Are there any questions before we move on?"

> **Hand over:** "Jhan Mark will now show you what the office sees when that report arrives."

---

# Segment 4 · Segment B — The Administrator Portal

**De Castro, Jhan Mark C. — 10 minutes**
*Primary audience: PMO personnel and ITSO staff*

> **Your goal:** the respondent watches the report from Segment A travel from arrival to assignment,
> and grasps the three commitments the office asked for — secure access, traceable decisions, nothing
> lost. This is the longest segment and it draws the most questions.

### B.1 Framing the role

> **Say:** "You now take the role of the **administrator** — the officer who decides what happens to
> each report. This portal was built around three requirements the office raised with us during
> consultation: that **access be secure**, that **every decision be traceable**, and that **nothing be
> lost between the report and the repair**.
>
> I will say at the outset that this portal is **designed for the desk, not the phone.** That was a
> deliberate decision taken with the office, whose work on these records is done at a workstation."

### B.2 Secure sign-in

**Do:** Open the administrator login and enter the credentials.

> **Say:** "Administrator access requires a password **and** a one-time code sent to the registered
> institutional mailbox. The code **expires after three minutes**, and repeated attempts against the
> login are rate-limited.
>
> The consequence is the one to hold on to when you reach the security items: **a stolen password by
> itself is not enough to enter this system** *(→ II-E #2)*."

**Do:** Retrieve the code and complete the sign-in.

### B.3 The dashboard

> **Say:** "This is the dashboard. The cards along the top summarise the present workload — reports
> received, in progress, completed, and those **approaching or already past their service deadline**,
> so an overdue case cannot sit unnoticed *(→ III-A #1)*.
>
> Below them are the equipment health indicators, showing which units fail most frequently. That is
> the figure the office needs when it argues a unit should be **replaced rather than repaired once
> again**.
>
> One further point about what you are seeing: reports are **scoped by unit**. A Property Management
> Office administrator and an ITSO administrator do not see the same queue, because they are not
> responsible for the same equipment."

### B.4 Reviewing and approving

**Do:** Open **Defect Reports** and select the report filed in Segment A.

> **Say:** "Here is the report submitted a few minutes ago, with the reporter's details, the equipment
> record and the photograph. The first action is to acknowledge it."

**Do:** Click **Mark as Received**. Sumague displays the notification arriving in the reporter's
mailbox.

> **Say:** "The reporter has now been told automatically that the office has seen the report. This one
> step answers **the most common complaint about the present process** — that a reporter never learns
> whether their report reached anyone at all.
>
> The second action is the decision itself. The office either **approves** the report, setting the
> responsible department and the priority, or **rejects it with a reason**. Either way the outcome is
> on record. Approving it sends the report straight to Ready for Assignment; there is no second form to
> encode *(→ III-A #2)*."

**Do:** Approve, setting department and priority.

### B.5 Assignment

**Do:** Open **Assign Technicians**.

> **Say:** "Each technician is shown with **their current workload**, so the assignment is made on the
> basis of who is genuinely free rather than who comes to mind first *(→ III-A #3)*. May I ask you to
> choose a technician."

**Do:** Let the respondent assign, then display the technician's notification email.

> **Say:** "The technician has received a notification with **a direct link to this exact task**. The
> priority you set a moment ago has also fixed the service deadline the technician now works against."

### B.6 The supporting modules

**Do:** Open each one briefly — no more than thirty seconds apiece.

> **Say:** "Four further areas bear on the items you will be rating.
>
> **Preventive Maintenance** schedules servicing before a failure happens, so the office is not
> confined to reacting to breakdowns.
>
> **Inventory** is the equipment register — the same register the reporter's search drew on. The office
> maintains it by uploading its official Excel workbook, and each item can be given a printable QR
> code.
>
> **Analytics** shows the failure patterns: which categories, and which individual units, account for
> the most reports. That is what supports repair-or-replace and procurement decisions
> *(→ III-A #7)*.
>
> And **reports export to PDF and Excel** on the official letterhead *(→ III-A #5)*, so the system
> produces documentation the office can actually file, instead of requiring records to be copied out by
> hand. **User Management**, which I will not open, is where the office creates accounts, invites
> technicians and resets passwords *(→ III-A #8)*."

### B.7 Data safeguards

> **Say:** "Two matters of assurance before I close.
>
> First, **every action in this system is recorded** — every approval, assignment, rejection and
> closure, with the user who performed it and the time it was performed. Nothing changes hands without
> leaving a trace *(→ III-A #5)*.
>
> Second, data recovery." **Do:** Open **Backup and Recovery**. "The system takes an **automatic daily
> backup of every table**, and a backup can also be taken on demand. Should records ever be deleted in
> error they can be restored from any of these snapshots — and the restore itself takes a safety
> snapshot first, before it changes anything.
>
> Are there any questions on the administrator's functions?"

> **Hand over:** "Mark Roi will now take you to the other end of the workflow — the technician in the
> field."

---

# Segment 5 · Segment C — The Technician Portal

**Tanyag, Mark Roi S. — 7 minutes**
*Primary audience: maintenance technicians*

> **Your goal:** the respondent completes the task assigned in Segment B, on the handset, in their own
> hands. Pass the phone early and let them hold it for the whole segment.

### C.1 Framing the role

**Do:** Hand over the handset with the technician portal already open.

> **Say:** "You take the role of the **technician** who carries out the repair. Because your work is
> done in the field and not at a desk, this portal was built **for the phone first**.
>
> Your account is issued to you by the office, and you sign in with your email and password. If I may
> show you one thing before we open the task —" **Do:** demonstrate **Add to Home Screen** if it is not
> already installed. "— it installs onto the home screen and opens like an ordinary mobile
> application, without going through an app store *(→ Portability #4)*."

### C.2 Receiving the task

**Do:** Open the task assigned in Segment B.

> **Say:** "This is the task assigned to you a few minutes ago. It carries the equipment, its location,
> the reporter's own description and the photograph, so **you know what you are attending to before you
> leave the shop** *(→ III-B #2)*.
>
> The bar across the top is the workflow tracker: received, in progress, waiting for materials,
> completed, verified *(→ III-B #8)*. The chip beside it shows **the time remaining before the service
> deadline**, set by the priority the office assigned *(→ III-B #6)*.
>
> Please tap **Receive Task** to acknowledge it."

**Do:** Let the respondent tap.

> **Say:** "The office can now see that you have taken the job. When you begin work, tap **Start
> Repair**. That starts the **repair timer**, which records how long the work actually took — and that
> figure is what lets the office measure its service performance instead of estimating it
> *(→ III-B #3)*."

**Do:** Let the respondent start the repair.

> **Say:** "If the repair cannot proceed — a part is unavailable, or the unit is beyond economical
> repair — you are not obliged to complete it. You may place it **on hold for materials**, or
> **recommend replacement** to the office. The report is never abandoned; it moves to a state the
> office can see and act on."

### C.3 Cost estimate

**Do:** Open the cost estimate worksheet.

> **Say:** "Where a repair carries a cost you record the materials, the labour and any miscellaneous
> charges, and the worksheet prints in the format the office uses. **This is an estimate for the office
> to act upon; the system does not contain a budget approval process** — that decision stays with the
> office, outside the system *(→ III-B #4)*."

*(Read the pre-session note on the budget items before this segment. If the adviser has not resolved
them on the instrument, deliver that sentence slowly — it is what a respondent needs in order to
answer those items honestly.)*

### C.4 The completion report

**Do:** Open the completion form.

> **Say:** "When the work is done you file the completion report: **what you found, what you did, the
> parts you consumed, and photographs of the unit before, during and after the repair**
> *(→ III-B #7)*.
>
> The required fields are enforced —" **Do:** attempt to submit with one field blank, once. "— the form
> will not submit while any of them is empty. That is what guarantees the office receives a **complete
> service record every time**, rather than one that has to be chased afterwards."

**Do:** Let the respondent complete and submit the report.

> **Say:** "The task has returned to the office for verification, and the reporter has already been
> told the repair is finished *(→ III-B #1)*. Your part of the workflow is complete.
>
> Are there any questions about the technician's functions?"

> **Hand over:** "Francis will now close the demonstration and hand you the questionnaire."

---

# Segment 6 · Closing and Administration of the Instrument

**Villar, Francis Miguel Z. — 4 minutes**

> **Your goal:** the respondent sees the whole loop as one thing, understands the scale and which parts
> apply to them, and feels genuinely permitted to be critical. Then you leave them alone to answer.

**Do:** Return the screen to the landing page.

> **Say:** "That completes the demonstration, and I should like to draw it together in one sentence,
> because it is the whole system in one line.
>
> **A reporter files a report against a real registered unit in a few minutes; the office receives it
> immediately, approves it, and assigns it on the basis of a technician's actual workload; the
> technician records the repair from the field, with photographs; the office verifies and closes it; the
> reporter is informed at every stage and is asked at the end whether the problem was truly resolved —
> and every one of those actions is recorded with the person who performed it and the time it was
> performed.**
>
> I will now hand you the evaluation questionnaire, and let me explain how it is arranged.
>
> It uses a **four-point scale: four is Strongly Agree, three is Agree, two is Disagree, one is
> Strongly Disagree.** There is no midpoint, so each item asks you to come down on one side.
>
> **Part Two** asks you to rate the system on the quality characteristics — its functionality,
> reliability, speed, user interface, user experience, security, maintainability and portability. Every
> respondent answers Part Two.
>
> **Part Three** is specific to a role. Section A is for administrators, Section B is for technicians.
> **If you are here as a reporter you skip Part Three entirely** and go straight on.
>
> **Part Four** asks for your overall satisfaction, and the form ends with an open space for your
> comments and recommendations. I would ask you not to leave that space empty.
>
> One request, and I mean it sincerely. **Please answer candidly, and tell us where the system is
> weak.** Uniformly favourable ratings are worth far less to this study than one specific criticism,
> because a criticism is the only thing that lets us correct the system before it reaches the office.
> You are not being discourteous by marking a low score; you are doing exactly what we asked you here
> to do.
>
> Please take as much time as you need, and tell any of us if an item is unclear. On behalf of the
> group — Cyril, Jaymiel, Jhan Mark, Shane, Mark Roi and myself — thank you for your time and your
> help."

**Do:** Distribute the instrument. **Step away, and do not remain within reading distance while it is
being completed.** On collection, check that no item was skipped, note the respondent's group and the
session date on the session log, and thank them again.

> **For PMO and ITSO respondents only:** they additionally answer the **Open-Ended Questionnaire** —
> fourteen items across five sections. Hand it over with the rating sheet and allow the extra time.

---

## Coverage check

Read this before Villar begins Segment 6. Anything not ticked is something the respondent is about to
rate without having seen it — either show it quickly, or state it plainly in the closing.

| Instrument section | Covered by |
|---|---|
| II-A · Functional Suitability | The full loop, filed through to verified · ticket number · tracking without an account |
| II-B · Reliability | Stated in B.7 — nothing lost, automatic daily backup, records restorable |
| II-C · Performance Efficiency | Let the respondent click for themselves; do not narrate over a slow page — the wait is the data |
| II-D · User Interface | All four surfaces seen: landing, reporter, administrator, technician · consistent colours, icons and wording |
| II-D · User Experience | A validation message · a notification email arriving · the "was this resolved?" question · comparison with the paper process |
| II-E · Security | Emailed code for reporter and administrator both · three-minute expiry · role-separated portals · privacy notice · recorded actions |
| II-F · Maintainability | Show one clear error message; state that the modules operate independently of one another |
| II-G · Portability | Reporter page on phone and desktop · any browser, nothing to install · technician portal installs to the home screen |
| III-A · Administrators | Dashboard · review and approval · assignment by workload · preventive maintenance · inventory · analytics · exports · user management · backup and recovery |
| III-B · Technicians | Task notification · repair workspace · status changes · cost estimate · service deadline · photographic documentation |
| IV · Satisfaction | The closing summary in Segment 6 |

---

## Anticipated questions

Answer in one or two sentences and return to the script. The presenter named takes the question;
De Castro takes anything not on this list.

| Question | Answer | Owner |
|---|---|---|
| "Is my personal information safe?" | Only your name, your institutional email and the report itself are stored. Access is restricted by role, and every action is recorded. | Casala |
| "What if the equipment is not in the list?" | Choose the closest match and say so in the description; the office corrects the record when it reviews the report. | Sumague |
| "What if the internet goes down?" | Filing needs a connection. The technician's last opened task stays on the handset, and nothing already submitted is lost. | Tanyag |
| "Who can delete a report?" | No one. A report is rejected or closed with a recorded reason; it stays on file either way. | De Castro |
| "Does this replace our existing forms?" | No — it produces the same records as PDF and Excel on the official letterhead, so existing filing requirements are still met. | De Castro |
| "What if an email fails to send?" | The in-system notification always fires; email is the secondary channel, and a failed send is logged without interrupting the workflow. | Colegio |
| "Where is the budget request feature?" | It was removed in July 2026 to match the scope of this study. Technicians file a cost estimate and the office acts on it outside the system. | Tanyag |
| "Can it handle the whole school?" | The database is hosted and managed, and the system has been tested against report volumes well beyond the office's present load. | De Castro |
| "Can I see the audit log?" | The record is kept server-side for every action; there is no browsable viewer in this version, and we will not pretend otherwise. | De Castro |
| "Why an emailed code and not a password?" | A password is one more thing for a reporter to keep and lose. The code proves you hold the mailbox at the moment you file, and the browser remembers you for thirty days afterwards. | Sumague |

---

## Contingencies

| Situation | Action |
|---|---|
| The connection fails mid-session | Continue from the prepared screenshots and keep narrating; Colegio records the incident on the session log |
| A one-time code does not arrive | Explain the three-minute expiry, request a resend after twenty-five seconds, and take questions while waiting. If it still does not arrive, switch to the prepared reporter account |
| The respondent's email is not in the directory | Do not debate it in the room. Move to the prepared reporter account and let the respondent drive every other step |
| The respondent becomes stuck | Wait five seconds, then help — and write down where. That is a usability finding, not an interruption |
| The session overruns | Cut Segment B to sign-in, approval and assignment; omit preventive maintenance, analytics and backup, and state them verbally instead |
| A presenter loses their place | The timekeeper reads the next **Do** line aloud. Do not restart the segment |
| The respondent asks to stop | Stop immediately, thank them, and record the withdrawal without asking why |

---

## Podium cards

One block per presenter — the whole part on a single card, for the moment you are standing up and
cannot read paragraphs. Print this page separately and cut along the rules.

**1 · Casala — Opening (3 min)**
Names of all six · fourth year BSIS · adviser Ms. Dino · system name · how the session runs · voluntary
and may withdraw · evaluating the system not you · confidential, anonymous unless named · interrupt any
time · **collect signatures before anything is shown** → *"Jaymiel will now explain what the system was
built to solve."*

**2 · Colegio — Overview (3 min)**
Reported verbally, by note, by chat · no single record of what, who, how long · reporter never learns it
arrived · no evidence for replacement · no filable documentation → one platform, defect to verified
close · four audiences from one landing page · the eight stages · every transition notifies →
*"Shane will take you through the reporter's portal."*

**3 · Sumague — Reporter (8 min)**
No account, no training, few minutes · privacy notice, RA 10173 · name + BEC email + consent →
**six-digit code, three minutes** (or one-tap if remembered 30 days) · department/course/year ·
**equipment searches the inventory as you type** · location the same · still usable / partially /
completely broken · photo · *(be silent — count five)* · validation message if blank · duplicate warning ·
**ticket number** · confirmation email on screen · Track Report needs no login, **follow-up and the
one-time resolved verdict need the reporter's session** · same page on the phone →
*"Jhan Mark will now show you what the office sees."*

**4 · De Castro — Administrator (10 min)**
Secure access, traceable decisions, nothing lost · desk not phone, by design · password **plus** emailed
code, three minutes, rate-limited · dashboard cards incl. **overdue** · equipment health justifies
replacement · queues **scoped PMO vs ITSO** · open the report · **Mark as Received** → show the reporter's
mail · **Approve** with department + priority (or reject with a reason) · **Assign** on shown workload →
technician's mail with a direct link · thirty seconds each: preventive, inventory, analytics, exports,
user management · **every action recorded, user and timestamp — no viewer to open** · **Backup and
Recovery**, daily automatic, restore takes a safety snapshot first →
*"Mark Roi will now take you to the technician in the field."*

**5 · Tanyag — Technician (7 min)**
Phone first · account issued by the office, email + password · **Add to Home Screen** · task carries
equipment, location, description, photo · stepper · **deadline chip** · **Receive Task** · **Start
Repair** starts the timer · hold for materials / recommend replacement — never abandoned · cost estimate
**is an estimate, there is no budget approval in the system** · completion report: found, did, parts,
**before / during / after photos** · show the blocked submit once →
*"Francis will now close and hand you the questionnaire."*

**6 · Villar — Closing (4 min)**
The one-sentence loop · **four-point scale, 4 Strongly Agree to 1 Strongly Disagree, no midpoint** ·
**Part Two everyone** · **Part Three by role — reporters skip it** · Part Four satisfaction · do not leave
the comments box empty · **ask for candour, a criticism is worth more than a high score** · thank them by
all six names · **step out of reading distance** · check for skipped items · log group and date.

---

*Corrected 08 August 2026 against the running system: the reporter sign-in code, the owner-only
satisfaction verdict, technician sign-in by email and password, the removal of the budget module, and
the removal of the audit log viewer. Companion to `docs/EVALUATION_DEMO_SCRIPT.md`, which carries the
same session as formal methodology.*
