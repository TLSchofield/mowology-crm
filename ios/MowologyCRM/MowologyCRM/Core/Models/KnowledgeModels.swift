import Foundation

enum KnowledgeStage: String, Codable, CaseIterable, Comparable {
    case recognized, identified, understood, mastered
    static func < (lhs: Self, rhs: Self) -> Bool { lhs.rank < rhs.rank }
    var rank: Int { Self.allCases.firstIndex(of: self) ?? 0 }
    var title: String { rawValue.capitalized }
    var nextTitle: String? { switch self { case .recognized: "identified"; case .identified: "understood"; case .understood: "mastered"; case .mastered: nil } }
}

enum KnowledgeItemStage: String, Codable, CaseIterable, Comparable {
    case recognized, understood, mastered
    static func < (lhs: Self, rhs: Self) -> Bool { lhs.rank < rhs.rank }
    var rank: Int { Self.allCases.firstIndex(of: self) ?? 0 }
    var title: String { rawValue.capitalized }
}

struct KnowledgeResponse: Decodable {
    let ok: Bool; let head, kind, generatedAt: String
    let thresholds: KnowledgeThresholds; let counts: KnowledgeCounts; let items: [KnowledgeVendor]
    enum CodingKeys: String, CodingKey { case ok, head, kind, thresholds, counts, items; case generatedAt = "generated_at" }
    init(from decoder: Decoder) throws { let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = c.kbBool(.ok); head = c.kbString(.head); kind = c.kbString(.kind); generatedAt = c.kbString(.generatedAt)
        thresholds = (try? c.decode(KnowledgeThresholds.self, forKey: .thresholds)) ?? .empty
        counts = (try? c.decode(KnowledgeCounts.self, forKey: .counts)) ?? .empty
        items = (try? c.decode([KnowledgeVendor].self, forKey: .items)) ?? []
    }
}
struct KnowledgeThresholds: Decodable { let masteredReceipts, masteredMatched, masteredUnchangedStreak, verifiedWithinDays: Int
    static let empty = Self(masteredReceipts: 5, masteredMatched: 3, masteredUnchangedStreak: 3, verifiedWithinDays: 365)
    enum CodingKeys: String, CodingKey { case masteredReceipts = "mastered_receipts", masteredMatched = "mastered_matched", masteredUnchangedStreak = "mastered_unchanged_streak", verifiedWithinDays = "verified_within_days" }
    init(masteredReceipts: Int, masteredMatched: Int, masteredUnchangedStreak: Int, verifiedWithinDays: Int) { self.masteredReceipts=masteredReceipts; self.masteredMatched=masteredMatched; self.masteredUnchangedStreak=masteredUnchangedStreak; self.verifiedWithinDays=verifiedWithinDays }
    init(from d: Decoder) throws { let c=try d.container(keyedBy:CodingKeys.self); self.init(masteredReceipts:c.kbInt(.masteredReceipts), masteredMatched:c.kbInt(.masteredMatched), masteredUnchangedStreak:c.kbInt(.masteredUnchangedStreak), verifiedWithinDays:c.kbInt(.verifiedWithinDays)) }
}
struct KnowledgeCounts: Decodable { let recognized, identified, understood, mastered: Int; static let empty=Self(recognized:0,identified:0,understood:0,mastered:0)
    init(recognized:Int,identified:Int,understood:Int,mastered:Int){self.recognized=recognized;self.identified=identified;self.understood=understood;self.mastered=mastered}
    enum CodingKeys:String,CodingKey{case recognized,identified,understood,mastered}; init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);self.init(recognized:c.kbInt(.recognized),identified:c.kbInt(.identified),understood:c.kbInt(.understood),mastered:c.kbInt(.mastered))}
}
struct KnowledgeException: Decodable, Hashable { let kind, text:String; enum CodingKeys:String,CodingKey{case kind,text}; init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);kind=c.kbString(.kind);text=c.kbString(.text)} }
struct KnowledgeEvidence: Decodable {
    let receiptsApproved, transactionsMatched, itemsRecognized, itemsNew, classificationDecided, unchangedStreak:Int
    let classificationAccuracy:Double?; let lastVerified:String?; let exceptions:[KnowledgeException]
    enum CodingKeys:String,CodingKey{case receiptsApproved="receipts_approved",transactionsMatched="transactions_matched",itemsRecognized="items_recognized",itemsNew="items_new",classificationAccuracy="classification_accuracy",classificationDecided="classification_decided",unchangedStreak="unchanged_streak",lastVerified="last_verified",exceptions}
    static let empty=Self(receiptsApproved:0,transactionsMatched:0,itemsRecognized:0,itemsNew:0,classificationDecided:0,unchangedStreak:0,classificationAccuracy:nil,lastVerified:nil,exceptions:[])
    init(receiptsApproved:Int,transactionsMatched:Int,itemsRecognized:Int,itemsNew:Int,classificationDecided:Int,unchangedStreak:Int,classificationAccuracy:Double?,lastVerified:String?,exceptions:[KnowledgeException]){self.receiptsApproved=receiptsApproved;self.transactionsMatched=transactionsMatched;self.itemsRecognized=itemsRecognized;self.itemsNew=itemsNew;self.classificationDecided=classificationDecided;self.unchangedStreak=unchangedStreak;self.classificationAccuracy=classificationAccuracy;self.lastVerified=lastVerified;self.exceptions=exceptions}
    init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);self.init(receiptsApproved:c.kbInt(.receiptsApproved),transactionsMatched:c.kbInt(.transactionsMatched),itemsRecognized:c.kbInt(.itemsRecognized),itemsNew:c.kbInt(.itemsNew),classificationDecided:c.kbInt(.classificationDecided),unchangedStreak:c.kbInt(.unchangedStreak),classificationAccuracy:c.kbDouble(.classificationAccuracy),lastVerified:c.kbOptionalString(.lastVerified),exceptions:(try?c.decode([KnowledgeException].self,forKey:.exceptions)) ?? [])}
}
struct KnowledgeVendor: Decodable, Identifiable {
    let key:String; let slot:Int; let label:String; let stage:KnowledgeStage; let discoveredAt:String?; let evidence:KnowledgeEvidence; let next:[String]
    var id:String{key}; enum CodingKeys:String,CodingKey{case key,slot,label,stage,evidence,next;case discoveredAt="discovered_at"}
    init(key:String,slot:Int,label:String,stage:KnowledgeStage,evidence:KnowledgeEvidence,next:[String]=[],discoveredAt:String?=nil){self.key=key;self.slot=slot;self.label=label;self.stage=stage;self.evidence=evidence;self.next=next;self.discoveredAt=discoveredAt}
    init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);key=c.kbString(.key);slot=max(0,c.kbInt(.slot));label=c.kbString(.label);stage=KnowledgeStage(rawValue:c.kbString(.stage).lowercased()) ?? .recognized;discoveredAt=c.kbOptionalString(.discoveredAt);evidence=(try?c.decode(KnowledgeEvidence.self,forKey:.evidence)) ?? .empty;next=(try?c.decode([String].self,forKey:.next)) ?? []}
}
struct KnowledgeItem: Decodable, Identifiable { let label:String;let stage:KnowledgeItemStage;let timesSeen,timesConfirmed:Int;var id:String{label}
    enum CodingKeys:String,CodingKey{case label,stage;case timesSeen="times_seen",timesConfirmed="times_confirmed"};init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);label=c.kbString(.label);stage=KnowledgeItemStage(rawValue:c.kbString(.stage)) ?? .recognized;timesSeen=c.kbInt(.timesSeen);timesConfirmed=c.kbInt(.timesConfirmed)} }
struct KnowledgeItemsResponse:Decodable{let ok:Bool;let key:String;let items:[KnowledgeItem];enum CodingKeys:String,CodingKey{case ok,key,items};init(from d:Decoder)throws{let c=try d.container(keyedBy:CodingKeys.self);ok=c.kbBool(.ok);key=c.kbString(.key);items=(try?c.decode([KnowledgeItem].self,forKey:.items)) ?? []}}

private extension KeyedDecodingContainer {
    func kbString(_ k:Key)->String { (try? decode(String.self,forKey:k)) ?? (try? decode(Int.self,forKey:k)).map(String.init) ?? "" }
    func kbOptionalString(_ k:Key)->String? { let s=kbString(k);return s.isEmpty ? nil:s }
    func kbInt(_ k:Key)->Int { (try? decode(Int.self,forKey:k)) ?? Int((try?decode(String.self,forKey:k)) ?? "") ?? 0 }
    func kbBool(_ k:Key)->Bool { (try?decode(Bool.self,forKey:k)) ?? ["1","true","yes"].contains(kbString(k).lowercased()) }
    func kbDouble(_ k:Key)->Double? { (try?decode(Double.self,forKey:k)) ?? Double((try?decode(String.self,forKey:k)) ?? "") }
}
