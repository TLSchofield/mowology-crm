/**
 * SocialVideoBuilder
 * Canvas-based before/after animation engine for social posts.
 *
 * Usage:
 *   const svb = new SocialVideoBuilder(canvasEl, { onStateChange: fn });
 *   svb.loadImages(beforeUrl, afterUrl);
 *   svb.setAnimType('wipe');
 *   svb.play();
 *   svb.capture().then(blob => ...);
 */
class SocialVideoBuilder {
    constructor(canvas, opts) {
        opts = opts || {};
        this.canvas   = canvas;
        this.ctx      = canvas.getContext('2d');
        this.W        = 1080;
        this.H        = 1080;
        this.animType = 'wipe';
        this.imgBefore = null;
        this.imgAfter  = null;
        this.branding  = {};
        this._raf      = null;
        this._playing  = false;
        this._onState  = opts.onStateChange || null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    loadImages(beforeUrl, afterUrl) {
        var self = this;
        self.imgBefore = null;
        self.imgAfter  = null;

        function loadOne(url) {
            return new Promise(function(res) {
                if (!url) { res(null); return; }
                var img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload  = function() { res(img); };
                img.onerror = function() { res(null); };
                img.src = url;
            });
        }

        return Promise.all([loadOne(beforeUrl), loadOne(afterUrl)]).then(function(imgs) {
            self.imgBefore = imgs[0];
            self.imgAfter  = imgs[1];
            if (!self._playing) self.drawFrame(0.02);
        });
    }

    setBranding(opts) {
        var b = this.branding;
        if (opts.name    !== undefined) b.name    = opts.name;
        if (opts.city    !== undefined) b.city    = opts.city;
        if (opts.service !== undefined) b.service = opts.service;
        if (opts.caption !== undefined) b.caption = opts.caption;
    }

    setAnimType(type) {
        this.animType = type;
        if (!this._playing) this.drawFrame(0.02);
    }

    play() {
        if (this._playing) { this.stop(); return; }
        this._playing = true;
        this._notify();
        var self = this, t0 = null, dur = 2200;
        function step(ts) {
            if (!t0) t0 = ts;
            var p = (ts - t0) / dur;
            self.drawFrame(p);
            if (p < 1) {
                self._raf = requestAnimationFrame(step);
            } else {
                self.drawFrame(1);
                self._playing = false;
                self._notify();
            }
        }
        this._raf = requestAnimationFrame(step);
    }

    stop() {
        if (this._raf) cancelAnimationFrame(this._raf);
        this._playing = false;
        this._notify();
    }

    isPlaying() { return this._playing; }

    /** Renders the full animation and returns a video Blob via MediaRecorder. */
    capture() {
        var self = this;
        return new Promise(function(resolve) {
            if (!window.MediaRecorder || !self.canvas.captureStream) {
                resolve(null); return;
            }
            try {
                if (self._playing) self.stop();
                var stream = self.canvas.captureStream(30);
                var mime   = MediaRecorder.isTypeSupported('video/mp4') ? 'video/mp4' : 'video/webm';
                var rec    = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 4000000 });
                var chunks = [];
                rec.ondataavailable = function(e) { if (e.data.size > 0) chunks.push(e.data); };
                rec.onstop = function() { resolve(new Blob(chunks, { type: mime })); };
                rec.start(100);
                var t0 = null, dur = 2200;
                function step(ts) {
                    if (!t0) t0 = ts;
                    var p = (ts - t0) / dur;
                    self.drawFrame(p);
                    if (p < 1) requestAnimationFrame(step);
                    else { self.drawFrame(1); setTimeout(function() { rec.stop(); }, 150); }
                }
                requestAnimationFrame(step);
            } catch(e) { resolve(null); }
        });
    }

    captureFrame(quality) {
        var self = this;
        return new Promise(function(res) { self.canvas.toBlob(res, 'image/jpeg', quality || 0.92); });
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    drawFrame(progress) {
        var t   = this._ease(Math.min(1, Math.max(0, progress)));
        var c   = this.ctx;
        var W   = this.W, H = this.H;
        c.clearRect(0, 0, W, H);
        c.fillStyle = '#0d1f12'; c.fillRect(0, 0, W, H);

        if (!this.imgBefore && !this.imgAfter) {
            c.fillStyle = 'rgba(255,255,255,.18)';
            c.font = '34px Arial'; c.textAlign = 'center';
            c.fillText('Select before & after photos', W / 2, H / 2);
            c.textAlign = 'left';
            return;
        }

        if (this.animType === 'wipe') {
            this._fill(this.imgBefore, 0, 0, W, H);
            var cx = t * W;
            c.save(); c.beginPath(); c.rect(cx, 0, W, H); c.clip();
            this._fill(this.imgAfter, 0, 0, W, H); c.restore();
            if (t > 0.02 && t < 0.98) {
                c.strokeStyle = '#c9a84c'; c.lineWidth = 5;
                c.beginPath(); c.moveTo(cx, 0); c.lineTo(cx, H); c.stroke();
                c.fillStyle = '#c9a84c';
                this._rrect(cx - 30, H / 2 - 30, 60, 60, 30); c.fill();
                c.fillStyle = '#0d1f12'; c.font = 'bold 28px Arial'; c.textAlign = 'center';
                c.fillText('↔', cx, H / 2 + 11); c.textAlign = 'left';
            }
        } else if (this.animType === 'split') {
            var half = W / 2, gap = t * half;
            c.save(); c.beginPath(); c.rect(0, 0, half - gap / 2, H); c.clip();
            this._fill(this.imgBefore, 0, 0, W, H); c.restore();
            c.save(); c.beginPath(); c.rect(half + gap / 2, 0, half - gap / 2, H); c.clip();
            this._fill(this.imgAfter, 0, 0, W, H); c.restore();
            if (t > 0.08) {
                c.save(); c.beginPath(); c.rect(half - gap / 2, 0, gap, H); c.clip();
                this._fill(this.imgAfter, 0, 0, W, H); c.restore();
            }
        } else if (this.animType === 'zoom') {
            this._fill(this.imgBefore, 0, 0, W, H);
            c.save(); c.globalAlpha = t;
            var s = 0.5 + t * 0.5;
            c.translate(W / 2, H / 2); c.scale(s, s); c.translate(-W / 2, -H / 2);
            this._fill(this.imgAfter, 0, 0, W, H); c.restore();
        } else if (this.animType === 'fade') {
            this._fill(this.imgBefore, 0, 0, W, H);
            c.save(); c.globalAlpha = t; this._fill(this.imgAfter, 0, 0, W, H); c.restore();
        } else if (this.animType === 'reveal') {
            this._fill(this.imgBefore, 0, 0, W, H);
            var radius = t * W * 0.85;
            c.save(); c.beginPath(); c.arc(W / 2, H / 2, radius, 0, Math.PI * 2); c.clip();
            this._fill(this.imgAfter, 0, 0, W, H); c.restore();
            if (t > 0.02 && t < 0.98) {
                c.strokeStyle = '#c9a84c'; c.lineWidth = 6;
                c.beginPath(); c.arc(W / 2, H / 2, radius, 0, Math.PI * 2); c.stroke();
            }
        } else if (this.animType === 'blinds') {
            var rows = 8, rh = H / rows;
            this._fill(this.imgBefore, 0, 0, W, H);
            for (var i = 0; i < rows; i++) {
                var delay = i / rows * 0.4;
                var lt = this._ease(Math.min(1, Math.max(0, (t - delay) / 0.6)));
                c.save(); c.beginPath(); c.rect(0, i * rh, W * lt, rh); c.clip();
                this._fill(this.imgAfter, 0, 0, W, H); c.restore();
            }
        }

        this._branding(t);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    _ease(t) { return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t; }

    _fill(img, x, y, w, h) {
        if (!img) return;
        var r = Math.max(w / img.width, h / img.height);
        var dw = img.width * r, dh = img.height * r;
        this.ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }

    _rrect(x, y, w, h, r) {
        var c = this.ctx;
        c.beginPath();
        c.moveTo(x + r, y); c.lineTo(x + w - r, y); c.quadraticCurveTo(x + w, y, x + w, y + r);
        c.lineTo(x + w, y + h - r); c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        c.lineTo(x + r, y + h); c.quadraticCurveTo(x, y + h, x, y + h - r);
        c.lineTo(x, y + r); c.quadraticCurveTo(x, y, x + r, y);
        c.closePath();
    }

    _branding(t) {
        var c = this.ctx, W = this.W, H = this.H, b = this.branding;
        var name    = b.name    || 'Mowology';
        var city    = b.city    || '';
        var service = b.service || '';
        var caption = ((b.caption || '').split('\n')[0]) || '';

        var tg = c.createLinearGradient(0, 0, 0, 200);
        tg.addColorStop(0, 'rgba(13,31,18,.88)'); tg.addColorStop(1, 'rgba(13,31,18,0)');
        c.fillStyle = tg; c.fillRect(0, 0, W, 200);

        c.fillStyle = '#fff'; c.font = 'bold 58px Arial';
        c.fillText(name.toUpperCase(), 54, 78);
        c.fillStyle = '#c9a84c';
        c.beginPath(); c.arc(54 + c.measureText(name.toUpperCase()).width + 20, 62, 9, 0, Math.PI * 2); c.fill();

        if (city) {
            c.fillStyle = 'rgba(255,255,255,.6)'; c.font = '28px Arial';
            c.fillText('📍 ' + city, 54, 118);
        }

        var bg = c.createLinearGradient(0, H - 220, 0, H);
        bg.addColorStop(0, 'rgba(13,31,18,0)'); bg.addColorStop(0.35, 'rgba(13,31,18,.88)'); bg.addColorStop(1, 'rgba(13,31,18,.96)');
        c.fillStyle = bg; c.fillRect(0, H - 220, W, 220);

        if (service) {
            c.fillStyle = '#2d7a3a';
            this._rrect(54, H - 168, 300, 52, 8); c.fill();
            c.fillStyle = '#fff'; c.font = 'bold 28px Arial';
            c.fillText(service, 72, H - 133);
        }

        if (caption.length > 2) {
            c.fillStyle = '#c9a84c'; c.font = 'bold 36px Arial';
            var cap = caption.length > 36 ? caption.slice(0, 34) + '…' : caption;
            c.fillText(cap, 54, H - 78);
        }

        if (t < 0.35) this._pill('BEFORE', W - 220, H - 148, '#fff');
        else if (t > 0.7) this._pill('AFTER', W - 200, H - 148, '#c9a84c');

        c.fillStyle = 'rgba(255,255,255,.3)'; c.font = '22px Arial';
        c.fillText('mowology.ca', W - 232, H - 36);
    }

    _pill(text, x, y, color) {
        var c = this.ctx;
        c.save();
        c.fillStyle = 'rgba(0,0,0,.55)';
        var w = c.measureText(text).width + 36;
        this._rrect(x - 18, y - 40, w, 54, 7); c.fill();
        c.fillStyle = color; c.font = 'bold 34px Arial';
        c.fillText(text, x, y);
        c.restore();
    }

    _notify() { if (this._onState) this._onState(this._playing); }
}
