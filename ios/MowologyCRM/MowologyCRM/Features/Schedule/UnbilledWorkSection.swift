//
//  UnbilledWorkSection.swift
//  MowologyCRM
//
//  "Also unbilled at this address" in the Complete Visit sheet: other work at the same
//  property nobody has billed (last 60 days), each as a toggle with its service date, amount
//  and evidence ("Job timer ran Tue Sep 29 9:59–10:20 (22 min)"). Ticked lines go on the
//  invoice being sent; the server re-checks them inside the invoice transaction.
//
//  Same list as the web panel (MwUnbilledWork) — UnbilledWorkFinder on the server.
//

import SwiftUI

struct UnbilledWorkSection: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var vm: VisitCompletionViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Also unbilled at this address", systemImage: "exclamationmark.circle")
                .font(appStyle.font(.footnote, weight: .semibold))
                .foregroundStyle(appStyle.meta)
                .textCase(.uppercase)
                .padding(.leading, 4)

            VStack(spacing: 0) {
                ForEach(vm.unbilledItems) { item in
                    row(item)
                    if item.id != vm.unbilledItems.last?.id { Divider() }
                }
                ForEach(vm.unbilledHints) { hint in
                    Divider()
                    Text(hint.text)
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.meta)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.vertical, 8)
                }
                if !vm.tickedUnbilled.isEmpty {
                    Divider()
                    HStack {
                        Text("Adding \(vm.tickedUnbilled.count) line\(vm.tickedUnbilled.count == 1 ? "" : "s")")
                            .font(appStyle.font(.subheadline))
                        Spacer()
                        Text(String(format: "+$%.2f + GST", vm.unbilledSubtotal))
                            .font(appStyle.font(.subheadline, weight: .bold).monospacedDigit())
                            .foregroundStyle(appStyle.tenant)
                    }
                    .padding(.top, 10)
                }
            }
            .padding(14)
            .background(appStyle.surface)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
    }

    private func row(_ item: UnbilledWorkItem) -> some View {
        let locked = item.isPossiblyDone && !vm.canMarkDone
        return VStack(alignment: .leading, spacing: 6) {
            Toggle(isOn: Binding(
                get: { vm.tickedUnbilled.contains(item.visitId) },
                set: { vm.setTicked(item, $0) }
            )) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(item.badge.uppercased())
                        .font(appStyle.font(.caption2, weight: .bold))
                        .padding(.horizontal, 7)
                        .padding(.vertical, 2)
                        .background(item.isPossiblyDone ? appStyle.attention : appStyle.tenant.opacity(0.12))
                        .foregroundStyle(item.isPossiblyDone ? Color.white : appStyle.tenant)
                        .clipShape(Capsule())
                    Text(item.description)
                        .font(appStyle.font(.subheadline, weight: .semibold))
                        .fixedSize(horizontal: false, vertical: true)
                    if !item.needsPrice {
                        Text(String(format: "$%.2f", item.amount))
                            .font(appStyle.font(.subheadline).monospacedDigit())
                    }
                }
            }
            .tint(appStyle.tenant)
            .disabled(locked || (item.needsPrice && vm.unbilledAmount(item) <= 0 && !vm.tickedUnbilled.contains(item.visitId)))

            if item.needsPrice {
                HStack {
                    Text("Price").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                    TextField("0.00", value: Binding(
                        get: { vm.unbilledPrices[item.visitId] },
                        set: { vm.setPrice(item, $0) }
                    ), format: .number.precision(.fractionLength(2)))
                    .keyboardType(.decimalPad)
                    .textFieldStyle(.roundedBorder)
                    .frame(maxWidth: 110)
                }
            }

            ForEach(item.evidence, id: \.self) { e in
                Text(e).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            }
            ForEach(item.warnings, id: \.self) { w in
                Label(w, systemImage: "exclamationmark.triangle")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.attention)
            }
            if locked {
                Text("The office has to confirm this one.")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.attention)
            }
        }
        .padding(.vertical, 8)
    }
}
