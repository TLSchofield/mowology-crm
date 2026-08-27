import Foundation

/// A service the office has published to the field — one tappable chip on the
/// job card. Curated in the CRM under Products › Field Recommendations.
///
/// Explicit CodingKeys throughout: the decoder deliberately does NOT use
/// `.convertFromSnakeCase` (see APIClient).
struct RecommendationOption: Codable, Identifiable, Hashable {
    let productId: Int
    let label: String
    let description: String
    let price: Double

    /// Fixed-price package — tapping this emails the client a quote immediately.
    let autoSend: Bool

    /// Price does not depend on measuring the property.
    let fixedPrice: Bool

    var id: Int { productId }

    /// "$450.00"
    var formattedPrice: String {
        String(format: "$%.2f", price)
    }

    enum CodingKeys: String, CodingKey {
        case productId   = "product_id"
        case label
        case description
        case price
        case autoSend    = "auto_send"
        case fixedPrice  = "fixed_price"
    }

    /// Every optional field decodes with `try?` so an older server payload still
    /// yields a usable chip rather than failing the whole list (same approach as Visit).
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        productId   = (try? c.decode(Int.self, forKey: .productId)) ?? 0
        label       = (try? c.decode(String.self, forKey: .label)) ?? "Service"
        description = (try? c.decode(String.self, forKey: .description)) ?? ""
        price       = (try? c.decode(Double.self, forKey: .price)) ?? 0
        autoSend    = (try? c.decode(Bool.self, forKey: .autoSend)) ?? false
        fixedPrice  = (try? c.decode(Bool.self, forKey: .fixedPrice)) ?? false
    }
}

/// GET /api/schedule/recommendation?action=options
struct RecommendationOptionsResponse: Decodable {
    let success: Bool
    let options: [RecommendationOption]

    enum CodingKeys: String, CodingKey {
        case success, options
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = (try? c.decode(Bool.self, forKey: .success)) ?? false
        options = (try? c.decode([RecommendationOption].self, forKey: .options)) ?? []
    }
}

/// POST /api/schedule/recommendation
struct RecommendationCreateResponse: Decodable {
    let success: Bool
    let observationId: Int
    let status: String
    let duplicate: Bool
    let quoteId: Int?
    let autoSent: Bool
    let message: String

    enum CodingKeys: String, CodingKey {
        case success
        case observationId = "observation_id"
        case status
        case duplicate
        case quoteId       = "quote_id"
        case autoSent      = "auto_sent"
        case message
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success       = (try? c.decode(Bool.self, forKey: .success)) ?? false
        observationId = (try? c.decode(Int.self, forKey: .observationId)) ?? 0
        status        = (try? c.decode(String.self, forKey: .status)) ?? "pending"
        duplicate     = (try? c.decode(Bool.self, forKey: .duplicate)) ?? false
        quoteId       = try? c.decode(Int.self, forKey: .quoteId)
        autoSent      = (try? c.decode(Bool.self, forKey: .autoSent)) ?? false
        message       = (try? c.decode(String.self, forKey: .message)) ?? "Saved"
    }
}
