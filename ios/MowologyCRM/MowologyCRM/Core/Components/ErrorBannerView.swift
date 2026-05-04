//
//  ErrorBannerView.swift
//  MowologyCRM
//
//  Shared error / warning banner used across all features.
//  Replaces four different inline implementations with a single
//  consistent component that follows the Mowology design system.
//

import SwiftUI

struct ErrorBannerView: View {

    let message: String
    var accentColor: Color = Color.MW.orange
    var onDismiss: (() -> Void)? = nil

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(accentColor)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(accentColor)
            Spacer()
            if let onDismiss {
                Button(action: onDismiss) {
                    Image(systemName: "xmark")
                        .font(.caption.bold())
                        .foregroundStyle(accentColor)
                }
            }
        }
        .padding(12)
        .background(accentColor.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(accentColor.opacity(0.25), lineWidth: 1)
        )
    }
}

#Preview {
    VStack(spacing: 12) {
        ErrorBannerView(message: "Failed to load schedule. Pull to retry.")
        ErrorBannerView(message: "Upload failed — no connection.", accentColor: .red) {}
        ErrorBannerView(message: "Saved locally — will sync when online.") {}
    }
    .padding()
}
