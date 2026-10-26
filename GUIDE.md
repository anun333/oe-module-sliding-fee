# Sliding Fee Discount: staff guide

For the staff who use the module day to day, task by task. What the schedule check covers and why, and how to install it, are in the [README](README.md).

The module works out what each patient's discount should be; it posts nothing to accounts. Billing staff post each discount, with its Adjustment Reason, where they post adjustments now. Insurers are still billed the full fee. It has been tested only on made-up patients; check the schedule against your board-approved policy.

## Front desk and eligibility staff

### Assess a patient's income

*Where:* the patient's dashboard, the sliding fee card › **Assessments and visit discounts**. Or the Fees menu › **Sliding Fee Discount** › **Current patient**.

1. Under **Record an assessment**, set the **Date** (today or earlier).
2. Enter the **Annual income** and the **Family size**. Income and family size only count, whatever the patient's insurance; your clinic's policy decides what counts as income and family, and what proof to ask for.
3. Under **Documented by**, choose **Documents seen** or **Self-declared**, or **Declined to give income** if the patient wouldn't say. Someone who declines gets no discount.
4. **Based on** is usually **Family income**. On a Title X schedule, choose **Own resources of a minor seeking confidential services** for a minor assessed on their own income (enter their income and a family size of 1), or **Counted as low-income by the project director** and give the reason in **Note**.
5. Choose **Record**.

*What you see:* **In force today**: the pay class the assessment places the patient in, their income as a share of the guidelines, and the date to re-assess by. Demographics is updated with the same family size, monthly income and Financial Review Date, except for a minor assessed on their own resources.

**Print the application and the notice (Clinic Forms)** prints the paper application and the notice of discounts, filled in, if the Clinic Forms module is installed.

### Find who needs re-assessing

*Where:* the Fees menu › **Sliding Fee Discount** › **Re-assessments due**.

Patients seen in the last 12 months whose assessment runs out soon, has run out, or who were never assessed. The **Status** column says which: **Runs out soon**, **Assessment ran out** or **Never assessed**. Open a patient to record a new assessment.

## Billing staff

### Work out the discount for a visit

*Where:* the patient's sliding fee page › **Visits**, then the visit's date.

The page shows the pay class on the date of service, the charges at full fee, what insurance has paid and adjusted, what the patient has paid and what they owe now.

1. Read **Discount to post now**. The page says the amount and the Adjustment Reason to use.
2. Post it as an adjustment to the patient balance in payment posting, with that reason. At checkout you can enter it as the discount, where your OpenEMR version keeps the reason; the page says which.

*What it doesn't do:*
- If insurance hasn't finished with the visit, the discount waits until it has; the page shows the most the patient pays meanwhile, copay included.
- With no assessment in force on the date of service, no discount applies: assess the patient first.
- Prescription drugs, eyeglasses and dentures aren't covered by the sliding fee discount program.
- Products sold at checkout, such as contraceptive supplies dispensed on site, aren't in the fee total. A Title X site should discount contraceptive products by hand for now: Title X counts them as family planning services (42 CFR 59.2).

## The administrator

### Set up or change the schedule

*Where:* the Fees menu › **Sliding Fee Discount** › **Schedule** (administrators only).

1. Under **Main schedule**, set the **Poverty guidelines for** region and **Year**, and **Re-assess every (months)**.
2. Choose the **Program rules**: the HRSA Health Center Program, or Title X family planning for a Title X-only clinic.
3. Under **At or below 100% of the guidelines**, choose **Full discount** or **Nominal charge per visit**.
4. Under **Pay classes above 100%**, give each class a **Name**, its **Up to (% of guidelines)**, and its **Discount**: **Percent off the fee** or **Flat charge per visit**. Leave a name empty to remove a row.
5. Tick the **Adjustment Reasons posted as sliding fee discounts**, and enter the **Board approved on** date. Until one is ticked, nothing counts as a discount already posted, so the visit page offers the whole discount.
6. Choose **Check and save**. A schedule with errors isn't saved; the page lists what to fix.

### Add a schedule for a service

HRSA allows more than one schedule only by service or delivery method, such as medical and dental, not by site or population.

*Where:* **Schedule** › **Add a schedule for a service**.

Give the **Name of the service**, tick the **Visit categories this schedule covers**, set its pay classes and reasons as above, and **Check and save**. It takes the guidelines and the re-assessment period from the main schedule. A health center with a Title X project gives its family planning visits a service schedule on Title X rules.

## The manager

### Evaluate the program

*Where:* the Fees menu › **Sliding Fee Discount** › **Evaluation**.

Choose **From** and **To** and **Show**. Each row is a pay class, with its patients, visits, charges at full fee and the sliding fee discounts posted, for the evaluation HRSA asks for at least every three years. **Download CSV** saves it.

*What it doesn't do:* it counts only adjustments posted under the reasons ticked in the schedule, and it doesn't collect patient surveys.
