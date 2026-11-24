<?php
declare(strict_types=1);

/**
 * Unit Test Suite for Philippine RA 9994 & BIR RR 7-2010 Pro-Rated Discount Calculation & Overpayment Settlement.
 * Run with: php database/test_ra9994_discount.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';

function assert_money(string $label, float $expected, float $actual): void
{
    if (abs($expected - $actual) > 0.01) {
        fwrite(STDERR, "[FAIL] $label: expected " . number_format($expected, 2) . ", got " . number_format($actual, 2) . PHP_EOL);
        exit(1);
    }
    echo "[PASS] $label = ₱" . number_format($actual, 2) . PHP_EOL;
}

echo "=== Test Case 1: 1 Senior Citizen + 3 Regular Companions (4 guests sharing ₱4,480.00 room, 50% regular downpayment = ₱2,240.00) ===" . PHP_EOL;
// Regular Rate = 4,480.00 (12% VAT-inclusive) -> VAT_Exclusive_Total = 4,000.00 -> Per_Pax_Base_Share = 1,000.00
// Senior Share (1 pax): 1,000.00 * 0.80 = 800.00 (VAT-Exempt Sales = 1,000.00, 20% Senior Discount = 200.00)
// Regular Share (3 pax): (1,000.00 * 1.12) * 3 = 3,360.00 (VATable Sales = 3,000.00, 12% VAT = 360.00)
// Final Discounted Total = 800.00 + 3,360.00 = 4,160.00 (Remaining Balance after 2,240.00 downpayment = 1,920.00)
$tc1 = calculate_ra9994_discount(4480.00, 4, 1, 2240.00);
assert_money('TC1 VAT-Exclusive Total', 4000.00, $tc1['vat_exclusive_total']);
assert_money('TC1 Per-Pax Base Share', 1000.00, $tc1['per_pax_base_share']);
assert_money('TC1 VAT-Exempt Sales (Senior Base)', 1000.00, $tc1['vat_exempt_sales']);
assert_money('TC1 20% Senior Discount Amount', 200.00, $tc1['senior_discount_amount']);
assert_money('TC1 Senior Share Total', 800.00, $tc1['senior_share_total']);
assert_money('TC1 VATable Sales (3 Regular Pax)', 3000.00, $tc1['vatable_sales']);
assert_money('TC1 12% VAT Amount', 360.00, $tc1['vat_amount']);
assert_money('TC1 Regular Share Total', 3360.00, $tc1['regular_share_total']);
assert_money('TC1 Final Discounted Total', 4160.00, $tc1['final_discounted_total']);
assert_money('TC1 Remaining Balance', 1920.00, $tc1['remaining_balance']);
assert_money('TC1 Overpayment Amount', 0.00, $tc1['overpayment_amount']);

echo PHP_EOL . "=== Test Case 2: Solo Senior Citizen (1 guest in ₱4,480.00 room, 50% regular downpayment = ₱2,240.00) ===" . PHP_EOL;
// VAT_Exclusive_Total = 4,000.00 -> Per_Pax_Base_Share = 4,000.00
// Senior Share (1 pax): 4,000.00 * 0.80 = 3,200.00 (20% Senior Discount = 800.00, VATable Sales = 0.00, VAT = 0.00)
// Remaining Balance after 2,240.00 downpayment = 960.00
$tc2 = calculate_ra9994_discount(4480.00, 1, 1, 2240.00);
assert_money('TC2 VAT-Exempt Sales', 4000.00, $tc2['vat_exempt_sales']);
assert_money('TC2 20% Senior Discount Amount', 800.00, $tc2['senior_discount_amount']);
assert_money('TC2 Final Discounted Total', 3200.00, $tc2['final_discounted_total']);
assert_money('TC2 VATable Sales', 0.00, $tc2['vatable_sales']);
assert_money('TC2 Remaining Balance', 960.00, $tc2['remaining_balance']);

echo PHP_EOL . "=== Test Case 3: All-Senior Group (4 verified seniors in ₱4,480.00 room, 50% regular downpayment = ₱2,240.00) ===" . PHP_EOL;
// Per_Pax_Base_Share = 1,000.00 * 4 seniors -> VAT-Exempt Sales = 4,000.00, 20% Discount = 800.00, Final Total = 3,200.00
$tc3 = calculate_ra9994_discount(4480.00, 4, 4, 2240.00);
assert_money('TC3 VAT-Exempt Sales (All 4 Seniors)', 4000.00, $tc3['vat_exempt_sales']);
assert_money('TC3 20% Senior Discount Amount', 800.00, $tc3['senior_discount_amount']);
assert_money('TC3 Final Discounted Total', 3200.00, $tc3['final_discounted_total']);
assert_money('TC3 VATable Sales', 0.00, $tc3['vatable_sales']);
assert_money('TC3 Remaining Balance', 960.00, $tc3['remaining_balance']);

echo PHP_EOL . "=== Test Case 4: Overpayment Refund / Credit Case (All-Senior group paid 80% or 100% upfront downpayment = ₱4,480.00) ===" . PHP_EOL;
// Final Discounted Total = 3,200.00, Downpayment Paid = 4,480.00 -> Raw Balance = -1,280.00 -> Overpayment Refund/Credit = 1,280.00
$tc4 = calculate_ra9994_discount(4480.00, 4, 4, 4480.00);
assert_money('TC4 Final Discounted Total', 3200.00, $tc4['final_discounted_total']);
assert_money('TC4 Remaining Balance Due', 0.00, $tc4['remaining_balance']);
assert_money('TC4 Overpayment Refund/Credit Amount', 1280.00, $tc4['overpayment_amount']);
if (!$tc4['needs_refund_or_credit']) {
    fwrite(STDERR, "[FAIL] TC4 needs_refund_or_credit flag should be true" . PHP_EOL);
    exit(1);
}
echo "[PASS] TC4 needs_refund_or_credit = true (flagged for Cash Refund or Consumable Resort Credit)" . PHP_EOL;

echo PHP_EOL . "ALL RA 9994 & BIR RR 7-2010 UNIT TESTS PASSED SUCCESSFULLY." . PHP_EOL;
