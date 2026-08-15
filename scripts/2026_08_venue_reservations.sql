-- 2026-08-15 — Venue reservations: the paper VRF, as a record.
--
-- Based on the PMO's "VENUE RESERVATION FORM" (BEC form, Rev. 00). The paper
-- copy is filled by the applicant, endorsed by their department head or
-- organisation adviser, approved by the Property Management Officer (or
-- disapproved by the School Administrator), assessed by Accounting, and closed
-- out by the Cashier with an OR number. Every one of those boxes is a column
-- here, so an approved reservation can be reprinted exactly as the form.
--
-- Two things the paper cannot do, which are the reason for the table:
--   • tell you the venue is already taken at that hour, and
--   • be found again next month without opening the folder.
--
-- Venue is stored as text rather than a foreign key to a venues table. The
-- bookable venues are not in this database — equipment.location holds 178
-- distinct *equipment* locations (mostly offices), not a room list anyone
-- curated — so inventing one would be inventing data. The form supplies the
-- string from a datalist of venues already used plus known locations, which
-- keeps the value consistent enough for the overlap check below.

CREATE TABLE IF NOT EXISTS public.venue_reservations (
    id                  serial PRIMARY KEY,

    -- Reference numbers off the top of the form. vrf_no is assigned when the
    -- PMO approves, mirroring how the paper pad is numbered on release.
    vrf_no              varchar(30) UNIQUE,
    cf_no               varchar(30),

    -- Applicant. user_id is null for a walk-in filed at the PMO counter, which
    -- is why the name/email/phone are stored on the row rather than joined.
    applicant_user_id   varchar(60),
    applicant_name      varchar(160) NOT NULL,
    applicant_email     varchar(160),
    applicant_phone     varchar(40),
    department_org      varchar(160) NOT NULL,

    venue               varchar(200) NOT NULL,

    -- The form's tick boxes; 'others' carries its own free-text line.
    nature              varchar(40)  NOT NULL DEFAULT 'meeting',
    nature_other        varchar(160),

    starts_at           timestamptz  NOT NULL,
    ends_at             timestamptz  NOT NULL,
    participants        integer,
    description         text,

    -- The handwritten "Materials:" list at the foot of the form.
    -- [{"item": "Plastic chairs", "qty": 40}, …]
    materials           jsonb        NOT NULL DEFAULT '[]'::jsonb,

    -- Signature over printed name — dept head / organisation adviser.
    adviser_name        varchar(160),
    adviser_endorsed_at timestamptz,

    -- submitted → endorsed → approved | disapproved, plus cancelled / completed
    status              varchar(30)  NOT NULL DEFAULT 'submitted',

    approved_by         varchar(60),
    approved_at         timestamptz,
    disapproved_by      varchar(60),
    disapproved_at      timestamptz,
    decision_remarks    text,

    -- Accounting half of the form.
    assessment_amount   numeric(12,2),
    assessment_by       varchar(60),
    payment_type        varchar(20),          -- 'down' | 'full'
    amount_paid         numeric(12,2),
    or_no               varchar(40),
    or_date             date,
    cashier_name        varchar(120),

    created_by          varchar(60),
    created_at          timestamptz  NOT NULL DEFAULT now(),
    updated_at          timestamptz  NOT NULL DEFAULT now(),

    -- An end before its start is not a booking; refuse it at the table rather
    -- than trusting every future caller to check.
    CONSTRAINT venue_reservations_time_order CHECK (ends_at > starts_at)
);

CREATE INDEX IF NOT EXISTS venue_reservations_status_idx    ON public.venue_reservations (status);
CREATE INDEX IF NOT EXISTS venue_reservations_starts_idx    ON public.venue_reservations (starts_at);
CREATE INDEX IF NOT EXISTS venue_reservations_venue_idx     ON public.venue_reservations (lower(venue));
CREATE INDEX IF NOT EXISTS venue_reservations_applicant_idx ON public.venue_reservations (applicant_user_id);

-- Double-booking is the one rule the paper form cannot enforce, so it is
-- enforced here rather than only in PHP: no two reservations that still hold
-- the room (submitted, endorsed or approved) may overlap in the same venue.
-- Touching ends (one ends 10:00, the next starts 10:00) do not overlap, which
-- is why the range is [) rather than closed.
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE public.venue_reservations
    DROP CONSTRAINT IF EXISTS venue_reservations_no_overlap;

ALTER TABLE public.venue_reservations
    ADD CONSTRAINT venue_reservations_no_overlap
    EXCLUDE USING gist (
        lower(venue) WITH =,
        tstzrange(starts_at, ends_at, '[)') WITH &&
    )
    WHERE (status IN ('submitted', 'endorsed', 'approved'));

COMMENT ON TABLE public.venue_reservations IS
    'Venue Reservation Form (VRF) records — applicant request through PMO approval, assessment and payment.';
