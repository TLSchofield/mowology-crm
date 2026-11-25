//
//  VisitWorkView.swift
//  MowologyCRM
//
//  Proof-of-work for one visit: tick the checklist, log materials used, leave
//  notes for the office or the client. Mirrors the Checklist / Materials / Notes
//  tabs of the web PoW screen (crm/jobs/visit-work.php).
//

import SwiftUI

struct VisitWorkView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    @StateObject private var viewModel: VisitWorkViewModel

    private enum Pane: String, CaseIterable { case checklist = "Checklist", materials = "Materials", notes = "Notes" }
    @State private var section = Pane.checklist

    // Composer state
    @State private var newChecklistItem = ""
    @State private var materialName = ""
    @State private var materialQty  = ""
    @State private var materialUnit = ""
    @State private var noteText     = ""
    @State private var noteType     = VisitNoteType.general
    @State private var noteVisible  = false

    init(visitId: Int, authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: VisitWorkViewModel(visitId: visitId, authSession: authSession))
    }

    var body: some View {
        VStack(spacing: 0) {
            Picker("Section", selection: $section) {
                ForEach(Pane.allCases, id: \.self) { Text($0.rawValue).tag($0) }
            }
            .pickerStyle(.segmented)
            .padding(.horizontal, 16)
            .padding(.vertical, 10)

            if viewModel.isLocked {
                banner("Locked by the office — view only.", icon: "lock.fill", tint: appStyle.meta)
            }
            if let error = viewModel.errorMessage {
                banner(error, icon: "exclamationmark.triangle.fill", tint: appStyle.attention)
            }

            List {
                switch section {
                case .checklist: checklistSection
                case .materials: materialsSection
                case .notes:     notesSection
                }
            }
            .listStyle(.insetGrouped)
            .itAppForm()
            .scrollDismissesKeyboard(.interactively)
        }
        .background(appStyle.page)
        .itAppScreen()
        .navigationTitle("Work Record")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                if viewModel.isSaving || viewModel.isLoading {
                    ProgressView()
                } else if viewModel.hasUnsavedChecklist || viewModel.hasUnsavedMaterials {
                    Button("Retry") {
                        Task {
                            if viewModel.hasUnsavedChecklist { await viewModel.saveChecklist() }
                            if viewModel.hasUnsavedMaterials { await viewModel.saveMaterials() }
                        }
                    }
                    .tint(appStyle.attention)
                }
            }
        }
        .task { await viewModel.load() }
    }

    // MARK: - Checklist

    @ViewBuilder
    private var checklistSection: some View {
        Section {
            if viewModel.checklist.isEmpty && !viewModel.isLoading {
                Text("No checklist for this job. Add your own items below.")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
            }
            ForEach(viewModel.checklist) { item in
                Button {
                    viewModel.toggle(item)
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: item.checked ? "checkmark.circle.fill" : "circle")
                            .font(appStyle.font(.title3))
                            .foregroundStyle(item.checked ? appStyle.tenant : (appStyle.isEnabled ? appStyle.line : Color(.systemGray3)))
                        Text(item.item)
                            .foregroundStyle(item.checked ? .secondary : appStyle.text)
                            .strikethrough(item.checked, color: appStyle.meta)
                        Spacer(minLength: 0)
                    }
                    .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(viewModel.isLocked)
            }
        } header: {
            if !viewModel.checklist.isEmpty {
                Text("\(viewModel.checkedCount) of \(viewModel.checklist.count) done")
            }
        }

        if !viewModel.isLocked {
            Section {
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())) {
                    TextField("Add checklist item", text: $newChecklistItem)
                        .submitLabel(.done)
                        .onSubmit(addChecklistItem)
                    Button("Add", action: addChecklistItem)
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                        .disabled(newChecklistItem.trimmingCharacters(in: .whitespaces).isEmpty)
                        .tint(appStyle.tenant)
                }
            }
        }
    }

    private func addChecklistItem() {
        viewModel.addChecklistItem(newChecklistItem)
        newChecklistItem = ""
    }

    // MARK: - Materials

    @ViewBuilder
    private var materialsSection: some View {
        Section {
            if viewModel.materials.isEmpty && !viewModel.isLoading {
                Text("Nothing logged yet.")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
            }
            ForEach(viewModel.materials) { material in
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())) {
                    Text(material.name)
                    Spacer()
                    Text(material.quantityLabel).foregroundStyle(appStyle.meta)
                }
            }
            .onDelete { offsets in
                Task { await viewModel.removeMaterials(at: offsets) }
            }
            .deleteDisabled(viewModel.isLocked)
        } header: {
            Text("Materials used")
        }

        if !viewModel.isLocked {
            Section {
                TextField("Material (e.g. Fertilizer 20-5-10)", text: $materialName)
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())) {
                    TextField("Qty", text: $materialQty)
                        .keyboardType(.decimalPad)
                        .frame(maxWidth: appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? .infinity : 90)
                    Divider()
                    TextField("Unit (kg, bags, yd)", text: $materialUnit)
                        .textInputAutocapitalization(.never)
                }
                Button {
                    let qty = Double(materialQty.replacingOccurrences(of: ",", with: "."))
                    let (name, unit) = (materialName, materialUnit)
                    materialName = ""; materialQty = ""; materialUnit = ""
                    Task { await viewModel.addMaterial(name: name, qty: qty, unit: unit) }
                } label: {
                    Label("Add material", systemImage: "plus.circle.fill")
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                }
                .disabled(materialName.trimmingCharacters(in: .whitespaces).isEmpty)
                .tint(appStyle.tenant)
            } header: {
                Text("Log a material")
            }
        }
    }

    // MARK: - Notes

    @ViewBuilder
    private var notesSection: some View {
        if !viewModel.isLocked {
            Section {
                TextField("What should the office know?", text: $noteText, axis: .vertical)
                    .lineLimit(3...8)
                Picker("Type", selection: $noteType) {
                    ForEach(VisitNoteType.allCases) { Text($0.label).tag($0) }
                }
                Toggle("Client can see this", isOn: $noteVisible)
                    .tint(appStyle.tenant)
                Button {
                    Task {
                        if await viewModel.addNote(noteText, type: noteType, visibleToCustomer: noteVisible) {
                            noteText = ""; noteType = .general; noteVisible = false
                        }
                    }
                } label: {
                    Label("Save note", systemImage: "square.and.pencil")
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                }
                .disabled(noteText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || viewModel.isSaving)
                .tint(appStyle.tenant)
            } header: {
                Text("New note")
            }
        }

        Section {
            if viewModel.notes.isEmpty && !viewModel.isLoading {
                Text("No notes on this visit.")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
            }
            ForEach(viewModel.notes) { note in
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 6) {
                        Text(VisitNoteType.label(for: note.noteType))
                            .font(appStyle.font(.caption, weight: .bold))
                            .foregroundStyle(note.noteType == "issue" ? appStyle.attention : appStyle.tenant)
                        if note.visibleToCustomer {
                            Label("Client", systemImage: "eye.fill")
                                .font(appStyle.font(.caption2))
                                .foregroundStyle(appStyle.meta)
                        }
                        Spacer()
                        Text(note.author ?? "").font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                    }
                    Text(note.content).font(appStyle.font(.subheadline))
                }
                .padding(.vertical, 2)
            }
        } header: {
            Text("On this visit")
        }
    }

    // MARK: - Banner

    private func banner(_ text: String, icon: String, tint: Color) -> some View {
        Label(text, systemImage: icon)
            .font(appStyle.font(.footnote))
            .foregroundStyle(tint)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 16)
            .padding(.bottom, 8)
    }
}
