/**
 * "Product / machine label" mode of the receipt capture (expenses page, mobile + Android app).
 *
 * window.triggerLabelCamera(): opens the camera, shrinks the photo, and posts it to
 * /crm/api/label-products.php (mode=capture). Same media upload + OCR as a receipt, but it
 * never creates an expense: Penny (product) or Otto (machine) shows a proposal on the
 * dashboard. The answer ("New product: … Penny will ask you to add it") is shown with mwToast.
 * Uses MwCameraPermission's denied guard like triggerCamera() does.
 */
(function () {
    'use strict';
    var API = '/crm/api/label-products.php';

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return window.CSRF || window.MW_CSRF_TOKEN || (m ? m.content : '') || '';
    }
    function toast(text, kind) {
        if (window.mwToast) window.mwToast(text, kind || 'success', 6000);
        else alert(text);
    }
    function status(text) {
        var el = document.getElementById('mwLabelStatus');
        if (!el) return;
        el.textContent = text || '';
        el.hidden = !text;
    }

    // Phone photos are 8–12 MB; labels read fine at 2000 px.
    function shrink(file) {
        return new Promise(function (resolve) {
            if (!/^image\/(jpeg|png|webp)$/i.test(file.type) || !window.createImageBitmap) { resolve(file); return; }
            createImageBitmap(file).then(function (bmp) {
                var scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
                var c = document.createElement('canvas');
                c.width = Math.round(bmp.width * scale);
                c.height = Math.round(bmp.height * scale);
                c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
                c.toBlob(function (b) { resolve(b ? new File([b], 'label.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
            }).catch(function () { resolve(file); });
        });
    }

    function where() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) { resolve(null); return; }
            var done = false;
            setTimeout(function () { if (!done) { done = true; resolve(null); } }, 4000);
            navigator.geolocation.getCurrentPosition(function (p) { if (!done) { done = true; resolve(p.coords); } },
                function () { if (!done) { done = true; resolve(null); } }, { timeout: 4000, maximumAge: 60000 });
        });
    }

    function send(file) {
        status('Reading the label…');
        return Promise.all([shrink(file), where()]).then(function (r) {
            var fd = new FormData();
            fd.append('mode', 'capture');
            fd.append('label_photo', r[0], 'label.jpg');
            fd.append('csrf_token', csrf());
            if (r[1]) { fd.append('lat', r[1].latitude); fd.append('lng', r[1].longitude); }
            return fetch(API, { method: 'POST', credentials: 'same-origin', body: fd });
        }).then(function (res) { return res.json(); }).then(function (d) {
            status('');
            if (d && d.ok) toast(d.message || 'Label saved.', d.kind ? 'success' : 'warning');
            else toast((d && (d.error || d.message)) || 'The label could not be saved.', 'error');
        }).catch(function () {
            status('');
            toast('No connection — the label was not sent. Try again when you have signal.', 'error');
        });
    }

    window.triggerLabelCamera = function () {
        if (window.MwCameraPermission && MwCameraPermission.isNativeAndroid() && MwCameraPermission.isDenied()) {
            MwCameraPermission.showDeniedDialog();
            return;
        }
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.setAttribute('capture', 'environment');
        input.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;z-index:-1;';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (input.parentNode) input.parentNode.removeChild(input);
            if (f) send(f);
        });
        input.click();
    };
})();
