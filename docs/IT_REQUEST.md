# What to ask BEC IT for

Two things are needed from whoever administers Google Workspace and DNS for
`bec.edu.ph`. Copy the message below into an email and attach
`EMAIL_DELIVERABILITY.md`.

**Only the first request blocks the system.** The second matters for mail sent
outside the college, which this system rarely does.

---

## The message

> **Subject:** Request — PMO mailbox and email authentication for the Equipment Reporting System
>
> Good day,
>
> The Property Management Office's Equipment Reporting System is now running at
> **https://becpmo.online**. Students, faculty, technicians and PMO staff sign in
> with their BEC email address, and the system emails them a 6-digit verification
> code. It also emails updates as each repair progresses.
>
> We have a delivery problem and need your help with two items.
>
> ---
>
> ### 1. A mailbox for the system to send from — `pmo@bec.edu.ph`
>
> **The problem.** The system currently sends from a personal Gmail address while
> displaying the name "BEC PMO". Google reads that combination — an institutional
> sender name on an outside address, carrying a login code — as a phishing
> pattern, so the messages are being delivered to **Spam**. Users do not receive
> their sign-in codes and cannot use the system.
>
> **The fix.** If the system sends from a `bec.edu.ph` mailbox, the mail stays
> inside our own Workspace and is delivered normally.
>
> **What we need:**
>
> 1. A mailbox or Google group: **`pmo@bec.edu.ph`**
> 2. **2-Step Verification** switched on for that account
> 3. An **App Password** generated for it
>    (`myaccount.google.com` → Security → 2-Step Verification → App passwords)
>
> The App Password is the credential the system uses to send. A normal account
> password will not work — Google requires an App Password for this.
>
> We ask for a role mailbox rather than using a staff member's personal account
> because an App Password grants access to that person's whole mailbox, replies
> would go to them individually, and system mail would stop working whenever that
> person changes their password or leaves the office.
>
> ---
>
> ### 2. Three DNS records for `bec.edu.ph`
>
> These let mail from our domain reach recipients **outside** the college without
> being marked as spam. Not urgent, but currently none of them exist.
>
> | Record | What to do |
> |---|---|
> | **DKIM** | In Google Workspace Admin → Apps → Google Workspace → Gmail → **Authenticate email**, generate a 2048-bit key for `bec.edu.ph` and publish the `google._domainkey` TXT record |
> | **SPF** | Add `include:_spf.google.com` to the existing SPF record. Keep it as **one** `v=spf1` record — a domain may only have one |
> | **DMARC** | Add a TXT record at `_dmarc` with `p=none` (monitoring only, safe to start with) |
>
> The attached `EMAIL_DELIVERABILITY.md` has the exact values and step-by-step
> screens.
>
> **How to confirm it worked:** send a message from a `bec.edu.ph` account to any
> Gmail address, open it, choose **Show original**, and check that both
> **SPF: PASS** and **DKIM: PASS** appear.
>
> ---
>
> Item 1 is what unblocks the system. Item 2 improves delivery to addresses
> outside the college.
>
> Thank you,
> *(your name)*

---

## When IT replies

They should give you the **App Password** — 16 characters, shown once and never
retrievable again, so save it immediately.

Then point the system at the new mailbox:

```
powershell -ExecutionPolicy Bypass -File scripts\set_mail_password.ps1 -Account pmo@bec.edu.ph
```

Use the script rather than editing the settings by hand: the address appears in
about twenty places, one per role plus the defaults, and missing one leaves a
single role still mailing from the old account.

Then copy `data/system_settings.json` to the server and send yourself a code to
confirm it lands in the inbox rather than Spam.

## If IT is slow

Do not wait. Enable 2-Step Verification on your own `@bec.edu.ph` account,
generate an App Password, and point the system at that in the meantime — it is
still a college address, so the spam problem is solved the same way. Switch to
`pmo@bec.edu.ph` when it exists; it is one command.
