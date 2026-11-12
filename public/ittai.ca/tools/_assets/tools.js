import { calculate } from './calc.js';

const root = document.querySelector('[data-tool]');

if (root) {
    const tool = root.dataset.tool;
    const form = root.querySelector('[data-calculator]');
    const output = root.querySelector('[data-result]');
    const working = root.querySelector('[data-working]');
    const email = root.querySelector('[data-email-form]');
    const started = Date.now();
    let latest = null;
    let counted = false;
    let visitorChangedInput = false;

    function values() {
        return Object.fromEntries(new FormData(form).entries());
    }

    function money(number) {
        return new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(number);
    }

    function percent(number) {
        return `${(number * 100).toFixed(2).replace(/\.00$/, '')}%`;
    }

    function storeLabour(result) {
        try {
            sessionStorage.setItem('ittaiToolLabour', JSON.stringify({
                costPaid: result.costPaid,
                costBilled: result.costBilled,
            }));
        } catch (error) {
        }
    }

    function countUse() {
        if (!visitorChangedInput || counted) {
            return;
        }
        counted = true;
        fetch('/api/tool-count.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tool, event: 'calc' }),
        }).catch(function ignoreCountError() {});
    }

    function showLabour(data) {
        const result = data.result;
        let html = `Each hour you bill costs you <strong>${money(result.costBilled)}</strong>.`;
        if (result.rate != null) {
            html += result.rate >= result.costBilled
                ? ` At ${money(result.rate)} you keep <strong>${money(result.difference)}</strong> of every billed hour, before your other costs.`
                : ` At ${money(result.rate)} you lose <strong>${money(result.difference)}</strong> on every hour you bill.`;
        }
        const lines = [
            `Wage: ${money(data.inputs.wage)}`,
            `With vacation and stat: ${money(result.withLeave)}`,
            `With CPP, EI and WorkSafeBC: ${money(result.withPayroll)}`,
            `Plus truck: ${money(result.costPaid)}`,
            `÷ ${data.inputs.billable}% billable share: ${money(result.costBilled)}`,
        ];
        storeLabour(result);
        return { html, lines };
    }

    function showMarkup(data) {
        const result = data.result;
        let html = result.mode === 'markup'
            ? `A <strong>${percent(result.markup)}</strong> markup keeps you <strong>${percent(result.margin)}</strong> of the price.`
            : `To keep <strong>${percent(result.margin)}</strong>, mark up by <strong>${percent(result.markup)}</strong>.`;
        if (result.price != null) {
            html += ` On ${money(data.inputs.cost)} of plants, charge <strong>${money(result.price)}</strong> and keep <strong>${money(result.profit)}</strong>.`;
        }
        return { html, lines: [] };
    }

    function showBreakEven(data) {
        const result = data.result;
        const html = result.hours == null
            ? `At ${money(data.inputs.rate)} each hour costs more than it brings in, so no number of hours breaks even. Raise the rate or cut the cost per hour.`
            : `You need <strong>${result.hours.toFixed(1)} billed hours a month</strong> (about <strong>${result.perWeek.toFixed(1)} a week</strong>) before you earn anything.`;
        return { html, lines: [] };
    }

    function showMow(data) {
        const result = data.result;
        let html = `Charge <strong>${money(result.final)}</strong> for this mow. That's ${money(result.perOnsiteMinute)} a minute on site.`;
        if (result.minimumApplied) {
            html += ' Your minimum charge sets the price.';
        }
        return { html, lines: [] };
    }

    function show(data) {
        let display;
        if (tool === 'labour-cost') {
            display = showLabour(data);
        } else if (tool === 'markup-margin') {
            display = showMarkup(data);
        } else if (tool === 'break-even') {
            display = showBreakEven(data);
        } else {
            display = showMow(data);
        }
        output.innerHTML = `<p>${display.html}</p>`;
        working.innerHTML = display.lines.length
            ? `<ul class="working">${display.lines.map(function lineItem(line) { return `<li>${line}</li>`; }).join('')}</ul>`
            : '';
        latest = data;
        if (email) {
            email.elements.tool.value = tool;
            email.elements.inputs.value = JSON.stringify(data.inputs);
        }
        countUse();
    }

    function run() {
        root.querySelectorAll('.error').forEach(function clearError(element) {
            element.textContent = '';
        });
        try {
            show(calculate(tool, values()));
        } catch (error) {
            latest = null;
            const match = /Invalid (\w+)/.exec(error.message);
            const key = match && match[1];
            const target = key && root.querySelector(`[data-error="${key}"]`);
            if (target) {
                target.textContent = error.message === 'Margin must be below 100'
                    ? error.message
                    : 'Enter a number within the allowed range.';
            }
            output.innerHTML = '<p>Check the highlighted field.</p>';
            working.innerHTML = '';
        }
    }

    function handleVisitorInput() {
        visitorChangedInput = true;
        run();
    }

    form.addEventListener('input', handleVisitorInput);
    form.addEventListener('change', handleVisitorInput);

    if (tool === 'break-even' || tool === 'mow-price') {
        try {
            const saved = JSON.parse(sessionStorage.getItem('ittaiToolLabour'));
            const key = tool === 'break-even' ? 'costBilled' : 'costPaid';
            const input = form.elements[tool === 'break-even' ? 'cost_per_billed_hour' : 'cost_paid'];
            if (saved && Number.isFinite(saved[key])) {
                input.value = saved[key].toFixed(2);
                root.querySelector('[data-chain-note]').textContent = (tool === 'break-even'
                    ? 'From your labour cost result.'
                    : 'Using the paid-hour figure because drive is counted separately here. ')
                    + ' Kept in this browser tab only.';
            }
        } catch (error) {
        }
    }

    run();

    if (email) {
        email.elements.t.value = String(started);
        const save = email.elements.save;

        function updateConsentFields() {
            for (const name of ['news', 'benchmarks']) {
                email.elements[name].disabled = !save.checked;
                if (!save.checked) {
                    email.elements[name].checked = false;
                }
            }
        }

        updateConsentFields();
        save.addEventListener('change', updateConsentFields);
        email.addEventListener('submit', async function submitEmail(event) {
            event.preventDefault();
            if (!latest) {
                return;
            }
            const data = new FormData(email);
            const payload = {
                email: data.get('email'),
                tool,
                inputs: latest.inputs,
                save: data.has('save'),
                news: data.has('news'),
                benchmarks: data.has('benchmarks'),
                hp: data.get('hp'),
                t: Number(data.get('t')),
            };
            const status = email.querySelector('.form-status');
            status.textContent = 'Sending…';
            try {
                const response = await fetch('/api/tool-result-email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const responseData = await response.json();
                status.textContent = responseData.message || responseData.error || 'Could not send.';
            } catch (error) {
                status.textContent = 'Could not send. Try again.';
            }
        });
    }
}
