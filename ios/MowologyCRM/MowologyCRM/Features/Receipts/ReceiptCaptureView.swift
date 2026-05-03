//
//  ReceiptCaptureView.swift
//  MowologyCRM
//

import SwiftUI
import PhotosUI
import UIKit
import CoreLocation

// MARK: - UIImagePickerController wrapper

private struct CameraPicker: UIViewControllerRepresentable {
    var onCapture: (UIImage) -> Void
    var onCancel:  () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker           = UIImagePickerController()
        picker.sourceType    = UIImagePickerController.isSourceTypeAvailable(.camera) ? .camera : .photoLibrary
        picker.delegate      = context.coordinator
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

// MARK: - ReceiptCaptureView

struct ReceiptCaptureView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    @Binding var showCapture: Bool
    @Binding var showReview:  Bool

    // Camera opens after sheet finishes animating (same as Capacitor setTimeout 300ms).
    @State private var showCamera  = false
    @State private var pickerItem: PhotosPickerItem?

    private let locationManager = CLLocationManager()

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                if viewModel.isUploading {
                    uploadingView
                } else {
                    fallbackButtons
                }
                if let err = viewModel.uploadError {
                    Text(err)
                        .font(.subheadline)
                        .foregroundStyle(.red)
                        .multilineTextAlignment(.center)
                        .padding(.horizontal)
                }
            }
            .padding()
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Add Receipt")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showCapture = false }
                        .foregroundStyle(Color.MW.green)
                }
            }
        }
        // Camera opens immediately and covers the sheet.
        .fullScreenCover(isPresented: $showCamera) {
            CameraPicker(
                onCapture: { image in
                    showCamera = false
                    guard let data = image.jpegData(compressionQuality: 0.9) else { return }
                    Task { await handleImageData(data) }
                },
                onCancel: {
                    showCamera = false
                    showCapture = false
                }
            )
            .ignoresSafeArea()
        }
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            Task {
                guard let data = try? await newItem.loadTransferable(type: Data.self) else { return }
                await handleImageData(data)
            }
        }
        // Delay mirrors Capacitor setTimeout(triggerCamera, 300) so the sheet
        // animation finishes before the fullScreenCover is presented.
        .onAppear {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) { showCamera = true }
        }
        .presentationDetents([.medium])
    }

    // MARK: - Fallback buttons (shown only when camera is dismissed without capture)

    private var fallbackButtons: some View {
        VStack(spacing: 16) {
            Button {
                viewModel.uploadError = nil
                showCamera = true
            } label: {
                Label("Take Photo", systemImage: "camera.fill")
                    .font(.headline)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 14))
            }

            PhotosPicker(selection: $pickerItem, matching: .images, photoLibrary: .shared()) {
                Label("Choose from Library", systemImage: "photo.on.rectangle")
                    .font(.headline)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(Color(.secondarySystemGroupedBackground))
                    .foregroundStyle(Color.MW.green)
                    .clipShape(RoundedRectangle(cornerRadius: 14))
                    .overlay(
                        RoundedRectangle(cornerRadius: 14)
                            .stroke(Color.MW.green, lineWidth: 1.5)
                    )
            }

            Text("Point at a receipt — the app reads amounts automatically.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)
        }
    }

    // MARK: - Uploading indicator

    private var uploadingView: some View {
        VStack(spacing: 16) {
            ProgressView().scaleEffect(1.5)
            Text("Reading receipt…")
                .font(.headline)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Image processing

    private func handleImageData(_ data: Data) async {
        let compressed = compressToJpeg(data, maxBytes: 1_500_000)
        let location   = await currentLocation()
        await viewModel.uploadImage(
            compressed,
            lat: location?.coordinate.latitude,
            lng: location?.coordinate.longitude
        )
        showCapture = false
        if viewModel.intakeResponse != nil { showReview = true }
    }

    private func compressToJpeg(_ data: Data, maxBytes: Int) -> Data {
        guard let uiImage = UIImage(data: data) else { return data }
        var quality: CGFloat = 0.85
        while quality > 0.1 {
            if let jpeg = uiImage.jpegData(compressionQuality: quality), jpeg.count <= maxBytes {
                return jpeg
            }
            quality -= 0.15
        }
        return uiImage.jpegData(compressionQuality: 0.1) ?? data
    }

    private func currentLocation() async -> CLLocation? {
        locationManager.requestWhenInUseAuthorization()
        return locationManager.location
    }
}
