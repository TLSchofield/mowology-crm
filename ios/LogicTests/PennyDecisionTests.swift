// Logic tests for Penny's decision order and page (written by the Penny job).
import Foundation

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { fatalError(message) }
}

let decisions = [
    PennyDecision(kind: .receipt, id: "r1", name: "Office Shop"),
    PennyDecision(kind: .duplicate, id: "d1", name: ""),
    PennyDecision(kind: .cheque, id: "c1", name: "Cheque deposit"),
    PennyDecision(kind: .depositMatch, id: "p1", name: "e-Transfer"),
    PennyDecision(kind: .materialsReceipt, id: "m1", name: "Garden Supply"),
    PennyDecision(kind: .materialsReceipt, id: "m2", name: "Nursery")
]

let ordered = PennyDecisionLogic.ordered(decisions)
expect(ordered.map(\.id) == ["m1", "m2", "p1", "c1", "d1", "r1"],
       "Penny decision priority or stable order changed")
expect(PennyDecisionLogic.nextDecision(decisions)?.id == "m1", "The page must open on the material receipt")
expect(PennyDecisionLogic.nextDecision(Array(decisions.dropLast(2)))?.id == "p1", "Deposits must precede cheques")
expect(PennyDecisionLogic.nextDecision([]) == nil, "An empty queue has no next decision")
expect(PennyDecisionLogic.position(of: PennyDecisionLogic.nextDecision(Array(decisions.dropLast(2))),
                                   in: ["answered", "p1", "later"]) == 1,
       "The carousel must reopen on the decision selected for Kaname")
expect(PennyDecisionLogic.position(of: nil, in: ["p1"]) == nil, "No decision must not move a carousel")

expect(ordered[0].rowText == "Garden Supply to review", "Receipt row text must name the opening receipt")
expect(ordered[2].rowText == "e-Transfer to match", "Deposit row text must name the opening deposit")
expect(ordered[4].rowText == "Receipts to compare", "Duplicate row text changed")

print("PennyDecisionTests passed")
