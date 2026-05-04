//
//  LiveReceiptScanner.swift
//  MowologyCRM
//
//  DataScannerViewController wrapper for live receipt scanning with real-time
//  text bounding-box overlays. Requires iOS 17+ / Neural Engine.
//
//  Call DataScannerViewController.isSupported before presenting this view;
//  fall back to CameraPicker when false.
//

import SwiftUI
import VisionKit

@MainActor
struct LiveReceiptScanner: UIViewControllerRepresentable {

    /// Called with the captured UIImage and the array of transcript strings
    /// recognised by VisionKit at the moment of capture.
    var onCapture: (UIImage, [String]) -> Void
    var onCancel:  () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(parent: self) }

    func makeUIViewController(context: Context) -> DataScannerViewController {
        let scanner = DataScannerViewController(
            recognizedDataTypes: [.text()],
            qualityLevel: .accurate,
            recognizesMultipleItems: true,
            isHighFrameRateTrackingEnabled: false,
            isPinchToZoomEnabled: true,
            isGuidanceEnabled: true,
            isHighlightingEnabled: true
        )
        scanner.delegate        = context.coordinator
        context.coordinator.scanner = scanner
        try? scanner.startScanning()
        addOverlay(to: scanner.view, coordinator: context.coordinator)
        return scanner
    }

    func updateUIViewController(_ uiViewController: DataScannerViewController, context: Context) {}

    static func dismantleUIViewController(_ uiViewController: DataScannerViewController,
                                          coordinator: Coordinator) {
        uiViewController.stopScanning()
    }

    // MARK: - Overlay

    private func addOverlay(to view: UIView, coordinator: Coordinator) {
        let captureBtn = makeCaptureButton(coordinator: coordinator)
        let cancelBtn  = makeCancelButton(coordinator: coordinator)
        let hint       = makeHintLabel()

        [captureBtn, cancelBtn, hint].forEach { view.addSubview($0) }

        NSLayoutConstraint.activate([
            captureBtn.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            captureBtn.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor, constant: -24),
            captureBtn.widthAnchor.constraint(equalToConstant: 72),
            captureBtn.heightAnchor.constraint(equalToConstant: 72),

            cancelBtn.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 28),
            cancelBtn.centerYAnchor.constraint(equalTo: captureBtn.centerYAnchor),

            hint.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            hint.bottomAnchor.constraint(equalTo: captureBtn.topAnchor, constant: -16),
            hint.heightAnchor.constraint(equalToConstant: 30),
            hint.widthAnchor.constraint(greaterThanOrEqualToConstant: 150),
        ])
    }

    private func makeCaptureButton(coordinator: Coordinator) -> UIButton {
        let btn = UIButton(type: .custom)
        btn.translatesAutoresizingMaskIntoConstraints = false
        btn.backgroundColor       = .white
        btn.layer.cornerRadius    = 36
        btn.layer.borderWidth     = 4
        btn.layer.borderColor     = UIColor.systemGray4.cgColor
        btn.addTarget(coordinator, action: #selector(Coordinator.capturePhoto), for: .touchUpInside)
        return btn
    }

    private func makeCancelButton(coordinator: Coordinator) -> UIButton {
        let btn = UIButton(type: .system)
        btn.translatesAutoresizingMaskIntoConstraints = false
        btn.setTitle("Cancel", for: .normal)
        btn.setTitleColor(.white, for: .normal)
        btn.titleLabel?.font = .systemFont(ofSize: 17, weight: .medium)
        btn.addTarget(coordinator, action: #selector(Coordinator.cancel), for: .touchUpInside)
        return btn
    }

    private func makeHintLabel() -> UILabel {
        let lbl = UILabel()
        lbl.translatesAutoresizingMaskIntoConstraints = false
        lbl.text            = "Point at receipt"
        lbl.textColor       = .white
        lbl.font            = .systemFont(ofSize: 13, weight: .regular)
        lbl.textAlignment   = .center
        lbl.backgroundColor = UIColor.black.withAlphaComponent(0.40)
        lbl.layer.cornerRadius = 10
        lbl.clipsToBounds   = true
        return lbl
    }

    // MARK: - Coordinator

    final class Coordinator: NSObject, DataScannerViewControllerDelegate {

        let parent: LiveReceiptScanner
        weak var scanner: DataScannerViewController?
        private var currentLines: [String] = []

        init(parent: LiveReceiptScanner) { self.parent = parent }

        // MARK: DataScannerViewControllerDelegate

        func dataScanner(_ dataScanner: DataScannerViewController,
                         didAdd addedItems: [RecognizedItem],
                         allItems: [RecognizedItem]) { updateLines(from: allItems) }

        func dataScanner(_ dataScanner: DataScannerViewController,
                         didUpdate updatedItems: [RecognizedItem],
                         allItems: [RecognizedItem]) { updateLines(from: allItems) }

        func dataScanner(_ dataScanner: DataScannerViewController,
                         didRemove removedItems: [RecognizedItem],
                         allItems: [RecognizedItem]) { updateLines(from: allItems) }

        private func updateLines(from items: [RecognizedItem]) {
            currentLines = items.compactMap {
                if case .text(let t) = $0 { return t.transcript }
                return nil
            }
        }

        // MARK: Actions

        @objc func capturePhoto() {
            guard let scanner else { return }
            Task { @MainActor in
                guard let photo = try? await scanner.capturePhoto() else { return }
                self.parent.onCapture(photo, self.currentLines)
            }
        }

        @objc func cancel() { parent.onCancel() }
    }
}
