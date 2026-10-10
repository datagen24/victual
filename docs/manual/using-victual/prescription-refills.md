# Prescription refills

**`/consumptionrefills`** shows the estimated reorder date of each prescription you can see, the fills
and orders you recorded for it, and the notices you have not yet marked as seen. A prescription is a
[consumption recipe](consumption-recipes.md). The page needs only the `STOCK_VIEW` permission to open,
and what you can change on a prescription depends on your rights on it.

The estimate is a date worked out from the fills you enter. It does not say that a pharmacy or an
insurer will allow a refill on that date, it is separate from the stock you hold, and Victual gives no
advice about how to take any product. See
[ADR-0015](../../adr/0015-medication-records-never-advises.md). Victual sends no notification. This page
lists the notices; an app such as `victual-kit` can show its own.

## Who can see it

Only the owner of a prescription and the users it is shared with can see its refill dates, fills and
orders. Reading them needs the read right on the prescription, and recording or changing them needs the
edit right. A member who can only read sees the dates and no forms. An administrator has no automatic
access. The operator page [Prescription refills](../operator/prescription-refills.md) lists the routes.

## Recording a fill

Under **Record a fill**, enter the date the pharmacy supplied the medication and the number of days it
covers, then choose **Record fill**. The date is the one on your calendar; Victual never fills it in for
you. The note is optional. Recording a fill does not change your stock: record the purchase on the
purchase page as usual.

A fill is never edited. If one is wrong, choose **Void** on its row and give a reason. The fill stays in
the list, marked as voided, the previous fill becomes the current one, and the estimate is calculated
again. Then record the correct fill.

## The estimated reorder date

By default the estimate is the fill date plus the days supplied, minus 14 days. A 30-day fill gives a
date 16 days after the fill and a 90-day fill gives a date 76 days after it. The page names where each
date comes from. Three things take precedence over the default.

- **A reorder date you choose.** Enter it under **Reorder date you choose**. It belongs to the current
  fill, so it stops applying as soon as you record a newer fill, and it does not apply again if you void
  that fill.
- **A rule for this prescription.** Under **Reorder rule**, choose days before the supply ends, days after
  the fill date, or a percent of the supply used, and enter its value. A rule is kept when you record a new
  fill.
- **The default.** Used when neither of the above is set.

When Victual cannot calculate a date it says so and gives the reason, such as no fill recorded, days
supplied missing, or a calculated date that is not after the fill date. It does not invent one. For a
supply of 14 days or less, choose a rule or enter a date.

## Advance warning

A prescription is shown as **Reorder date approaching** from some days before its reorder date, and
**Reorder date reached** on that date. The number of days comes from, in order, the value you set for the
prescription, the value you set at the top of the page for all your prescriptions, and 7. It can be 0 to 60.
With 0 there is no approaching period.

## Orders

**Record order** notes that you asked the pharmacy. The prescription then shows **Order placed**, and no
notice is raised for it. An order adds no stock and does not change the fill history or the estimate.
When the fill arrives, fill in its date and days and choose **Receive order**: the fill is recorded and the
order is closed in one step. **Cancel order** returns the prescription to the status its fills and rules say.
Only one order can be open on a prescription.

## Notices

A notice appears at the top of the page from the advance-warning date, and again as a different notice on the
reorder date. **Mark as seen** hides a notice for you only; other people who share the prescription keep their
own. If you correct a fill so that the reorder date changes, you get a new notice. If you void a fill and
record an identical one, you do not.

## Which date counts as today

The page compares the dates with the date on your device, and tells the server that date with each request.
The server runs in UTC. A phone on New York time at 21:00 on 17 March therefore reads a reorder date of
18 March as approaching, even though it is already the 18th in UTC.
