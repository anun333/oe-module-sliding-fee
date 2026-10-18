# Sliding Fee Discount for OpenEMR

A sliding fee discount program for OpenEMR, as HRSA requires of every Health Center Program awardee and look-alike (Compliance Manual, Chapter 9), and as free clinics and Title X sites also run.

It covers four things:
- the clinic's schedule, checked against Chapter 9's requirements;
- each patient's income assessment;
- the discount each visit is owed;
- the utilization figures the program's evaluation needs.

It posts nothing to accounts: staff post each discount, with its Adjustment Reason, where they post adjustments now.

> **Status: 0.1.0, a pre-release.** Tested on OpenEMR 8.4.1 with synthetic data only. The schedule, the discount arithmetic and HRSA's rules are as read on 2026-10-18; check them against your board-approved policy before staff rely on them.

What installing it changes in your database, what it reads and writes, and what it hooks into are listed in [FOOTPRINT.md](FOOTPRINT.md), generated from the source.

## What it does

- **Fees › Sliding Fee Discount:** the schedule in force, its compliance check, and links to the pages you may open.
- **Schedule** (administrators):
  - the poverty guidelines region and year;
  - what happens at or below 100% of the guidelines: a full discount, or a flat nominal charge per visit;
  - the pay classes above it, each up to a percentage of the guidelines, as a percentage off the fee or a flat charge per visit;
  - how often patients are re-assessed;
  - which Adjustment Reasons are sliding fee discounts.

  It's checked as it's saved, and a schedule with errors isn't saved:

  | Check | Basis |
  |---|---|
  | A full discount or a nominal charge at or below 100% | Chapter 9, element c |
  | At least three pay classes between 101% and 200%, reaching 200% | element c |
  | No discount above 200%, unless the clinic says other funding pays for it | element c |
  | Discounts that shrink as income rises | element c ("adjust based on gradations in income levels") |
  | A nominal charge below what the first pay class pays. For a percentage class, the fees in your fee schedule for which it wouldn't be are counted | element c |
  | The newest guidelines year loaded | element e |
  | When the board approved it, and whether that was more than three years ago | element l and Chapter 19 |
- **Assessment** (from the patient's dashboard card):
  - **Recorded:** annual income, family size, the date, and how it was documented (documents seen, self-declared, or declined), with a note.
  - **Shown:** the percentage of the guidelines and the pay class.
  - **Kept:** every assessment, as element g asks.
  - **Demographics:** saving one also updates family size, monthly income and Financial Review Date there, through OpenEMR's own `updatePatientData()`. Everything that reads those fields, such as a UDS Table 4 report, sees the same income.
  - **The policy is yours:** the clinic decides what counts as income and family, what proof to ask for, and how often to re-assess. HRSA leaves all of that to the health center.
- **Dashboard card:** the patient's pay class, the percentage, how it was documented, and when re-assessment is due.
- **A visit's discount:** the pay class in force on the date of service, the charges at full fee, what insurance and the patient have paid, the most the patient pays, and the discount to post now.
  - **Uninsured:** the discount is the charges less the class's charge.
  - **Insured:** the class's charge, worked out on the full fee, is the most the patient pays in all, copays and deductibles included (element k). HRSA's own example: an $80 fee, a $60 copay, a class that pays half; the patient pays $40. Until insurance has finished with the visit, the page gives that most-the-patient-pays figure and holds the discount.
  - **Insurers are billed the full fee** (Chapter 16), so the discount is never a lower price on the fee sheet.
- **Evaluation:** for a period, patients, visits, charges and sliding fee discounts posted, within each pay class and at or below 100%, with a CSV.
  - **What it's for:** element l asks for this data at least once every three years, with other data such as patient surveys.
  - **How visits are placed:** each visit goes in the pay class of the assessment in force on its date, on the schedule in use. Visits with no assessment, or a declined one, have rows of their own.

## Posting the discount

The visit page says how much to post and under which Adjustment Reason.

- **The reason:** installing the module adds one to Admin › Forms › Lists › Adjustment Reasons, **Sliding fee discount**, as a charge adjustment. If one by that name already exists, it's left alone.
- **On OpenEMR 8.5 and later:** checkout's discount records a reason (openemr/openemr#14583), so the discount can be entered at checkout with that reason.
- **On 8.4 and earlier:** checkout's discount is recorded only as "Discount". Post it in payment posting instead, where the reason is kept.
- **The UDS module:** its Table 9D worksheet treats any reason starting with "Sliding" as a sliding fee discount (Column D).

## Who can open what

Each page makes the same check as the OpenEMR page it stands beside, and nothing more:

| Page | Check | Same as |
|---|---|---|
| Overview and menu entry | `acct/bill` | Fees |
| Schedule | `admin/super` | the UDS module's settings |
| Assessments (writes Demographics) | `patients/demo` write | the Demographics editor |
| A visit's discount | `acct/bill` write | checkout |
| Evaluation (reads payments) | `acct/rep_a` | the Collections report |
| Dashboard card | `patients/demo` | the dashboard's other cards |

Viewing a patient's assessments or a visit's discount, and recording an assessment, are written to OpenEMR's audit log.

## Not done yet

- **Posting the discount itself.** It writes to accounts receivable, so it waits until the calculator has been checked against real visits.
- **Separate schedules by service** (for example medical and dental, element d). The schedule table can hold them; the pages show one.
- **Discounts above 200%** funded from other sources, beyond the flag on the schedule.
- **A list of patients due for re-assessment,** for example as a Staff Worklist tab.

## Install

```
composer require anun333/oe-module-sliding-fee
```

Then in OpenEMR, Modules › Manage Modules: Register, Install and Enable **Sliding Fee Discount**. Set up the schedule from your board-approved policy before assessing patients.

## Compatibility

Tested with synthetic data on OpenEMR 8.4.1 (Docker image `openemr/openemr:8.4.1`), with `dev/sliding_fee_check.py` in the openhealthcare repository. That check:
- installs the module through Module Manager;
- refuses a schedule with two pay classes between 101% and 200%, and saves a compliant one;
- records assessments for four patients through the page (one declined) and refuses one with no income;
- confirms Demographics and the UDS module's Table 4 then read the same income;
- shows the card on the dashboard;
- works out the discount for an uninsured visit, before and after it's posted, and for an insured one, pending and then with insurance's payment;
- produces the evaluation report and its CSV;
- removes everything and puts Demographics back.

The same check passes on current master (8.5.0-dev, 1faefbbeb9, 2026-10-18). There the visit page says to post the discount at checkout, which records its reason from 8.5. It uses OpenEMR features present since 8.1.0, but it hasn't been run on 8.1 to 8.4.0. It needs PHP 8.3 or later.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php          # regenerate FOOTPRINT.md after changing the module
php tools/footprint.php --check  # what CI runs
```

The unit tests need no database or OpenEMR install. CI runs them, and the footprint check, on PHP 8.3, 8.4 and 8.5.

## Sources

- HRSA, Health Center Program Compliance Manual, [Chapter 9: Sliding Fee Discount Program](https://bphc.hrsa.gov/compliance/compliance-manual/chapter9), with Chapters 16 and 19, the [Site Visit Protocol](https://bphc.hrsa.gov/compliance/site-visits/site-visit-protocol/sliding-fee-discount-program) and the compliance FAQ, read 2026-10-18.
- HHS poverty guidelines for 2026, 91 FR 1797 (January 15, 2026), in [data/poverty-guidelines.json](data/poverty-guidelines.json). A year and region whose figures aren't filled in are shown as not loaded, never estimated.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
