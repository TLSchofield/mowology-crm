//
//  MessagesView.swift
//  MowologyCRM
//
//  In-app message inbox with compose sheet.
//  Accessible from the Account tab for all roles.
//

import SwiftUI

struct MessagesView: View {

    @StateObject private var viewModel: MessagesViewModel
    @State private var showCompose = false

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: MessagesViewModel(authSession: authSession))
    }

    var body: some View {
        NavigationStack {
            Group {
                if viewModel.isLoading && viewModel.messages.isEmpty {
                    loadingView
                } else if let err = viewModel.errorMessage, viewModel.messages.isEmpty {
                    errorView(err)
                } else if viewModel.messages.isEmpty {
                    emptyView
                } else {
                    messageList
                }
            }
            .navigationTitle("Messages")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        Task { await viewModel.loadUsers() }
                        showCompose = true
                    } label: {
                        Image(systemName: "square.and.pencil")
                    }
                }
                ToolbarItem(placement: .navigationBarLeading) {
                    Button {
                        Task { await viewModel.loadInbox() }
                    } label: {
                        Image(systemName: "arrow.clockwise")
                    }
                    .disabled(viewModel.isLoading)
                }
            }
            .task { await viewModel.loadInbox() }
            .sheet(isPresented: $showCompose) {
                ComposeView(viewModel: viewModel, isPresented: $showCompose)
            }
        }
    }

    // MARK: - Message list

    private var messageList: some View {
        List {
            ForEach(viewModel.messages) { message in
                NavigationLink {
                    MessageDetailView(message: message) {
                        Task { await viewModel.markRead(ids: [message.id]) }
                    }
                } label: {
                    MessageRow(message: message)
                }
                .listRowInsets(EdgeInsets(top: 0, leading: 16, bottom: 0, trailing: 16))
            }

            if viewModel.currentPage < viewModel.totalPages {
                Button("Load more…") {
                    Task { await viewModel.loadNextPage() }
                }
                .foregroundStyle(Color.MW.green)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
                .listRowSeparator(.hidden)
            }
        }
        .listStyle(.plain)
        .refreshable { await viewModel.loadInbox() }
    }

    // MARK: - States

    private var loadingView: some View {
        VStack(spacing: 12) {
            ProgressView()
            Text("Loading messages…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private var emptyView: some View {
        VStack(spacing: 12) {
            Image(systemName: "tray")
                .font(.system(size: 44))
                .foregroundStyle(.secondary)
            Text("No messages")
                .font(.headline)
            Text("Your inbox is empty.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle")
                .font(.largeTitle)
                .foregroundStyle(.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Retry") {
                Task { await viewModel.loadInbox() }
            }
            .buttonStyle(.bordered)
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

// MARK: - Message Row

private struct MessageRow: View {
    let message: Message

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            // Unread indicator
            Circle()
                .fill(message.isRead ? Color.clear : Color.MW.green)
                .frame(width: 8, height: 8)
                .padding(.top, 6)

            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(message.fromName)
                        .font(.subheadline.weight(message.isRead ? .regular : .semibold))
                    Spacer()
                    Text(message.formattedDate)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Text(message.subject)
                    .font(.subheadline)
                    .foregroundStyle(message.isRead ? .secondary : .primary)
                    .lineLimit(1)
                Text(message.body)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
        }
        .padding(.vertical, 8)
    }
}

// MARK: - Message Detail

struct MessageDetailView: View {
    let message: Message
    let onAppear: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                // Header
                VStack(alignment: .leading, spacing: 6) {
                    Text(message.subject)
                        .font(.title3.bold())
                    HStack {
                        Label(message.fromName, systemImage: "person.fill")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(message.formattedDate)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                .padding()
                .background(Color(.secondarySystemGroupedBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))

                // Body
                Text(message.body)
                    .font(.body)
                    .padding()
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Color(.secondarySystemGroupedBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .padding()
        }
        .navigationTitle("Message")
        .navigationBarTitleDisplayMode(.inline)
        .onAppear(perform: onAppear)
    }
}

// MARK: - Compose Sheet

private struct ComposeView: View {
    @ObservedObject var viewModel: MessagesViewModel
    @Binding var isPresented: Bool

    var body: some View {
        NavigationStack {
            Form {
                Section("To") {
                    if viewModel.users.isEmpty {
                        ProgressView()
                    } else {
                        Picker("Recipient", selection: $viewModel.composeToUserId) {
                            Text("Select…").tag(0)
                            ForEach(viewModel.users) { user in
                                Text(user.fullName).tag(user.id)
                            }
                        }
                    }
                }

                Section("Subject") {
                    TextField("Subject (optional)", text: $viewModel.composeSubject)
                }

                Section("Message") {
                    TextEditor(text: $viewModel.composeBody)
                        .frame(minHeight: 120)
                }

                if let err = viewModel.sendError {
                    Section {
                        Label(err, systemImage: "exclamationmark.triangle")
                            .foregroundStyle(.red)
                            .font(.caption)
                    }
                }
            }
            .navigationTitle("New Message")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        viewModel.resetCompose()
                        isPresented = false
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Send") {
                        Task {
                            if await viewModel.sendMessage() {
                                isPresented = false
                            }
                        }
                    }
                    .disabled(viewModel.composeToUserId == 0 || viewModel.composeBody.isEmpty || viewModel.isSending)
                }
            }
        }
    }
}
