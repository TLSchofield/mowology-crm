//
//  ReceiptsEmptyWording.swift
//  MowologyCRM
//
//  What the empty Receipts screen says about the camera (2026-11-13). Pure, no SwiftUI: checked by
//  `bash ios/LogicTests/run.sh`. The auto wording is only true when the phone's own camera (the one that waits until
//  the phone is steady and shoots by itself) is what opens: ReceiptCameraSwitch on and a camera on the device.
//

import Foundation

enum ReceiptsEmptyWording {
    static let autoLine = "Tap the camera below. Hold your phone over the receipt, and it takes the photo by itself when it is steady."
    static let manualLine = "Tap the camera below, point it at the receipt and tap the button."
    static let adminTail = "Penny reads it and files it; she asks you only if she is not sure."
    static let crewTail = "The office reviews it."

    /// `autoShoots` false: the switch is off or there is no camera, so the person presses the button; the line about who reads
    /// the receipt stays either way (it is what introduces Penny), the same as the Android app.
    static func subtitle(isAdmin: Bool, autoShoots: Bool) -> String {
        (autoShoots ? autoLine : manualLine) + "\n" + (isAdmin ? adminTail : crewTail)
    }

    /// The hint and arrow show only once the list has loaded, is empty and has no error: never while loading.
    static func showsHint(hasLoaded: Bool, isLoading: Bool, isEmpty: Bool, hasError: Bool) -> Bool {
        hasLoaded && !isLoading && isEmpty && !hasError
    }
}
