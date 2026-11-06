//
//  ReceiptCaptureView.swift
//  MowologyCRM
//
//  UIImagePickerController wrapper. Used by ReceiptsView as a fullScreenCover.
//

import SwiftUI
import UIKit
import VisionKit

struct CameraPicker: UIViewControllerRepresentable {
    var onCapture: (UIImage) -> Void
    var onCancel:  () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker        = UIImagePickerController()
        picker.sourceType = UIImagePickerController.isSourceTypeAvailable(.camera)
                          ? .camera : .photoLibrary
        picker.delegate   = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    final class Coordinator: NSObject,
                             UIImagePickerControllerDelegate,
                             UINavigationControllerDelegate {
        let parent: CameraPicker
        init(_ parent: CameraPicker) { self.parent = parent }

        func imagePickerController(
            _ picker: UIImagePickerController,
            didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]
        ) {
            if let image = (info[.editedImage] ?? info[.originalImage]) as? UIImage {
                parent.onCapture(image)
            } else {
                parent.onCancel()
            }
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            parent.onCancel()
        }
    }
}

// MARK: - Document camera (receipts)

/// Apple's document camera (VisionKit, as in Notes) for receipt photos: it outlines the receipt's edges live, says
/// "move closer" / "hold still", shoots by itself once the receipt is steady, and returns the page cropped and
/// straightened (Tim, 2026-11-06: "I didn't see any guides for good angle"). The first page is the receipt; a crew
/// member who scans several gets the first. Not available in the simulator or on unsupported devices: callers check
/// `isSupported` and fall back to CameraPicker.
struct DocumentCameraPicker: UIViewControllerRepresentable {
    var onCapture: (UIImage) -> Void
    var onCancel:  () -> Void

    static var isSupported: Bool { VNDocumentCameraViewController.isSupported }

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIViewController(context: Context) -> VNDocumentCameraViewController {
        let scanner = VNDocumentCameraViewController()
        scanner.delegate = context.coordinator
        return scanner
    }

    func updateUIViewController(_ uiViewController: VNDocumentCameraViewController, context: Context) {}

    final class Coordinator: NSObject, VNDocumentCameraViewControllerDelegate {
        let parent: DocumentCameraPicker
        init(_ parent: DocumentCameraPicker) { self.parent = parent }

        func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFinishWith scan: VNDocumentCameraScan) {
            if scan.pageCount > 0 {
                parent.onCapture(scan.imageOfPage(at: 0))
            } else {
                parent.onCancel()
            }
        }

        func documentCameraViewControllerDidCancel(_ controller: VNDocumentCameraViewController) {
            parent.onCancel()
        }

        func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFailWithError error: Error) {
            AppDiagnostics.shared.record(category: "DocumentCamera", message: ErrorDetails.describe(error))
            parent.onCancel()
        }
    }
}
