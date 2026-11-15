import Foundation

private func step(_ key: String, _ state: BusinessOnboardingLogic.State) -> BusinessOnboardingLogic.Step {
    .init(key: key, title: key, state: state, head: nil, skippable: true, data: [:])
}

private func map(_ states: [BusinessOnboardingLogic.Step]) -> BusinessOnboardingLogic.MapState {
    .init(steps: states, next: nil, readyToFinish: false)
}

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else { fatalError(message) }
}

expect(BrainOnSpineLogic.units(for: map([
    step("name", .next), step("company", .todo), step("done", .todo)
])) == 0, "No stored answers must mean an empty brain")

expect(BrainOnSpineLogic.units(for: map([
    step("name", .done), step("company", .todo), step("books", .notNeeded), step("done", .done)
])) == 1, "Only real done answers count; not-needed and the sentinel do not")

expect(BrainOnSpineLogic.units(for: map([
    step("name", .done), step("company", .skipped), step("done", .done)
])) == 1, "A skipped answer must not earn a triangle or complete the brain")

expect(BrainOnSpineLogic.units(for: map([
    step("name", .done), step("company", .done), step("books", .notNeeded), step("done", .todo)
])) == nil, "All applicable stored answers must show Kaname's face")

expect(BrainOnSpineLogic.phase(from: 2, to: 3, reduceMotion: false) == .build(from: 2, to: 3),
       "A new stored answer builds a triangle")
expect(BrainOnSpineLogic.phase(from: 3, to: nil, reduceMotion: false) == .turnToFace(units: 3),
       "Completion turns the brain into the face")
expect(BrainOnSpineLogic.phase(from: 3, to: nil, reduceMotion: true) == .face,
       "Reduce Motion must show the completed face immediately")
expect(BrainOnSpineLogic.phase(from: 2, to: 3, reduceMotion: true) == .brain(units: 3),
       "Reduce Motion must show the new brain immediately")

expect(BrainOnSpineLogic.accessibilityValue(units: 0) == "0 brain units",
       "The empty brain must expose its unit count")
expect(BrainOnSpineLogic.accessibilityValue(units: 1) == "1 brain unit",
       "A single unit must use the singular wording")
expect(BrainOnSpineLogic.accessibilityValue(units: 5) == "5 brain units",
       "The current brain unit count must be exposed")
expect(BrainOnSpineLogic.accessibilityValue(units: nil) == "Onboarding complete",
       "The completed face must expose completion")

expect(BrainOnSpineLogic.demoState(arguments: ["app", "-mwOnboardBarDemo", "0"]) == .brain(units: 0),
       "The demo exposes the empty state")
expect(BrainOnSpineLogic.demoState(arguments: ["app", "-mwOnboardBarDemo=5"]) == .brain(units: 5),
       "The demo exposes intermediate triangle counts")
expect(BrainOnSpineLogic.demoState(arguments: ["app", "-mwOnboardBarDemo", "6"]) == .face,
       "The sixth demo state is the completed face")
expect(BrainOnSpineLogic.demoState(arguments: ["app"]) == nil,
       "A normal launch must not override live onboarding state")

print("BrainOnSpineTests passed")
