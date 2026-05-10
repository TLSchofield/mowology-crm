//
//  MessageModels.swift
//  MowologyCRM
//
//  Decodable models for the /api/messages/inbox JWT endpoint.
//

import Foundation

// MARK: - Message

struct Message: Decodable, Identifiable {
    let id: Int
    let subject: String
    let body: String
    let isRead: Bool
    let readAt: String?
    let contextType: String
    let contextId: Int?
    let createdAt: String
    let fromName: String
    let fromUserId: Int

    var formattedDate: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        guard let date = formatter.date(from: createdAt) else { return createdAt }
        let out = DateFormatter()
        out.dateStyle = .short
        out.timeStyle = .short
        return out.string(from: date)
    }

    enum CodingKeys: String, CodingKey {
        case id, subject, body
        case isRead       = "is_read"
        case readAt       = "read_at"
        case contextType  = "context_type"
        case contextId    = "context_id"
        case createdAt    = "created_at"
        case fromName     = "from_name"
        case fromUserId   = "from_user_id"
    }

    /// Returns a copy of this message with `isRead` set to `true`.
    func asRead() -> Message {
        Message(
            id: id, subject: subject, body: body,
            isRead: true, readAt: readAt,
            contextType: contextType, contextId: contextId,
            createdAt: createdAt, fromName: fromName, fromUserId: fromUserId
        )
    }
}

// MARK: - Inbox response

struct InboxResponse: Decodable {
    let success: Bool
    let messages: [Message]
    let total: Int
    let page: Int
    let pages: Int
}

// MARK: - Unread count response

struct UnreadCountResponse: Decodable {
    let success: Bool
    let count: Int
}

// MARK: - User (for compose To: picker)

struct MessageUser: Decodable, Identifiable {
    let id: Int
    let fullName: String
    let role: String

    enum CodingKeys: String, CodingKey {
        case id, role
        case fullName = "full_name"
    }
}

struct MessageUsersResponse: Decodable {
    let success: Bool
    let users: [MessageUser]
}

// MARK: - Generic success response

struct MessageActionResponse: Decodable {
    let success: Bool
    let id: Int?
    let error: String?
}
