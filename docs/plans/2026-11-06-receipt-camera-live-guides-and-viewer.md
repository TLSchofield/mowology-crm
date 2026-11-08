# iPhone receipts: live framing guides and a receipt you can check (2026-11-06)

Tim, testing 1.3.85: "I didn't see any guides for good angle" and "the receipt in the viewport should be enlarged and
movable so that a user can easily check the bill". Both go into 1.3.86 with the camera-bead tap fix and 1920 px photos.

## 1. Live guides while aiming — Apple's document camera for receipts
**Now:** the system camera (UIImagePickerController) shows nothing while aiming; the edge crop and the "Hold flat, fill
the frame" check run only after the shot.
**Change:** receipt photos use VisionKit's document camera (VNDocumentCameraViewController, as in Notes): it outlines the
receipt's edges live, tells the user to move closer / hold still, shoots by itself when the receipt is steady and square,
and returns the page already cropped and straightened. Label photos and the photo library stay as they are. The first
page is the receipt (the crew can retake inside the scanner). The blur check stays as the safety net after the shot;
the edge crop is skipped for scans (already done).
**Unknowns:** (a) the document camera cannot run in the simulator (`isSupported` is false there) — it is proven only on
Tim's iPhone through TestFlight; if it is not supported, the app falls back to today's camera + crop. (b) its default
"Color" filter brightens the page — check on #533-style receipts that the server read is no worse (compare a few
before/after reads in receipt_ocr_compare).

## 2. A receipt you can check while reviewing
**Now:** the review sheet shows the photo at most 180 pt tall; tapping opens a full-screen view whose pan is clumsy
(a ScrollView around a scaled image).
**Change:** the photo is pinned at the top of the review sheet (about 40% of the height) and stays there while the fields
scroll beneath, so the total, tax and items can be read against the paper. Pinch to zoom and drag to move inside it; double-tap
zooms to the totals width and back. A tap on the expand icon still opens full screen, which gets the same zoom view
(UIScrollView-based: smooth pinch, pan and bounce). The same view replaces the receipt detail screen's image.
**Proof:** simulator build with a DEBUG demo receipt to check layout and zoom/pan at iPhone SE and Pro Max sizes.

## Ship
Simulator build, code-reviewer (Codex out of usage) review, then one TestFlight build 1.3.86 together with the bead fix,
the photo-size fix and the server fixes (no invented GST, AI items against the printed subtotal).
