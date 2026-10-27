# Changelog

## 0.6.0 (unreleased)

- **Figures for other modules' pages.** Through openhealthcare-common 1.5.0's figures contract, another module's page (Revenue Cycle's dashboard, planned) can ask for the sliding fee discounts posted over a period and how many visits have one, for one facility or all, without reading this module's tables. They're counted as the evaluation counts them: patient-side adjustments with one of the schedules' Adjustment Reasons, on visits dated in the period, net of reversals, voided adjustments left out. Each figure links to the evaluation for the same period. They're answered only to users the evaluation admits (`acct/rep_a`) and only with a schedule in force; totals only, never a patient. A failure is logged and the figures left out, so the asking page still opens.
- **The bootstrap also registers the library's contracts under their own prefix,** so where modules copied in by hand carry an older copy of the library that registered first, the figures still answer.
- Requires openhealthcare-common 1.5.0.
- **Next:** January's poverty guidelines ship as 0.6.1.
- **Checked:** unit tests on PHP 8.2 and 8.5 (60; 4 new: both figures with their note and link; the totals' query counts discounts as the evaluation's does, with the facility and the last day included; with no reason nothing counts and the note says why; only a request for these figures is answered). On OpenEMR 8.4.1, asked through `FiguresRequest::ask()` from a page: nothing without a schedule; with one, two planted visits' discounts (40.00 and 25.00) and nothing for an insurer's adjustment with the same reason, another reason, a voided discount or a visit after the period; 40.00 and one visit for the first facility alone; only the figure asked for; and the evaluation's own October total the same, 65.00. `dev/sliding_fee_check.py` passes (52).

## 0.5.0 (2026-10-26)

- **Title X: contraceptive products are discounted with the visit.** 42 CFR 59.2 counts "FDA-approved contraceptive products" among family planning services, and 59.5(a)(7) and (8) apply no charge and the sliding discount to services, so on a Title X schedule the contraceptive products sold at a visit (OpenEMR's product sales, sales only) now join its charges, on the visit page (a **Contraceptive products at full fee** line) and in the evaluation. A product counts when it has a CYP factor in Inventory (couple-years of protection, which only contraceptive methods have, and the first test OpenEMR's own IPPF statistics report applies), or when the Title X schedule lists it: the schedule page lists the clinic's active products, those with a CYP factor already counted, for a site that hasn't set CYP factors. Products are still never discounted on HRSA rules. This replaces 0.4.4's advice to discount them by hand.
- **The re-assessments due list comes 200 at a time,** with how many are due, each status's count, a status filter and Previous and Next. It used to send every patient due at once: about 15,500 rows and 6.5 MB on a 20,000-patient test site. Its audit entry now gives the count, the status chosen and the page, and names only the patients on that page, rather than every patient due. The list has no download, so nothing else needed the whole list.
- **A missing visit, patient or schedule shows a message inside the page,** with its title and language, instead of a bare line of text (the visit and patient pages).
- **Each page declares the signed-in user's OpenEMR language and direction** (openhealthcare-common 1.4.0's `pageLang()`), instead of always English. The call is guarded: where a site's other modules load an older copy of the shared library first, the page says English, as before, rather than stop. The module now requires openhealthcare-common ^1.4.0.
- **The dashboard card's button is checked as a guide label directly** (the shared library now reads Twig's `|xlt`), so composer.json no longer names it.
- **Install notes:** on a manual install, every module's copy of the shared library must be the same version, since the first one loaded serves all.
- **Next:** January's poverty guidelines ship as 0.5.1.
- **Checked:**
  - Unit tests: the products' rules on Title X and HRSA schedules, their storage, the visit's and evaluation's product queries (sales only, CYP factor) and the language guard's fallback fail on 0.4.4's code. All 56 tests pass on PHP 8.2 and 8.5.
  - End to end on OpenEMR 8.4.1 (`dev/sliding_fee_check.py`): a contraceptive with a CYP factor is discounted and another product and a purchase row are not, a product the schedule lists is discounted, the Title X evaluation counts both, a missing visit or patient gets the page's message, and the due list's never-assessed filter shows only those with the summary in the audit log. On 0.4.4's code exactly those seven checks fail.
  - With an older shared library (1.3.3) loaded first, every page still opens, in English.
  - The due list's paging, filter and audit summary: unit tests that fail on 0.4.4's code. Measured on 8.4.1 with 3,000 synthetic due patients (3,011 in all), then removed: the page went from 1,310,749 bytes and 3,011 rows to 89,404 bytes and 200 rows, and its audit entry from 21,124 characters naming every patient to about 1,500.

## 0.4.4 (2026-10-26)

- **Fixed: with no Adjustment Reason ticked, adjustments were counted as discounts already posted.** The schedule check already warns when none is ticked, but until then every adjustment with an empty memo, an insurer's included, counted as a sliding fee discount posted, so the visit page showed a smaller balance and a smaller discount to post, and the evaluation counted discounts nobody posted. With no reason ticked, nothing now counts as a posted discount.
- **Fixed: an insurer's adjustment carrying the discount's reason was counted twice,** once as the insurer's adjustment and again as a sliding fee discount. A discount is now only a patient-side adjustment (payer type 0) with one of the reasons, on the visit page and in the evaluation alike.
- **Installs on PHP 8.2,** as OpenEMR 8.1.0 does. It asked for PHP 8.3, which refused an install on 8.1.x with PHP 8.2, though nothing in it needs 8.3; it moves to 8.3 with the shared library on 2027-01-01.
- **Known limit, written down:** products sold at checkout (such as contraceptive supplies dispensed on site) aren't part of the fee total, so they're never discounted. HRSA's program doesn't require it for prescription drugs, but Title X's definition of family planning services (42 CFR 59.2) includes FDA-approved contraceptive products, so a Title X site should discount those by hand for now.
- **Next:** January's poverty guidelines ship as 0.4.5 (the build order had numbered that 0.4.2, which went to the contrast fix).
- **Checked:** new unit tests for the discount's condition, on the visit page's and the evaluation's queries, fail on 0.4.3's code. On adjustments planted in a rolled-back transaction on OpenEMR 8.4.1's database, the old query counted 25.00 of an insurer's and a patient's empty-memo adjustments as discounts with no reason ticked, and 37.00 with one (an insurer's 7.00 among them); the new one counts 0.00 and the patient's 30.00. The tests pass on PHP 8.2, 8.3 and 8.5, and every file lints on 8.2.

## 0.4.3 (2026-10-26)

- **A staff guide:** [GUIDE.md](GUIDE.md) walks through each task by role, with the labels as the pages show them. **Help** on every page opens it at this version's tag, in a new window.
- **The guide's labels are checked:** each of the 52 labels GUIDE.md quotes in bold is a string the pages show, or the dashboard card's button, named in composer.json with the template it comes from, so a renamed button fails the tests.

## 0.4.2 (2026-10-26)

- **Text and buttons are easier to read on this module's pages.** OpenEMR's blue (#007bff) is 3.97:1 on white, under the 4.5:1 that WCAG 2.1 AA asks of normal text and that HHS's Section 504 rule requires from 2027-05-11. Under OpenEMR's light and manila themes, the module's links and primary buttons now use #0056b3 (7.0:1), and muted and warning text darker shades that stay readable on tinted rows, and a link inside text is underlined; the dark, solar and colour themes keep their own colours, and OpenEMR's own pages are unchanged.

## 0.4.1 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, enabling this module stopped every OpenEMR page, the login page included (HTTP 500).** Those releases have `OEGlobalsBag` without the getters its bootstrap called, which came with 8.1.0. The module needs 8.1.0 or later (its pages use 8.1's session and service container), so on an older release it now stays switched off, as it already did before 7.0.4. Its pages ask openhealthcare-common 1.3.3, now required at runtime, for the web root and OpenEMR's folders. Checked on fresh installs of the official 7.0.2, 7.0.4 and 8.0.0 images with every module enabled, and on 8.4.1 and master.
- Development: the test for translated strings with an apostrophe or quote mark uses [openhealthcare-common](https://github.com/anun333/openhealthcare-common)'s scan, the same one every openhealthcare module runs. It is a development dependency only; nothing installed changes.
- **Accessibility:** every page declares its language (`<html lang>`), and links in running text on the schedule and evaluation pages are underlined.
- **FOOTPRINT.md** now comes from openhealthcare-common's footprint tool (1.1), the one every module shares; `tools/footprint.php` only loads it. The sheet's closing notes, what it can't show, are this module's own (in composer.json): the old ones were the Staff Worklist's, and said this module's permission checks had been verified end to end with restricted users, which they haven't.

## 0.4.0 (2026-10-22)

- **No longer stops an older OpenEMR.** OpenEMR loads a module's bootstrap on every request, the login page included. This module's used `OEGlobalsBag`, which came with OpenEMR 7.0.4 and 8.0, so on 7.0.3 or older, enabling it made every page fail (HTTP 500), login included, until the module was switched off in the database. It now stays switched off there instead. Found by the nightly matrix's new fresh 7.0.2 install (`dev/module_compat_check.sh`).
- **Title X schedules.** A schedule can follow Title X's rules (42 CFR 59.5(a)(7) and (8)) instead of HRSA's: a health center with a Title X project gives its family planning visits a service schedule on them.
  - **Checked as it's saved:** a nominal charge at or below 100% is refused (and none is ever charged); the last pay class must reach 250%; a discount above 250% is a warning. HRSA's three-classes, 200% and board-approval checks don't apply there.
  - **Insured clients** pay no more than the discount schedule charges at or below 250% (59.5(a)(8)(ii)), as every visit's discount already works.
- **What an assessment is based on** (42 CFR 59.2), offered when a Title X schedule is in force: the family's income, a minor's own resources (confidential services), or the project director counting the patient as low-income, with the reason in the note. The director's patient is at or below 100% on a Title X schedule. A minor's own-resources assessment leaves Demographics with the family's figures.
  - **Database:** a `basis` column on `sliding_fee_assessment` (`sql/0_3_0-to-0_4_0_upgrade.sql`, also in `install.sql`; guarded). Module Manager offers the upgrade.
- **Not moved:** the re-assessments-due tab stays the Staff Worklist's own for now (its row keys would change, bringing back dismissed rows); Title X doesn't change re-assessment.

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
