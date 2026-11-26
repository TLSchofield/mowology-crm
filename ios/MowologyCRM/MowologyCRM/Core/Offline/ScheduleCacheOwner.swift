//
//  ScheduleCacheOwner.swift
//  MowologyCRM
//
//  Whose cached schedule is on this phone. Pure rule, so it is checked on the Mac (ios/LogicTests).
//

import Foundation

enum ScheduleCacheOwner {
    /// The cache is emptied unless it was last filled for exactly this person. No recorded owner (a cache from
    /// before this rule) counts as someone else's: it is only a cache, and the next fetch refills it.
    static func shouldClear(recordedOwner: Int?, signingIn userId: Int) -> Bool {
        recordedOwner != userId
    }
}
