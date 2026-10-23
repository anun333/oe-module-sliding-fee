# Changelog

## 0.3.0 (2026-10-22)

- **Clinic Forms' sliding fee notice, filled with the schedule in force.** With Clinic Forms installed (0.33.0 or later), its notice's discounts table prints, for household sizes 1 to 8, the yearly income up to which the full discount applies (100% of the poverty guideline) and up to which a partial discount applies (the highest pay class, rounded down). A note says it's from the schedule in force and which year's guidelines. English and Spanish, printed or downloaded.
  - It answers Clinic Forms' `clinic_forms.document.fill` event and needs none of Clinic Forms' classes.
  - Without a schedule, or its year's guidelines, the table prints blank for the clinic to fill in.
- **The patient page links to Clinic Forms** with the application and the notice ticked and the patient filled in, for staff to print.
- Checked on OpenEMR 8.4.1 in the end-to-end check (36 checks): the 2026 figures for 1, 4 and 8 people in English, the Spanish note, the Word download, and the link.

## 0.2.0 (2026-10-19)

- **Schedules by service.** A clinic can add a schedule for a service or delivery method, such as dental, beside its main one (Chapter 9, element d). It covers the visit categories it names. It has its own pay classes, nominal charge, Adjustment Reasons and approval date, and the same compliance check.
  - Each visit category belongs to one schedule at most. A schedule claiming one another already covers is refused.
  - A service schedule takes the guidelines and re-assessment period from the main schedule: a patient's income assessment covers every service.
  - **A visit's discount** uses the schedule for its category, placing the patient's income on that schedule's classes. The page names the schedule when there is more than one.
  - **The evaluation report** has one report per schedule, each with its own visits, and a CSV for each.
- **Re-assessments due:** a new page listing patients seen in the last 12 months whose assessment runs out within 30 days, has run out, or who were never assessed. It needs `patients/demo` (reading Demographics), and viewing it is written to the audit log.
- **Still for staff to post:** posting the discount through OpenEMR's accounts needs a posting service in OpenEMR itself, so staff still post what the visit page shows.
- **Tested** with unit tests for choosing a visit's schedule, placing an assessment on a service schedule's classes, and the due list. The end-to-end check on OpenEMR 8.4.1 now:
  - refuses a schedule with no categories, and one claiming a category already covered;
  - shows a visit moved to the Dental category using the Dental schedule ($20 at most, so $5 more after the $75 already posted);
  - counts that visit only in Dental's evaluation;
  - lists a patient never assessed, then the same patient as running out soon after an older assessment.

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
