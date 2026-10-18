# Changelog

## 0.1.0 (2026-10-18)

First release, as a standalone Composer package.

- **Schedule**, checked against HRSA's Compliance Manual Chapter 9 as it's saved:
  - full discount or nominal charge at or below 100%;
  - at least three pay classes between 101% and 200%, reaching 200%;
  - no discount above 200% unless other funding pays for it;
  - discounts that shrink as income rises;
  - a nominal charge below the first class's charge;
  - the newest guidelines;
  - board approval within three years.

  A schedule with errors isn't saved.
- **Income assessments:** each one is kept, and Demographics gets the same family size, monthly income and Financial Review Date through OpenEMR's own `updatePatientData()`.
- **A dashboard card** with the patient's pay class and re-assessment date.
- **The discount a visit is owed:**
  - uninsured: the charges less the class's charge;
  - insured: the class's charge is the most the patient pays, copays included, once insurance has finished.

  It posts nothing; it says how much to post and under which reason. Installing adds a **Sliding fee discount** Adjustment Reason.
- **Evaluation:** patients, visits, charges and discounts posted, by pay class, with a CSV.
- **Access:** each page makes the same check as the OpenEMR page it stands beside.
- **Tested:**
  - **Isolated tests:** 22, with HRSA's own $80/$60 example among them; three deliberate breaks each fail them.
  - **On OpenEMR 8.4.1:** `dev/sliding_fee_check.py`, 22 checks, from installing through Module Manager to the evaluation CSV.
