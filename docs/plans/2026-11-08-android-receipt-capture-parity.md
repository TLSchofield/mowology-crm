# Android receipt capture: catching up with the iPhone (for Codex, after 2026-11-11)

From the capture review of 2026-11-08 (server and iPhone done first). Crew app, `~/Projects/mowology-android-codex`, branch `codex/native`. Run with `scripts/codex-build.sh`.

1. **Text readability check.** After the shot in `MwCameraActivity.saveReceiptShot`, run `DeviceTextScanner.read` and use the iPhone's rule, in `ReceiptPhotoLogic`: non-space characters >= 150, or >= 60 with a money amount `\d[.,]\d{2}\b`. Weak: the existing hold and hint with Retake / Use this photo. A read that fails or times out never blocks. Unit-test the rule.
2. **Blur.** Measure after the scanner crop only; on an uncropped CameraX frame the number is not comparable. Keep 40 until real values arrive.
3. **Send capture quality.** Multipart field `client_capture`: JSON `{"chars":int,"sharpness":number,"used_anyway":bool}` (`cleanDeviceCapture`, DeviceOcrCompare.php).
4. **Refused receipts.** `ReceiptListDialog`: Try again (state back to `pending`, attempts 0) and Discard (with a confirm) on `refused` rows. A 403/404/400 that is not the app's own 413/415 JSON error is RETRY, as on the iPhone.
5. **Office sees refusals.** Report the refused count with the app's existing health report.
6. **Filed status.** `receipt-capture` returns `status`. When it is `rejected` the list says "Filed: already in the closed books" and not "Sent".
7. **Photo size.** Resize to 1920 px, JPEG 78%, at most 1.5 MB, before queueing, as the iPhone does (server limit 10 MB).

Keep: queue-first at the shutter, `client_ref`, and moving past a failed item. They are right already.
