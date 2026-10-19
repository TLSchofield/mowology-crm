import Foundation

protocol KnowledgeAPI {
    func knowledge() async throws -> KnowledgeResponse
    func items(key: String) async throws -> KnowledgeItemsResponse
}

struct LiveKnowledgeAPI: KnowledgeAPI {
    let client: APIClient
    func knowledge() async throws -> KnowledgeResponse { try await client.request(.pennyKnowledge) }
    func items(key: String) async throws -> KnowledgeItemsResponse { try await client.request(.pennyKnowledgeItems(key: key)) }
}
