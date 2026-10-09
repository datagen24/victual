"""Apply experimental predicates to a disposable exported tree, never the checkout."""
import pathlib, re, sys
root = pathlib.Path(sys.argv[1]); mode = sys.argv[2]
if mode not in ('absolute', 'relative'): raise SystemExit('absolute or relative required')
p = root / 'services/StockService.php'; s = p.read_text()
def replace(old, new, count=None):
    global s
    n = s.count(old)
    if not n or (count is not None and n != count): raise RuntimeError((old, n, count))
    s = s.replace(old, new)
helper = '''
	// ADR-0032 experiment only. Not a production implementation.
	public static function SpikeTolerance(float $a, float $b): float
	{
		return TOLERANCE_EXPRESSION;
	}
	public static function SpikeCompare(float $a, float $b): int
	{
		if (!is_finite($a) || !is_finite($b)) { throw new \\InvalidArgumentException('Non-finite stock amount'); }
		$d = $a - $b;
		$t = self::SpikeTolerance($a, $b);
		return $d > $t ? 1 : ($d < -$t ? -1 : 0);
	}
'''.replace('TOLERANCE_EXPRESSION', 'self::AMOUNT_TOLERANCE' if mode == 'absolute' else 'max(self::AMOUNT_TOLERANCE, 1e-12 * max(abs($a), abs($b)))')
replace('\tconst AMOUNT_TOLERANCE = 1e-9;', '\tconst AMOUNT_TOLERANCE = 1e-9;\n'+helper, 1)
for method, arg in [('AddProduct','amount'),('ConsumeProduct','amount'),('EditStockEntry','amount'),('InventoryProduct','newAmount'),('OpenProduct','amount'),('TransferProduct','amount')]:
    pattern = rf'(public function {method}\([^\n]+\)\n\t\{{)'
    s,n = re.subn(pattern, lambda m:m[1]+f"\n\t\tif (!is_finite(${arg}) || ${arg} < 0) {{ throw new \\InvalidArgumentException('Invalid stock amount'); }}", s)
    assert n==1,method
replace('round($amount, 2) > round($productStockAmount, 2)', 'self::SpikeCompare($amount, $productStockAmount) > 0',1)
for name in ['productStockAmountUnopened','productStockAmountAtFromLocation']:
    replace(f'$amount > ${name}',f'self::SpikeCompare($amount, ${name}) > 0',1)
replace('round($amount, 2) == 1.0', '$amount == 1.0',1)
replace('round($stockRow->amount, 2) != 1.0', '$stockRow->amount != 1.0',1)
replace('round($amount, 2) != 1.0', '$amount != 1.0',1)
replace('round($targetEntry->amount, 2) < 1.0', '$targetEntry->amount < 1.0',1)
for op,cmp in [('==','=='),('>','>'),('<','<')]:
    replace(f'$newAmount {op} $productDetails->stock_amount',f'self::SpikeCompare($newAmount, $productDetails->stock_amount) {cmp} 0',1)
replace('($stockEntry->amount - $amount) <= self::AMOUNT_TOLERANCE','self::SpikeCompare($stockEntry->amount, $amount) <= 0',3)
# A tolerated whole-row match exhausts the transient request before unit conversion.
replace('$amount -= $stockEntry->amount;', '$amount = self::SpikeCompare($amount, $stockEntry->amount) == 0 ? 0.0 : $amount - $stockEntry->amount;',3)
# Exact structural condition overrides tolerant whole-entry selection for a measurement.
a=s.index('public function OpenProduct'); b=s.index('public function ',a+20)
part=s[a:b].replace('if (self::SpikeCompare($stockEntry->amount, $amount) <= 0)', 'if ($resolvedMeasurement !== null ? $stockEntry->amount <= $amount : self::SpikeCompare($stockEntry->amount, $amount) <= 0)')
s=s[:a]+part+s[b:]
# Preserve subtraction operands for undo: relative comparison against remainder alone is invalid.
replace('$newAmount = $totalAmount - $logRow->amount;', '$newAmount = $totalAmount - $logRow->amount;\n\t\t\t\t$spikeComparison = self::SpikeCompare($totalAmount, $logRow->amount);',1)
replace('$newAmount = $stockRow->amount - $logRow->amount;', '$newAmount = $stockRow->amount - $logRow->amount;\n\t\t\t\t$spikeComparison = self::SpikeCompare($stockRow->amount, $logRow->amount);',2)
replace('$newAmount < -self::AMOUNT_TOLERANCE','$spikeComparison < 0',3)
replace('$newAmount <= self::AMOUNT_TOLERANCE','$spikeComparison == 0',2)
for expr, left, right in [('abs($cleanRelocateRow->amount - abs($logRow->amount))','$cleanRelocateRow->amount','abs($logRow->amount)'),('abs($cleanRelocatedHome->amount - abs($logRow->amount))','$cleanRelocatedHome->amount','abs($logRow->amount)'),('abs($stockRow->amount - $logRow->amount)','$stockRow->amount','$logRow->amount'),('abs($stockRow->amount - $correlatedNew->amount)','$stockRow->amount','$correlatedNew->amount')]:
    replace(expr+' > self::AMOUNT_TOLERANCE',f'self::SpikeCompare({left}, {right}) != 0',1)
# ResolveMeasurement also needs finite validation; do not weaken exact sign checks.
replace("!is_numeric($measurement['amount'])", "!is_numeric($measurement['amount']) || !is_finite((float)$measurement['amount'])",1)
replace("!is_numeric($measurement['tare'])", "!is_numeric($measurement['tare']) || !is_finite((float)$measurement['tare'])",1)
p.write_text(s)
p=root/'services/RecipesService.php';s=p.read_text()
s=s.replace('$recipePosition->stock_amount > 0', 'StockService::SpikeCompare($recipePosition->stock_amount, 0) > 0')
s=s.replace('$recipePosition->stock_amount < $recipePosition->recipe_amount','StockService::SpikeCompare($recipePosition->stock_amount, $recipePosition->recipe_amount) < 0')
p.write_text(s)
