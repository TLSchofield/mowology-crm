import Foundation

@MainActor
protocol KnowledgeAPI {
    func knowledge() async throws -> KnowledgeResponse
    func items(key: String) async throws -> KnowledgeItemsResponse
}

@MainActor
struct LiveKnowledgeAPI: KnowledgeAPI {
    let client: APIClient

    func knowledge() async throws -> KnowledgeResponse {
        let response: KnowledgeResponse = try await client.request(.pennyKnowledge)
        guard response.ok else { throw APIError.serverError("Penny's knowledge is unavailable right now.") }
        return response
    }

    func items(key: String) async throws -> KnowledgeItemsResponse {
        let response: KnowledgeItemsResponse = try await client.request(.pennyKnowledgeItems(key: key))
        guard response.ok else { throw APIError.serverError("Penny's item details are unavailable right now.") }
        return response
    }
}
