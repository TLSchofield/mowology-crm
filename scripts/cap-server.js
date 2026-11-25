#!/usr/bin/env node
/**
 * scripts/cap-server.js
 *
 * Switch capacitor.config.json between production and local MAMP dev server.
 *
 * Usage:
 *   npm run cap:prod   — point to https://mowology.ca  (production)
 *   npm run cap:dev    — point to http://<LAN_IP>:8888  (MAMP local)
 *
 * The LAN IP is auto-detected from your network interfaces.
 * Run `npm run cap:prod` before committing — never commit the dev URL.
 */

'use strict';

var fs  = require('fs');
var os  = require('os');
var path = require('path');

var CONFIG_PATH = path.join(__dirname, '..', 'capacitor.config.json');
var MODE = process.argv[2]; // 'prod' or 'dev'
var MAMP_PORT = process.env.MAMP_PORT || '8888';

if (MODE !== 'prod' && MODE !== 'dev') {
    console.error('Usage: node scripts/cap-server.js [prod|dev]');
    process.exit(1);
}

// ── Detect LAN IP ─────────────────────────────────────────────────────────
function getLanIp() {
    var interfaces = os.networkInterfaces();
    for (var name of Object.keys(interfaces)) {
        for (var iface of interfaces[name]) {
            if (iface.family === 'IPv4' && !iface.internal) {
                return iface.address;
            }
        }
    }
    return '127.0.0.1';
}

// ── Read + patch config ────────────────────────────────────────────────────
var raw    = fs.readFileSync(CONFIG_PATH, 'utf8');
var config = JSON.parse(raw);

if (MODE === 'prod') {
    config.server = {
        url: 'https://mowology.ca',
        cleartext: false,
        androidScheme: 'https'
    };
    console.log('✅  Capacitor → production: https://mowology.ca');
} else {
    var ip  = getLanIp();
    var url = 'http://' + ip + ':' + MAMP_PORT;
    config.server = {
        url: url,
        cleartext: true,
        androidScheme: 'http'
    };
    console.log('🛠   Capacitor → local MAMP: ' + url);
    console.log('    (Run npm run cap:prod before committing!)');
}

fs.writeFileSync(CONFIG_PATH, JSON.stringify(config, null, 2) + '\n', 'utf8');
console.log('    Written: capacitor.config.json');
