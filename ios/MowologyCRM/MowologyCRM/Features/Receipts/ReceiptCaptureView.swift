//
//  ReceiptCaptureView.swift
//  MowologyCRM
//
//  Camera and photo-library picker. Compresses to JPEG before upload.
//

import SwiftUI
import PhotosUI
import CoreLocation

struct ReceiptCaptureView: View {

    @ObservedObject var viewModel: ReceiptsViewModel
    @Binding var showCapture: Bool
    @Binding var showReview:  Bool

    @State private var pickerItem: PhotosPickerItem?
    @State private var showCamera = false

    private let locationManager = CLLocationManager()

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                if viewModel.isUploading {
                    uploadingView
                } else {
                    sourceButtons
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
        .onChange(of: pickerItem) { _, newItem in
            guard let newItem else { return }
            Task { await handlePickedItem(newItem) }
        }
        .presentationDetents([.medium])
    }

    // MARK: - Source Buttons

    private var sourceButtons: some View {
        VStack(spacing: 16) {
            PhotosPicker(selection: $pickerItem, matching: .images, photoLibrary: .shared()) {
                Label("Camera / Library", systemImage: "camera.fill")
                    .font(.headline)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 14))
            }

            Text("Point at a receipt and capture it — the app will read the amounts automatically.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)
        }
    }

    // MARK: - Uploading view

    private var uploadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.5)
            Text("Reading receipt…")
                .font(.headline)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Handle picked photo

    private func handlePickedItem(_ item: PhotosPickerItem) async {
        guard let data = try? await item.loadTransferable(type: Data.self) else { return }

        // Compress to ≤ 1.5 MB JPEG
        let compressed = compressToJpeg(data, maxBytes: 1_500_000)

        let location = await currentLocation()
        await viewModel.uploadImage(compressed, lat: location?.coordinate.latitude, lng: location?.coordinate.longitude)

        // Dismiss capture sheet; if upload succeeded show review
        showCapture = false
        if viewModel.intakeResponse != nil {
            showReview = true
        }
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
