//
//  AddDial.swift
//  MowologyCRM
//
//  The menu behind the bar's Add slot (Tim, 2026-11-05). Tapping Add moves the bead there (it shows ×), lays a
//  light veil over the screen and stacks one circle per thing you can add straight above the bead, nearest the
//  thumb first. ×, the veil or the escape gesture closes it and the bead goes back to the tab you were on.
//  Only what the iPhone can create today is listed; the rest join as their screens are built.
//

import SwiftUI

/// Identifies one creation action offered by the Add dial.
enum AddDialItem: CaseIterable, Identifiable {
    case job, client, task, quote, invoice

    var id: Self { self }

    var title: String {
        switch self {
        case .job:    return "Job"
        case .client: return "Client"
        case .task:   return "Task"
        case .quote:  return "Quote"
        case .invoice: return "Invoice"
        }
    }

    var icon: String {
        switch self {
        case .job:    return "calendar.badge.plus"
        case .client: return "person.crop.circle.badge.plus"
        case .task:   return "checklist"
        case .quote:  return "doc.text"
        case .invoice: return "dollarsign.circle"
        }
    }

    /// Bottom of the column (nearest the thumb) first.
    /// Quote and Invoice are the office's; crew invoice their own visits from the visit.
    static func column(office: Bool) -> [AddDialItem] {
        office ? [.job, .client, .task, .quote, .invoice] : [.job, .client, .task]
    }
}

/// Presents creation actions in a column aligned with the Add tab's physical slot.
struct AddDial: View {
    @Environment(\.itAppStyle) private var style
    @Environment(\.layoutDirection) private var layoutDirection

    /// The Add slot and how many slots the bar has, so the column rises straight above the bead.
    let slot: Int
    let slotCount: Int
    var office = false
    let onPick: (AddDialItem) -> Void
    let onClose: () -> Void

    private let disc: CGFloat = 56

    var body: some View {
        GeometryReader { geo in
            let physicalSlot = ItMeniscusGeometry.physicalSlotIndex(
                slot, count: slotCount, isRightToLeft: layoutDirection == .rightToLeft
            )
            let beadX = ItMeniscusGeometry.beadX(CGFloat(physicalSlot), count: slotCount, width: geo.size.width)
            ZStack(alignment: .bottomTrailing) {
                // A light veil, not a dark scrim: the screen stays readable behind the menu.
                Rectangle()
                    .fill(.ultraThinMaterial)
                    .overlay(style.page.opacity(0.82))
                    .ignoresSafeArea(edges: .top)
                    .onTapGesture(perform: onClose)
                    .accessibilityHidden(true)

                VStack(alignment: .trailing, spacing: 12) {
                    ForEach(AddDialItem.column(office: office).reversed()) { item in
                        Button { onPick(item) } label: {
                            HStack(spacing: 14) {
                                Text(item.title)
                                    .font(style.font(.title3, weight: .medium))
                                    .foregroundStyle(style.text)
                                Image(systemName: item.icon)
                                    .font(.system(size: 22, weight: .medium))
                                    .foregroundStyle(style.tenant)
                                    .frame(width: disc, height: disc)
                                    .background(Circle().fill(style.surface))
                                    .overlay(Circle().stroke(style.tenant, lineWidth: 1.5))   // a tenant ring: in dark the surface alone sank into the veil
                                    .shadow(color: style.line, radius: 6, y: 2)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel("Add a \(item.title.lowercased())")
                    }
                }
                .padding(.trailing, max(8, geo.size.width - beadX - disc / 2))
                .padding(.bottom, 14)
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityAction(.escape, onClose)
    }
}
