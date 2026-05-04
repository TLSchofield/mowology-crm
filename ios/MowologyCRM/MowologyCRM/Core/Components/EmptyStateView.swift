//
//  EmptyStateView.swift
//  MowologyCRM
//
//  Shared empty-state placeholder used across all features.
//

import SwiftUI

struct EmptyStateView: View {

    let icon: String
    let title: String
    var subtitle: String? = nil

    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: icon)
                .font(.system(size: 48))
                .foregroundStyle(Color(.systemGray3))

            Text(title)
                .font(.title3.bold())
                .foregroundStyle(.primary)
                .multilineTextAlignment(.center)

            if let subtitle {
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
        }
        .padding(32)
        .frame(maxWidth: .infinity)
    }
}

#Preview {
    VStack {
        EmptyStateView(icon: "calendar.badge.exclamationmark", title: "No stops today", subtitle: "Enjoy your day off!")
        EmptyStateView(icon: "doc.text.image", title: "No receipts yet", subtitle: "Tap the camera button to snap your first receipt.")
    }
}
