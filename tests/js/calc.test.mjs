// node tests/js/calc.test.mjs: holds public/ittai.ca/tools/_assets/calc.js to the same fixture answers as ToolCalculatorTest.
// The repo's package.json says "type": "commonjs", so calc.js (an ES module) is imported as text through a data: URL.
import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../public/ittai.ca/tools/_assets/calc.js', import.meta.url), 'utf8');
const { calculate } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const cases = JSON.parse(fs.readFileSync(new URL('../fixtures/tools-calculator.json', import.meta.url), 'utf8'));

for (const c of cases) {
    const out = calculate(c.tool, c.inputs);
    assert.equal(out.tool, c.tool);
    assert.equal(out.version, 1);
    for (const [key, value] of Object.entries(c.expected)) {
        if (value === null) {
            assert.equal(out.result[key], null, `${c.tool} ${key}`);
        } else {
            assert.ok(Math.abs(out.result[key] - value) < 0.00001, `${c.tool} ${key}: ${out.result[key]} vs ${value}`);
        }
    }
}

assert.throws(() => calculate('mow-price', { onsite: 30, crew: 1.5, drive: 10, cost_paid: 30, margin: 20 }), /Invalid crew/);
assert.throws(() => calculate('markup-margin', { mode: 'margin', percent: 100 }));
assert.equal(calculate('labour-cost', { wage: 24, vacation: 4, stat: 4.4, cpp: 5.95, ei: 2.282, wcb: 3.14, billable: 75, truck: 6 }).result.rate, null);

console.log(`calc.js: ${cases.length} fixtures match`);
