//
//  PennyRiskRow.swift
//  MowologyCRM
//
//  Penny's explanation of a receipt's risk score on the receipt detail screen (admins).
//  Data: GET /api/expenses/bookkeeper-mobile?mode=risk (RiskExplainer.php).
//

import SwiftUI

// MARK: - Penny's risk row

/// Penny explains the score: her face, a one-line summary, and one plain line per flag,
/// with a small ring at the side. Admins only (ReceiptDetailView falls back to the ring).
struct PennyRiskRow: View {
    let risk: ReceiptRiskResponse
    let score: Int
    let status: String

    private var color: Color {
        switch score {
        case 31...:   return .red
        case 16...30: return Color.MW.orange
        default:      return Color.MW.green
        }
    }

    private var isDone: Bool { ["approved", "forwarded", "sent"].contains(status) }

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            AsyncImage(url: URL(string: "https://mowology.ca/crm/img/heads/penny.jpg")) { phase in
                if let img = phase.image {
                    img.resizable().scaledToFill()
                } else {
                    Text("P").font(.headline.bold()).foregroundStyle(.white)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .background(Color.MW.dark)
                }
            }
            .frame(width: 44, height: 44)
            .clipShape(Circle())
            .overlay(Circle().stroke(Color.MW.lime, lineWidth: 3))

            VStack(alignment: .leading, spacing: 6) {
                Text(risk.summary)
                    .font(.subheadline.weight(.semibold))
                    .fixedSize(horizontal: false, vertical: true)
                ForEach(risk.flags) { f in
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Text("•").font(.footnote.bold()).foregroundStyle(color)
                        Text(f.penny)
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
            Spacer(minLength: 0)

            VStack(spacing: 2) {
                ZStack {
                    Circle().stroke(Color(.systemGray5), lineWidth: 4)
                    Circle()
                        .trim(from: 0, to: min(Double(score) / 40.0, 1.0))
                        .stroke(color, style: StrokeStyle(lineWidth: 4, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                    Text("\(score)").font(.caption2.weight(.semibold))
                }
                .frame(width: 32, height: 32)
                Text(isDone ? "Checked" : risk.tier.capitalized)
                    .font(.system(size: 9, weight: .semibold))
                    .foregroundStyle(isDone ? Color.MW.green : color)
            }
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .combine)
    }
}
