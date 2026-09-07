# Open decisions

Questions where two people the project answers to have given conflicting
guidance, and the code has had to do *something* in the meantime.

Each entry says what is implemented right now, who disagrees, and what would
have to change if the decision goes the other way. Nothing here is settled —
an entry is closed only when the person named as the decider has ruled and
that ruling is written down.

---

## 1. Who may see consultation details

**Status:** OPEN — awaiting Ma'am Nanette
**Raised:** 2026-09-04 (nurse interview, transcript dated Aug 19 consultation)
**Decider:** Ma'am Nanette
**Touches:** `App\Support\ConsultationVisibility`, the class adviser's student
profile (Consultation Log tab), `ConsultationPrivacyTest`

### The conflict

Two authorities on this project have given opposite instructions.

**Ma'am Nanette (project adviser)** — a class adviser should see only the
**date and time** of a clinic visit. Not the complaint, not the diagnosis, not
the treatment. The reasoning is minimum-necessary: a teacher needs to know a
pupil was out of class; they do not need the clinical narrative to do their
job, and handing it to them widens who holds a child's medical information.

**The school nurse (interviewed stakeholder)** — a class adviser should see
the **full consultation record**. Her reasoning: the adviser is the
"second parent sa school", they are the ones with direct contact to the
parents, and without knowing what actually happened they cannot inform a
parent properly or answer a parent's question when they are asked one.

Both are coherent. They are also incompatible, and this is not a UI
preference — it decides who in a school holds a named child's medical
information.

### What is implemented right now

**Ma'am Nanette's version.** The adviser's payload carries the visit and
nothing clinical:

- `ConsultationVisibility::DETAIL_ROLES` is `['school_nurse', 'clinic_staff']`.
- For every other role, `present()` returns the timestamp, the consultation id
  and the shared-photo count — the `condition`, `treatment_given`, `status`
  and `grade_section` keys are never in the array at all, so no template can
  print them by accident.
- The adviser's Consultation Log tab shows date and time under a notice that
  says why the rest is withheld.
- `ConsultationPrivacyTest` asserts all of the above, including that the
  School Head sees no per-visit complaint either.

This was implemented before the nurse's disagreement was known. It is a
default, not a ruling.

### One partial bridge already exists

The nurse can photograph an injury during a consultation and **share that
photo with the class adviser per photo** (`ConsultationPhoto`,
`shared_with_adviser`, default off). That was built for a different request,
but it happens to give the nurse a deliberate way to tell a teacher about
something the teacher needs to act on, without opening the whole record.

It may or may not be enough to satisfy the nurse's concern. That is part of
what needs deciding.

### If the decision goes the nurse's way

Small change, deliberately:

1. Add `'class_adviser'` to `ConsultationVisibility::DETAIL_ROLES`.
2. Update `ConsultationPrivacyTest` — the assertions that currently pin the
   redaction would need to pin the opposite for that role.
3. Revisit the adviser-facing notice on the Consultation Log tab, which
   currently explains why detail is withheld.
4. Decide separately whether the **School Head** also changes. Ma'am Nanette
   was explicit that the principal should see no consultation details, and the
   nurse's argument (direct parent contact) does not apply to a principal — so
   the head most likely stays redacted either way.

The whole rule lives in one class precisely so this is a one-line change
rather than a hunt through templates.

### Questions worth putting to Ma'am Nanette

- Does the nurse's parent-contact argument change the answer, or is
  minimum-necessary still the rule?
- If advisers get more than date and time, is it *everything*, or a middle
  position — for example the complaint but not the treatment?
- Is the nurse's per-photo sharing enough to cover "the teacher needs to know
  about this one"?
- Does the answer differ for an injury sustained at school (which the adviser
  may have witnessed and may have filed an incident report about) versus an
  illness the learner arrived with?

---

## 2. How long a learner's record is kept after they stop appearing

**Status:** OPEN — needs Ma'am Nanette, or DepEd's own records retention policy
**Raised:** 2026-09-04 (consultation; discussed, not resolved)
**Decider:** Ma'am Nanette / DepEd policy — not an informal call
**Touches:** nothing yet. No retention rule is implemented, so today the
answer is "forever by default".

### Settled in the same conversation

Promotion **updates, it does not delete**. A learner moving Grade 7 → 8 keeps
their prior-year data, retrievable and traceable. That part is confirmed and
is recorded as an invariant in CLAUDE.md.

### Not settled

How long a record is kept once a learner **stops appearing** — dropout,
transfer out, or simply never returns. Three figures were floated and none
agreed:

| Floated | Rationale given |
|---|---|
| 3 years | Shortest span discussed |
| 6 years | Covers a full Grade 7–10 run with margin |
| 10 years | Accounts for returning dropouts and ALS transfers coming back |

The 10-year argument is the substantive one: a learner who drops out and
returns through ALS years later is precisely the person whose earlier health
history matters, and deleting at 3 years would destroy it.

### What happens today

Nothing is deleted, ever. There is no retention window, no archive flag, no
purge job, and no "inactive learner" state:

- `student_health_records` rows are keyed by `student_id` + `institution_id` +
  `school_year`, and each school year is its own row.
- No code path anywhere deletes a `StudentHealthRecord`.
- A learner who stops appearing simply stops getting new rows. Their old ones
  stay indefinitely.

So the current behaviour is "retain forever". That satisfies the 10-year
reading by accident, and it is almost certainly wrong as a policy — indefinite
retention of children's health data is the thing a data-privacy reviewer asks
about first.

### What implementing a decision would take

Whichever number is chosen:

1. A way to mark a learner as no longer appearing, with a date — there is no
   such state today, and "stopped appearing" cannot be derived reliably from
   the absence of new rows (a learner missing for one year may return).
2. A retention window read from configuration, per school if DepEd's policy
   varies by division — the same shape as `institutions.feeding_cycle_days`,
   which exists because a compiled-in figure is one a school cannot correct.
3. A decision on what "delete" means: hard delete, or anonymise and keep the
   aggregate figures so historical BMI and feeding reports do not change
   retroactively. **Hard deletion would silently alter past DepEd returns.**
   That is a separate question and probably the more important one.
4. Whatever happens, `audit_logs` is append-only evidence and is not covered
   by this — deleting a learner's record must not delete the trail showing it
   existed.

### Questions worth putting to Ma'am Nanette

- Does DepEd publish a records retention schedule for school health records?
  If so, that answers this and nobody needs to pick a number.
- Does the clock start at the last recorded school year, or at a date somebody
  enters when the learner leaves?
- On expiry: delete outright, or anonymise and keep the counts so past reports
  still reconcile?
- Do the feeding programme's historical figures need to survive the learner's
  record being removed?

---
## 3. Sheet 2 — who writes it, and can the adviser see it at all

**Status:** OPEN on two counts — one ambiguity, one gap between the
confirmation and the code
**Raised:** 2026-09-04 (consultation)
**Decider:** Ma'am Nanette
**Touches:** the enrolment form's Sheet 2 panel, `AdviserController::store`,
`App\Support\StudentVitalSigns`, `AdviserSheetTwoReadOnlyTest`

### What was confirmed

> Sheet 2 (vital signs, general appearance) — view-only for advisers,
> editable only by nurse.

### Where the code already agrees

**Vital signs** — temperature, pulse rate, blood pressure. Exactly as stated:
the adviser's form shows them as a readout with no input, `AdviserController`
neither validates nor writes them, and `StudentVitalSignsController` accepts
`school_nurse` only. Covered by `StudentVitalSignsRoleTest`.

### Where it does not — two mismatches, both worth raising

**1. Nothing lets the nurse edit Sheet 2.** The Systems Review is read-only
for the adviser *when they open an existing learner*, but the adviser still
writes it **at enrolment**, and there is no nurse-side form for it anywhere.
So today Sheet 2 is: written once by the adviser, then frozen. "Editable only
by nurse" is not true of it in either direction.

Making it literally true means building a nurse-side Systems Review form —
real work, not a permissions flag. Without one, switching the write off would
leave Sheet 2 permanently blank for every learner enrolled from that point on.
This risk was flagged when Sheet 2 was first made read-only and is unchanged.

**2. "General appearance" is not on Sheet 2.** Consciousness, posture and
hygiene sit in **Sheet 1**, section D, in the `health_history` blob — and the
adviser writes them freely, at enrolment and on every edit. If the intent was
that these move to the nurse too, that is a third change and nobody has
described it.

### The ambiguity

The transcript is unclear on whether the adviser should **see** Sheet 2 at all,
or merely be unable to edit it. The code currently shows it, read-only.

The case for showing it: an adviser who can see a learner has an abnormal
cardiac finding knows to be careful in PE. The case for hiding it: a systems
review is a clinical examination, and the same minimum-necessary argument that
took the consultation narrative away from advisers (entry 1) applies here at
least as strongly.

**These two entries should be decided together.** It would be odd to withhold
a consultation complaint from an adviser while showing them a full body-systems
examination on the same learner — and equally odd the other way round.

### Questions worth putting to Ma'am Nanette

- Should the adviser see Sheet 2, or not see it? Decide alongside entry 1.
- If the nurse is to edit Sheet 2, should a nurse-side form be built — and
  until it exists, should the adviser keep writing it at enrolment?
- Does "general appearance" move to the nurse as well, or stay with the adviser
  on Sheet 1 where it is now?

---
## How to use this file

Add an entry the moment two instructions conflict, before writing the code —
not after. An implemented default that nobody agreed to is harder to unwind
than an open question, because tests grow around it and it starts to look
like a decision somebody made.
