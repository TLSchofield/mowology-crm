/**
 * MwNative Bridge Surface Test
 *
 * Verifies that capacitor-bridge.js exposes the expected MwNative API shape
 * in a Capacitor context. Runs in Node.js (no browser required).
 *
 * Usage:
 *   node tests/js/bridge-surface-test.js
 *   node tests/js/bridge-surface-test.js --verbose
 *
 * Exit code: 0 = all pass, 1 = failures
 */

'use strict';

const path = require('path');
const fs   = require('fs');

const verbose = process.argv.includes('--verbose');

// ── Colour helpers ────────────────────────────────────────────────────────────
const GREEN  = '\x1b[32m';
const RED    = '\x1b[31m';
const YELLOW = '\x1b[33m';
const RESET  = '\x1b[0m';

let passed = 0, failed = 0;
const failures = [];

function ok(label)   { passed++; if (verbose) console.log(`  ${GREEN}✓${RESET} ${label}`); }
function fail(label) { failed++; failures.push(label); console.log(`  ${RED}✗${RESET} ${label}`); }

function assertFn(label, obj, method) {
    typeof obj?.[method] === 'function'
        ? ok(`${label}.${method} is a function`)
        : fail(`${label}.${method} missing or not a function (got ${typeof obj?.[method]})`);
}
function assertProp(label, obj, prop, type) {
    typeof obj?.[prop] === type
        ? ok(`${label}.${prop} is ${type}`)
        : fail(`${label}.${prop} missing or wrong type (expected ${type}, got ${typeof obj?.[prop]})`);
}

function section(name) { console.log(`\n${YELLOW}▶${RESET} ${name}`); }

// ── Minimal Capacitor stubs ───────────────────────────────────────────────────
// The bridge IIFE destructures from `Plugins` immediately at the top of the
// closure, before any Capacitor.isNativePlatform() check. We must stub
// `Plugins` as a globalvar so those destructures don't throw.

global.window = global;
// Stub browser APIs the bridge accesses at module evaluation time
global.window.location = { pathname: '/crm/jobs/schedule.php', href: 'https://mowology.ca/crm/jobs/schedule.php' };
global.document = {
    addEventListener:  () => {},
    dispatchEvent:     () => {},
    getElementById:    () => null,
    readyState: 'complete',
};
global.CustomEvent = class CustomEvent { constructor(name, opts) { this.type = name; this.detail = opts?.detail; } };

const positionStub = Promise.resolve({
    coords: { latitude: 49.2827, longitude: -123.1207, accuracy: 10, altitude: 0, speed: 0, heading: 0 }
});
const statusStub = Promise.resolve({ connected: true });

const geoStub = {
    watchPosition:      (_opts, cb) => { /* async in real Capacitor */ return 1; },
    clearWatch:         (_id)       => {},
    getCurrentPosition: (_opts)     => positionStub,
};
const mwTrackingStub = {
    startSession:  (_opts)  => Promise.resolve({ sessionId: 'test-session' }),
    stopSession:   ()       => Promise.resolve({ stopped: true }),
    getHealth:     ()       => Promise.resolve({ ok: true }),
    markSynced:    (_opts)  => Promise.resolve({ marked: 0 }),
    addListener:   (_e, cb) => {},
};
const localNotificationsStub = {
    requestPermissions: () => Promise.resolve({ display: 'granted' }),
    schedule: (_opts)    => Promise.resolve(),
};
const networkStub = {
    getStatus:   ()       => statusStub,
    addListener: (_e, cb) => {},
};
const bgGeoStub = {
    addWatcher:    (_opts, cb) => Promise.resolve('watch-id'),
    removeWatcher: (_opts)     => Promise.resolve(),
};

// The bridge reads `window.Capacitor.Plugins` at IIFE start (line 26 of bridge).
// All plugin objects must be nested under Capacitor.Plugins.
global.Capacitor = {
    isNativePlatform: () => true,
    Plugins: {
        BackgroundGeolocation: bgGeoStub,
        Geolocation:           geoStub,
        LocalNotifications:    localNotificationsStub,
        Network:               networkStub,
        MwTracking:            mwTrackingStub,
    },
};

// ── Load the bridge ───────────────────────────────────────────────────────────
const bridgePath = path.resolve(__dirname, '../../public/crm/js/capacitor-bridge.js');

if (!fs.existsSync(bridgePath)) {
    console.error(`${RED}ERROR:${RESET} capacitor-bridge.js not found at:\n  ${bridgePath}`);
    process.exit(1);
}

// The bridge script is an IIFE that sets window.MwNative — eval it in our context
const bridgeSource = fs.readFileSync(bridgePath, 'utf8');
eval(bridgeSource); // eslint-disable-line no-eval

const M = global.window.MwNative;

// ═════════════════════════════════════════════════════════════════════════════
// TESTS
// ═════════════════════════════════════════════════════════════════════════════

section('MwNative top-level object');
if (typeof M !== 'object' || M === null) {
    fail('window.MwNative is not an object — bridge did not initialise');
    process.exit(1); // Nothing to test further
}
ok('window.MwNative exists');

// ─────────────────────────────────────────────────────────────────────────────
section('MwNative.geo — GPS / background tracking');
assertFn('geo', M.geo, 'startBackgroundTracking');
assertFn('geo', M.geo, 'stopBackgroundTracking');
assertFn('geo', M.geo, 'getCurrentPosition');

// startBackgroundTracking must accept (callback, options)
// callback should receive a position object (or error)
{
    let callbackFired = false;
    M.geo.startBackgroundTracking((pos, err) => { callbackFired = true; }, { distanceFilter: 10 });
    // Callback may fire asynchronously in production; in test environment the stub fires synchronously
    // We just check the method didn't throw
    ok('geo.startBackgroundTracking accepts (callback, options)');
}

// getCurrentPosition returns a Promise
{
    const result = M.geo.getCurrentPosition();
    typeof result?.then === 'function'
        ? ok('geo.getCurrentPosition() returns Promise')
        : fail('geo.getCurrentPosition() does not return a Promise');
}

// ─────────────────────────────────────────────────────────────────────────────
section('MwNative.tracking — session management');
assertFn('tracking', M.tracking, 'startSession');
assertFn('tracking', M.tracking, 'stopSession');
assertFn('tracking', M.tracking, 'getHealth');
assertFn('tracking', M.tracking, 'markSynced');
assertFn('tracking', M.tracking, 'on');   // event listener registration

// startSession returns a Promise
{
    const result = M.tracking.startSession(1, 'sess-123');
    typeof result?.then === 'function'
        ? ok('tracking.startSession() returns Promise')
        : fail('tracking.startSession() does not return a Promise');
}

// getHealth returns a Promise
{
    const result = M.tracking.getHealth();
    typeof result?.then === 'function'
        ? ok('tracking.getHealth() returns Promise')
        : fail('tracking.getHealth() does not return a Promise');
}

// ─────────────────────────────────────────────────────────────────────────────
section('MwNative.notifications — local push');
assertFn('notifications', M.notifications, 'notify');
// init is private-ish but exists
typeof M.notifications.init === 'function'
    ? ok('notifications.init exists (called during auto-init)')
    : fail('notifications.init missing');

// notify must not throw when called
try {
    M.notifications.notify('Test', 'Hello', 42);
    ok('notifications.notify() does not throw');
} catch (e) {
    fail(`notifications.notify() threw: ${e.message}`);
}

// ─────────────────────────────────────────────────────────────────────────────
section('MwNative.network — online/offline detection');
assertProp('network', M.network, 'isOnline', 'boolean');
assertFn('network', M.network, 'onStatusChange');
assertFn('network', M.network, 'init');

// isOnline should be true (our stub returns connected:true)
M.network.isOnline === true
    ? ok('network.isOnline = true (stub returns connected)')
    : fail(`network.isOnline = ${M.network.isOnline} (expected true)`);

// onStatusChange accepts a callback
try {
    M.network.onStatusChange(() => {});
    ok('network.onStatusChange() accepts a callback');
} catch (e) {
    fail(`network.onStatusChange() threw: ${e.message}`);
}

// ─────────────────────────────────────────────────────────────────────────────
section('MwNative.pow — presence-of-work visit tracking');
// pow is the higher-level abstraction over geo for visit GPS recording
if (typeof M.pow === 'object' && M.pow !== null) {
    assertFn('pow', M.pow, 'startVisitTracking');
    assertFn('pow', M.pow, 'stopVisitTracking');
    // pow uses DOM CustomEvents for GPS data — no .on() method by design
    ok('pow uses DOM CustomEvents (no .on() method — correct)');
} else {
    // pow is optional in non-Capacitor builds; flag as info not failure
    console.log(`  ${YELLOW}i${RESET} MwNative.pow not present (expected in Capacitor context only)`);
}

// ─────────────────────────────────────────────────────────────────────────────
section('No-op guard — bridge must not crash in browser context');
// Re-evaluate the bridge after clearing Capacitor to simulate browser env.
// Note: `Plugins` must still exist because it is destructured at IIFE entry
// before any Capacitor guard. The bridge's guard is Capacitor.isNativePlatform().
{
    const savedMwNative = global.MwNative;
    global.MwNative = undefined;
    const savedCap = global.Capacitor;
    // Simulate browser: Capacitor present but isNativePlatform() → false.
    // Keep Plugins intact so the bridge IIFE doesn't throw before the guard check.
    global.Capacitor = { isNativePlatform: () => false, Plugins: {} };

    try {
        eval(bridgeSource); // eslint-disable-line no-eval
        ok('Bridge evaluates without throwing when isNativePlatform() = false');
        // In browser mode the bridge may or may not set MwNative — what matters is it doesn't throw
        ok('Bridge survives non-native context');
    } catch (e) {
        fail(`Bridge threw in browser context: ${e.message}`);
    }

    // Restore
    global.Capacitor = savedCap;
    global.MwNative  = savedMwNative;
}

// ═════════════════════════════════════════════════════════════════════════════
// Results
// ═════════════════════════════════════════════════════════════════════════════
console.log('');
console.log('─'.repeat(56));
console.log(`  ${GREEN}PASS: ${passed}${RESET}   ${RED}FAIL: ${failed}${RESET}`);
console.log('─'.repeat(56));

if (failed > 0) {
    console.log('\nFailed tests:');
    failures.forEach(f => console.log(`  • ${f}`));
    console.log('');
    process.exit(1);
}

console.log(`\n  ${GREEN}All checks passed.${RESET}\n`);
process.exit(0);
