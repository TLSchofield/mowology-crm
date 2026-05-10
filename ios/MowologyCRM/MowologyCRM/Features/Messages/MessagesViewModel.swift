//
//  MessagesViewModel.swift
//  MowologyCRM
//

import Foundation

@MainActor
final class MessagesViewModel: ObservableObject {

    // MARK: - Published

    @Published private(set) var messages: [Message]  = []
    @Published private(set) var unreadCount: Int     = 0
    @Published private(set) var users: [MessageUser] = []
    @Published private(set) var isLoading            = false
    @Published private(set) var currentPage          = 1
    @Published private(set) var totalPages           = 1
    @Published var errorMessage: String?             = nil

    // Compose state
    @Published var composeToUserId: Int  = 0
    @Published var composeSubject        = ""
    @Published var composeBody           = ""
    @Published private(set) var isSending = false
    @Published var sendError: String?     = nil

    // MARK: - Private

    private let apiClient: APIClient

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Load inbox

    func loadInbox(page: Int = 1) async {
        isLoading    = true
        errorMessage = nil
        do {
            let response: InboxResponse = try await apiClient.request(
                .messagesInbox(page: page)
            )
            messages    = response.messages
            currentPage = response.page
            totalPages  = response.pages
        } catch {
            errorMessage = "Could not load messages. Check your connection."
        }
        isLoading = false
    }

    func loadNextPage() async {
        guard currentPage < totalPages, !isLoading else { return }
        await loadInbox(page: currentPage + 1)
    }

    // MARK: - Unread count (for badge)

    func refreshUnreadCount() async {
        guard let response = try? await apiClient.request(.messagesUnreadCount) as UnreadCountResponse else { return }
        unreadCount = response.count
    }

    // MARK: - Mark read

    func markRead(ids: [Int]) async {
        guard !ids.isEmpty else { return }
        _ = try? await apiClient.request(
            .messagesAction,
            body: ["action": "mark-read", "ids": ids]
        ) as MessageActionResponse

        // Update local state optimistically
        messages = messages.map { msg in
            ids.contains(msg.id) ? msg.asRead() : msg
        }
        if unreadCount > 0 { unreadCount = max(0, unreadCount - ids.count) }
    }

    // MARK: - Load users (for compose To: picker)

    func loadUsers() async {
        guard users.isEmpty else { return }
        guard let response = try? await apiClient.request(.messagesUsers) as MessageUsersResponse else { return }
        users = response.users
    }

    // MARK: - Compose & send

    func sendMessage() async -> Bool {
        isSending = true
        sendError = nil
        defer { isSending = false }

        let body: [String: Any] = [
            "action":       "compose",
            "to_user_id":   composeToUserId,
            "subject":      composeSubject,
            "body":         composeBody,
            "context_type": "none",
            "context_id":   0,
        ]

        do {
            let response: MessageActionResponse = try await apiClient.request(
                .messagesAction, body: body
            )
            if response.success {
                composeSubject  = ""
                composeBody     = ""
                composeToUserId = 0
                return true
            } else {
                sendError = response.error ?? "Failed to send message."
                return false
            }
        } catch {
            sendError = error.localizedDescription
            return false
        }
    }

    func resetCompose() {
        composeSubject  = ""
        composeBody     = ""
        composeToUserId = 0
        sendError       = nil
    }
}
