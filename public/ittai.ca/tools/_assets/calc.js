const fields = {
    'labour-cost': ['wage', 'vacation', 'stat', 'cpp', 'ei', 'wcb', 'billable', 'truck', 'rate'],
    'markup-margin': ['mode', 'percent', 'cost'],
    'break-even': [
        'truck_payment',
        'insurance',
        'phone_software',
        'yard_storage',
        'own_pay',
        'other',
        'rate',
        'cost_per_billed_hour',
    ],
    'mow-price': ['onsite', 'crew', 'drive', 'cost_paid', 'margin', 'minimum'],
};

export function allowlist(tool, raw) {
    if (!fields[tool]) {
        throw Error('Unknown tool');
    }
    const output = {};
    for (const key of fields[tool]) {
        if (key === 'mode') {
            output[key] = raw[key] === 'margin' ? 'margin' : 'markup';
            continue;
        }
        if (raw[key] !== '' && raw[key] != null && Number.isFinite(Number(raw[key]))) {
            output[key] = Number(raw[key]);
        }
    }
    return output;
}

function need(input, key, min, max) {
    if (input[key] == null || input[key] < min || input[key] > max) {
        throw Error(`Invalid ${key}`);
    }
    return input[key];
}

function optional(input, key, min, max) {
    return input[key] == null ? null : need(input, key, min, max);
}

function pack(tool, inputs, result) {
    return { tool, version: 1, inputs, result };
}

function calculateLabour(tool, input) {
    const wage = need(input, 'wage', 0.01, 1000);
    const vacation = need(input, 'vacation', 0, 100);
    const stat = need(input, 'stat', 0, 100);
    const cpp = need(input, 'cpp', 0, 100);
    const ei = need(input, 'ei', 0, 100);
    const wcb = need(input, 'wcb', 0, 100);
    const billable = need(input, 'billable', 0.01, 100);
    const truck = need(input, 'truck', 0, 10000);
    const rate = optional(input, 'rate', 0, 10000);
    const withLeave = wage * (1 + (vacation + stat) / 100);
    const withPayroll = withLeave * (1 + (cpp + ei + wcb) / 100);
    const burden = (1 + (vacation + stat) / 100) * (1 + (cpp + ei + wcb) / 100) - 1;
    const costPaid = wage * (1 + burden) + truck;
    const costBilled = costPaid / (billable / 100);
    return pack(tool, input, {
        burden,
        withLeave,
        withPayroll,
        costPaid,
        costBilled,
        rate,
        difference: rate == null ? null : Math.abs(rate - costBilled),
    });
}

function calculateMarkup(tool, input) {
    const percent = need(input, 'percent', 0, 100000);
    const cost = optional(input, 'cost', 0, 100000000);
    const mode = input.mode || 'markup';
    let markup;
    let margin;
    if (mode === 'margin') {
        if (percent >= 100) {
            throw Error('Margin must be below 100');
        }
        margin = percent / 100;
        markup = margin / (1 - margin);
    } else {
        markup = percent / 100;
        margin = markup / (1 + markup);
    }
    const price = cost == null ? null : cost * (1 + markup);
    return pack(tool, input, {
        mode,
        markup,
        margin,
        price,
        profit: price == null ? null : price - cost,
    });
}

function calculateBreakEven(tool, input) {
    let fixed = 0;
    for (const key of fields[tool].slice(0, 6)) {
        fixed += optional(input, key, 0, 100000000) || 0;
    }
    const rate = need(input, 'rate', 0, 1000000);
    const cost = need(input, 'cost_per_billed_hour', 0, 1000000);
    const contribution = rate - cost;
    const hours = contribution > 0 ? fixed / contribution : null;
    return pack(tool, input, {
        fixed,
        contribution,
        hours,
        perWeek: hours == null ? null : hours * 12 / 52,
    });
}

function calculateMow(tool, input) {
    const onsite = need(input, 'onsite', 0.01, 100000);
    const crew = need(input, 'crew', 1, 50);
    if (!Number.isInteger(crew)) {
        throw Error('Invalid crew');
    }
    const drive = need(input, 'drive', 0, 100000);
    const cost = need(input, 'cost_paid', 0, 1000000);
    const margin = need(input, 'margin', 0, 99.99);
    const minimum = optional(input, 'minimum', 0, 1000000) || 0;
    const labour = (onsite + drive) * crew * cost / 60;
    const price = labour / (1 - margin / 100);
    const final = Math.max(price, minimum);
    return pack(tool, input, {
        labour,
        price,
        final,
        perOnsiteMinute: final / onsite,
        minimumApplied: minimum > price,
    });
}

export function calculate(tool, raw) {
    const input = allowlist(tool, raw);
    if (tool === 'labour-cost') {
        return calculateLabour(tool, input);
    }
    if (tool === 'markup-margin') {
        return calculateMarkup(tool, input);
    }
    if (tool === 'break-even') {
        return calculateBreakEven(tool, input);
    }
    if (tool === 'mow-price') {
        return calculateMow(tool, input);
    }
    throw Error('Unknown tool');
}
